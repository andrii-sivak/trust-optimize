<?php
/**
 * Conversion diagnostics.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Diagnostics\ConversionDiagnostics;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';

/**
 * Counts diagnostic-only samples.
 */
class TrustOptimizeCountingDiagnostics extends ConversionDiagnostics {

	/**
	 * Cgroup samples.
	 *
	 * @var int
	 */
	public $cgroup_reads = 0;

	/**
	 * getrusage samples.
	 *
	 * @var int
	 */
	public $rusage_samples = 0;

	/**
	 * Force cgroup unreadability when true.
	 *
	 * @var bool
	 */
	public $cgroup_unreadable = false;

	/**
	 * Directory with fixture cgroup files, or null to use the real cgroup.
	 *
	 * @var string|null
	 */
	public $cgroup_fixture_dir = null;

	protected function cgroup_dir() {
		return $this->cgroup_fixture_dir ?? parent::cgroup_dir();
	}

	protected function sample_cgroup() {
		++$this->cgroup_reads;
		if ( $this->cgroup_unreadable ) {
			return null;
		}

		return parent::sample_cgroup();
	}

	protected function sample_rusage() {
		++$this->rusage_samples;
		return parent::sample_rusage();
	}

	protected function cgroup_unavailable_reason() {
		return $this->cgroup_unreadable ? 'not_readable' : parent::cgroup_unavailable_reason();
	}
}

/**
 * GD editor that reports a quality different from the requested setting.
 */
class TrustOptimizeQualityProbeEditor extends WP_Image_Editor_GD {

	public function get_quality() {
		return 77;
	}
}

/**
 * GD editor whose load() reads the diagnostics file.
 */
class TrustOptimizeDiagnosticsProbeEditor extends WP_Image_Editor_GD {

	/**
	 * Lines present while load() runs.
	 *
	 * @var array[]
	 */
	public static $lines = array();

	public function load() {
		self::$lines = ConversionDiagnosticsTest::jsonl_events();

		return parent::load();
	}
}

/**
 * @covers \TrustOptimize\Diagnostics\ConversionDiagnostics
 * @covers \TrustOptimize\Features\Optimization\ImageConverter
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 */
class ConversionDiagnosticsTest extends WP_UnitTestCase {

	/**
	 * Uploads sub directory used by the test.
	 *
	 * @var string
	 */
	private $relative_dir;

	/**
	 * Absolute directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * @var string[]
	 */
	private $tokens = array();

	/**
	 * @var int[]
	 */
	private $attachment_ids = array();

