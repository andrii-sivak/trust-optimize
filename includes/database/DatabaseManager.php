<?php
/**
 * Database Manager class
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Database;

use TrustOptimize\Domain\JobStatus;
use TrustOptimize\Domain\PauseReason;
use WP_Error;

/**
 * Class DatabaseManager
 * Manages custom database operations for the plugin
 */
class DatabaseManager {

	/**
	 * Current database version
	 */
	const DB_VERSION = '1.2.0';

	/**
	 * Register the schema version check.
	 */
	public function register() {
		// Check if tables need to be created or updated
		add_action( 'plugins_loaded', array( $this, 'check_version' ), 20 );
	}

	/**
	 * Check database version and update if necessary
	 */
	public function check_version() {
		$db_version = get_option( 'trust_optimize_db_version', '0.0.0' );

		if ( version_compare( $db_version, self::DB_VERSION, '<' ) && $this->migrate() ) {
			update_option( 'trust_optimize_db_version', self::DB_VERSION );
		}
	}

	/**
	 * Whether this site's stored schema version has reached a version.
	 *
	 * Writers that touch columns of a newer schema use it to fall back to the older shape
	 * while the migration has not finished.
	 *
	 * @param string $version Schema version that introduced the columns.
	 * @return bool
	 */
	public function has_schema( $version ) {
		return version_compare( get_option( 'trust_optimize_db_version', '0.0.0' ), $version, '>=' );
	}

	/**
	 * The answer of every entry point that would convert an image before the migration to 1.2.0 has finished.
	 *
	 * @return WP_Error
	 */
	public static function schema_pending_error() {
		return new WP_Error(
			'trust_optimize_schema_pending',
			__( 'The database update has not finished yet, so no image is converted. It is retried automatically.', 'trust-optimize' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Bring the schema to the current version. Every step checks the live schema, so a run
	 * after a partial one finishes the work and a run on a migrated site changes nothing.
	 *
	 * @return bool True when every step succeeded.
	 */
	private function migrate() {
		global $wpdb;

		$this->create_tables();

		$jobs        = $this->get_table_name( 'trust_optimize_jobs' );
		$attachments = $this->get_table_name( 'trust_optimize_attachments' );

		// dbDelta() reports no failure, so confirm its result.
		$added = array(
			array( $jobs, array( 'pause_reason', 'resumed_at', 'resume_token', 'resume_cursor_id' ) ),
			array( $attachments, array( 'attempt_source', 'attempt_token', 'publishing_at' ) ),
		);

		foreach ( $added as $columns ) {
			foreach ( $columns[1] as $column ) {
				if ( ! $this->column_exists( $columns[0], $column ) ) {
					return false;
				}
			}
		}

		$paused   = JobStatus::PAUSED;
		$reasons  = array( PauseReason::DEACTIVATED, PauseReason::LOW_DISK_SPACE );
		$backfill = array(
			// A reason the old code kept in last_error moves to its own column.
			array(
				'UPDATE %i SET pause_reason = last_error, last_error = NULL WHERE status = %s AND pause_reason IS NULL AND last_error IN (%s, %s)',
				array( $jobs, $paused, $reasons[0], $reasons[1] ),
			),
			// Every other pause writer stored a non-empty last_error, so a pause without one is the user's.
			array(
				"UPDATE %i SET pause_reason = %s WHERE status = %s AND pause_reason IS NULL AND ( last_error IS NULL OR last_error = '' )",
				array( $jobs, PauseReason::USER, $paused ),
			),
			array(
				'UPDATE %i SET pause_reason = %s WHERE status = %s AND pause_reason IS NULL',
				array( $jobs, PauseReason::UNKNOWN, $paused ),
			),
			// A column that survived a failed run can hold a reason on a job that has moved on since.
			array(
				'UPDATE %i SET pause_reason = NULL WHERE status <> %s AND pause_reason IS NOT NULL',
				array( $jobs, $paused ),
			),
			// The codes are not errors in any other status either.
			array(
				'UPDATE %i SET last_error = NULL WHERE status <> %s AND last_error IN (%s, %s)',
				array( $jobs, $paused, $reasons[0], $reasons[1] ),
			),
		);

		foreach ( $backfill as $step ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $wpdb->prepare( $step[0], $step[1] ) ) ) {
				return false;
			}
		}

		foreach ( array( 'processed', 'skipped', 'failed_count', 'created_count', 'deleted_count', 'profile_hash' ) as $column ) {
			if ( ! $this->column_exists( $jobs, $column ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $jobs, $column ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check the live schema for a column.
	 *
	 * @param string $table  Full table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private function column_exists( $table, $column ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $wpdb->esc_like( $column ) ) );
	}

	/**
	 * Create plugin database tables
	 */
	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$jobs_table_name = $wpdb->prefix . 'trust_optimize_jobs';
		$attachments     = $wpdb->prefix . 'trust_optimize_attachments';
		$variants        = $wpdb->prefix . 'trust_optimize_variants';

		$jobs_sql = "CREATE TABLE {$jobs_table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			total int unsigned NOT NULL DEFAULT 0,
			settings_snapshot longtext NULL,
			pause_reason varchar(32) NULL,
			resumed_at datetime NULL,
			resume_token char(32) NULL,
			resume_cursor_id bigint(20) unsigned NULL,
			last_error text NULL,
			started_at datetime NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			finished_at datetime NULL,
			PRIMARY KEY  (id),
			KEY type_status (type, status),
			KEY status_updated (status, updated_at),
			KEY cursor_id (cursor_id)
		) $charset_collate;";

		$attachments_sql = "CREATE TABLE {$attachments} (
			attachment_id bigint(20) unsigned NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'none',
			reason varchar(64) NULL,
			attempts tinyint unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NULL,
			attempt_source varchar(16) NULL,
			attempt_token char(32) NULL,
			publishing_at datetime NULL,
			last_error varchar(255) NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (attachment_id),
			KEY state (state),
			KEY job_state (job_id,state)
		) $charset_collate;";

		$variants_sql = "CREATE TABLE {$variants} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			size_name varchar(100) NOT NULL,
			format varchar(10) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			source_relative_path varchar(255) NOT NULL DEFAULT '',
			relative_path varchar(255) NULL,
			width int unsigned NOT NULL DEFAULT 0,
			height int unsigned NOT NULL DEFAULT 0,
			quality tinyint unsigned NOT NULL DEFAULT 0,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			source_file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			file_hash char(64) NULL,
			reason varchar(64) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY attachment_size_format (attachment_id,size_name,format),
			KEY status (status),
			KEY source_path (source_relative_path(191)),
			KEY relative_path (relative_path(191))
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $jobs_sql );
		dbDelta( $attachments_sql );
		dbDelta( $variants_sql );
	}

	/**
	 * Get table name with prefix
	 *
	 * @param string $table Base table name without prefix.
	 * @return string Full table name with prefix
	 */
	public function get_table_name( $table ) {
		global $wpdb;
		return $wpdb->prefix . $table;
	}

	/**
	 * Get TrustOptimize custom table names.
	 *
	 * @return array Custom table names keyed by logical table identifier.
	 */
	public function get_plugin_table_names() {
		return array(
			'attachments' => $this->get_table_name( 'trust_optimize_attachments' ),
			'variants'    => $this->get_table_name( 'trust_optimize_variants' ),
			'jobs'        => $this->get_table_name( 'trust_optimize_jobs' ),
		);
	}

	/**
	 * Check whether a database table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool True when the table exists.
	 */
	public function table_exists( $table ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table )
			)
		);
		// phpcs:enable

		return $found === $table;
	}
}
