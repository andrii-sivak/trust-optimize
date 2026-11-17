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
	 * Derived from data that only real work writes. A sync job has progress when one of its attachments
	 * ended a worker attempt (optimized, partial, skipped, or failed other than by running out of attempts)
	 * after the Resume; the other types when the cursor moved. The producer waiting (touch()) is never progress.
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE job_id = %d AND updated_at > %s AND ( state IN (%s, %s, %s) OR ( state = %s AND ( reason IS NULL OR reason <> %s ) ) ) LIMIT 1',
				$this->db_manager->get_table_name( 'trust_optimize_attachments' ),
				$job->get_id(),
				(string) ( $data['resumed_at'] ?? '' ),
				AttachmentState::OPTIMIZED,
				AttachmentState::PARTIAL,
				AttachmentState::SKIPPED,
				AttachmentState::FAILED,
				'max_attempts'
			)
		);
		// phpcs:enable
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
		if ( $this->db_manager->has_schema( '1.1.0' ) ) {
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

		$column = $this->db_manager->has_schema( '1.1.0' ) ? 'pause_reason' : 'last_error';

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
				'UPDATE %i SET last_error = %s WHERE id = %d AND status IN (%s, %s, %s)',
				$this->get_table_name(),
				$message,
				(int) $job_id,
				$statuses[0],
				$statuses[1],
				$statuses[2]
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
	 * Find running jobs that have been silent for longer than a threshold.
	 *
	 * @param int $stale_after_seconds Stale threshold in seconds.
	 * @return array
	 */
	public function find_stale_running( $stale_after_seconds ) {
		global $wpdb;

		$table     = $this->get_table_name();
		$threshold = gmdate( 'Y-m-d H:i:s', time() - (int) $stale_after_seconds );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND updated_at < %s ORDER BY id ASC',
				$table,
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
		if ( ! $this->db_manager->has_schema( '1.1.0' ) ) {
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

		if ( ! $this->db_manager->has_schema( '1.1.0' ) ) {
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
