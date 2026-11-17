<?php
/**
 * Persistence of per-attachment optimization state.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Storage;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\VariantStatus;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Repository over plugin tables; names come from DatabaseManager and values are prepared.

/**
 * Class AttachmentRepository
 */
class AttachmentRepository {

	/**
	 * Longest stored error message.
	 */
	const LAST_ERROR_LENGTH = 255;

	/**
	 * Default age of a "processing" claim after which it is considered abandoned.
	 */
	const STALE_CLAIM_SECONDS = 900;

	/**
	 * Claims that may start without finishing before the attachment is given up as a poison file.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Source of an attempt started by a queue worker.
	 */
	const SOURCE_QUEUE = 'queue';

	/**
	 * Source of an attempt started by a synchronous request (REST, WP-CLI).
	 */
	const SOURCE_SYNC = 'sync';

	/**
	 * Reason of an attachment parked because its bulk job is paused.
	 */
	const REASON_PAUSED = 'paused';

	/**
	 * Reason of an attachment suspended by a deactivation of the plugin.
	 */
	const REASON_DEACTIVATED = 'deactivated';

	/**
	 * Age after which a "queued" mark is considered lost (its Action Scheduler action vanished).
	 */
	const STALE_QUEUED_SECONDS = DAY_IN_SECONDS;

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $database;

	/**
	 * Variant repository (the aggregate is derived from its rows).
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager   $database Database manager.
	 * @param VariantRepository $variants Variant repository.
	 */
	public function __construct( DatabaseManager $database, VariantRepository $variants ) {
		$this->database = $database;
		$this->variants = $variants;
	}

	/**
	 * Table of the current site.
	 *
	 * Resolved on every call: the repository outlives switch_to_blog(), and the table name carries the site prefix.
	 *
	 * @return string
	 */
	private function table() {
		return $this->database->get_plugin_table_names()['attachments'];
	}

	/**
	 * Aggregate state from variant counts per status.
	 *
	 * @param int[] $counts Counts keyed by VariantStatus.
	 * @return string AttachmentState constant.
	 */
	public static function derive_state( array $counts ) {
		$count = static function ( $status ) use ( $counts ) {
			return (int) ( $counts[ $status ] ?? 0 );
		};

		if ( 0 === array_sum( $counts ) ) {
			return AttachmentState::NONE;
		}

		if ( $count( VariantStatus::PROCESSING ) > 0 ) {
			return AttachmentState::PROCESSING;
		}

		if ( $count( VariantStatus::PENDING ) > 0 ) {
			return AttachmentState::QUEUED;
		}

		if ( $count( VariantStatus::FAILED ) > 0 ) {
			return $count( VariantStatus::DONE ) > 0 ? AttachmentState::PARTIAL : AttachmentState::FAILED;
		}

		return AttachmentState::OPTIMIZED;
	}

	/**
	 * Attachment row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public function get( $attachment_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE attachment_id = %d", (int) $attachment_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['attachment_id'] = (int) $row['attachment_id'];
		$row['attempts']      = (int) $row['attempts'];

		return $row;
	}

	/**
	 * States of several attachments in one query.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @return string[] AttachmentState keyed by attachment ID; attachments without a row are NONE.
	 */
	public function get_states( array $attachment_ids ) {
		global $wpdb;

		$ids    = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );
		$states = array_fill_keys( $ids, AttachmentState::NONE );

		if ( empty( $ids ) ) {
			return $states;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT attachment_id, state FROM {$this->table()} WHERE attachment_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$states[ (int) $row['attachment_id'] ] = $row['state'];
		}

