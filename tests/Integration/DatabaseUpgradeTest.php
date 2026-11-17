<?php
/**
 * Schema creation and the version check.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Bulk\BulkJobRepository;
use TrustOptimize\Database\DatabaseManager;

/**
 * @covers \TrustOptimize\Database\DatabaseManager
 */
class DatabaseUpgradeTest extends WP_UnitTestCase {

	/**
	 * Column type as reported by MySQL, e.g. "varchar(32)".
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return string|null Null when the column does not exist.
	 */
	private function column_type( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ), ARRAY_A );

		return $row ? strtolower( (string) $row['Type'] ) : null;
	}

	public function test_new_tables_exist_with_expected_columns() {
		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();

		foreach ( array( 'attachments', 'variants', 'jobs' ) as $key ) {
			$this->assertTrue( $database->table_exists( $tables[ $key ] ), "Table {$key} is missing." );
		}

		$this->assertSame( 'varchar(32)', $this->column_type( $tables['attachments'], 'state' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['variants'], 'status' ) );
		$this->assertSame( 'varchar(32)', $this->column_type( $tables['jobs'], 'status' ) );
	}

	public function test_the_variants_table_has_no_columns_of_earlier_formats() {
		$variants = ( new DatabaseManager() )->get_plugin_table_names()['variants'];

		$this->assertNull( $this->column_type( $variants, 'naming' ) );
		$this->assertNull( $this->column_type( $variants, 'legacy_relative_path' ) );
		$this->assertSame( 'varchar(255)', $this->column_type( $variants, 'relative_path' ) );
	}

	public function test_the_schema_is_created_from_scratch_at_the_current_version() {
		$database = new DatabaseManager();

		delete_option( 'trust_optimize_db_version' );
		$database->check_version();

		$this->assertSame( '1.2.0', DatabaseManager::DB_VERSION );
		$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
		$jobs = $database->get_plugin_table_names()['jobs'];
		$this->assertSame( 'varchar(32)', $this->column_type( $jobs, 'pause_reason' ) );
		foreach ( array( 'processed', 'skipped', 'failed_count', 'created_count', 'deleted_count', 'profile_hash' ) as $column ) {
			$this->assertNull( $this->column_type( $jobs, $column ), $column );
		}
		$this->assertSame( array( 'attachments', 'variants', 'jobs' ), array_keys( $database->get_plugin_table_names() ) );
	}

	public function test_a_current_schema_is_left_alone() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];

		// Narrow a column (DDL commits implicitly): create_tables() would widen it again.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "ALTER TABLE `{$jobs}` MODIFY status varchar(20) NOT NULL DEFAULT 'pending'" );

		try {
			update_option( 'trust_optimize_db_version', DatabaseManager::DB_VERSION );
			$database->check_version();

			$this->assertSame( 'varchar(20)', $this->column_type( $jobs, 'status' ), 'The current version does not touch the tables.' );
		} finally {
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
		}

		$this->assertSame( 'varchar(32)', $this->column_type( $jobs, 'status' ) );
		$this->assertSame( DatabaseManager::DB_VERSION, get_option( 'trust_optimize_db_version' ) );
	}

	/**
	 * Turn the jobs table into the 1.0.0 shape and add the legacy paused rows.
	 *
	 * @param string $jobs Jobs table name.
	 * @return int[] Row IDs keyed by label.
	 */
	private function downgrade_jobs( $jobs ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$jobs}`" );
		$wpdb->query( "ALTER TABLE `{$jobs}` DROP COLUMN pause_reason" );
		$wpdb->query(
			"ALTER TABLE `{$jobs}` ADD COLUMN processed int unsigned NOT NULL DEFAULT 0, ADD COLUMN skipped int unsigned NOT NULL DEFAULT 0,
			ADD COLUMN failed_count int unsigned NOT NULL DEFAULT 0, ADD COLUMN created_count int unsigned NOT NULL DEFAULT 0,
			ADD COLUMN deleted_count int unsigned NOT NULL DEFAULT 0, ADD COLUMN profile_hash varchar(64) NOT NULL DEFAULT ''"
		);

		$rows = array(
			'deactivated' => array( 'paused', 'deactivated' ),
			'disk'        => array( 'paused', 'low_disk_space' ),
			'user'        => array( 'paused', null ),
			'empty'       => array( 'paused', '' ),
			'stale'       => array( 'paused', 'Bulk job recovered from stale running state after 900 seconds of inactivity. Resume is required.' ),
			'done'        => array( 'completed', 'low_disk_space' ),
			'failed'      => array( 'failed', 'boom' ),
		);
		$ids  = array();

		foreach ( $rows as $label => $row ) {
			$wpdb->insert(
				$jobs,
				array(
					'type'       => 'sync',
					'status'     => $row[0],
					'last_error' => $row[1],
					'updated_at' => '2026-11-01 10:00:00',
				)
			);
			$ids[ $label ] = (int) $wpdb->insert_id;
		}
		// phpcs:enable

		update_option( 'trust_optimize_db_version', '1.0.0' );

		return $ids;
	}

	/**
	 * Keep the restored version: the DDL above committed the test transaction while the option still held a value
	 * written during the migration, and the rollback at the end of the test would bring that value back.
	 */
	private function commit() {
		global $wpdb;

		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function job_row( $jobs, $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_row( $wpdb->prepare( 'SELECT status, pause_reason, last_error FROM %i WHERE id = %d', $jobs, $id ), ARRAY_A );
	}

	public function test_a_1_0_0_jobs_table_is_migrated_and_a_second_run_changes_nothing() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];

		try {
			$ids = $this->downgrade_jobs( $jobs );
			$this->assertFalse( $database->has_schema( '1.1.0' ) );

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertTrue( $database->has_schema( '1.1.0' ) );
			foreach ( array( 'processed', 'skipped', 'failed_count', 'created_count', 'deleted_count', 'profile_hash' ) as $column ) {
				$this->assertNull( $this->column_type( $jobs, $column ), $column );
			}

			$expected = array(
				'deactivated' => array( 'deactivated', null ),
				'disk'        => array( 'low_disk_space', null ),
				'user'        => array( 'user', null ),
				'empty'       => array( 'user', '' ),
				'stale'       => array( 'unknown', 'Bulk job recovered from stale running state after 900 seconds of inactivity. Resume is required.' ),
				'done'        => array( null, null ),
				'failed'      => array( null, 'boom' ),
			);
			foreach ( $expected as $label => $values ) {
				$row = $this->job_row( $jobs, $ids[ $label ] );
				$this->assertSame( $values, array( $row['pause_reason'], $row['last_error'] ), $label );
			}

			$before = $wpdb->get_results( "SELECT * FROM `{$jobs}` ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			update_option( 'trust_optimize_db_version', '1.0.0' );
			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM `{$jobs}` ORDER BY id", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$jobs}`" );
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}

	public function test_a_failed_step_keeps_the_old_version_and_the_next_run_completes() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];
		$break    = static function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'ALTER TABLE' ) && false !== stripos( $query, 'pause_reason' ) ? 'SELECT 1' : $query;
		};

		try {
			$ids = $this->downgrade_jobs( $jobs );

			add_filter( 'query', $break );
			$wpdb->suppress_errors( true );
			$database->check_version();
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $break );

			$this->assertSame( '1.0.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertNotNull( $this->column_type( $jobs, 'profile_hash' ), 'Nothing is dropped before the new column is confirmed.' );

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertSame( 'user', $this->job_row( $jobs, $ids['user'] )['pause_reason'] );
		} finally {
			remove_filter( 'query', $break );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$jobs}`" );
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}

	public function test_a_drop_that_loses_the_race_fails_this_run_and_the_next_one_completes() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];
		$raced    = false;
		$other    = function ( $query ) use ( &$raced, $jobs ) {
			global $wpdb;

			if ( ! $raced && 0 === stripos( ltrim( $query ), 'ALTER TABLE' ) && false !== stripos( $query, 'DROP COLUMN' ) ) {
				$raced = true;
				// The other request drops the same column first.
				$wpdb->query( preg_replace( '/^\s*ALTER TABLE\s+\S+/i', "ALTER TABLE `{$jobs}`", $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			}

			return $query;
		};

		try {
			$ids = $this->downgrade_jobs( $jobs );

			add_filter( 'query', $other );
			$wpdb->suppress_errors( true );
			$database->check_version();
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $other );

			$this->assertTrue( $raced, 'The other request got in.' );
			$this->assertSame( '1.0.0', get_option( 'trust_optimize_db_version' ), 'The failed drop leaves the version.' );

			// The column exists and is filled; the job moves on before the next run.
			$this->assertSame( 'user', $this->job_row( $jobs, $ids['user'] )['pause_reason'] );
			$wpdb->update( $jobs, array( 'status' => 'running' ), array( 'id' => $ids['user'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertSame( 'varchar(32)', $this->column_type( $jobs, 'pause_reason' ) );
			foreach ( array( 'processed', 'skipped', 'failed_count', 'created_count', 'deleted_count', 'profile_hash' ) as $column ) {
				$this->assertNull( $this->column_type( $jobs, $column ), $column );
			}
			$this->assertNull( $this->job_row( $jobs, $ids['user'] )['pause_reason'], 'A job that left the pause does not keep a reason.' );
		} finally {
			remove_filter( 'query', $other );
			$wpdb->suppress_errors( false );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$jobs}`" );
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}

	public function test_pauses_written_on_a_1_0_0_table_land_and_the_migration_maps_them() {
		global $wpdb;

		$database = new DatabaseManager();
		$jobs     = $database->get_plugin_table_names()['jobs'];
		$repo     = new BulkJobRepository( $database );
		$sentence = 'Bulk job recovered from stale running state after 900 seconds of inactivity. Resume is required.';

		$insert = static function ( $status, $last_error = null, $updated_at = '2026-11-10 10:00:00' ) use ( $wpdb, $jobs ) {
			$wpdb->insert(
				$jobs,
				array(
					'type'       => 'sync',
					'status'     => $status,
					'last_error' => $last_error,
					'updated_at' => $updated_at,
				)
			);

			return (int) $wpdb->insert_id;
		};
		$old_row = function ( $id ) use ( $wpdb, $jobs ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_row( $wpdb->prepare( 'SELECT status, last_error FROM %i WHERE id = %d', $jobs, $id ), ARRAY_A );
		};

		try {
			$this->downgrade_jobs( $jobs );
			$this->assertFalse( $database->has_schema( '1.1.0' ) );

			$stale = $insert( 'running', 'earlier failure', gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );
			$silent = $repo->find_stale_running( 900 );
			$this->assertCount( 1, $silent );
			$this->assertTrue( $repo->stop( $silent[0], 900 ) );
			$this->assertSame( array( 'paused', "earlier failure\n" . $sentence ), array_values( $old_row( $stale ) ) );

			$deactivated = $insert( 'running' );
			$this->assertSame( 1, $repo->pause_unfinished( 'deactivated' ) );
			$this->assertSame( array( 'paused', 'deactivated' ), array_values( $old_row( $deactivated ) ) );

			$disk = $insert( 'running' );
			$this->assertTrue( $repo->pause( $disk, 'low_disk_space' ) );
			$this->assertSame( array( 'paused', 'low_disk_space' ), array_values( $old_row( $disk ) ) );

			$user = $insert( 'running' );
			$this->assertTrue( $repo->pause( $user ) );
			$this->assertSame( array( 'paused', null ), array_values( $old_row( $user ) ) );

			$cancelled = $insert( 'running' );
			$this->assertTrue( $repo->pause( $cancelled, 'low_disk_space' ) );
			$this->assertTrue( $repo->cancel( $cancelled ) );
			$this->assertSame( 'cancelled', $old_row( $cancelled )['status'] );

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$expected = array(
				'stale'       => array( $stale, 'unknown', "earlier failure\n" . $sentence ),
				'deactivated' => array( $deactivated, 'deactivated', null ),
				'disk'        => array( $disk, 'low_disk_space', null ),
				'user'        => array( $user, 'user', null ),
				'cancelled'   => array( $cancelled, null, null ),
			);
			foreach ( $expected as $label => $values ) {
				$row = $this->job_row( $jobs, $values[0] );
				$this->assertSame( array( $values[1], $values[2] ), array( $row['pause_reason'], $row['last_error'] ), $label );
			}
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DELETE FROM `{$jobs}`" );
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}

	/**
	 * Columns 1.2.0 adds, by table key.
	 *
	 * @var string[][]
	 */
	const RECOVERY_COLUMNS = array(
		'attachments' => array( 'attempt_source', 'attempt_token', 'publishing_at' ),
		'jobs'        => array( 'resumed_at', 'resume_token', 'resume_cursor_id' ),
	);

	/**
	 * Take the columns of 1.2.0 away and store version 1.1.0.
	 *
	 * @param string[] $tables Plugin table names keyed by logical name.
	 */
	private function downgrade_to_1_1_0( array $tables ) {
		global $wpdb;

		foreach ( self::RECOVERY_COLUMNS as $key => $columns ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "ALTER TABLE `{$tables[ $key ]}` DROP COLUMN " . implode( ', DROP COLUMN ', $columns ) );
		}

		update_option( 'trust_optimize_db_version', '1.1.0' );
	}

	private function assert_recovery_columns( array $tables ) {
		$this->assertSame( 'varchar(16)', $this->column_type( $tables['attachments'], 'attempt_source' ) );
		$this->assertSame( 'char(32)', $this->column_type( $tables['attachments'], 'attempt_token' ) );
		$this->assertSame( 'datetime', $this->column_type( $tables['attachments'], 'publishing_at' ) );
		$this->assertSame( 'datetime', $this->column_type( $tables['jobs'], 'resumed_at' ) );
		$this->assertSame( 'char(32)', $this->column_type( $tables['jobs'], 'resume_token' ) );
		$this->assertSame( 'bigint(20) unsigned', $this->column_type( $tables['jobs'], 'resume_cursor_id' ) );
	}

	public function test_a_1_1_0_schema_gets_the_recovery_columns_and_a_second_run_changes_nothing() {
		global $wpdb;

		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();

		try {
			$this->downgrade_to_1_1_0( $tables );
			$this->assertFalse( $database->has_schema( '1.2.0' ) );
			$this->assertTrue( $database->has_schema( '1.1.0' ) );

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertTrue( $database->has_schema( '1.2.0' ) );
			$this->assert_recovery_columns( $tables );

			$before = $wpdb->get_results( "SHOW CREATE TABLE `{$tables['attachments']}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			update_option( 'trust_optimize_db_version', '1.1.0' );
			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assertSame( $before, $wpdb->get_results( "SHOW CREATE TABLE `{$tables['attachments']}`", ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		} finally {
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}

	public function test_a_failed_step_to_1_2_0_keeps_the_old_version_and_the_next_run_completes() {
		global $wpdb;

		$database = new DatabaseManager();
		$tables   = $database->get_plugin_table_names();
		$break    = static function ( $query ) {
			return 0 === stripos( ltrim( $query ), 'ALTER TABLE' ) && false !== stripos( $query, 'attempt_token' ) ? 'SELECT 1' : $query;
		};

		try {
			$this->downgrade_to_1_1_0( $tables );

			add_filter( 'query', $break );
			$wpdb->suppress_errors( true );
			$database->check_version();
			$wpdb->suppress_errors( false );
			remove_filter( 'query', $break );

			$this->assertSame( '1.1.0', get_option( 'trust_optimize_db_version' ), 'A column that did not land keeps the old version.' );
			$this->assertNull( $this->column_type( $tables['attachments'], 'attempt_token' ) );

			$database->check_version();

			$this->assertSame( '1.2.0', get_option( 'trust_optimize_db_version' ) );
			$this->assert_recovery_columns( $tables );
		} finally {
			remove_filter( 'query', $break );
			$wpdb->suppress_errors( false );
			update_option( 'trust_optimize_db_version', '0.0.0' );
			$database->check_version();
			$this->commit();
		}
	}
}
