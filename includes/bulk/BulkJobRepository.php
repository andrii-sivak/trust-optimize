<?php
/**
 * Bulk job repository.
 *
 * @package TrustOptimize\Bulk
 */

namespace TrustOptimize\Bulk;

use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Storage\AttachmentRepository;

/**
 * Class BulkJobRepository
 */
class BulkJobRepository {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	private $table = 'trust_optimize_jobs';

	/**
	 * Database manager.
	 *
	 * @var DatabaseManager
	 */
	private $db_manager;

	/**
	 * Option that holds the job that owns the library (the mutex of create()).
	 *
	 * The value is the ID of that job or, between taking the mutex and inserting the job,
	 * the time it was taken. It is read and written with SQL like the core upgrade lock does:
	 * INSERT IGNORE on the primary key of wp_options is atomic, add_option() is not.
	 */
	const ACTIVE_OPTION = 'trust_optimize_bulk_active';

	/**
	 * Age after which a mutex without a job counts as left behind by a crashed request.
	 */
	const MUTEX_STALE_SECONDS = 60;

	/**
	 * Columns of the jobs table that transition() may change. Anything else is ignored.
	 *
	 * @var string[]
	 */
	const UPDATABLE_COLUMNS = array(
		'status',
		'cursor_id',
		'total',
		'settings_snapshot',
		'pause_reason',
		'resumed_at',
		'resume_token',
		'resume_cursor_id',
		'last_error',
		'started_at',
		'updated_at',
		'finished_at',
	);

	/**
	 * Constructor.
	 *
	 * @param DatabaseManager $db_manager Database manager.
	 */
	public function __construct( DatabaseManager $db_manager ) {
		$this->db_manager = $db_manager;
	}

	/**
	 * Create a new bulk job unless another one owns the library.
	 *
	 * Two concurrent calls cannot both succeed: add_option() is atomic (primary key of wp_options).
	 *
	 * @param string $type              Job type.
	 * @param array  $settings_snapshot Settings the job was created with.
	 * @param int    $total             Total candidate attachments.
	 * @return BulkJob|false False when another job is active.
	 */
	public function create( $type, array $settings_snapshot = array(), $total = 0 ) {
		if ( $this->get_active_job() || ! $this->acquire_mutex() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->get_table_name(),
			array(
				'type'              => $type,
				'status'            => JobStatus::PENDING,
				'cursor_id'         => 0,
				'total'             => (int) $total,
				'settings_snapshot' => wp_json_encode( $settings_snapshot ),
				'updated_at'        => current_time( 'mysql', true ),
			)
		);

		if ( ! $result ) {
			$this->release_mutex();

			return false;
		}

		$this->write_mutex( (int) $wpdb->insert_id );

		return $this->get( (int) $wpdb->insert_id );
	}

	/**
	 * Take the mutex, first removing one that was left behind.
	 *
	 * @return bool True when this call owns the mutex.
	 */
	private function acquire_mutex() {
		if ( $this->insert_mutex() ) {
			return true;
		}

		$holder = $this->read_mutex();

		// A job ID is stale when the job is finished; a timestamp when its request died long ago.
		$stale = $holder > 1000000000 ? $holder < time() - self::MUTEX_STALE_SECONDS : ! $this->is_active( $this->get( $holder ) );

		if ( ! $stale ) {
			return false;
		}

		$this->release_mutex();

		return $this->insert_mutex();
	}