		return $states;
	}

	/**
	 * Failed attempts of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	public function get_attempts( $attachment_id ) {
		$row = $this->get( $attachment_id );

		return $row ? $row['attempts'] : 0;
	}

	/**
	 * Current state, "none" when there is no row.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function get_state( $attachment_id ) {
		$row = $this->get( $attachment_id );

		return $row ? $row['state'] : AttachmentState::NONE;
	}

	/**
	 * Set the state explicitly (e.g. "skipped" for an unsupported attachment).
	 *
	 * An attachment that a worker holds ("processing") is never changed here: only the attempt that holds it
	 * may end it, with finish().
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $state         AttachmentState constant.
	 * @param string|null $reason        Reason for skipped/failed.
	 * @param string|null $last_error    Last error message (truncated to 255 characters).
	 */
	public function set_state( $attachment_id, $state, $reason = null, $last_error = null ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, reason = NULLIF( %s, '' ), last_error = NULLIF( %s, '' ), updated_at = %s WHERE attachment_id = %d AND state <> %s",
				$state,
				(string) $reason,
				$this->truncate( (string) $last_error ),
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING
			)
		);
	}

	/**
	 * End the attempt that holds an attachment: store the final state and forget the attempt (token-guarded).
	 *
	 * The closing write of a worker. It misses when the attempt no longer holds the attachment, for example
	 * because it was recovered as dead meanwhile; the worker then changed nothing.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $token         Token of the attempt.
	 * @param string      $state         Final AttachmentState, never "processing".
	 * @param string|null $reason        Reason for skipped/failed.
	 * @param string|null $last_error    Last error message (truncated to 255 characters).
	 * @return bool True when this call ended the attempt.
	 */
	public function finish( $attachment_id, $token, $state, $reason = null, $last_error = null ) {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, reason = NULLIF( %s, '' ), last_error = NULLIF( %s, '' ), attempts = 0, attempt_source = NULL, attempt_token = NULL, publishing_at = NULL, updated_at = %s WHERE attachment_id = %d AND state = %s AND attempt_token = %s",
				$state,
				(string) $reason,
				$this->truncate( (string) $last_error ),
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING,
				$token
			)
		);
	}

	/**
	 * Recompute the aggregate state from the variant rows and store it.
	 *
	 * An explicit "skipped" state of an attachment without variants is kept. An attachment that a worker
	 * holds is not changed, and "processing" is never stored for variants alone: only claim() does that.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string The resulting state.
	 */
	public function recompute( $attachment_id ) {
		$state = self::derive_state( $this->variants->count_by_status( $attachment_id ) );

		if ( AttachmentState::NONE === $state && AttachmentState::SKIPPED === $this->get_state( $attachment_id ) ) {
			return AttachmentState::SKIPPED;
		}

		if ( AttachmentState::PROCESSING === $state ) {
			return $this->get_state( $attachment_id );
		}

		$this->ensure_row( $attachment_id );
		$this->write_state( $attachment_id, $state );

		return $state;
	}

	/**
	 * Take ownership of an attachment for processing (compare-and-set).
	 *
	 * A "processing" attachment is never taken over: only the death compare-and-set of the job health check
	 * or an operator ends an attempt that did not end itself. The claim writes the attempt record (its source
	 * and a new token) and counts as an attempt; a synchronous claim sets the count to 1, which is the explicit
	 * retry of a file that failed too often. A queue claim does not take an attachment of a paused bulk job,
	 * so a Pause that committed before the claim always wins.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        SOURCE_QUEUE or SOURCE_SYNC.
	 * @return string|false The token of the attempt, false when the attachment is held or parked.
	 */
	public function claim( $attachment_id, $source ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		$token    = bin2hex( random_bytes( 16 ) );
		$attempts = self::SOURCE_SYNC === $source ? '1' : 'LEAST(a.attempts + 1, 255)';

		$claimed = 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} a SET a.state = %s, a.attempts = {$attempts}, a.attempt_source = %s, a.attempt_token = %s, a.publishing_at = NULL, a.updated_at = %s WHERE a.attachment_id = %d AND a.state <> %s AND ( %d = 0 OR NOT EXISTS ( SELECT 1 FROM %i j WHERE j.id = a.job_id AND j.status = %s ) )",
				AttachmentState::PROCESSING,
				$source,
				$token,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING,
				self::SOURCE_QUEUE === $source ? 1 : 0,
				$this->database->get_plugin_table_names()['jobs'],
				JobStatus::PAUSED
			)
		);

		return $claimed ? $token : false;
	}

	/**
	 * Mark an attachment as queued unless it is already queued or processing (compare-and-set).
	 *
	 * A "queued" mark older than a day is treated as lost and may be set again. A "processing" attachment is
	 * never taken over, and a file that died MAX_ATTEMPTS times is not queued again by anything but an explicit sync.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when this call changed the state to "queued".
	 */
	public function mark_queued( $attachment_id ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, updated_at = %s WHERE attachment_id = %d AND attempts < %d AND (state NOT IN (%s, %s) OR (state = %s AND updated_at < %s))",
				AttachmentState::QUEUED,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				self::MAX_ATTEMPTS,
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING,
				AttachmentState::QUEUED,
				gmdate( 'Y-m-d H:i:s', time() - self::STALE_QUEUED_SECONDS )
			)
		);
	}

	/**
	 * Forget the failed attempts of an attachment that no worker holds.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function reset_attempts( $attachment_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET attempts = 0 WHERE attachment_id = %d AND attempts > 0 AND state <> %s",
				(int) $attachment_id,
				AttachmentState::PROCESSING
			)
		);
	}

	/**
	 * Attachments that were queued long ago and whose task is gone (candidates; the caller checks the queue).
	 *
	 * @param int $older_than Seconds since the last change.
	 * @param int $limit      Maximum number of rows.
	 * @return array[] Rows with attachment_id, state and attempts, oldest first.
	 */
	public function find_stuck( $older_than, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, state, attempts FROM {$this->table()} WHERE state = %s AND attempts < %d AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				AttachmentState::QUEUED,
				self::MAX_ATTEMPTS,
				gmdate( 'Y-m-d H:i:s', time() - (int) $older_than ),
				(int) $limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'attachment_id' => (int) $row['attachment_id'],
					'state'         => $row['state'],
					'attempts'      => (int) $row['attempts'],
				);
			},
			$rows
		);
	}

	/**
	 * Give up the claim on queued and processing attachments, remembering why (the queue was emptied).
	 *
	 * An attachment inside a publication (it holds the lease) is left alone: its worker is writing a served file.
	 *
	 * @param string $reason Reason stored with the state "none".
	 * @return int Number of attachments suspended.
	 */
	public function suspend_unfinished( $reason ) {
		global $wpdb;

		// Before the migration to 1.2.0 there is no attempt record to forget and no lease to respect.
		if ( ! $this->database->has_schema( '1.2.0' ) ) {
			return (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table()} SET state = %s, reason = %s, updated_at = %s WHERE state IN (%s, %s)",
					AttachmentState::NONE,
					$reason,
					current_time( 'mysql', true ),
					AttachmentState::QUEUED,
					AttachmentState::PROCESSING
				)
			);
		}

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, reason = %s, updated_at = %s, attempt_source = NULL, attempt_token = NULL WHERE state IN (%s, %s) AND publishing_at IS NULL",
				AttachmentState::NONE,
				$reason,
				current_time( 'mysql', true ),
				AttachmentState::QUEUED,
				AttachmentState::PROCESSING
			)
		);
	}

	/**
	 * Attachments suspended for a reason.
	 *
	 * @param string $reason Reason given to suspend_unfinished().
	 * @param int    $limit  Maximum number of IDs.
	 * @return int[]
	 */
	public function find_suspended( $reason, $limit ) {
		global $wpdb;

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT attachment_id FROM {$this->table()} WHERE state = %s AND reason = %s ORDER BY attachment_id ASC LIMIT %d",
					AttachmentState::NONE,
					$reason,
					(int) $limit
				)
			)
		);
	}

	/**
	 * Mark a queued attachment whose task is gone as queued again (compare-and-set on its state and age).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $older_than    Seconds the attachment must have been stuck.
	 * @return bool True when this call changed the state.
	 */
	public function requeue( $attachment_id, $older_than ) {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, updated_at = %s WHERE attachment_id = %d AND state = %s AND attempts < %d AND updated_at < %s",
				AttachmentState::QUEUED,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::QUEUED,
				self::MAX_ATTEMPTS,
				gmdate( 'Y-m-d H:i:s', time() - (int) $older_than )
			)
		);
	}

	/**
	 * Park a queued attachment of a paused bulk job instead of converting it (compare-and-set).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when this call parked it.
	 */
	public function park( $attachment_id ) {
		return $this->park_where( $attachment_id, array( JobStatus::PAUSED ) );
	}

	/**
	 * Park a queued attachment of an unfinished bulk job whose task could not be scheduled, so the job's producer queues it again.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when this call parked it.
	 */
	public function repark( $attachment_id ) {
		return $this->park_where( $attachment_id, JobStatus::active() );
	}

	/**
	 * Attachments that were claimed by a queue worker and have not been touched for a while, without a lease.
	 *
	 * These are the candidates of the death compare-and-set; the caller asks the queue whether a task still owns them.
	 *
	 * @param int $older_than Seconds since the last change.
	 * @param int $limit      Maximum number of rows.
	 * @return array[] Rows with attachment_id, attempt_token, attempts and job_id, oldest first.
	 */
	public function find_death_candidates( $older_than, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT attachment_id, attempt_token, attempts, job_id FROM {$this->table()} WHERE state = %s AND attempt_source = %s AND publishing_at IS NULL AND updated_at < %s ORDER BY updated_at ASC LIMIT %d",
				AttachmentState::PROCESSING,
				self::SOURCE_QUEUE,
				gmdate( 'Y-m-d H:i:s', time() - (int) $older_than ),
				(int) $limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'attachment_id' => (int) $row['attachment_id'],
					'attempt_token' => $row['attempt_token'],
					'attempts'      => (int) $row['attempts'],
					'job_id'        => null === $row['job_id'] ? null : (int) $row['job_id'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * End an attempt that died (compare-and-set on the attempt that was observed, and on there being no lease).
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $token         Token the attempt was observed with.
	 * @return bool True when this call ended the attempt.
	 */
	public function recover_dead( $attachment_id, $token ) {
		return $this->end_attempt( $attachment_id, $token, 'a.publishing_at IS NULL' );
	}

	/**
	 * End an attempt on the operator's word (the same compare-and-set as the death one, keyed on the attempt as it was inspected).
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $token         Token of the attempt, null for a row from before the attempt record.
	 * @param string      $since         The attachment's updated_at when it was inspected.
	 * @param string|null $lease         The attachment's publishing_at when it was inspected, null when none.
	 * @return bool True when this call ended the attempt, false when the attempt changed since the inspection.
	 */
	public function release( $attachment_id, $token, $since, $lease ) {
		global $wpdb;

		$lease_guard = null === $lease ? 'a.publishing_at IS NULL' : $wpdb->prepare( 'a.publishing_at = %s', $lease );

		return $this->end_attempt( $attachment_id, $token, $wpdb->prepare( 'a.updated_at = %s AND ', $since ) . $lease_guard );
	}

	/**
	 * Take the publication lease of an attempt (compare-and-set); only the attempt that holds the attachment can.
	 *
	 * Lease writes never touch updated_at: the age of the attempt stays what it was.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token         Token of the attempt.
	 * @return bool|null True when the lease is taken, false when the attempt no longer holds the attachment, null on a database error.
	 */
	public function take_lease( $attachment_id, $token ) {
		global $wpdb;

		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET publishing_at = %s WHERE attachment_id = %d AND state = %s AND attempt_token = %s AND publishing_at IS NULL",
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING,
				$token
			)
		);

		return false === $changed ? null : 1 === (int) $changed;
	}

	/**
	 * Release the publication lease of an attempt.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $token         Token of the attempt.
	 * @return bool True when the lease is released.
	 */
	public function release_lease( $attachment_id, $token ) {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET publishing_at = NULL WHERE attachment_id = %d AND attempt_token = %s AND publishing_at IS NOT NULL",
				(int) $attachment_id,
				$token
			)
		);
	}

	/**
	 * Attach an attachment to a bulk job, so the job's progress can be derived from the states.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $job_id        Job ID.
	 */
	public function assign_job( $attachment_id, $job_id ) {
		global $wpdb;

		$this->ensure_row( $attachment_id );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET job_id = %d WHERE attachment_id = %d",
				(int) $job_id,
				(int) $attachment_id
			)
		);
	}

	/**
	 * Number of attachments per state, over the whole library.
	 *
	 * @return int[] Counts keyed by AttachmentState; states without attachments are absent.
	 */
	public function count_states() {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS total FROM {$this->table()} GROUP BY state", ARRAY_A );

		return array_map( 'intval', array_column( $rows, 'total', 'state' ) );
	}

	/**
	 * Number of attachments of a job per state.
	 *
	 * @param int $job_id Job ID.
	 * @return int[] Counts keyed by AttachmentState; states without attachments are absent.
	 */
	public function count_states_for_job( $job_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT state, COUNT(*) AS total FROM {$this->table()} WHERE job_id = %d GROUP BY state", (int) $job_id ),
			ARRAY_A
		);

		return array_map( 'intval', array_column( $rows, 'total', 'state' ) );
	}

	/**
	 * Delete the row of an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function delete( $attachment_id ) {
		global $wpdb;

		$wpdb->delete( $this->table(), array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) );
	}

	/**
	 * Move a queued attachment to "none" with the parked reason while its job is in one of the statuses.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string[] $statuses      JobStatus values the owning job must have.
	 * @return bool
	 */
	private function park_where( $attachment_id, array $statuses ) {
		global $wpdb;

		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} a SET a.state = %s, a.reason = %s, a.updated_at = %s WHERE a.attachment_id = %d AND a.state = %s AND EXISTS ( SELECT 1 FROM %i j WHERE j.id = a.job_id AND j.status IN (" . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ') )',
				array_merge(
					array(
						AttachmentState::NONE,
						self::REASON_PAUSED,
						current_time( 'mysql', true ),
						(int) $attachment_id,
						AttachmentState::QUEUED,
						$this->database->get_plugin_table_names()['jobs'],
					),
					$statuses
				)
			)
		);
	}

	/**
	 * Take an attempt away from an attachment in one statement: the attachment, and the variants the attempt left open.
	 *
	 * A file that died MAX_ATTEMPTS times is failed with its open variants. Otherwise its processing variants go back
	 * to pending and the attachment is parked when its job is paused at that moment and queued when it is not.
	 * Everything the statement writes depends on columns it does not change, because the order of assignments in a
	 * multiple-table UPDATE is not defined.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $token         Token the attempt must have; null matches a row without one.
	 * @param string      $guard         Further condition on the attachment alias "a", already prepared.
	 * @return bool True when the attachment changed.
	 */
	private function end_attempt( $attachment_id, $token, $guard ) {
		global $wpdb;

		$tables   = $this->database->get_plugin_table_names();
		$holder   = null === $token ? 'a.attempt_token IS NULL' : $wpdb->prepare( 'a.attempt_token = %s', $token );
		$paused   = $wpdb->prepare( 'EXISTS ( SELECT 1 FROM %i j WHERE j.id = a.job_id AND j.status = %s )', $tables['jobs'], JobStatus::PAUSED );
		$now      = current_time( 'mysql', true );
		$max      = self::MAX_ATTEMPTS;
		$poisoned = 'a.attempts >= ' . (int) $max;

		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i a LEFT JOIN %i v ON v.attachment_id = a.attachment_id AND ( v.status = %s OR ( {$poisoned} AND v.status = %s ) )
				SET a.state = CASE WHEN {$poisoned} THEN %s WHEN {$paused} THEN %s ELSE %s END,
					a.reason = CASE WHEN {$poisoned} THEN %s WHEN {$paused} THEN %s ELSE NULL END,
					a.last_error = CASE WHEN {$poisoned} THEN CONCAT( %s, a.attempts, %s ) ELSE NULL END,
					a.attempt_source = NULL, a.attempt_token = NULL, a.publishing_at = NULL, a.updated_at = %s,
					v.status = CASE WHEN {$poisoned} THEN %s ELSE %s END,
					v.reason = CASE WHEN {$poisoned} THEN %s ELSE v.reason END,
					v.updated_at = %s
				WHERE a.attachment_id = %d AND a.state = %s AND {$holder} AND {$guard}",
				$tables['attachments'],
				$tables['variants'],
				VariantStatus::PROCESSING,
				VariantStatus::PENDING,
				AttachmentState::FAILED,
				AttachmentState::NONE,
				AttachmentState::QUEUED,
				'max_attempts',
				self::REASON_PAUSED,
				'The worker died ',
				' times on this file.',
				$now,
				VariantStatus::FAILED,
				VariantStatus::PENDING,
				'max_attempts',
				$now,
				(int) $attachment_id,
				AttachmentState::PROCESSING
			)
		);

		$this->variants->invalidate( $attachment_id );

		return (int) $changed > 0;
	}

	/**
	 * Create the row if missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function ensure_row( $attachment_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$this->table()} (attachment_id, state, updated_at) VALUES (%d, %s, %s)",
				(int) $attachment_id,
				AttachmentState::NONE,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Store a state, clearing reason and error.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $state         State.
	 */
	private function write_state( $attachment_id, $state ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table()} SET state = %s, reason = NULL, last_error = NULL, updated_at = %s WHERE attachment_id = %d AND state <> %s",
				$state,
				current_time( 'mysql', true ),
				(int) $attachment_id,
				AttachmentState::PROCESSING
			)
		);
	}

	/**
	 * Truncate to the column length.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function truncate( $message ) {
		return mb_substr( (string) $message, 0, self::LAST_ERROR_LENGTH, 'UTF-8' );
	}
}
