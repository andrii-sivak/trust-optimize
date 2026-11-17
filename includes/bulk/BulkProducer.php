<?php
/**
 * Hands the attachments of a bulk job over to the queue, a batch at a time.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use Throwable;
use WP_Error;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Utils\DiskSpace;

/**
 * Class BulkProducer
 *
 * The producer is one Action Scheduler action per job that reschedules itself. It never converts
 * anything: a sync job puts an attachment task on the queue for every attachment, so one
 * crashing image cannot stop the others. Its position is the job's cursor, its progress is
 * derived by JobProgress, and it keeps the number of waiting tasks below a limit (backpressure).
 */
class BulkProducer {

	/**
	 * Action Scheduler hook of the producer.
	 */
	const HOOK_PRODUCE = 'trust_optimize_bulk_produce';

	/**
	 * Attachments handed over per run when nothing else is configured.
	 */
	const DEFAULT_BATCH_SIZE = 25;

	/**
	 * Largest batch (also the limit of the CLI option).
	 */
	const MAX_BATCH_SIZE = 100;

	/**
	 * Waiting attachment tasks above which the producer pauses.
	 */
	const DEFAULT_MAX_PENDING = 200;

	/**
	 * Seconds before a producer that waits for the queue looks again.
	 */
	const WAIT_SECONDS = 30;

	/**
	 * Seconds one run may spend handing attachments over.
	 */
	const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Eligibility query.
	 *
	 * @var EligibilityQuery
	 */
	private $eligibility;

	/**
	 * Job progress.
	 *
	 * @var JobProgress
	 */
	private $progress;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Conversion queue.
	 *
	 * @var ConversionQueue
	 */
	private $queue;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Inventory.
	 *
	 * @var Inventory
	 */
	private $inventory;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Job health.
	 *
	 * @var JobHealth
	 */
	private $health;

	/**
	 * Constructor.
	 *
	 * @param BulkJobRepository    $jobs         Job repository.
	 * @param EligibilityQuery     $eligibility  Eligibility query.
	 * @param JobProgress          $progress     Job progress.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param ConversionQueue      $queue        Conversion queue.
	 * @param ImageCleanupService  $cleanup      Cleanup service.
	 * @param Inventory            $inventory    Inventory.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 * @param DatabaseManager      $database     Database manager.
	 * @param JobHealth            $health       Job health.
	 */
	public function __construct( BulkJobRepository $jobs, EligibilityQuery $eligibility, JobProgress $progress, AttachmentRepository $attachments, ConversionQueue $queue, ImageCleanupService $cleanup, Inventory $inventory, Settings $settings, CapabilityService $capabilities, DatabaseManager $database, JobHealth $health ) {
		$this->jobs         = $jobs;
		$this->eligibility  = $eligibility;
		$this->progress     = $progress;
		$this->attachments  = $attachments;
		$this->queue        = $queue;
		$this->cleanup      = $cleanup;
		$this->inventory    = $inventory;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
		$this->database     = $database;
		$this->health       = $health;
	}

	/**
	 * Register the producer action.
	 */
	public function register() {
		add_action( self::HOOK_PRODUCE, array( $this, 'produce' ), 10, 1 );
	}

	/**
	 * Create a job and start it.
	 *
	 * @param string $type Job type (BulkJob::TYPE_*).
	 * @return BulkJob|false|WP_Error False when another job is active; an error before the database update has finished,
	 *                                or when the first producer could not be scheduled (the job is then failed and the library free).
	 */
	public function launch( $type ) {
		if ( ! $this->database->has_schema( DatabaseManager::SCHEMA_RECOVERY ) ) {
			return DatabaseManager::schema_pending_error();
		}

		$total = BulkJob::TYPE_REMOVE === $type ? $this->eligibility->count_plugin_managed_attachments() : $this->eligibility->count_eligible_attachments();
		$job   = $this->jobs->create( $type, OptimizationSettings::from_options( $this->settings, $this->capabilities )->to_array(), $total );

		if ( ! $job ) {
			return false;
		}

		if ( ! $this->jobs->mark_running( $job->get_id() ) || ! $this->schedule( $job->get_id() ) ) {
			$this->jobs->fail( $job->get_id(), 'producer could not be scheduled' );

			return new WP_Error(
				'trust_optimize_start_failed',
				__( 'The bulk job could not be started: Action Scheduler did not accept its first task.', 'trust-optimize' ),
				array( 'status' => 500 )
			);
		}

		return $this->jobs->get( $job->get_id() );
	}