	public function set_up() {
		parent::set_up();
		$this->relative_dir = 'diag-test-' . wp_generate_uuid4();
		$this->dir          = wp_upload_dir()['basedir'] . '/' . $this->relative_dir;
		wp_mkdir_p( $this->dir );
		delete_option( ConversionDiagnostics::OPTION );
		delete_option( ConversionDiagnostics::PRUNE_OPTION );
		$this->delete_logs();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 80, 'avif_quality' => 62 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	public function tear_down() {
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );
		$this->delete_logs();
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir );
		}
		foreach ( $this->attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}
		delete_option( ConversionDiagnostics::OPTION );
		parent::tear_down();
	}

	public static function jsonl_events() {
		$dir    = wp_upload_dir()['basedir'] . '/to-logs';
		$events = array();
		foreach ( glob( $dir . '/*.jsonl' ) ?: array() as $file ) {
			foreach ( file( $file, FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$events[] = json_decode( $line, true );
			}
		}

		return $events;
	}

	public function test_off_creates_no_directory_and_matches_a_converter_without_a_writer() {
		update_option( ConversionDiagnostics::OPTION, '0', false );
		$counter = $this->counting_writer();
		$with    = $this->converter( $counter );
		$without = $this->converter( null );
		$row_a   = $this->pending_jpeg( 'off-a.jpg', 501 );
		$row_b   = $this->pending_jpeg( 'off-b.jpg', 502 );

		$result_with    = $this->convert_row( $with, $row_a );
		$result_without = $this->convert_row( $without, $row_b );

		$this->assertDirectoryDoesNotExist( $this->logs_dir() );
		$this->assertSame( 0, $counter->cgroup_reads );
		$this->assertSame( 0, $counter->rusage_samples );
		$this->assertSame( $result_without->get_status(), $result_with->get_status() );
		$this->assertSame( $result_without->get_message(), $result_with->get_message() );
		$done_with    = $this->variants->get_for_attachment( 501 )[0];
		$done_without = $this->variants->get_for_attachment( 502 )[0];
		$this->assertSame( $done_without['status'], $done_with['status'] );
		$this->assertSame( $done_without['file_size'], $done_with['file_size'] );

		$old = $this->logs_dir();
		wp_mkdir_p( $old );
		$file = $old . '/2000-01-01.jsonl';
		file_put_contents( $file, "{}\n" );
		touch( $file, time() - ( 61 * DAY_IN_SECONDS ) );
		$this->counting_writer()->prune_old_files();
		$this->assertFileDoesNotExist( $file );
	}

	public function test_only_the_string_one_enables_recording() {
		$writer = $this->counting_writer();
		$row    = $this->pending_jpeg( 'flag.jpg', 503 );

		update_option( ConversionDiagnostics::OPTION, '1', false );
		$this->assertSame( '1', get_option( ConversionDiagnostics::OPTION ) );
		$this->convert_row( $this->converter( $writer ), $row );
		$this->assertNotEmpty( $this->events_of( 'variant_start' ) );

		( new Settings() )->sanitize( array( 'convert_to_webp' => 1, 'webp_quality' => 70 ) );
		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'convert_to_webp' => 1 ) ) );
		$this->assertSame( '1', get_option( ConversionDiagnostics::OPTION ) );

		$this->delete_logs();
		delete_option( ConversionDiagnostics::OPTION );
		foreach ( array( '0', 'yes', 'true' ) as $value ) {
			update_option( ConversionDiagnostics::OPTION, $value, false );
			$this->assertFalse( $writer->is_enabled(), $value );
		}
		delete_option( ConversionDiagnostics::OPTION );
		$this->assertTrue( $writer->is_enabled() );

		update_option( ConversionDiagnostics::OPTION, 1, false );
		$got = get_option( ConversionDiagnostics::OPTION );
		if ( '1' !== $got ) {
			$this->assertFalse( $writer->is_enabled() );
		}
		update_option( ConversionDiagnostics::OPTION, true, false );
		$got = get_option( ConversionDiagnostics::OPTION );
		if ( '1' !== $got ) {
			$this->assertFalse( $writer->is_enabled() );
		}
	}

	public function test_settings_form_saves_on_and_off_and_keeps_a_saved_off() {
		if ( ! isset( get_registered_settings()['trust_optimize_diagnostics'] ) ) {
			Plugin::get_instance()->admin->register_settings();
		}

		$allowed = apply_filters( 'allowed_options', array() );
		$this->assertContains( 'trust_optimize_diagnostics', $allowed['trust_optimize_settings'] );

		delete_option( ConversionDiagnostics::OPTION );
		$html = $this->settings_html();
		$this->assertMatchesRegularExpression(
			'/id="trust_optimize_diagnostics"[^>]*checked/',
			$html
		);
		$this->assertStringContainsString( 'value="0"', $html );
		$this->assertStringContainsString( 'uploads/to-logs', $html );
		$this->assertStringContainsString( '60 days', $html );
		$this->assertStringContainsString( '512 MB', $html );

		$this->post_diagnostics( '0' );
		$this->assertSame( '0', get_option( ConversionDiagnostics::OPTION ) );
		$this->assertFalse( ( new ConversionDiagnostics( new Settings() ) )->is_enabled() );
		$html = $this->settings_html();
		$this->assertDoesNotMatchRegularExpression(
			'/id="trust_optimize_diagnostics"[^>]*checked/',
			$html
		);

		$this->post_diagnostics( '1' );
		$this->assertSame( '1', get_option( ConversionDiagnostics::OPTION ) );
		$this->assertTrue( ( new ConversionDiagnostics( new Settings() ) )->is_enabled() );

		$this->post_diagnostics( '0' );
		update_option( 'trust_optimize_options', ( new Settings() )->sanitize( array( 'convert_to_webp' => 1 ) ) );
		$this->assertSame( '0', get_option( ConversionDiagnostics::OPTION ) );
		$stored = get_option( 'trust_optimize_options' );
		$this->assertArrayNotHasKey( 'trust_optimize_diagnostics', $stored );
	}

	/**
	 * Same write options.php performs for one whitelisted option.
	 *
	 * @param string $posted Posted checkbox value.
	 */
	private function post_diagnostics( $posted ) {
		update_option( ConversionDiagnostics::OPTION, wp_unslash( $posted ) );
	}

	public function test_start_is_flushed_before_the_editor_loads() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		TrustOptimizeDiagnosticsProbeEditor::$lines = array();
		add_filter(
			'wp_image_editors',
			static function () {
				return array( TrustOptimizeDiagnosticsProbeEditor::class );
			}
		);
		$row    = $this->pending_jpeg( 'early.jpg', 504 );
		$result = $this->convert_row( $this->converter( $this->counting_writer() ), $row );

		$during = array_values(
			array_filter(
				TrustOptimizeDiagnosticsProbeEditor::$lines,
				static function ( $event ) {
					return 'variant_start' === ( $event['event'] ?? '' );
				}
			)
		);
		$this->assertNotEmpty( $during );
		$start = $during[0];
		$this->assertSame( 504, $start['attachment_id'] );
		$this->assertSame( 'original', $start['size'] );
		$this->assertSame( 'webp', $start['format'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $start['conversion_id'] );
		$this->assertArrayHasKey( 'php_bytes', $start );

		$results = $this->events_of( 'variant_result' );
		$this->assertSame( $start['conversion_id'], $results[0]['conversion_id'] );
		$this->assertTrue( $result->is_success() );

		$row = $this->variants->get_for_attachment( 504 )[0];
		$this->assertStringNotContainsString( $start['conversion_id'], wp_json_encode( $row ) );
		$this->assertStringNotContainsString( $start['conversion_id'], wp_json_encode( $this->attachments->get( 504 ) ) );
		global $wpdb;
		foreach ( $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'trust_optimize%'" ) as $value ) {
			$this->assertStringNotContainsString( $start['conversion_id'], (string) $value );
		}
	}

	public function test_done_result_matches_the_variant_row() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$row = $this->pending_jpeg( 'done.jpg', 505 );
		$this->convert_row( $this->converter( $this->counting_writer() ), $row );
		$stored = $this->variants->get_for_attachment( 505 )[0];
		$result = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'done', $result['status'] );
		$this->assertSame( VariantStatus::DONE, $stored['status'] );
		$this->assertSame( $stored['reason'], $result['reason'] );
	}

	public function test_not_smaller_matches_the_row_and_keeps_output_bytes() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$path = $this->dir . '/noise.jpg';
		$im   = imagecreatetruecolor( 64, 64 );
		for ( $x = 0; $x < 64; $x++ ) {
			for ( $y = 0; $y < 64; $y++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		imagejpeg( $im, $path, 5 );
		$this->variants->upsert(
			array(
				'attachment_id'        => 506,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/noise.jpg',
			)
		);
		$row = $this->variants->get_for_attachment( 506 )[0];
		$this->convert_row( $this->converter( $this->counting_writer() ), $row, $this->settings( 100 ) );
		$stored = $this->variants->get_for_attachment( 506 )[0];
		$event  = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'skipped', $event['status'] );
		$this->assertSame( 'not_smaller', $event['reason'] );
		$this->assertSame( VariantStatus::SKIPPED, $stored['status'] );
		$this->assertSame( 'not_smaller', $stored['reason'] );
		$this->assertNotNull( $event['output_bytes'] );
	}

	public function test_unmatched_returns_are_logged_as_returned() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = $this->counting_writer();
		$row    = $this->pending_jpeg( 'pending.jpg', 507 );
		$this->attachments->claim( 507, AttachmentRepository::SOURCE_SYNC );
		$result = $this->converter( $writer )->convert( $row, $this->settings(), 'not-the-token' );
		$this->assertSame( 'not_pending', $result->get_message() );
		$event = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'skipped', $event['status'] );
		$this->assertSame( 'not_pending', $event['reason'] );
		$stored = $this->variants->get_for_attachment( 507 )[0];
		$this->assertSame( VariantStatus::PENDING, $stored['status'] );
		$this->assertNotSame( 'not_pending', $stored['reason'] );
	}

	public function test_attempt_lost_is_logged_as_returned() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$row    = $this->pending_jpeg( 'lost.jpg', 519 );
		$atomic = $this->writer_after_temporary(
			function () {
				$this->attachments->recover_dead( 519, $this->tokens[519] );
			}
		);
		$result = $this->convert_row( $this->converter( $this->counting_writer(), $atomic ), $row );
		$this->assertSame( 'attempt_lost', $result->get_message() );
		$event = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'skipped', $event['status'] );
		$this->assertSame( 'attempt_lost', $event['reason'] );
		$stored = $this->variants->get_for_attachment( 519 )[0];
		$this->assertNotSame( 'attempt_lost', $stored['reason'] );
	}

	public function test_row_gone_is_logged_as_returned() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$row    = $this->pending_jpeg( 'gone-row.jpg', 520 );
		$atomic = $this->writer_after_temporary(
			function () use ( $row ) {
				$this->variants->delete( $row['id'] );
			}
		);
		$result = $this->convert_row( $this->converter( $this->counting_writer(), $atomic ), $row );
		$this->assertSame( 'row_gone', $result->get_message() );
		$event = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'skipped', $event['status'] );
		$this->assertSame( 'row_gone', $event['reason'] );
		$this->assertSame( array(), $this->variants->get_for_attachment( 520 ) );
	}

	public function test_lock_busy_leaves_the_variant_unchanged() {
		$off_row = $this->pending_jpeg( 'lock-off.jpg', 508 );
		$on_row  = $this->pending_jpeg( 'lock-on.jpg', 509 );
		$off     = $this->convert_row( $this->converter( null ), $off_row );
		wp_mkdir_p( $this->logs_dir() );
		$handle = fopen( $this->logs_dir() . '/write.lock', 'cb+' );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );
		update_option( ConversionDiagnostics::OPTION, '1', false );
		try {
			$on = $this->convert_row( $this->converter( $this->counting_writer() ), $on_row );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
		$this->assertSame( $off->get_status(), $on->get_status() );
		$this->assertSame( $off->get_message(), $on->get_message() );
		$this->assertSame( array(), $this->events_of( 'variant_start' ) );
		$this->assertSame( array(), $this->events_of( 'variant_result' ) );
	}

	public function test_unwritable_directory_does_not_change_the_variant() {
		$off_row = $this->pending_jpeg( 'err-off.jpg', 510 );
		$on_row  = $this->pending_jpeg( 'err-on.jpg', 511 );
		$off     = $this->convert_row( $this->converter( null ), $off_row );
		file_put_contents( $this->logs_dir(), 'not a directory' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		update_option( ConversionDiagnostics::OPTION, '1', false );
		try {
			$on = $this->convert_row( $this->converter( $this->counting_writer() ), $on_row );
		} finally {
			unlink( $this->logs_dir() );
		}
		$this->assertSame( $off->get_status(), $on->get_status() );
		$this->assertSame( $off->get_message(), $on->get_message() );
		$off_done = $this->variants->get_for_attachment( 510 )[0];
		$on_done  = $this->variants->get_for_attachment( 511 )[0];
		$this->assertSame( $off_done['status'], $on_done['status'] );
		$this->assertSame( $off_done['file_size'], $on_done['file_size'] );
	}

	public function test_two_rotations_in_the_same_second_have_different_names() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = $this->counting_writer();
		$dir    = $this->logs_dir();
		wp_mkdir_p( $dir );
		$stem  = '20261120T120000.000000Z';
		$first = $writer->rotated_path( $stem, $dir );
		file_put_contents( $first, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$second = $writer->rotated_path( $stem, $dir );
		$this->assertNotSame( $first, $second );
		$this->assertStringEndsWith( '.jsonl', $first );
		$this->assertStringEndsWith( '.jsonl', $second );
	}

	public function test_site_cap_deletes_the_oldest_rotated_file_and_still_appends() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = $this->counting_writer();
		$dir    = $this->logs_dir();
		wp_mkdir_p( $dir );
		$oldest = null;
		// One more full file than the site cap, so the next line must drop the oldest.
		$files = (int) floor( ConversionDiagnostics::SITE_MAX / ConversionDiagnostics::FILE_MAX ) + 1;
		for ( $i = 0; $i < $files; $i++ ) {
			$path = $dir . '/20200101T' . sprintf( '%06d', $i ) . '.000000Z.jsonl';
			$fh   = fopen( $path, 'wb' );
			ftruncate( $fh, ConversionDiagnostics::FILE_MAX );
			fclose( $fh );
			touch( $path, time() - 200 + $i );
			if ( 0 === $i ) {
				$oldest = $path;
			}
		}
		$row = $this->pending_jpeg( 'cap.jpg', 512 );
		$this->convert_row( $this->converter( $writer ), $row );
		$this->assertFileDoesNotExist( $oldest );
		$this->assertNotEmpty( $this->events_of( 'variant_result' ) );
		$stored = $this->variants->get_for_attachment( 512 )[0];
		$this->assertSame( VariantStatus::DONE, $stored['status'] );
	}

	public function test_age_cleanup_deletes_only_old_files_and_schedules_nothing() {
		$dir = $this->logs_dir();
		wp_mkdir_p( $dir );
		$old = $dir . '/20200101.jsonl';
		$new = $dir . '/' . gmdate( 'Y-m-d' ) . '.jsonl';
		file_put_contents( $old, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $new, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $old, time() - ( 61 * DAY_IN_SECONDS ) );
		touch( $new, time() - DAY_IN_SECONDS );
		$before = as_get_scheduled_actions( array( 'group' => 'trust-optimize' ), 'ids' );
		$this->assertTrue( $this->counting_writer()->prune_old_files() );
		$this->assertFileDoesNotExist( $old );
		$this->assertFileExists( $new );
		$after = as_get_scheduled_actions( array( 'group' => 'trust-optimize' ), 'ids' );
		$this->assertSame( $before, $after );
	}

	public function test_a_line_over_the_byte_cap_is_not_appended() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = $this->counting_writer();
		$writer->write_event( array( 'v' => 1, 'event' => 'variant_result', 'pad' => str_repeat( 'a', 2000 ) ) );
		$this->assertSame( array(), self::jsonl_events() );
		$writer->write_event( array( 'v' => 1, 'event' => 'variant_result', 'reason' => 'ok' ) );
		$lines = self::jsonl_events();
		$this->assertCount( 1, $lines );
		json_decode( wp_json_encode( $lines[0] ) );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		$this->assertArrayHasKey( 'reason', $lines[0] );
	}

	public function test_missing_file_logs_the_reason_without_paths_or_messages() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$row = $this->pending_jpeg( 'gone.jpg', 513 );
		unlink( $this->dir . '/gone.jpg' );
		$this->convert_row( $this->converter( $this->counting_writer() ), $row );
		$event = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'failed', $event['status'] );
		$this->assertSame( 'missing_file', $event['reason'] );
		foreach ( array( 'message', 'path', 'url', 'file', 'filename', 'attempt_token', 'token' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $event );
		}
		$blob = wp_json_encode( $event );
		$this->assertStringNotContainsString( 'gone.jpg', $blob );
		$this->assertStringNotContainsString( 'http', $blob );
	}

	public function test_cgroup_unavailable_is_not_filled_from_php_bytes() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer                     = $this->counting_writer();
		$writer->cgroup_unreadable = true;
		$this->convert_row( $this->converter( $writer ), $this->pending_jpeg( 'scope.jpg', 514 ) );
		$start = $this->events_of( 'variant_start' )[0];
		$this->assertSame( 'unavailable', $start['cgroup'] );
		$this->assertSame( 'not_readable', $start['cgroup_reason'] );
		$this->assertArrayNotHasKey( 'cgroup_memory_current_bytes', $start );
		$this->assertSame( 'php_allocator_pages_excludes_native', $start['php_memory_scope'] );
	}

	/**
	 * @dataProvider cgroup_events_samples
	 */
	public function test_cgroup_samples_preserve_memory_and_distinguish_unavailable_oom( $events, $expected ) {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		file_put_contents( $this->dir . '/memory.current', "123456\n" );
		if ( null !== $events ) {
			file_put_contents( $this->dir . '/memory.events', $events );
		}
		$writer                     = $this->counting_writer();
		$writer->cgroup_fixture_dir = $this->dir;
		$result = $this->convert_row( $this->converter( $writer ), $this->pending_jpeg( 'cgroup.jpg', 515 ) );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( VariantStatus::DONE, $this->variants->get_for_attachment( 515 )[0]['status'] );
		foreach ( array( 'variant_start', 'variant_result' ) as $type ) {
			$events = $this->events_of( $type );
			$this->assertCount( 1, $events );
			$event = $events[0];
			$this->assertSame( 123456, $event['cgroup_memory_current_bytes'] );
			$this->assertSame( 'cgroup_including_neighbours', $event['cgroup_memory_scope'] );
			$this->assertSame( $expected, $event['cgroup_oom_kill'] );
			$this->assertSame( 'cgroup_counter', $event['cgroup_oom_scope'] );
			$this->assertArrayNotHasKey( 'cgroup', $event );
			if ( 'unavailable' === $expected ) {
				$this->assertSame( 'not_readable', $event['cgroup_oom_reason'] );
			} else {
				$this->assertArrayNotHasKey( 'cgroup_oom_reason', $event );
			}
		}
	}

	public static function cgroup_events_samples() {
		return array(
			'zero'           => array( "low 0\noom_kill 0\noom_group_kill 0\n", 0 ),
			'positive'       => array( "oom_kill 7\n", 7 ),
			'maximum'        => array( 'oom_kill ' . PHP_INT_MAX . "\n", PHP_INT_MAX ),
			'missing file'   => array( null, 'unavailable' ),
			'missing field'  => array( "oom 7\n", 'unavailable' ),
			'empty'          => array( '', 'unavailable' ),
			'negative'       => array( "oom_kill -1\n", 'unavailable' ),
			'nonnumeric'     => array( "oom_kill unknown\n", 'unavailable' ),
			'numeric prefix' => array( "oom_kill 7oops\n", 'unavailable' ),
			'split line'     => array( "oom_kill\n7\n", 'unavailable' ),
			'overflow'       => array( 'oom_kill ' . PHP_INT_MAX . "0\n", 'unavailable' ),
		);
	}

	public function test_cgroup_result_resamples_memory_and_oom_availability() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		file_put_contents( $this->dir . '/memory.current', "123456\n" );
		file_put_contents( $this->dir . '/memory.events', "oom_kill 0\n" );
		$writer                     = $this->counting_writer();
		$writer->cgroup_fixture_dir = $this->dir;
		$file_writer                = $this->writer_after_temporary(
			function () {
				file_put_contents( $this->dir . '/memory.current', "234567\n" );
				file_put_contents( $this->dir . '/memory.events', "oom_kill invalid\n" );
			}
		);

		$result = $this->convert_row( $this->converter( $writer, $file_writer ), $this->pending_jpeg( 'cgroup.jpg', 515 ) );
		$this->assertTrue( $result->is_success() );
		$start    = $this->events_of( 'variant_start' )[0];
		$finished = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 123456, $start['cgroup_memory_current_bytes'] );
		$this->assertSame( 0, $start['cgroup_oom_kill'] );
		$this->assertSame( 234567, $finished['cgroup_memory_current_bytes'] );
		$this->assertSame( 'unavailable', $finished['cgroup_oom_kill'] );
		$this->assertSame( 'not_readable', $finished['cgroup_oom_reason'] );
	}

	public function test_cgroup_events_read_exception_preserves_the_memory_sample() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		file_put_contents( $this->dir . '/memory.current', "123456\n" );
		// A readable directory cannot be read as a counter file.
		$this->assertTrue( symlink( $this->dir, $this->dir . '/memory.events' ) );
		$writer                     = $this->counting_writer();
		$writer->cgroup_fixture_dir = $this->dir;
		$row                        = $this->pending_jpeg( 'cgroup.jpg', 515 );
		$read_errors                = 0;

		set_error_handler(
			static function ( $severity, $message ) use ( &$read_errors ) {
				if ( str_starts_with( $message, 'file_get_contents():' ) ) {
					++$read_errors;
					throw new ErrorException( $message, 0, $severity );
				}
				return false;
			}
		);
		try {
			$result = $this->convert_row( $this->converter( $writer ), $row );
		} finally {
			restore_error_handler();
		}

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 2, $read_errors );
		foreach ( array( 'variant_start', 'variant_result' ) as $type ) {
			$events = $this->events_of( $type );
			$this->assertCount( 1, $events );
			$this->assertSame( 123456, $events[0]['cgroup_memory_current_bytes'] );
			$this->assertSame( 'unavailable', $events[0]['cgroup_oom_kill'] );
			$this->assertSame( 'not_readable', $events[0]['cgroup_oom_reason'] );
		}
	}

	public function test_deadline_is_not_increased_by_a_write() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$settings = new Settings();
		$writer   = new class( $settings ) extends ConversionDiagnostics {
			public $before;
			public $after;
			public $processor;
			public $deadline;
			protected function append( array $payload ) {
				$this->before = $this->deadline->getValue( $this->processor );
				$ok           = parent::append( $payload );
				$this->after  = $this->deadline->getValue( $this->processor );

				return $ok;
			}
		};
		$plugin    = Plugin::get_instance();
		$processor = new AttachmentProcessor( $this->attachments, $this->variants, $this->converter( $writer ), $plugin->planner, $plugin->cleanup, $settings, new CapabilityService(), new DatabaseManager(), $writer );
		$property  = new ReflectionProperty( AttachmentProcessor::class, 'deadline' );
		$property->setAccessible( true );
		$writer->processor = $processor;
		$writer->deadline  = $property;
		$id                = $this->upload_jpeg();
		$processor->sync( $id );
		$this->assertNotNull( $writer->before );
		$this->assertSame( $writer->before, $writer->after );
	}

	public function test_environment_is_written_once_per_job_until_it_changes() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer    = $this->counting_writer();
		$processor = $this->processor( $writer );
		$id        = $this->upload_jpeg();
		$processor->sync( $id );
		$envs = $this->events_of( 'environment' );
		$this->assertCount( 1, $envs );
		$variants = $this->events_of( 'variant_start' );
		$this->assertGreaterThanOrEqual( 1, count( $variants ) );
		$this->assertSame( $envs[0]['env'], $variants[0]['env'] );
		$this->assertArrayNotHasKey( 'php', $variants[0] );

		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 80, 'avif_quality' => 10 ) );
		$id2 = $this->upload_jpeg();
		$processor->sync( $id2 );
		$envs = $this->events_of( 'environment' );
		$this->assertCount( 2, $envs );
		$all   = self::jsonl_events();
		$index = array_column( $all, 'event' );
		$keys  = array_keys( $index, 'environment', true );
		$this->assertArrayHasKey( 1, $keys );
		$this->assertContains( 'variant_start', array_slice( $index, $keys[1] ) );
	}

	public function test_planner_skip_is_not_a_start() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$id       = $this->upload_jpeg();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );
		add_filter( 'trust_optimize_max_pixels', static fn() => 100 );
		$this->processor( $this->counting_writer() )->sync( $id );
		$this->assertSame( array(), $this->events_of( 'variant_start' ) );
		$skip = $this->events_of( 'attachment_skip' );
		$this->assertCount( 1, $skip );
		$this->assertSame( 'too_large', $skip[0]['reason'] );
		$this->assertSame( 12000, $skip[0]['source_w'] );
		$this->assertSame( 12000, $skip[0]['source_h'] );
		$this->assertNull( $skip[0]['source_bytes'] );
	}

	public function test_settings_rest_and_cli_do_not_emit_a_to_logs_url() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$this->convert_row( $this->converter( $this->counting_writer() ), $this->pending_jpeg( 'url.jpg', 516 ) );
		$html = $this->settings_html();
		$this->assertDoesNotMatchRegularExpression( '#https?://[^\\s"\']*to-logs#', $html );
		$this->assertStringContainsString( 'uploads/to-logs', $html );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/trust-optimize/v1/status' ) );
		$this->assertStringNotContainsString( 'to-logs', wp_json_encode( $response->get_data() ) );
		$this->assertFileExists( $this->logs_dir() . '/.htaccess' );
		$this->assertFileExists( $this->logs_dir() . '/web.config' );
		$this->assertFileExists( $this->logs_dir() . '/index.php' );
	}

	/**
	 * @group ms-required
	 */
	public function test_a_line_for_another_blog_is_absent_from_this_sites_logs() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs WP_MULTISITE=1.' );
		}
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$this->convert_row( $this->converter( $this->counting_writer() ), $this->pending_jpeg( 'site1.jpg', 517 ) );
		$blog_1 = self::jsonl_events();
		$this->assertNotEmpty( $blog_1 );
		$blog_id = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		update_option( ConversionDiagnostics::OPTION, '1', false );
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		$other_dir = wp_upload_dir()['basedir'] . '/to-logs';
		restore_current_blog();
		foreach ( $blog_1 as $event ) {
			$this->assertSame( get_current_blog_id(), $event['blog_id'] );
		}
		$this->assertDirectoryDoesNotExist( $other_dir );
	}

	public function test_a_diagnostics_throw_does_not_fail_the_variant() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = new class( new Settings() ) extends ConversionDiagnostics {
			public function record_start( $session, $source_bytes ) {
				throw new RuntimeException( 'diagnostics exploded' );
			}
		};
		$result = $this->convert_row( $this->converter( $writer ), $this->pending_jpeg( 'throw.jpg', 521 ) );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( VariantStatus::DONE, $this->variants->get_for_attachment( 521 )[0]['status'] );
	}

	public function test_environment_hash_is_remembered_only_after_a_successful_write() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$writer = new class( new Settings() ) extends ConversionDiagnostics {
			public $env_attempts = 0;
			public function write_event( array $payload ) {
				if ( 'environment' === ( $payload['event'] ?? '' ) ) {
					++$this->env_attempts;
					if ( 1 === $this->env_attempts ) {
						return false;
					}
				}

				return parent::write_event( $payload );
			}
		};
		$this->convert_row( $this->converter( $writer ), $this->pending_jpeg( 'env-a.jpg', 522 ) );
		$this->convert_row( $this->converter( $writer ), $this->pending_jpeg( 'env-b.jpg', 523 ) );
		$this->assertSame( 2, $writer->env_attempts );
		$this->assertCount( 1, $this->events_of( 'environment' ) );
	}

	public function test_a_symlink_to_logs_is_not_used() {
		$target = $this->dir . '/log-target';
		wp_mkdir_p( $target );
		file_put_contents( $target . '/keep.txt', 'keep' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertTrue( symlink( $target, $this->logs_dir() ) );
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$this->convert_row( $this->converter( $this->counting_writer() ), $this->pending_jpeg( 'link.jpg', 524 ) );
		$this->assertSame( VariantStatus::DONE, $this->variants->get_for_attachment( 524 )[0]['status'] );
		$this->assertSame( array( 'keep.txt' ), array_values( array_diff( scandir( $target ), array( '.', '..' ) ) ) );
		unlink( $target . '/keep.txt' );
		rmdir( $target );
	}

	public function test_a_planner_skip_from_the_queue_is_not_a_start() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$id       = $this->upload_jpeg();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );
		add_filter( 'trust_optimize_max_pixels', static fn() => 100 );
		$this->delete_logs();
		Plugin::get_instance()->conversion_queue->plan_and_enqueue( $id );
		$this->assertSame( array(), $this->events_of( 'variant_start' ) );
		$skip = $this->events_of( 'attachment_skip' );
		$this->assertCount( 1, $skip );
		$this->assertSame( 'too_large', $skip[0]['reason'] );
	}

	public function test_quality_applied_on_the_row_is_on_the_result_line() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		add_filter(
			'wp_image_editors',
			static function () {
				return array( TrustOptimizeQualityProbeEditor::class );
			}
		);
		$this->convert_row( $this->converter( $this->counting_writer() ), $this->pending_jpeg( 'q.jpg', 525 ) );
		$stored = $this->variants->get_for_attachment( 525 )[0];
		$event  = $this->events_of( 'variant_result' )[0];
		$this->assertSame( 'quality_applied:77', $stored['reason'] );
		$this->assertSame( $stored['reason'], $event['reason'] );
		$this->assertSame( 'done', $event['status'] );
	}

	public function test_a_second_attempt_gets_a_new_conversion_id() {
		update_option( ConversionDiagnostics::OPTION, '1', false );
		$row    = $this->pending_jpeg( 'again.jpg', 518 );
		$writer = $this->counting_writer();
		$this->convert_row( $this->converter( $writer ), $row );
		$first = $this->events_of( 'variant_result' )[0]['conversion_id'];
		$done  = $this->variants->get_for_attachment( 518 )[0];
		$this->variants->transition( $done['id'], VariantStatus::DONE, VariantStatus::PENDING, array( 'relative_path' => null, 'file_hash' => null ) );
		unset( $this->tokens[518] );
		$this->convert_row( $this->converter( $writer ), $this->variants->get_for_attachment( 518 )[0] );
		$ids = array_column( $this->events_of( 'variant_result' ), 'conversion_id' );
		$this->assertCount( 2, array_unique( $ids ) );
		$this->assertNotSame( $first, $ids[1] );
	}

	private function logs_dir() {
		return wp_upload_dir()['basedir'] . '/to-logs';
	}

	private function delete_logs() {
		$dir = $this->logs_dir();
		if ( ! file_exists( $dir ) && ! is_link( $dir ) ) {
			return;
		}
		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			unlink( $dir );
			return;
		}
		$items = scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				if ( is_link( $path ) || is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	private function events_of( $event ) {
		return array_values(
			array_filter(
				self::jsonl_events(),
				static function ( $row ) use ( $event ) {
					return $event === ( $row['event'] ?? '' );
				}
			)
		);
	}

	private function counting_writer() {
		return new TrustOptimizeCountingDiagnostics( new Settings() );
	}

	private function processor( ConversionDiagnostics $writer ) {
		$plugin = Plugin::get_instance();

		return new AttachmentProcessor( $this->attachments, $this->variants, $this->converter( $writer ), $plugin->planner, $plugin->cleanup, new Settings(), new CapabilityService(), new DatabaseManager(), $writer );
	}

	private function converter( ?ConversionDiagnostics $diagnostics = null, ?AtomicImageWriter $writer = null ) {
		return new ImageConverter( $this->variants, $writer ?? new AtomicImageWriter( new FileOwnership( $this->variants ) ), new CapabilityService(), $this->attachments, $diagnostics );
	}

	/**
	 * Writer that runs a callback after the temporary file exists, before the lease.
	 *
	 * @param callable $after Called once.
	 * @return AtomicImageWriter
	 */
	private function writer_after_temporary( callable $after ) {
		return new class( new FileOwnership( $this->variants ), $after ) extends AtomicImageWriter {
			/**
			 * Callback after the temporary file is written.
			 *
			 * @var callable
			 */
			private $after;

			public function __construct( FileOwnership $ownership, callable $after ) {
				parent::__construct( $ownership );
				$this->after = $after;
			}

			public function write_temporary( WP_Image_Editor $editor, $target_path, $mime ) {
				$saved = parent::write_temporary( $editor, $target_path, $mime );
				( $this->after )();

				return $saved;
			}
		};
	}

	private function settings( $webp_quality = 80 ) {
		return new OptimizationSettings( array( 'webp' ), array( 'webp' ), array( 'webp' => $webp_quality ) );
	}

	private function convert_row( ImageConverter $converter, array $row, ?OptimizationSettings $settings = null ) {
		$id = (int) $row['attachment_id'];
		if ( ! isset( $this->tokens[ $id ] ) ) {
			$this->tokens[ $id ] = $this->attachments->claim( $id, AttachmentRepository::SOURCE_SYNC );
		}
		$diagnostics = $this->counting_from( $converter );
		if ( $diagnostics ) {
			$diagnostics->set_attempt_context( null, 1, AttachmentRepository::SOURCE_SYNC );
		}

		return $converter->convert( $row, $settings ?? $this->settings(), $this->tokens[ $id ] );
	}

	private function counting_from( ImageConverter $converter ) {
		$ref = new ReflectionProperty( ImageConverter::class, 'diagnostics' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$diag = $ref->getValue( $converter );

		return $diag instanceof ConversionDiagnostics ? $diag : null;
	}

	private function pending_jpeg( $name, $attachment_id ) {
		$path = $this->dir . '/' . $name;
		$im   = imagecreatetruecolor( 200, 120 );
		imagejpeg( $im, $path, 95 );
		$this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/' . $name,
				'width'                => 200,
				'height'               => 120,
			)
		);

		return $this->variants->get_for_attachment( $attachment_id )[0];
	}

	private function upload_jpeg() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		ConversionQueue::cancel_tasks_for_attachment( $id );
		$this->attachment_ids[] = $id;

		return $id;
	}

	private function settings_html() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::get_instance()->admin->display_settings_page();

		return ob_get_clean();
	}
}