	/**
	 * Insert the mutex row; false when it exists.
	 *
	 * @return bool
	 */
	private function insert_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::ACTIVE_OPTION,
				(string) time()
			)
		);
	}

	/**
	 * Record the job that owns the mutex.
	 *
	 * @param int $job_id Job ID.
	 */
	private function write_mutex( $job_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) $job_id ), array( 'option_name' => self::ACTIVE_OPTION ) );
	}

	/**
	 * Job ID (or timestamp) held by the mutex.
	 *
	 * @return int 0 when there is none.
	 */
	private function read_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ACTIVE_OPTION ) );
	}

	/**
	 * Remove the mutex.
	 */
	private function release_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::ACTIVE_OPTION ) );
	}

	/**
	 * Whether a job exists and is not finished.
	 *
	 * @param BulkJob|null $job Job.
	 * @return bool
	 */
	private function is_active( ?BulkJob $job ) {
		return null !== $job && in_array( $job->get_status(), JobStatus::active(), true );
	}

	/**
	 * Get a job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return BulkJob|null
	 */
	public function get( $job_id ) {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $job_id ),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Get the active library job. Reading never changes it; abandoned jobs are stopped by JobHealth.
	 *
	 * @return BulkJob|null
	 */
	public function get_active_job() {
		global $wpdb;

		$table    = $this->get_table_name();
		$statuses = JobStatus::active();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s, %s, %s) ORDER BY id DESC LIMIT 1',
				$table,
				$statuses[0],
				$statuses[1],
				$statuses[2]
			),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Get the latest job.
	 *
	 * @return BulkJob|null
	 */
	public function get_latest_job() {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $table ),
			ARRAY_A
		);
		// phpcs:enable

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Mark an unfinished job as running; a finished job is never revived.
	 *
	 * Continuing a paused job records the Resume: a new token, the time and the cursor at that moment, and
	 * clears the pause reason and the last error. Nothing is written for a job that already runs. The
	 * columns of the Resume belong to schema 1.2.0; callers answer "schema pending" before they get here.
	 *
	 * @param int    $job_id       Job ID.
	 * @param string $resume_token Token that identifies this Resume to revert_resume().
	 * @return bool True when the job is running now.
	 */
	public function mark_running( $job_id, $resume_token = '' ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$resumed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, started_at = COALESCE(started_at, %s), updated_at = %s, pause_reason = NULL, last_error = NULL, resumed_at = %s, resume_token = %s, resume_cursor_id = cursor_id WHERE id = %d AND status = %s',
				$this->get_table_name(),
				JobStatus::RUNNING,
				$now,
				$now,
				$now,
				$resume_token,
				(int) $job_id,
				JobStatus::PAUSED
			)
		);

		$started = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, started_at = COALESCE(started_at, %s), updated_at = %s WHERE id = %d AND status = %s',
				$this->get_table_name(),
				JobStatus::RUNNING,
				$now,
				$now,
				(int) $job_id,
				JobStatus::PENDING
			)
		);
		// phpcs:enable

		if ( $resumed || $started ) {
			return true;
		}

		$job = $this->get( $job_id );

		return null !== $job && JobStatus::RUNNING === $job->get_status();
	}

	/**
	 * Undo a Resume whose producer could not be scheduled (compare-and-set on that Resume).
	 *
	 * The job goes back to "paused" with what it showed before. A death that was recorded meanwhile keeps its
	 * text: the previous last error only fills an empty one. It misses when a Pause or another Resume came first.
	 *
	 * @param int    $job_id   Job ID.
	 * @param string $token    Token the Resume was made with.
	 * @param array  $previous The job as it was before the Resume: pause_reason, last_error and resumed_at.
	 * @return bool True when this call restored the pause.
	 */
	public function revert_resume( $job_id, $token, array $previous ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, pause_reason = NULLIF( %s, '' ), resumed_at = NULLIF( %s, '' ), last_error = COALESCE( last_error, NULLIF( %s, '' ) ), updated_at = %s WHERE id = %d AND status = %s AND resume_token = %s",
				$this->get_table_name(),
				JobStatus::PAUSED,
				(string) ( $previous['pause_reason'] ?? '' ),
				(string) ( $previous['resumed_at'] ?? '' ),
				(string) ( $previous['last_error'] ?? '' ),
				current_time( 'mysql', true ),
				(int) $job_id,
				JobStatus::RUNNING,
				$token
			)
		);
		// phpcs:enable
	}

	/**
	 * Whether the job made progress since its last Resume.
	 *
	 * Derived from data that only real work writes. A sync job has progress when a worker claimed or changed
	 * something of its attachments since the Resume (attempt evidence); the other types when the cursor moved.
	 * The producer waiting (touch()) is never progress.
	 *
	 * @param BulkJob $job Job that has been resumed.
	 * @return bool
	 */
	public function has_progress_since_resume( BulkJob $job ) {
		global $wpdb;

		$data = $job->to_array();

		if ( BulkJob::TYPE_SYNC !== $job->get_type() ) {
			return $job->get_cursor_id() > (int) ( $data['resume_cursor_id'] ?? 0 );
		}

		$resumed_at = (string) ( $data['resumed_at'] ?? '' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The only dynamic part is the evidence condition, which is itself prepared.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i j WHERE j.id = %d AND ' . $this->attempt_evidence( $resumed_at, $resumed_at ) . ' LIMIT 1',
				$this->get_table_name(),
				$job->get_id()
			)
		);
		// phpcs:enable
	}

	/**
	 * SQL condition: the job "j" has attempt evidence among its attachments.
	 *
	 * The one definition of "a worker worked on this job". An attachment counts when it is processing since its claim
	 * (only claim() stores that state, and its lease writes keep updated_at), or when a worker changed one of its
	 * variants. The attachment row is looked at first: an attempt that ended refreshed it with its closing write,
	 * and one still running is processing, however old its claim (a sync has no time budget). The stored state of an
	 * attachment is never evidence: the planner, regeneration and cleanup rewrite it without any worker. The statuses a worker writes are the ones counted; the death and poison writes
	 * (failed for max_attempts) and cleanup's delete failures are not a worker's, and the planner only writes pending.
	 *
	 * @param string $variant_since UTC time a worker's variant change must come after.
	 * @param string $claim_since   UTC time a running claim must come after.
	 * @return string Prepared condition on the alias "j" of the jobs table.
	 */
	private function attempt_evidence( $variant_since, $claim_since ) {
		global $wpdb;

		return $wpdb->prepare(
			'EXISTS ( SELECT 1 FROM %i a WHERE a.job_id = j.id AND ( ( a.state = %s AND a.updated_at > %s ) OR ( ( a.state = %s OR a.updated_at > %s ) AND EXISTS ( SELECT 1 FROM %i v WHERE v.attachment_id = a.attachment_id AND v.updated_at > %s AND ( v.status IN ( %s, %s, %s ) OR ( v.status = %s AND ( v.reason IS NULL OR v.reason NOT IN ( %s, %s ) ) ) ) ) ) ) )',
			$this->db_manager->get_table_name( 'trust_optimize_attachments' ),
			AttachmentState::PROCESSING,
			$claim_since,
			AttachmentState::PROCESSING,
			$variant_since,
			$this->db_manager->get_table_name( 'trust_optimize_variants' ),
			$variant_since,
			VariantStatus::PROCESSING,
			VariantStatus::DONE,
			VariantStatus::SKIPPED,
			VariantStatus::FAILED,
			'max_attempts',
			'delete_failed'
		);
	}

	/**
	 * Pause a job that has not finished.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $reason PauseReason value.
	 * @return bool True when the job was paused.
	 */
	public function pause( $job_id, $reason = PauseReason::USER ) {
		return $this->transition( $job_id, array( JobStatus::PENDING, JobStatus::RUNNING ), $this->pause_data( $reason ) );
	}

	/**
	 * Columns that pause a job for a reason, in the shape the stored schema has.
	 *
	 * Until the migration to the pause_reason column has finished the reason goes to last_error
	 * as it used to, so a pause always lands; the migration maps that row later.
	 *
	 * @param string      $reason  PauseReason value.
	 * @param string|null $message Text for last_error on the old schema, defaults to the reason.
	 * @return array
	 */
	private function pause_data( $reason, $message = null ) {
		if ( $this->db_manager->has_schema( DatabaseManager::SCHEMA_PAUSE_REASON ) ) {
			return array(
				'status'       => JobStatus::PAUSED,
				'pause_reason' => $reason,
			);
		}

		$data = array( 'status' => JobStatus::PAUSED );

		if ( PauseReason::USER !== $reason ) {
			$data['last_error'] = null === $message ? $reason : $message;
		}

		return $data;
	}

	/**
	 * Pause every job that is not paused or finished (the plugin is being deactivated).
	 *
	 * @param string $reason PauseReason value.
	 * @return int Number of jobs paused.
	 */
	public function pause_unfinished( $reason ) {
		global $wpdb;

		$column = $this->db_manager->has_schema( DatabaseManager::SCHEMA_PAUSE_REASON ) ? 'pause_reason' : 'last_error';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, %i = %s, updated_at = %s WHERE status IN (%s, %s)',
				$this->get_table_name(),
				JobStatus::PAUSED,
				$column,
				$reason,
				current_time( 'mysql', true ),
				JobStatus::PENDING,
				JobStatus::RUNNING
			)
		);
		// phpcs:enable
	}

	/**
	 * Cancel a job.
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function cancel( $job_id ) {
		return $this->finish( $job_id, JobStatus::CANCELLED );
	}

	/**
	 * Complete a job.
	 *
	 * @param int  $job_id      Job ID.
	 * @param bool $with_errors Whether some attachments failed.
	 * @return bool
	 */
	public function complete( $job_id, $with_errors = false ) {
		return $this->finish( $job_id, $with_errors ? JobStatus::COMPLETED_WITH_ERRORS : JobStatus::COMPLETED );
	}

	/**
	 * Fail a job.
	 *
	 * @param int    $job_id     Job ID.
	 * @param string $last_error Last error.
	 * @return bool
	 */
	public function fail( $job_id, $last_error = '' ) {
		return $this->finish(
			$job_id,
			JobStatus::FAILED,
			array( 'last_error' => $last_error )
		);
	}

	/**
	 * Move the cursor to the last attachment handed over to the queue.
	 *
	 * @param int $job_id    Job ID.
	 * @param int $cursor_id Last handled attachment ID.
	 * @return bool
	 */
	public function set_cursor( $job_id, $cursor_id ) {
		return $this->update( $job_id, array( 'cursor_id' => (int) $cursor_id ) );
	}

	/**
	 * Record that the job is alive (a producer run that had nothing to hand over).
	 *
	 * @param int $job_id Job ID.
	 * @return bool
	 */
	public function touch( $job_id ) {
		return $this->update( $job_id, array() );
	}

	/**
	 * Remember that a worker of the job died, in place of what the job's last error said before.
	 *
	 * Neither appends nor touches updated_at: a death is no sign that the job itself is alive.
	 *
	 * @param int    $job_id  Job ID.
	 * @param string $message Text for last_error.
	 * @return bool True when an unfinished job took the message.
	 */
	public function record_death( $job_id, $message ) {
		global $wpdb;

		$statuses = JobStatus::active();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_error = %s WHERE id = %d AND status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
				array_merge( array( $this->get_table_name(), $message, (int) $job_id ), $statuses )
			)
		);
		// phpcs:enable
	}

	/**
	 * Merge values into one section of the stored snapshot (the inventory result).
	 *
	 * @param int    $job_id Job ID.
	 * @param string $key    Snapshot section.
	 * @param array  $values Values to set in it.
	 * @return bool
	 */
	public function merge_snapshot( $job_id, $key, array $values ) {
		$job = $this->get( $job_id );

		if ( ! $job ) {
			return false;
		}

		$snapshot         = $job->get_settings_snapshot();
		$snapshot[ $key ] = array_merge( (array) ( $snapshot[ $key ] ?? array() ), $values );

		return $this->update( $job_id, array( 'settings_snapshot' => wp_json_encode( $snapshot ) ) );
	}

	/**
	 * Find running jobs that show no life: no producer step and no attempt evidence within the threshold.
	 *
	 * A running claim counts for the recovery wait of its attempt, so a job is never stopped while its attempt is still
	 * protected from recovery.
	 *
	 * @param int $stale_after_seconds Stale threshold in seconds.
	 * @return array
	 */
	public function find_stale_running( $stale_after_seconds ) {
		global $wpdb;

		$now       = time();
		$threshold = gmdate( 'Y-m-d H:i:s', $now - (int) $stale_after_seconds );
		$claims    = gmdate( 'Y-m-d H:i:s', $now - max( AttachmentRepository::STALE_CLAIM_SECONDS, (int) $stale_after_seconds ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- The only dynamic part is the evidence condition, which is itself prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT j.* FROM %i j WHERE j.status = %s AND j.updated_at < %s AND NOT ' . $this->attempt_evidence( $threshold, $claims ) . ' ORDER BY j.id ASC',
				$this->get_table_name(),
				JobStatus::RUNNING,
				$threshold
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Move a running job whose producer vanished to "paused" so it can be resumed manually.
	 *
	 * Stopped jobs are paused instead of pending to avoid starting work unexpectedly on admin/status requests.
	 *
	 * @param BulkJob $job                 Job that has been silent for too long.
	 * @param int     $stale_after_seconds Threshold that made it stale, in seconds.
	 * @return bool True when this call stopped the job.
	 */
	public function stop( BulkJob $job, $stale_after_seconds ) {
		$message = null;

		// On the old schema the reason is the last error; the pause_reason column makes that text unnecessary.
		if ( ! $this->db_manager->has_schema( DatabaseManager::SCHEMA_PAUSE_REASON ) ) {
			$message = sprintf(
				'Bulk job recovered from stale running state after %d seconds of inactivity. Resume is required.',
				$stale_after_seconds
			);

			$previous = $job->to_array()['last_error'] ?? '';

			if ( ! empty( $previous ) ) {
				$message = $previous . "\n" . $message;
			}
		}

		return $this->transition( $job->get_id(), array( JobStatus::RUNNING ), $this->pause_data( PauseReason::STOPPED, $message ) );
	}

	/**
	 * Seconds of silence after which a running job counts as stale.
	 *
	 * @return int
	 */
	public function stale_after_seconds() {
		$threshold = (int) apply_filters( 'trust_optimize_bulk_stale_after_seconds', 15 * MINUTE_IN_SECONDS );

		return max( MINUTE_IN_SECONDS, $threshold );
	}

	/**
	 * Update job fields.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $data   Job data.
	 * @return bool
	 */
	public function update( $job_id, array $data ) {
		global $wpdb;

		$table              = $this->get_table_name();
		$data['updated_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $job_id )
		);

		return false !== $result;
	}

	/**
	 * Finish an unfinished job with a terminal status and release the library.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $status Terminal status.
	 * @param array  $data   Additional data.
	 * @return bool True when the job was finished by this call.
	 */
	private function finish( $job_id, $status, array $data = array() ) {
		$data['status']       = $status;
		$data['pause_reason'] = null;
		$data['finished_at']  = current_time( 'mysql', true );
		$finished             = $this->transition( $job_id, JobStatus::active(), $data );

		if ( $finished && $this->read_mutex() === (int) $job_id ) {
			$this->release_mutex();
		}

		return $finished;
	}

	/**
	 * Change fields of a job that is in one of the given statuses (compare-and-set).
	 *
	 * @param int      $job_id Job ID.
	 * @param string[] $from   Statuses the job may be in.
	 * @param array    $data   Columns to set (only UPDATABLE_COLUMNS; null is stored as NULL).
	 * @return bool True when a row was changed.
	 */
	private function transition( $job_id, array $from, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );
		$data               = array_intersect_key( $data, array_flip( self::UPDATABLE_COLUMNS ) );

		if ( ! $this->db_manager->has_schema( DatabaseManager::SCHEMA_PAUSE_REASON ) ) {
			unset( $data['pause_reason'] );
		}

		$sets   = array();
		$values = array( $this->get_table_name() );

		foreach ( $data as $column => $value ) {
			$values[] = $column;

			if ( null === $value ) {
				$sets[] = '%i = NULL';
				continue;
			}

			$sets[]   = '%i = %s';
			$values[] = $value;
		}

		$values[] = (int) $job_id;
		$values   = array_merge( $values, $from );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- The SET list is made of %i = %s / %i = NULL pairs over UPDATABLE_COLUMNS, every name and value is bound.
		return 1 === (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND status IN (' . implode( ', ', array_fill( 0, count( $from ), '%s' ) ) . ')',
				$values
			)
		);
		// phpcs:enable
	}

	/**
	 * Hydrate a job row.
	 *
	 * @param array $row Database row.
	 * @return BulkJob
	 */
	private function hydrate( array $row ) {
		if ( isset( $row['settings_snapshot'] ) && is_string( $row['settings_snapshot'] ) ) {
			$decoded                  = json_decode( $row['settings_snapshot'], true );
			$row['settings_snapshot'] = is_array( $decoded ) ? $decoded : array();
		}

		return new BulkJob( $row );
	}

	/**
	 * Get full table name.
	 *
	 * @return string
	 */
	private function get_table_name() {
		return $this->db_manager->get_table_name( $this->table );
	}
}