	/**
	 * Continue a paused job.
	 *
	 * The order matters. The disk is checked first, and a refusal changes nothing. Then the dead attempts of the
	 * queue are recovered, so they are queued again or parked before the job runs (Resume then clears the job's last error). Then the job is marked running, which records the
	 * Resume; when its producer cannot be scheduled that record is undone, unless a Pause or another Resume came first.
	 * Resuming a job that already runs only schedules a missing producer.
	 *
	 * @param int $job_id Job ID.
	 * @return bool|WP_Error False when there is no unfinished job; an error when the Resume was refused or failed.
	 */
	public function resume( $job_id ) {
		if ( ! $this->database->has_schema( DatabaseManager::SCHEMA_RECOVERY ) ) {
			return DatabaseManager::schema_pending_error();
		}

		$job = $this->jobs->get( $job_id );

		if ( ! $job || ! in_array( $job->get_status(), JobStatus::active(), true ) ) {
			return false;
		}

		if ( BulkJob::TYPE_SYNC === $job->get_type() && DiskSpace::is_low() ) {
			return new WP_Error(
				'trust_optimize_disk_low',
				__( 'The disk is almost full. Free some space, then resume the job.', 'trust-optimize' ),
				array( 'status' => 409 )
			);
		}

		$this->health->recover_attempts();

		// The recovery may have written a death into the job: what is restored after a failure is read after it.
		$job      = $this->jobs->get( $job_id );
		$previous = $job ? $job->to_array() : array();
		$token    = bin2hex( random_bytes( 16 ) );

		if ( ! $job || ! $this->jobs->mark_running( $job_id, $token ) ) {
			return false;
		}

		if ( $this->schedule( $job_id ) ) {
			return true;
		}

		return $this->resume_failed( $job_id, $token, $previous );
	}

	/**
	 * The error of a Resume whose producer could not be scheduled, after undoing the Resume where that is still possible.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $token    Token of this Resume.
	 * @param array  $previous The job before the Resume.
	 * @return WP_Error
	 */
	private function resume_failed( $job_id, $token, array $previous ) {
		$reverted = JobStatus::PAUSED === ( $previous['status'] ?? '' ) && $this->jobs->revert_resume( $job_id, $token, $previous );
		$current  = $this->jobs->get( $job_id );
		$message  = __( 'The job could not be resumed: Action Scheduler did not accept its task.', 'trust-optimize' );

		if ( ! $reverted && $current ) {
			$message .= ' ' . sprintf(
				/* translators: %s: status of the job, such as running or paused. */
				__( 'The job is now %s.', 'trust-optimize' ),
				$current->get_status()
			);
		}

		return new WP_Error(
			'trust_optimize_resume_failed',
			$message,
			array(
				'status'   => 500,
				'reverted' => $reverted,
			)
		);
	}

