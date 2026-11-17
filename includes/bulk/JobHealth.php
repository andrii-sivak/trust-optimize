<?php
/**
 * Finds work that stopped without telling anyone and puts it right.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class JobHealth
 *
 * Step 1 recovers a queue attempt that Action Scheduler confirms dead. Age alone proves nothing:
 * a worker that is slow, or one whose action Action Scheduler has given up on, may still be running.
 * So an attempt is recovered only when it has been silent for longer than STALE_CLAIM_SECONDS, holds no
 * publication lease, and no Action Scheduler action of the attachment is pending or in progress.
 */
class JobHealth {

	/**
	 * Attachments looked at per call.
	 */
	const BATCH_SIZE = 20;

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
	 * Constructor.
	 *
	 * @param AttachmentRepository $attachments Attachment repository.
	 * @param BulkJobRepository    $jobs        Job repository.
	 * @param ConversionQueue      $queue       Conversion queue.
	 * @param DatabaseManager      $database    Database manager.
	 */
	public function __construct( AttachmentRepository $attachments, BulkJobRepository $jobs, ConversionQueue $queue, DatabaseManager $database ) {
		$this->attachments = $attachments;
		$this->jobs        = $jobs;
		$this->queue       = $queue;
		$this->database    = $database;
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
}
