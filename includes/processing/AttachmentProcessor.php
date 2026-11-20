<?php
/**
 * Converts the pending variants of one attachment.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Processing;

use Throwable;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Diagnostics\ConversionDiagnostics;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Planning\Plan;
use TrustOptimize\Planning\VariantPlanner;
use TrustOptimize\Service\ImageCleanupService;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class AttachmentProcessor
 */
class AttachmentProcessor {

	/**
	 * Default time budget of one run, in seconds.
	 */
	const DEFAULT_TIME_BUDGET = 20;

	/**
	 * Message of the failed result of a sync that waits for the database update.
	 */
	const SCHEMA_PENDING = 'schema_pending';

	/**
	 * Message of the skipped result of a run or sync that found the attachment held or parked.
	 */
	const BUSY = 'busy';

	/**
	 * Message of the skipped result of a run whose attempt was taken away while it worked.
	 */
	const ATTEMPT_LOST = 'attempt_lost';

	/**
	 * Message of a queue run refused because the attachment already used all its attempts.
	 */
	const ATTEMPTS_EXHAUSTED = 'attempts_exhausted';

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Image converter.
	 *
	 * @var ImageConverter
	 */
	private $converter;

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
	 * Variant planner.
	 *
	 * @var VariantPlanner
	 */
	private $planner;

	/**
	 * Cleanup service.
	 *
	 * @var ImageCleanupService
	 */
	private $cleanup;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Conversion diagnostics writer, or null when the processor was built without one.
	 *
	 * @var ConversionDiagnostics|null
	 */
	private $diagnostics;

	/**
	 * Deadline of the current convert_pending() run. Set once; never moved for writer time.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepository       $attachments  Attachment repository.
	 * @param VariantRepository          $variants     Variant repository.
	 * @param ImageConverter             $converter    Image converter.
	 * @param VariantPlanner             $planner      Variant planner.
	 * @param ImageCleanupService        $cleanup      Cleanup service.
	 * @param Settings                   $settings     Plugin settings.
	 * @param CapabilityService          $capabilities Capability service.
	 * @param DatabaseManager            $database     Database manager.
	 * @param ConversionDiagnostics|null $diagnostics  Opt-in diagnostics writer.
	 */
	public function __construct( AttachmentRepository $attachments, VariantRepository $variants, ImageConverter $converter, VariantPlanner $planner, ImageCleanupService $cleanup, Settings $settings, CapabilityService $capabilities, DatabaseManager $database, ?ConversionDiagnostics $diagnostics = null ) {
		$this->attachments  = $attachments;
		$this->variants     = $variants;
		$this->converter    = $converter;
		$this->planner      = $planner;
		$this->cleanup      = $cleanup;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
		$this->database     = $database;
		$this->diagnostics  = $diagnostics;
	}