	/**
	 * Pause a job. Attachments already on the queue are parked when their action runs, and wait for Resume.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function pause( $job_id ) {
		$paused = $this->jobs->pause( $job_id, PauseReason::USER );

		if ( $paused ) {
			$this->unschedule( $job_id );
		}

		return $paused;
	}

	/**
	 * Cancel a job. Attachments already on the queue are still processed.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		$cancelled = $this->jobs->cancel( $job_id );

		if ( $cancelled ) {
			$this->unschedule( $job_id );

			// No producer will come for the attachments that wait for Resume, and Cancel leaves queued work to finish.
			do {
				$queued = $this->queue_parked( (int) $job_id, self::batch_size() );
			} while ( $queued > 0 );
		}

		return $cancelled;
	}

	/**
	 * Action Scheduler callback: hand the next batch over, then reschedule or finish the job.
	 *
	 * @param int $job_id Job ID.
	 */
	public function produce( $job_id ) {
		$job = $this->jobs->get( (int) $job_id );

		if ( ! $job || JobStatus::RUNNING !== $job->get_status() ) {
			return;
		}

		if ( BulkJob::TYPE_SYNC === $job->get_type() ) {
			if ( DiskSpace::is_low() ) {
				$this->jobs->pause( $job->get_id(), PauseReason::LOW_DISK_SPACE );
				return;
			}

			if ( $this->has_backlog() ) {
				$this->wait( $job->get_id() );
				return;
			}
		}

		$limit = self::batch_size();

		if ( BulkJob::TYPE_SYNC === $job->get_type() ) {
			$this->queue_parked( $job->get_id(), $limit );
		}

		$ids      = $this->next_ids( $job, $limit );
		$deadline = microtime( true ) + max( 1, (int) apply_filters( 'trust_optimize_bulk_time_budget', self::DEFAULT_TIME_BUDGET ) );
		$handled  = 0;

		foreach ( $ids as $attachment_id ) {
			$this->hand_over( $job, $attachment_id );
			$this->jobs->set_cursor( $job->get_id(), $attachment_id );
			++$handled;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( BulkJob::TYPE_INVENTORY === $job->get_type() ) {
			$this->record_inspection( $job, array_slice( $ids, 0, $handled ) );
		}

		$job = $this->jobs->get( $job->get_id() );

		// The job may have been paused or cancelled meanwhile.
		if ( ! $job || JobStatus::RUNNING !== $job->get_status() ) {
			return;
		}

		// Fewer IDs than asked for, all handled: nothing is left behind the cursor.
		if ( count( $ids ) === $handled && $handled < $limit ) {
			$this->finish_or_wait( $job );
			return;
		}

		$this->schedule( $job->get_id() );
	}

	/**
	 * Most conversion tasks the producer leaves waiting on the queue.
	 *
	 * @return int
	 */
	public static function max_pending() {
		return max( 1, (int) apply_filters( 'trust_optimize_bulk_max_pending', self::DEFAULT_MAX_PENDING ) );
	}

	/**
	 * Number of attachments per run.
	 *
	 * @return int
	 */
	public static function batch_size() {
		$size = (int) apply_filters( 'trust_optimize_bulk_batch_size', self::DEFAULT_BATCH_SIZE );

		return max( 1, min( self::MAX_BATCH_SIZE, $size ) );
	}

	/**
	 * Cancel the scheduled producers of every job.
	 */
	public static function cancel_all_tasks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PRODUCE, null, ConversionQueue::GROUP );
		}
	}

	/**
	 * Do the work of the job for one attachment.
	 *
	 * Sync marks the attachment with the job and queues it; remove deletes through the cleanup
	 * service. A failure of one attachment never stops the job.
	 *
	 * @param BulkJob $job           Job.
	 * @param int     $attachment_id Attachment ID.
	 */
	private function hand_over( BulkJob $job, $attachment_id ) {
		try {
			if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
				$this->cleanup->cleanup_attachment( $attachment_id );
			} elseif ( BulkJob::TYPE_SYNC === $job->get_type() ) {
				$this->attachments->assign_job( $attachment_id, $job->get_id() );
				$this->queue->plan_and_enqueue( $attachment_id );
			}
		} catch ( Throwable $throwable ) {
			$this->attachments->set_state( $attachment_id, AttachmentState::FAILED, 'exception', $throwable->getMessage() );
		}
	}

	/**
	 * Queue the attachments of the job that wait for Resume, wherever the cursor is.
	 *
	 * A queue action that ran while the job was paused (or after a deactivation) left its attachment parked; the cursor
	 * has passed it, so only this brings it back.
	 *
	 * @param int $job_id Job ID.
	 * @param int $limit  Maximum number of attachments.
	 * @return int Number of attachments queued.
	 */
	private function queue_parked( $job_id, $limit ) {
		$queued = 0;

		foreach ( $this->attachments->find_parked( $job_id, $limit ) as $attachment_id ) {
			if ( $this->queue->enqueue( $attachment_id ) ) {
				++$queued;
			}
		}

		return $queued;
	}

	/**
	 * Next attachment IDs after the cursor, for the type of the job.
	 *
	 * @param BulkJob $job   Job.
	 * @param int     $limit Maximum number of IDs.
	 * @return int[]
	 */
	private function next_ids( BulkJob $job, $limit ) {
		if ( BulkJob::TYPE_REMOVE === $job->get_type() ) {
			return $this->eligibility->get_next_plugin_managed_attachment_ids( $job->get_cursor_id(), $limit );
		}

		return $this->eligibility->get_next_attachment_ids( $job->get_cursor_id(), $limit );
	}

	/**
	 * The cursor is exhausted: complete the job, or wait for its tasks on the queue and for the attachments parked until Resume.
	 *
	 * @param BulkJob $job Job.
	 */
	private function finish_or_wait( BulkJob $job ) {
		if ( $this->progress->in_flight( $job ) > 0 ) {
			$this->wait( $job->get_id() );
			return;
		}

		if ( BulkJob::TYPE_INVENTORY === $job->get_type() ) {
			$this->jobs->merge_snapshot( $job->get_id(), 'inventory', $this->inventory->summary() );
		}

		$this->jobs->complete( $job->get_id(), $this->progress->has_errors( $job ) );
	}

	/**
	 * Add what the inventory found in a batch to the totals stored with the job.
	 *
	 * @param BulkJob $job Inventory job.
	 * @param int[]   $ids Attachment IDs of the batch.
	 */
	private function record_inspection( BulkJob $job, array $ids ) {
		$totals = $job->get_settings_snapshot()['inventory'] ?? array();

		foreach ( $this->inventory->inspect( $ids ) as $key => $count ) {
			$totals[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + $count;
		}

		$this->jobs->merge_snapshot( $job->get_id(), 'inventory', $totals );
	}

	/**
	 * Whether more attachment tasks wait than the queue should hold.
	 *
	 * @return bool
	 */
	private function has_backlog() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$max = self::max_pending();

		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => ConversionQueue::HOOK_PROCESS,
					'group'    => ConversionQueue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => $max + 1,
					'orderby'  => 'none',
				),
				'ids'
			)
		) > $max;
	}

	/**
	 * Look at the job again later, keeping it marked as alive.
	 *
	 * @param int $job_id Job ID.
	 */
	private function wait( $job_id ) {
		$this->jobs->touch( $job_id );
		$this->schedule( $job_id, self::WAIT_SECONDS );
	}

	/**
	 * Schedule the next producer run unless one is already waiting.
	 *
	 * The running action itself is not "pending", so a producer can schedule its successor.
	 *
	 * @param int $job_id Job ID.
	 * @param int $delay  Seconds from now.
	 * @return bool True when a producer run is scheduled (a pending one counts), false when Action Scheduler refused (an ID of 0, or an exception).
	 */
	private function schedule( $job_id, $delay = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		if ( $this->has_pending_producer( $job_id ) ) {
			return true;
		}

		try {
			if ( $delay > 0 ) {
				return 0 !== as_schedule_single_action( time() + $delay, self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
			}

			return 0 !== as_enqueue_async_action( self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/**
	 * Whether a producer run of the job is scheduled.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	private function has_pending_producer( $job_id ) {
		return ! empty(
			as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK_PRODUCE,
					'args'     => array( 'job_id' => (int) $job_id ),
					'group'    => ConversionQueue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			)
		);
	}

	/**
	 * Remove the scheduled producer runs of a job.
	 *
	 * @param int $job_id Job ID.
	 */
	private function unschedule( $job_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PRODUCE, array( 'job_id' => (int) $job_id ), ConversionQueue::GROUP );
		}
	}
}
