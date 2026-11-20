<?php
/**
 * Oversized images and repeated failures stay away from the editors (04.4).
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Planning\ImageLimits;
use TrustOptimize\Processing\AttachmentProcessor;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Value\OptimizeResult;

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';

/**
 * Converter that leaves every row pending.
 */
class Stubborn_Converter extends ImageConverter {

	/**
	 * Calls so far.
	 *
	 * @var int
	 */
	public $calls = 0;

	public function convert( array $variant_row, TrustOptimize\Settings\OptimizationSettings $settings, $token ) {
		if ( ++$this->calls > 20 ) {
			throw new RuntimeException( 'The same row was tried again and again.' );
		}

		return OptimizeResult::skipped( 'not_pending' );
	}
}

/**
 * GD editor that counts load() so a skip can be shown not to decode.
 */
class TrustOptimizeGuardCountingEditor extends WP_Image_Editor_GD {

	/**
	 * load() calls since the last reset.
	 *
	 * @var int
	 */
	public static $loads = 0;

	public function load() {
		++self::$loads;

		return parent::load();
	}
}

/**
 * @covers \TrustOptimize\Planning\ImageLimits
 * @covers \TrustOptimize\Planning\VariantPlanner
 * @covers \TrustOptimize\Processing\AttachmentProcessor
 */