	/**
	 * Bring an attachment up to date right now: plan, remove what is no longer wanted, convert.
	 *
	 * This is the synchronous path used by REST and WP-CLI; the queue and bulk jobs use run().
	 * It claims the attachment first: an attachment that a worker holds is answered "busy" before
	 * anything else happens, so a request that cannot run changes nothing. Planning, removals and
	 * conversion all run under the claim, which ends with one write.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return OptimizeResult Data: 'state', 'deleted' (files removed) and, for a skipped attachment, 'reason'. Failed with SCHEMA_PENDING before the database update has finished; skipped as "busy" (data 'source' and 'since') when a worker holds the attachment.
	 * @throws Throwable Whatever planning or conversion threw, after the attempt was ended as failed.
	 */
	public function sync( $attachment_id ) {
		if ( ! $this->database->has_schema( DatabaseManager::SCHEMA_RECOVERY ) ) {
			return OptimizeResult::failed( self::SCHEMA_PENDING );
		}

		$token = $this->begin( $attachment_id, AttachmentRepository::SOURCE_SYNC );

		if ( false === $token ) {
			$row = $this->attachments->get( $attachment_id );

			return OptimizeResult::skipped(
				self::BUSY,
				array(
					'source' => $row['attempt_source'] ?? null,
					'since'  => $row['updated_at'] ?? null,
				)
			);
		}

		try {
			$this->bind_attempt( $attachment_id );
			$plan    = $this->planner->plan( $attachment_id, true );
			$deleted = $this->apply_removals( $attachment_id, $plan );

			if ( $plan->is_skipped() ) {
				$this->log_planner_skip( $attachment_id, $plan );
				$this->attachments->finish( $attachment_id, $token, AttachmentState::SKIPPED, $plan->skip_reason() );

				return OptimizeResult::skipped( $plan->skip_reason(), array( 'deleted' => $deleted ) );
			}

			if ( ! $plan->has_work() ) {
				$state = AttachmentRepository::derive_state( $this->variants->count_by_status( $attachment_id ) );

				// Only a claim stores "processing". A variant that is still marked so (its reset failed) ends the attempt as a failure.
				if ( AttachmentState::PROCESSING === $state ) {
					$message = 'A variant is still marked as processing.';
					$this->attachments->finish( $attachment_id, $token, AttachmentState::FAILED, 'exception', $message );

					return OptimizeResult::failed( 'exception', array( $message ), array( 'deleted' => $deleted ) );
				}

				$this->attachments->finish( $attachment_id, $token, $state );

				return OptimizeResult::success(
					'up_to_date',
					array(
						'state'   => $state,
						'deleted' => $deleted,
					)
				);
			}
		} catch ( Throwable $throwable ) {
			$this->attachments->finish( $attachment_id, $token, AttachmentState::FAILED, 'exception', $throwable->getMessage() );

			throw $throwable;
		}

		return $this->with_data( $this->convert_claimed( $attachment_id, $token, INF ), array( 'deleted' => $deleted ) );
	}

	/**
	 * Delete the files and rows a plan marks as no longer wanted.
	 *
	 * Covers vanished sizes, disabled formats and the old files of rows that were re-pointed
	 * at another source (regenerated thumbnails). Used by every path that plans an attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param Plan $plan          Plan returned by the planner.
	 * @return int Number of files deleted.
	 */
	public function apply_removals( $attachment_id, Plan $plan ) {
		$deleted = 0;

		if ( $plan->to_delete() ) {
			$deleted += count( $this->cleanup->cleanup_variants( $attachment_id, $plan->to_delete() )->get_data()['deleted'] ?? array() );
		}

		if ( $plan->replaced() ) {
			$deleted += count( $this->cleanup->cleanup_replaced_files( $attachment_id, $plan->replaced() )->get_data()['deleted'] ?? array() );
		}

		return $deleted;
	}

	/**
	 * Copy of a result with extra data.
	 *
	 * @param OptimizeResult $result Result.
	 * @param array          $extra  Extra data.
	 * @return OptimizeResult
	 */
	private function with_data( OptimizeResult $result, array $extra ) {
		$data = array_merge( $result->get_data(), $extra );

		if ( $result->is_success() ) {
			return OptimizeResult::success( $result->get_message(), $data );
		}
		if ( $result->is_partial() ) {
			return OptimizeResult::partial( $result->get_message(), $result->get_errors(), $data );
		}
		if ( $result->is_failed() ) {
			return OptimizeResult::failed( $result->get_message(), $result->get_errors(), $data );
		}

		return OptimizeResult::skipped( $result->get_message(), $data );
	}

	/**
	 * Convert the pending variants of an attachment, one by one, as a queue worker.
	 *
	 * The attachment is claimed first, so two workers never process it together.
	 * When the time budget runs out the rest stays pending and the result asks for
	 * another run (data 'more'). The aggregate state is stored by the closing write.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param float|null $budget        Time budget in seconds; null uses the filterable default.
	 * @return OptimizeResult Data: 'state' (AttachmentState) and 'more' (bool). Skipped as "busy" when the attachment is held or parked, as "attempts_exhausted" when it already used all its attempts, as "attempt_lost" when the attempt was taken away meanwhile.
	 */
	public function run( $attachment_id, $budget = null ) {
		$token = $this->begin( $attachment_id, AttachmentRepository::SOURCE_QUEUE );

		if ( false === $token ) {
			$row = $this->attachments->get( $attachment_id );

			// A row that a worker holds is busy whatever its count; any other row at the limit was refused by the count.
			if ( $row && AttachmentState::PROCESSING !== $row['state'] && $row['attempts'] >= AttachmentRepository::MAX_ATTEMPTS ) {
				return OptimizeResult::skipped( self::ATTEMPTS_EXHAUSTED, array( 'more' => false ) );
			}

			return OptimizeResult::skipped( self::BUSY, array( 'more' => false ) );
		}

		return $this->convert_claimed( $attachment_id, $token, $budget, true );
	}

