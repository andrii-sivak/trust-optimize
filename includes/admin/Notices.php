<?php
/**
 * Admin notices that ask the site owner to do something or explain what the plugin does.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Admin;

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Bulk\BulkProducer;
use TrustOptimize\Bulk\JobHealth;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;

/**
 * Class Notices
 *
 * Shown to users who can manage options only. A notice can be hidden for the current user; the
 * hidden keys are kept in user meta, and a key changes when the event it describes changes, so a
 * new event shows again. The notices that need an action cannot be hidden: the ones about a paused
 * bulk job have the button that resumes it, and those about the queue and about attachments held by
 * a conversion that nothing will end say what to do.
 */
class Notices {

	/**
	 * User meta that holds the hidden notice keys.
	 */
	const META = 'trust_optimize_dismissed_notices';

	/**
	 * Action of the dismiss link.
	 */
	const ACTION_DISMISS = 'trust_optimize_dismiss_notice';

	/**
	 * Action of the resume button.
	 */
	const ACTION_RESUME = 'trust_optimize_resume_bulk';

	/**
	 * Transient (per user) that carries the error of a Resume to the next page.
	 */
	const RESUME_ERROR = 'trust_optimize_resume_error_';

	/**
	 * Bulk job repository.
	 *
	 * @var BulkJobRepository
	 */
	private $jobs;

	/**
	 * Bulk producer.
	 *
	 * @var BulkProducer
	 */
	private $producer;

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
	 * @param BulkJobRepository $jobs         Bulk job repository.
	 * @param BulkProducer      $producer     Bulk producer.
	 * @param CapabilityService $capabilities Capability service.
	 * @param DatabaseManager   $database     Database manager.
	 * @param JobHealth         $health       Job health.
	 */
	public function __construct( BulkJobRepository $jobs, BulkProducer $producer, CapabilityService $capabilities, DatabaseManager $database, JobHealth $health ) {
		$this->jobs         = $jobs;
		$this->producer     = $producer;
		$this->capabilities = $capabilities;
		$this->database     = $database;
		$this->health       = $health;
	}

