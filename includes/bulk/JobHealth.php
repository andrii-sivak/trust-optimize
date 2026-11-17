<?php
/**
 * Finds work that stopped without telling anyone and puts it right.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

/**
 * Class JobHealth
 *
 * The health check has two steps. Step 1 recovers a queue attempt that Action Scheduler confirms dead. Age alone proves nothing:
 * a worker that is slow, or one whose action Action Scheduler has given up on, may still be running.
 * So an attempt is recovered only when it has been silent for longer than STALE_CLAIM_SECONDS, holds no
 * publication lease, and no Action Scheduler action of the attachment is pending or in progress.
 * An attempt that step 1 may not recover (a synchronous one, one from before the attempt record, one inside
 * a publication) is reported as held, for an operator to release. Step 2 stops a running job whose producer
 * has vanished, unless it was resumed while the queue is stuck and has simply not had a chance to work yet.
 * The queue signals are diagnostics: they say what looks wrong with Action Scheduler and decide nothing.
 */
class JobHealth {

	/**
	 * Attachments looked at per call.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Seconds of silence after which an Action Scheduler action counts as stuck.
	 */
	const SIGNAL_SECONDS = 900;

	/**
	 * Signal: an action has been in progress, or claimed, for too long.
	 */
	const SIGNAL_STUCK_CLAIM = 'stuck_claim';

	/**
	 * Signal: an action is due, and nothing has completed for too long.
	 */
	const SIGNAL_NO_PROGRESS = 'no_progress';

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	private $queue;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param BulkJobRepository    $jobs        Job repository.
	 * @param ConversionQueue      $queue       Conversion queue.
	 * @param DatabaseManager      $database    Database manager.
	 * @param VariantRepository    $variants    Variant repository.
	 */
	public function __construct( AttachmentRepository $attachments, BulkJobRepository $jobs, ConversionQueue $queue, DatabaseManager $database, VariantRepository $variants ) {
		$this->attachments = $attachments;
		$this->jobs        = $jobs;
		$this->queue       = $queue;
		$this->database    = $database;
		$this->variants    = $variants;
	}