	/**
	 * Claim an attachment and take over what a dead attempt left behind.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        AttachmentRepository::SOURCE_*.
	 * @return string|false The token of the attempt, false when the attachment cannot be claimed.
	 */
	private function begin( $attachment_id, $source ) {
		$token = $this->attachments->claim( $attachment_id, $source );

		if ( false === $token ) {
			return false;
		}

		// No other attempt holds the attachment now, so a variant in "processing" belongs to an attempt that ended without finishing.
		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::PROCESSING === $row['status'] ) {
				$this->variants->transition( $row['id'], VariantStatus::PROCESSING, VariantStatus::PENDING, array(), $token );
			}
		}

		return $token;
	}

	/**
	 * Convert the pending variants of an attachment this attempt holds, then end the attempt.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $token         Token of the attempt.
	 * @param float|null $budget        Time budget in seconds; null uses the filterable default.
	 * @param bool       $queue_worker  Whether late work must continue through the queue.
	 * @return OptimizeResult
	 * @throws Throwable Whatever the conversion threw, after the attempt was ended as failed.
	 */
	private function convert_claimed( $attachment_id, $token, $budget, $queue_worker = false ) {
		try {
			return $this->convert_pending( $attachment_id, $token, $budget, $queue_worker );
		} catch ( Throwable $throwable ) {
			$this->attachments->finish( $attachment_id, $token, AttachmentState::FAILED, 'exception', $throwable->getMessage() );

			throw $throwable;
		}
	}

	/**
	 * Convert the pending variants one by one and write the closing state.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $token         Token of the attempt.
	 * @param float|null $budget        Time budget in seconds; null uses the filterable default.
	 * @param bool       $queue_worker  Whether late work must continue through the queue.
	 * @return OptimizeResult
	 */
	private function convert_pending( $attachment_id, $token, $budget, $queue_worker ) {
		$settings       = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		$deadline       = microtime( true ) + ( null === $budget ? $this->time_budget() : $budget );
		$this->deadline = $deadline;
		$this->bind_attempt( $attachment_id );
		$last_err = null;
		$more     = false;
		$handled  = array();

		// Rows may be added while this worker runs (metadata regeneration): look again until none is pending.
		// A row is tried once per run, so one that stays pending cannot keep the loop going.
		do {
			$pending = array_filter(
				$this->variants->get_for_attachment( $attachment_id ),
				static function ( $row ) use ( $handled ) {
					return VariantStatus::PENDING === $row['status'] && ! isset( $handled[ $row['id'] ] );
				}
			);

			foreach ( $pending as $row ) {
				if ( microtime( true ) >= $deadline ) {
					$more = true;
					break 2;
				}

				$handled[ $row['id'] ] = true;
				$result                = $this->converter->convert( $row, $settings, $token );

				// The file may be in place while its row is not done: the attachment stays held for an operator.
				if ( ImageConverter::REASON_PUBLISH_INCOMPLETE === $result->get_message() ) {
					return OptimizeResult::failed( ImageConverter::REASON_PUBLISH_INCOMPLETE, $result->get_errors(), array( 'more' => false ) );
				}

				if ( $result->is_failed() ) {
					$errors   = $result->get_errors();
					$last_err = $errors ? (string) reset( $errors ) : $result->get_message();
				}

				// Nothing more is published when the lease could not be given back; the closing write clears it.
				if ( ! empty( $result->get_data()['lease_kept'] ) ) {
					break 2;
				}
			}
		} while ( $pending );

		// The worker survived: whatever failed, it failed in an orderly way.
		$state = AttachmentRepository::derive_state( $this->variants->count_by_status( $attachment_id ) );

		// A variant whose last write failed is still "processing": the attachment goes back to the queue and the next claim resets the row.
		if ( AttachmentState::PROCESSING === $state ) {
			$state = AttachmentState::QUEUED;
		}

		$reason = null === $last_err ? null : $this->failure_reason( $attachment_id );
		$closed = $this->attachments->finish( $attachment_id, $token, $state, $reason, $last_err, $queue_worker );

		// Planning can add rows after the final count. Only the same token may hand that work back to the queue.
		if ( $queue_worker && ! $closed && AttachmentState::QUEUED !== $state ) {
			$state  = AttachmentState::QUEUED;
			$closed = $this->attachments->finish( $attachment_id, $token, $state, $reason, $last_err );
		}

		// The attempt was taken away: whoever holds the attachment now owns what happens next.
		if ( ! $closed ) {
			return OptimizeResult::skipped( self::ATTEMPT_LOST, array( 'more' => false ) );
		}

		// A row that appeared after the last look leaves the attachment queued: ask for another run.
		$more = $more || AttachmentState::QUEUED === $state;

		$data = array(
			'state' => $state,
			'more'  => $more,
		);

		switch ( $state ) {
			case AttachmentState::OPTIMIZED:
				return OptimizeResult::success( $state, $data );
			case AttachmentState::PARTIAL:
				return OptimizeResult::partial( $state, array(), $data );
			case AttachmentState::FAILED:
				return OptimizeResult::failed( $state, array(), $data );
			default:
				return $more ? OptimizeResult::success( $state, $data ) : OptimizeResult::skipped( $state, $data );
		}
	}

	/**
	 * Reason of the first failed variant, kept on the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	private function failure_reason( $attachment_id ) {
		foreach ( $this->variants->get_for_attachment( $attachment_id ) as $row ) {
			if ( VariantStatus::FAILED === $row['status'] ) {
				return $row['reason'];
			}
		}

		return null;
	}

	/**
	 * Time budget of one run.
	 *
	 * @return float Seconds.
	 */
	private function time_budget() {
		return max( 0.0, (float) apply_filters( 'trust_optimize_worker_time_budget', self::DEFAULT_TIME_BUDGET ) );
	}

	/**
	 * Copy job id, attempt count and source from the claimed attachment row, once per attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function bind_attempt( $attachment_id ) {
		try {
			if ( null === $this->diagnostics || ! $this->diagnostics->is_enabled() ) {
				return;
			}

			$row      = $this->attachments->get( $attachment_id );
			$job_id   = isset( $row['job_id'] ) && is_numeric( $row['job_id'] ) && (int) $row['job_id'] > 0 ? (int) $row['job_id'] : null;
			$source   = isset( $row['attempt_source'] ) && is_string( $row['attempt_source'] ) && '' !== $row['attempt_source'] ? $row['attempt_source'] : null;
			$attempts = (int) ( $row['attempts'] ?? 0 );

			$this->diagnostics->set_attempt_context( $job_id, $attempts, $source );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * Planner skip: one line, not a start. Used by sync and by the queue's plan_and_enqueue.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param Plan $plan          Skipped plan.
	 */
	public function log_planner_skip( $attachment_id, Plan $plan ) {
		if ( ! $plan->is_skipped() ) {
			return;
		}

		try {
			$this->bind_attempt( $attachment_id );
			$this->record_attachment_skip( $attachment_id, $plan );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * Planner skip: one line, not a start.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param Plan $plan          Skipped plan.
	 */
	private function record_attachment_skip( $attachment_id, Plan $plan ) {
		if ( null === $this->diagnostics ) {
			return;
		}

		$this->diagnostics->record_attachment_skip( $attachment_id, (string) $plan->skip_reason(), $plan->skip_width(), $plan->skip_height() );
	}
}
