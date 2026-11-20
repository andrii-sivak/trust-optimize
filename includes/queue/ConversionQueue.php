<?php
/**
 * Conversion Queue class
 *
 * Manages asynchronous image conversion via Action Scheduler: one action per attachment.
 *
 * @package TrustOptimize\Queue
 */

namespace TrustOptimize\Queue;

use Throwable;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class ConversionQueue
 */
class ConversionQueue {

	/**
	 * Action Scheduler hook that processes one attachment.
	 */
	const HOOK_PROCESS = 'trust_optimize_process_attachment';

	/**
	 * Action Scheduler group name.
	 */
	const GROUP = 'trust-optimize';

	/**
	 * Seconds before a task that found the database update unfinished runs again.
	 */
	const SCHEMA_RETRY_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Priority of the plugin's own service actions: the bulk producer, Maintenance and Restore (Action Scheduler runs a lower number first).
	 */
	const PRIORITY_SERVICE = 10;

	/**
	 * Priority of conversion actions (Action Scheduler runs a lower number first, so conversions yield to the service actions and to default-priority tasks of other plugins).
	 */
	const PRIORITY_CONVERSION = 20;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Attachment processor.
	 *
	 * @var AttachmentProcessor
	 */
	private $processor;

	/**
	 * Variant planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

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
	 * @param AttachmentProcessor  $processor   Attachment processor.
	 * @param VariantPlanner       $planner     Variant planner.
	 * @param DatabaseManager      $database    Database manager.
	 */
	public function __construct( AttachmentRepository $attachments, AttachmentProcessor $processor, VariantPlanner $planner, DatabaseManager $database ) {
		$this->attachments = $attachments;
		$this->processor   = $processor;
		$this->planner     = $planner;
		$this->database    = $database;
	}

	/**
	 * Register the Action Scheduler hook and the upload handler.
	 */
	public function register() {
		add_action( self::HOOK_PROCESS, array( $this, 'process' ), 10, 1 );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_new_metadata' ), 20, 2 );
	}

	/**
	 * Plan and queue an attachment whose metadata was just generated.
	 *
	 * @param array $metadata      Attachment metadata (returned unchanged).
	 * @param int   $attachment_id Attachment ID.
	 * @return array
	 */
	public function handle_new_metadata( $metadata, $attachment_id ) {
		$this->plan_and_enqueue( $attachment_id );

		return $metadata;
	}

	/**
	 * Plan an attachment, remove what is no longer wanted and queue the work that is left.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when work was queued.
	 */
	public function plan_and_enqueue( $attachment_id ) {
		$plan = $this->planner->plan( $attachment_id );
		$this->processor->apply_removals( $attachment_id, $plan );

		if ( $plan->has_work() ) {
			return $this->enqueue( $attachment_id );
		}

		if ( $plan->is_skipped() ) {
			$this->processor->log_planner_skip( $attachment_id, $plan );
		} else {
			$this->attachments->recompute( $attachment_id );
		}

		return false;
	}

	/**
	 * Queue an attachment unless it is already queued or being processed.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	public function enqueue( $attachment_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! $this->attachments->mark_queued( $attachment_id ) ) {
			return false;
		}

		return $this->schedule_or_park( $attachment_id );
	}

	/**
	 * Queue an attachment again whose task was lost.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	public function requeue( $attachment_id ) {
		return function_exists( 'as_enqueue_async_action' )
			&& $this->attachments->requeue( $attachment_id, AttachmentRepository::STALE_CLAIM_SECONDS )
			&& $this->schedule_or_park( $attachment_id );
	}

	/**
	 * Schedule the action of an attachment a dead attempt left queued, at priority 20.
	 *
	 * Nothing is scheduled for an attachment that is not queued: it was parked behind a paused bulk job or given up.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	public function schedule_recovered( $attachment_id ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || AttachmentState::QUEUED !== $this->attachments->get_state( $attachment_id ) ) {
			return false;
		}

		return $this->schedule_or_park( $attachment_id );
	}

	/**
	 * Action Scheduler callback: process one attachment, continuing in a new action when the time budget ran out.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @throws Throwable Whatever the processor threw, after the attachment was marked failed.
	 */
	public function process( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// The claim writes columns of the newest schema: wait for the migration instead of spending an attempt.
		if ( ! $this->database->has_schema( DatabaseManager::SCHEMA_RECOVERY ) ) {
			$this->schedule( $attachment_id, self::SCHEMA_RETRY_SECONDS );
			return;
		}

		$result = $this->processor->run( $attachment_id );

		// A file that already used all its attempts is not converted again by a late action; one that no attempt holds is closed here.
		if ( AttachmentProcessor::ATTEMPTS_EXHAUSTED === $result->get_message() ) {
			$this->attachments->give_up( $attachment_id );
			return;
		}

		// Held by another worker, or the bulk job it belongs to is paused: park it when the job is, and when the park misses
		// (the job was resumed meanwhile) take the claim once more.
		if ( AttachmentProcessor::BUSY === $result->get_message() && $result->is_skipped() ) {
			if ( $this->attachments->park( $attachment_id ) ) {
				return;
			}

			$result = $this->processor->run( $attachment_id );
		}

		$data = $result->get_data();

		if ( ! empty( $data['more'] ) ) {
			$this->schedule_or_park( $attachment_id );
		}
	}

	/**
	 * Cancel pending tasks of an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public function cancel_attachment_tasks( $attachment_id ) {
		self::cancel_tasks_for_attachment( $attachment_id );
	}

	/**
	 * Cancel pending tasks of an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 */
	public static function cancel_tasks_for_attachment( $attachment_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS, array( 'attachment_id' => (int) $attachment_id ), self::GROUP );
		}
	}

	/**
	 * Cancel all pending TrustOptimize conversion tasks.
	 */
	public static function cancel_all_tasks() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS, null, self::GROUP );
		}
	}

	/**
	 * Schedule the action for a queued attachment; when that fails, park an attachment of an unfinished bulk job so its producer queues it again.
	 *
	 * Without a job the attachment stays queued for the maintenance task that repairs lost queued attachments.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when an action was scheduled.
	 */
	private function schedule_or_park( $attachment_id ) {
		if ( $this->schedule( $attachment_id ) ) {
			return true;
		}

		$this->attachments->repark( $attachment_id );

		return false;
	}

	/**
	 * Schedule the action for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $delay         Seconds from now; 0 runs it as soon as a runner is free.
	 * @return bool True when Action Scheduler accepted the action.
	 */
	private function schedule( $attachment_id, $delay = 0 ) {
		$args = array( 'attachment_id' => (int) $attachment_id );

		try {
			if ( $delay > 0 ) {
				return 0 !== as_schedule_single_action( time() + $delay, self::HOOK_PROCESS, $args, self::GROUP, false, self::PRIORITY_CONVERSION );
			}

			return 0 !== as_enqueue_async_action( self::HOOK_PROCESS, $args, self::GROUP, false, self::PRIORITY_CONVERSION );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}
}