	/**
	 * Run both steps on admin page loads.
	 *
	 * Never on AJAX or cron requests: a status poll must not change anything.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'check_admin_load' ) );
	}

	/**
	 * Callback of admin_init.
	 */
	public function check_admin_load() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$this->recover_attempts();
		$this->stop_stale_jobs();
	}

	/**
	 * Step 1: recover the queue attempts that Action Scheduler confirms dead.
	 *
	 * Each recovery is the death compare-and-set of the attachment repository. The attachment is queued again
	 * (ahead of new work), parked when its bulk job is paused, or given up after its third death. A job that
	 * is not finished remembers the death in its last error.
	 *
	 * @return int Number of attempts recovered.
	 */
	public function recover_attempts() {
		if ( ! $this->database->has_schema( '1.2.0' ) || ! $this->scheduler_ready() ) {
			return 0;
		}

		$recovered = 0;

		foreach ( $this->attachments->find_death_candidates( AttachmentRepository::STALE_CLAIM_SECONDS, self::BATCH_SIZE ) as $row ) {
			if ( false !== $this->has_live_action( $row['attachment_id'] ) ) {
				continue;
			}

			if ( ! $this->attachments->recover_dead( $row['attachment_id'], $row['attempt_token'] ) ) {
				continue;
			}

			++$recovered;

			if ( null !== $row['job_id'] ) {
				$this->jobs->record_death( $row['job_id'], sprintf( 'attachment %d, death %d of %d', $row['attachment_id'], $row['attempts'], AttachmentRepository::MAX_ATTEMPTS ) );
			}

			$this->queue->schedule_recovered( $row['attachment_id'] );
		}

		return $recovered;
	}

	/**
	 * Whether Action Scheduler can answer questions at all.
	 *
	 * Before it has initialised, its query functions return nothing, which must not be read as "no action".
	 *
	 * @return bool
	 */
	private function scheduler_ready() {
		return function_exists( 'as_get_scheduled_actions' ) && did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * Whether an action of the attachment is pending or in progress.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool|null Null when the answer cannot be trusted (the query failed).
	 */
	private function has_live_action( $attachment_id ) {
		global $wpdb;

		$wpdb->last_error = '';

		$found = as_get_scheduled_actions(
			array(
				'hook'     => ConversionQueue::HOOK_PROCESS,
				'args'     => array( 'attachment_id' => (int) $attachment_id ),
				'group'    => ConversionQueue::GROUP,
				'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
				'per_page' => 1,
			),
			'ids'
		);

		return '' === $wpdb->last_error ? ! empty( $found ) : null;
	}

	/**
	 * Attachments that an operator has to release.
	 *
	 * A row is held when step 1 may not recover it: a synchronous attempt or a row from before the attempt record
	 * that has been silent for longer than STALE_CLAIM_SECONDS, or a row whose publication lease is that old.
	 *
	 * @return array[] Each with attachment_id, source ("queue", "sync" or "legacy"), token (null for legacy), since (updated_at), attempts, lease (publishing_at or null), kind and paths.
	 *               The kind is "publication_incomplete" (lease set and variants still processing; paths lists them), "lease_not_released" (lease set, no variant processing), "sync" or "legacy".
	 */
	public function held() {
		if ( ! $this->database->has_schema( '1.2.0' ) ) {
			return array();
		}

		$held = array();

		foreach ( $this->attachments->find_held( AttachmentRepository::STALE_CLAIM_SECONDS, self::BATCH_SIZE ) as $row ) {
			$paths = array();

			if ( null !== $row['publishing_at'] ) {
				foreach ( $this->variants->get_for_attachment( $row['attachment_id'] ) as $variant ) {
					if ( VariantStatus::PROCESSING === $variant['status'] ) {
						$paths[] = (string) $variant['relative_path'];
					}
				}
			}

			$source = $row['attempt_source'] ?? 'legacy';
			$kind   = $source;

			if ( null !== $row['publishing_at'] ) {
				$kind = array() === $paths ? 'lease_not_released' : 'publication_incomplete';
			}

			$held[] = array(
				'attachment_id' => $row['attachment_id'],
				'source'        => $source,
				'token'         => $row['attempt_token'],
				'since'         => $row['updated_at'],
				'attempts'      => $row['attempts'],
				'lease'         => $row['publishing_at'],
				'kind'          => $kind,
				'paths'         => $paths,
			);
		}

		return $held;
	}

	/**
	 * Step 2: stop the running jobs whose producer vanished.
	 *
	 * A job silent for longer than the threshold is stopped, except a Resume that has had no chance yet: no work
	 * finished since it, and either its producer is still scheduled or the queue shows a signal. Such a job is
	 * waiting for Action Scheduler, and stopping it again would undo the Resume.
	 *
	 * @return int Number of jobs stopped.
	 */
	public function stop_stale_jobs() {
		$threshold = $this->jobs->stale_after_seconds();
		$stopped   = 0;

		foreach ( $this->jobs->find_stale_running( $threshold ) as $job ) {
			if ( $this->waits_for_the_queue( $job ) ) {
				continue;
			}

			if ( $this->jobs->stop( $job, $threshold ) ) {
				++$stopped;
			}
		}

		return $stopped;
	}

	/**
	 * Whether a resumed job without progress still has a reason to wait.
	 *
	 * @param BulkJob $job Job that has been silent for too long.
	 * @return bool
	 */
	private function waits_for_the_queue( BulkJob $job ) {
		if ( empty( $job->to_array()['resumed_at'] ) || $this->jobs->has_progress_since_resume( $job ) ) {
			return false;
		}

		return $this->producer_is_scheduled( $job->get_id() ) || array() !== $this->queue_signals();
	}

	/**
	 * Whether a producer action of the job is pending or in progress.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function producer_is_scheduled( $job_id ) {
		if ( ! $this->scheduler_ready() ) {
			return false;
		}

		return ! empty(
			as_get_scheduled_actions(
				array(
					'hook'     => BulkProducer::HOOK_PRODUCE,
					'args'     => array( 'job_id' => (int) $job_id ),
					'group'    => ConversionQueue::GROUP,
					'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
					'per_page' => 1,
				),
				'ids'
			)
		);
	}

	/**
	 * What looks wrong with the queue, computed only while a bulk job is active.
	 *
	 * Diagnostics for the person who has to act: they never stop, recover or refuse anything.
	 *
	 * @return string[] SIGNAL_* values.
	 */
	public function queue_signals() {
		if ( ! $this->scheduler_ready() || null === $this->jobs->get_active_job() ) {
			return array();
		}

		$cutoff  = as_get_datetime_object( time() - self::SIGNAL_SECONDS );
		$signals = array();

		$stuck   = $this->query_actions(
			array(
				'status'           => \ActionScheduler_Store::STATUS_RUNNING,
				'modified'         => $cutoff,
				'modified_compare' => '<',
			)
		);
		$claimed = $this->query_actions(
			array(
				'status'           => \ActionScheduler_Store::STATUS_PENDING,
				'claimed'          => true,
				'modified'         => $cutoff,
				'modified_compare' => '<',
			)
		);

		if ( ! empty( $stuck ) || ! empty( $claimed ) ) {
			$signals[] = self::SIGNAL_STUCK_CLAIM;
		}

		$due = $this->query_actions(
			array(
				'status'       => \ActionScheduler_Store::STATUS_PENDING,
				'date'         => $cutoff,
				'date_compare' => '<',
			)
		);

		if ( ! empty( $due ) ) {
			$completed = $this->query_actions(
				array(
					'hook'             => ConversionQueue::HOOK_PROCESS,
					'status'           => \ActionScheduler_Store::STATUS_COMPLETE,
					'modified'         => $cutoff,
					'modified_compare' => '>=',
				)
			);

			if ( empty( $completed ) ) {
				$signals[] = self::SIGNAL_NO_PROGRESS;
			}
		}

		return $signals;
	}

	/**
	 * One action ID of the plugin's group that matches a query.
	 *
	 * @param array $query Arguments of as_get_scheduled_actions().
	 * @return array Empty when nothing matches or the query failed.
	 */
	private function query_actions( array $query ) {
		global $wpdb;

		$wpdb->last_error = '';

		$found = as_get_scheduled_actions(
			array_merge(
				$query,
				array(
					'group'    => ConversionQueue::GROUP,
					'per_page' => 1,
				)
			),
			'ids'
		);

		return '' === $wpdb->last_error ? (array) $found : array();
	}
}