	/**
	 * Register the hooks.
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_post_' . self::ACTION_RESUME, array( $this, 'handle_resume' ) );
	}

	/**
	 * Print the notices.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$hidden = (array) get_user_meta( get_current_user_id(), self::META, true );

		foreach ( $this->collect() as $notice ) {
			if ( ! empty( $notice['dismissible'] ) && in_array( $notice['key'], $hidden, true ) ) {
				continue;
			}

			$actions = '';

			if ( ! empty( $notice['resume'] ) ) {
				$actions = sprintf(
					'<form method="post" action="%1$s" style="display:inline"><input type="hidden" name="action" value="%2$s">%3$s <button type="submit" class="button button-primary">%4$s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_attr( self::ACTION_RESUME ),
					wp_nonce_field( self::ACTION_RESUME, '_wpnonce', true, false ),
					esc_html__( 'Resume', 'trust-optimize' )
				);
			}

			if ( ! empty( $notice['bulk_link'] ) ) {
				$actions .= sprintf( ' <a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=trust-optimize#bulk' ) ), esc_html__( 'Bulk Optimization', 'trust-optimize' ) );
			}

			if ( ! empty( $notice['dismissible'] ) ) {
				$url      = wp_nonce_url(
					add_query_arg(
						array(
							'action' => self::ACTION_DISMISS,
							'notice' => $notice['key'],
						),
						admin_url( 'admin-post.php' )
					),
					self::ACTION_DISMISS
				);
				$actions .= sprintf( ' <a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Dismiss', 'trust-optimize' ) );
			}

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- The message and the actions are built from escaped parts above.
			printf(
				'<div class="notice notice-%1$s"><p><strong>TrustOptimize:</strong> %2$s</p>%3$s</div>',
				esc_attr( $notice['type'] ),
				$notice['message'],
				'' === $actions ? '' : '<p>' . $actions . '</p>'
			);
			// phpcs:enable
		}
	}

	/**
	 * Hide a notice for the current user.
	 */
	public function handle_dismiss() {
		check_admin_referer( self::ACTION_DISMISS );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'trust-optimize' ), '', array( 'response' => 403 ) );
		}

		$key = isset( $_GET['notice'] ) ? sanitize_text_field( wp_unslash( $_GET['notice'] ) ) : '';

		if ( '' !== $key ) {
			$hidden   = array_filter( (array) get_user_meta( get_current_user_id(), self::META, true ) );
			$hidden[] = $key;
			update_user_meta( get_current_user_id(), self::META, array_values( array_unique( $hidden ) ) );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Continue the paused bulk job.
	 *
	 * An error is kept for the next page, where it is shown next to the button that caused it.
	 */
	public function handle_resume() {
		check_admin_referer( self::ACTION_RESUME );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'trust-optimize' ), '', array( 'response' => 403 ) );
		}

		$job = $this->paused_job();

		if ( $job ) {
			$outcome = $this->producer->resume( $job['id'] );

			if ( is_wp_error( $outcome ) ) {
				set_transient( self::RESUME_ERROR . get_current_user_id(), $outcome->get_error_message(), MINUTE_IN_SECONDS );
			}
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=trust-optimize' ) );
		exit;
	}

	/**
	 * The notices that apply now.
	 *
	 * What blocks conversion comes first (the unfinished update, the queue, attachments only an operator can free),
	 * then the paused job with the button that resumes it.
	 *
	 * @return array[] Each with key, type, message (limited HTML) and optionally dismissible, resume and bulk_link.
	 */
	private function collect() {
		$notices = array();

		if ( ! $this->database->has_schema( '1.2.0' ) ) {
			$notices[] = array(
				'key'     => 'schema-pending',
				'type'    => 'warning',
				'message' => esc_html__( 'The database update has not finished yet, so no image is converted and bulk jobs cannot be started or resumed. The update is retried automatically.', 'trust-optimize' ),
			);
		}

		$signals = $this->health->queue_signals();

		if ( $signals ) {
			$notices[] = $this->queue_notice( $signals );
		}

		foreach ( $this->health->held() as $attempt ) {
			$notices[] = $this->held_notice( $attempt );
		}

		$paused = $this->paused_job();

		if ( $paused ) {
			$notices[] = $this->paused_notice( $paused );
		}

		$error = get_transient( self::RESUME_ERROR . get_current_user_id() );

		if ( false !== $error ) {
			delete_transient( self::RESUME_ERROR . get_current_user_id() );
			$notices[] = array(
				'key'     => 'resume-error',
				'type'    => 'error',
				'message' => esc_html( (string) $error ),
			);
		}

		foreach ( $this->capabilities->downgrades() as $format => $note ) {
			$notices[] = array(
				'key'         => 'downgrade:' . $format . ':' . $note['at'],
				'type'        => 'warning',
				'dismissible' => true,
				'message'     => esc_html(
					sprintf(
						/* translators: 1: format name such as AVIF, 2: error reported by the image editor. */
						__( '%1$s files are no longer created: the image editor failed to write one (%2$s). Settings > "Re-check format support" tries again.', 'trust-optimize' ),
						strtoupper( $format ),
						$note['reason']
					)
				),
			);
		}

		return $notices;
	}

	/**
	 * The active bulk job when it is paused for another reason than the user's own Pause.
	 *
	 * @return array|null
	 */
	private function paused_job() {
		$job = $this->jobs->get_active_job();
		$job = $job ? $job->to_array() : null;

		if ( $job && JobStatus::PAUSED === $job['status'] && PauseReason::USER !== $this->reason_of( $job ) ) {
			return $job;
		}

		return null;
	}

	/**
	 * Why a paused job is paused.
	 *
	 * Before the migration to 1.1.0 the reason is the last error, and a pause without one is the user's.
	 *
	 * @param array $job Job row.
	 * @return string
	 */
	private function reason_of( array $job ) {
		if ( ! empty( $job['pause_reason'] ) ) {
			return (string) $job['pause_reason'];
		}

		return empty( $job['last_error'] ) ? PauseReason::USER : (string) $job['last_error'];
	}

	/**
	 * The notice of a paused job: why it is paused, the error behind that, and the button.
	 *
	 * @param array $job Job row.
	 * @return array
	 */
	private function paused_notice( array $job ) {
		$reason = $this->reason_of( $job );

		if ( PauseReason::LOW_DISK_SPACE === $reason ) {
			return array(
				'key'     => 'disk:' . $job['id'],
				'type'    => 'warning',
				'resume'  => true,
				'message' => esc_html__( 'The bulk job is paused because the disk is almost full. Free some space, then resume the job.', 'trust-optimize' ),
			);
		}

		$labels = array(
			PauseReason::DEACTIVATED => __( 'the plugin was deactivated', 'trust-optimize' ),
			PauseReason::STOPPED     => __( 'the job stopped responding', 'trust-optimize' ),
		);
		$label  = $labels[ $reason ] ?? __( 'the reason is unknown', 'trust-optimize' );
		$error  = (string) ( $job['last_error'] ?? '' );

		return array(
			'key'       => 'paused:' . $job['id'],
			'type'      => 'warning',
			'resume'    => true,
			'bulk_link' => true,
			'message'   => esc_html(
				sprintf(
					/* translators: 1: why the bulk job is paused, 2: the last error of the job or a sentence saying that the cause is unknown. */
					__( 'The bulk job is paused: %1$s. %2$s', 'trust-optimize' ),
					$label,
					'' !== $error && PauseReason::UNKNOWN !== $reason
						? sprintf(
							/* translators: %s: the last error of the job. */
							__( 'Last error: %s.', 'trust-optimize' ),
							$error
						)
						: __( 'The cause is not known.', 'trust-optimize' )
				)
			),
		);
	}

	/**
	 * The warning about a queue that does not run.
	 *
	 * @param string[] $signals JobHealth signals.
	 * @return array
	 */
	private function queue_notice( array $signals ) {
		$parts = array();

		if ( in_array( JobHealth::SIGNAL_STUCK_CLAIM, $signals, true ) ) {
			$parts[] = __( 'A queued task has been running or claimed for more than 15 minutes, so its worker may have died.', 'trust-optimize' );
		}

		if ( in_array( JobHealth::SIGNAL_NO_PROGRESS, $signals, true ) ) {
			$parts[] = __( 'Tasks are due, but none has finished in the last 15 minutes.', 'trust-optimize' );
		}

		return array(
			'key'       => 'queue-signals',
			'type'      => 'warning',
			'bulk_link' => true,
			'message'   => esc_html( implode( ' ', $parts ) . ' ' . __( 'Action Scheduler may not be running: check that WP-Cron or a system cron runs it.', 'trust-optimize' ) ),
		);
	}

	/**
	 * The notice of an attachment that a conversion holds and nothing else will free: instructions, no button.
	 *
	 * @param array $attempt Attempt as described by JobHealth::held().
	 * @return array
	 */
	private function held_notice( array $attempt ) {
		$id    = (int) $attempt['attachment_id'];
		$since = get_date_from_gmt( $attempt['since'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

		switch ( $attempt['kind'] ) {
			case 'publication_incomplete':
				$what = sprintf(
					/* translators: 1: attachment ID, 2: date and time, 3: file path. */
					__( 'The publication of the optimized files of attachment %1$d did not finish (since %2$s). The file at %3$s may already be the new version while its record keeps the previous hash. It is not served while its record is not "done", and it will be kept and reported as target_exists_foreign.', 'trust-optimize' ),
					$id,
					$since,
					implode( ', ', $attempt['paths'] )
				);
				break;
			case 'lease_not_released':
				$what = sprintf(
					/* translators: 1: attachment ID, 2: date and time. */
					__( 'A conversion of attachment %1$d finished publishing a file but did not release its lock (since %2$s).', 'trust-optimize' ),
					$id,
					$since
				);
				break;
			case 'legacy':
				$what = sprintf(
					/* translators: 1: attachment ID, 2: date and time. */
					__( 'A conversion of attachment %1$d that started before an update of the plugin has been running since %2$s.', 'trust-optimize' ),
					$id,
					$since
				);
				break;
			default:
				$what = sprintf(
					/* translators: 1: attachment ID, 2: date and time. */
					__( 'A synchronous conversion of attachment %1$d (WP-CLI or REST) has been running since %2$s.', 'trust-optimize' ),
					$id,
					$since
				);
		}

		return array(
			'key'     => 'held:' . $id . ':' . $attempt['since'],
			'type'    => 'warning',
			'message' => esc_html(
				$what . ' ' . sprintf(
					/* translators: %d: attachment ID. */
					__( 'Make sure that process has ended or stop it; the age of the record does not prove it; then run `wp trust-optimize release %d`.', 'trust-optimize' ),
					$id
				)
			),
		);
	}
}