class ImageGuardTest extends WP_UnitTestCase {

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	public function set_up() {
		parent::set_up();
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => false ) );
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0 ) );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		$database          = new DatabaseManager();
		$this->variants    = new VariantRepository( $database );
		$this->attachments = new AttachmentRepository( $database, $this->variants );
	}

	private function upload() {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.jpg' );
		as_unschedule_all_actions( ConversionQueue::HOOK_PROCESS );

		return $id;
	}

	private function spy_load() {
		TrustOptimizeGuardCountingEditor::$loads = 0;
		add_filter(
			'wp_image_editors',
			static function () {
				return array( TrustOptimizeGuardCountingEditor::class );
			}
		);
	}

	private function header_jpeg( $width, $height ) {
		$sof = "\xFF\xC0" . pack( 'n', 11 ) . "\x08" . pack( 'n', $height ) . pack( 'n', $width ) . "\x01\x01\x11\x00";

		return "\xFF\xD8" . $sof . "\xFF\xD9";
	}

	private function replace_attached_bytes( $id, $bytes ) {
		$file = get_attached_file( $id );
		file_put_contents( $file, $bytes );
		clearstatcache( true, $file );

		return $file;
	}

	private function clear_dimensions( $id, $width = null, $height = null ) {
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();

		if ( null === $width && null === $height ) {
			unset( $metadata['width'], $metadata['height'] );
		} else {
			$metadata['width']  = $width;
			$metadata['height'] = $height;
		}

		wp_update_attachment_metadata( $id, $metadata );
	}

	private function done_original( $id ) {
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			if ( VariantStatus::DONE === $row['status'] && 'original' === $row['size_name'] ) {
				return $row;
			}
		}

		return null;
	}

	private function plant_variant( $id, array $data ) {
		$source = get_post_meta( $id, '_wp_attached_file', true );
		$row    = array_merge(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $source,
				'source_file_size'     => (int) wp_filesize( get_attached_file( $id ) ),
				'quality'              => 80,
			),
			$data
		);

		if ( ! array_key_exists( 'relative_path', $data ) ) {
			$row['relative_path'] = $source . '.' . $row['size_name'] . '.' . $row['format'];
		}

		if ( ! empty( $row['relative_path'] ) && VariantStatus::DONE === $row['status'] ) {
			$path     = wp_upload_dir()['basedir'] . '/' . $row['relative_path'];
			$contents = 'variant-bytes-' . $row['size_name'] . '-' . $row['format'];
			if ( ! is_file( $path ) ) {
				file_put_contents( $path, $contents );
			}
			if ( empty( $row['file_hash'] ) ) {
				$row['file_hash'] = hash( 'sha256', is_file( $path ) ? file_get_contents( $path ) : $contents );
			}
		}

		$this->variants->upsert( $row );

		return $row;
	}

	/**
	 * Workers that claim the attachment and die, one after the other.
	 *
	 * @param int $id    Attachment ID.
	 * @param int $times Number of dead workers.
	 */
	private function claim_times( $id, $times ) {
		global $wpdb;

		$tables = ( new DatabaseManager() )->get_plugin_table_names();

		for ( $i = 0; $i < $times; $i++ ) {
			$this->attachments->claim( $id, AttachmentRepository::SOURCE_QUEUE );
			// The worker died and its attempt was ended without touching the count.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE `{$tables['attachments']}` SET state = 'none', attempt_source = NULL, attempt_token = NULL WHERE attachment_id = %d", $id ) );
		}
	}

	public function test_an_image_with_too_many_pixels_is_skipped_by_its_metadata() {
		$id       = $this->upload();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'too_large', $result->get_message() );
		$row = $this->attachments->get( $id );
		$this->assertSame( AttachmentState::SKIPPED, $row['state'] );
		$this->assertSame( 'too_large', $row['reason'] );
	}

	public function test_the_pixel_limit_can_be_changed_by_a_filter() {
		$id = $this->upload();
		add_filter(
			'trust_optimize_max_pixels',
			static function () {
				return 10;
			}
		);

		$this->assertSame( 'too_large', Plugin::get_instance()->processor->sync( $id )->get_message() );
	}

	public function test_an_image_that_does_not_fit_into_the_free_memory_is_skipped() {
		$id       = $this->upload();
		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 3000, 'height' => 3000 ) ) );
		$old = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 10 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		try {
			$reason = Plugin::get_instance()->processor->sync( $id )->get_message();
		} finally {
			ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		$this->assertSame( 'insufficient_memory', $reason );
	}

	public function test_a_missing_file_is_skipped_before_any_row_is_converted() {
		$id = $this->upload();
		wp_delete_file( get_attached_file( $id ) );

		$this->assertSame( 'missing_file', Plugin::get_instance()->processor->sync( $id )->get_message() );
	}

	public function test_a_normal_image_is_converted() {
		$id = $this->upload();

		$this->assertTrue( Plugin::get_instance()->processor->sync( $id )->is_success() );
	}

	public function test_failed_variants_are_not_reset_while_the_attempts_are_used_up() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed' ) );
		}
		$this->claim_times( $id, AttachmentRepository::MAX_ATTEMPTS );

		$plan = Plugin::get_instance()->planner->plan( $id );

		$this->assertFalse( $plan->has_work(), 'A poison file is not retried by itself.' );

		$this->attachments->reset_attempts( $id );
		$this->assertTrue( Plugin::get_instance()->planner->plan( $id )->has_work() );
	}

	public function test_an_explicit_sync_gives_a_poison_file_another_chance() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );
		foreach ( $this->variants->get_for_attachment( $id ) as $row ) {
			$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::FAILED, array( 'reason' => 'save_failed' ) );
		}
		$this->claim_times( $id, AttachmentRepository::MAX_ATTEMPTS );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
	}

	public function test_a_row_that_stays_pending_is_tried_once_per_run() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );

		$plugin    = Plugin::get_instance();
		$converter = new Stubborn_Converter( $this->variants, new AtomicImageWriter( new FileOwnership( $this->variants ) ), new CapabilityService(), $this->attachments );
		$processor = new AttachmentProcessor( $this->attachments, $this->variants, $converter, $plugin->planner, $plugin->cleanup, new TrustOptimize\Admin\Settings(), new CapabilityService(), new DatabaseManager() );

		$processor->run( $id, INF );

		$this->assertSame( count( $this->variants->get_for_attachment( $id ) ), $converter->calls );
	}

	public function test_a_header_over_the_pixel_limit_is_skipped_without_loading() {
		$id          = $this->upload();
		$id_negative = $this->upload();
		$this->replace_attached_bytes( $id, $this->header_jpeg( 12000, 12000 ) );
		$this->clear_dimensions( $id );
		$this->replace_attached_bytes( $id_negative, $this->header_jpeg( 12000, 12000 ) );
		$this->clear_dimensions( $id_negative, -1, -1 );
		$this->spy_load();

		$this->assertFalse( Plugin::get_instance()->conversion_queue->plan_and_enqueue( $id ) );
		$this->assertSame(
			array(),
			as_get_scheduled_actions(
				array(
					'hook'   => ConversionQueue::HOOK_PROCESS,
					'status' => ActionScheduler_Store::STATUS_PENDING,
					'args'   => array( 'attachment_id' => $id ),
				),
				'ids'
			)
		);

		$result = Plugin::get_instance()->processor->sync( $id_negative );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'too_large', $result->get_message() );
		$this->assertSame( 0, TrustOptimizeGuardCountingEditor::$loads );
		$this->assertSame( $this->header_jpeg( 12000, 12000 ), file_get_contents( get_attached_file( $id ) ) );
		$this->assertArrayNotHasKey( 'width', wp_get_attachment_metadata( $id ) );
	}

	public function test_too_large_removes_an_existing_done_variant() {
		$id = $this->upload();
		$this->assertTrue( Plugin::get_instance()->processor->sync( $id )->is_success() );
		$done = $this->done_original( $id );
		$path = wp_upload_dir()['basedir'] . '/' . $done['relative_path'];
		$this->assertFileExists( $path );

		$metadata = wp_get_attachment_metadata( $id );
		wp_update_attachment_metadata( $id, array_merge( $metadata, array( 'width' => 12000, 'height' => 12000 ) ) );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertSame( 'too_large', $result->get_message() );
		$this->assertSame( array(), $this->variants->get_for_attachment( $id ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_a_header_that_does_not_fit_memory_is_skipped_without_loading() {
		$id = $this->upload();
		$this->replace_attached_bytes( $id, $this->header_jpeg( 3000, 3000 ) );
		$this->clear_dimensions( $id );
		$this->spy_load();
		$old = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 10 * MB_IN_BYTES ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed

		try {
			$reason = Plugin::get_instance()->processor->sync( $id )->get_message();
		} finally {
			ini_set( 'memory_limit', $old ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		$this->assertSame( 'insufficient_memory', $reason );
		$this->assertSame( 0, TrustOptimizeGuardCountingEditor::$loads );
	}

	public function test_unknown_bytes_are_skipped_without_loading() {
		$id   = $this->upload();
		$file = get_attached_file( $id );
		$this->replace_attached_bytes( $id, 'not-an-image' );
		$this->clear_dimensions( $id );
		$this->spy_load();

		$result = Plugin::get_instance()->processor->sync( $id );
		$row    = $this->attachments->get( $id );

		$this->assertTrue( $result->is_skipped() );
		$this->assertSame( 'unknown_dimensions', $result->get_message() );
		$this->assertSame( AttachmentState::SKIPPED, $row['state'] );
		$this->assertSame( 'unknown_dimensions', $row['reason'] );
		$this->assertSame( 0, TrustOptimizeGuardCountingEditor::$loads );
		$this->assertSame( 'not-an-image', file_get_contents( $file ) );
		$this->assertSame( 'unknown_dimensions', ImageLimits::skip_reason( 0, 0 ) );
		$this->assertSame( 'unknown_dimensions', ImageLimits::skip_reason( -1, -1 ) );
	}

	public function test_a_jpeg_with_cleared_dimensions_is_converted_from_its_header() {
		$id   = $this->upload();
		$file = get_attached_file( $id );
		$before = file_get_contents( $file );
		$this->clear_dimensions( $id );

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $before, file_get_contents( $file ) );
		$this->assertNotEmpty( $this->done_original( $id ) );
	}

	public function test_a_done_variant_of_a_header_sized_jpeg_is_not_encoded_again() {
		$id = $this->upload();
		$this->assertTrue( Plugin::get_instance()->processor->sync( $id )->is_success() );
		$done = $this->done_original( $id );
		$hash = $done['file_hash'];
		$path = $done['relative_path'];
		$bytes = file_get_contents( wp_upload_dir()['basedir'] . '/' . $path );
		$this->clear_dimensions( $id );
		$this->spy_load();

		$result = Plugin::get_instance()->processor->sync( $id );
		$after  = $this->done_original( $id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 0, TrustOptimizeGuardCountingEditor::$loads );
		$this->assertSame( $done['id'], $after['id'] );
		$this->assertSame( $path, $after['relative_path'] );
		$this->assertSame( $hash, $after['file_hash'] );
		$this->assertSame( $bytes, file_get_contents( wp_upload_dir()['basedir'] . '/' . $path ) );
	}

	public function test_unknown_dimensions_keeps_a_matching_done_variant_and_removes_the_rest() {
		$id = $this->upload();
		$this->replace_attached_bytes( $id, 'not-an-image' );
		$this->clear_dimensions( $id );
		$current = (int) wp_filesize( get_attached_file( $id ) );

		$kept = $this->plant_variant(
			$id,
			array(
				'size_name'        => 'original',
				'format'           => 'webp',
				'quality'          => 1,
				'source_file_size' => $current,
			)
		);
		$disabled = $this->plant_variant(
			$id,
			array(
				'size_name'        => 'original',
				'format'           => 'avif',
				'source_file_size' => $current,
			)
		);
		$changed = $this->plant_variant(
			$id,
			array(
				'size_name'        => 'thumbnail',
				'format'           => 'webp',
				'source_file_size' => $current + 10,
			)
		);
		$this->plant_variant(
			$id,
			array(
				'size_name'        => 'medium',
				'format'           => 'webp',
				'status'           => VariantStatus::PENDING,
				'relative_path'    => null,
				'source_file_size' => $current,
			)
		);
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 80 ) );
		$this->spy_load();

		$result = Plugin::get_instance()->processor->sync( $id );
		$rows   = $this->variants->get_for_attachment( $id );
		$left   = $rows[0];

		$this->assertSame( 'unknown_dimensions', $result->get_message() );
		$this->assertSame( 0, TrustOptimizeGuardCountingEditor::$loads );
		$this->assertCount( 1, $rows );
		$this->assertSame( $kept['relative_path'], $left['relative_path'] );
		$this->assertSame( VariantStatus::DONE, $left['status'] );
		$this->assertSame( 1, (int) $left['quality'] );
		$this->assertSame( $kept['relative_path'], VariantRepository::servable_path( $left ) );
		$this->assertFileExists( wp_upload_dir()['basedir'] . '/' . $kept['relative_path'] );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/' . $disabled['relative_path'] );
		$this->assertFileDoesNotExist( wp_upload_dir()['basedir'] . '/' . $changed['relative_path'] );
		$this->assertSame( AttachmentState::SKIPPED, $this->attachments->get( $id )['state'] );
		$this->assertSame( 'unknown_dimensions', $this->attachments->get( $id )['reason'] );
	}

	public function test_a_claim_counts_as_an_attempt_and_a_finished_run_resets_it() {
		$id = $this->upload();
		Plugin::get_instance()->planner->plan( $id );

		$this->claim_times( $id, 1 );
		$this->assertSame( 1, $this->attachments->get_attempts( $id ) );

		Plugin::get_instance()->processor->run( $id, INF );
		$this->assertSame( 0, $this->attachments->get_attempts( $id ) );
	}
}
