<?php
/**
 * ImageConverter tests.
 *
 * @package TrustOptimize\Tests
 */

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Core\Plugin;
use TrustOptimize\Database\DatabaseManager;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Features\Optimization\ImageConverter;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Files\FileOwnership;
use TrustOptimize\Queue\ConversionQueue;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;

require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';
require_once ABSPATH . 'wp-includes/class-wp-image-editor-imagick.php';

/**
 * GD editor that refuses an explicit quality; the default it resolves while loading is accepted.
 */
class TrustOptimizeTestRefusingEditor extends WP_Image_Editor_GD {
	public function set_quality( $quality = null, $dims = array() ) {
		if ( null === $quality ) {
			return parent::set_quality( $quality, $dims );
		}

		return new WP_Error( 'test_refused', 'The editor refuses this quality.' );
	}
}

/**
 * GD editor whose save returns an error.
 */
class TrustOptimizeTestErroringEditor extends WP_Image_Editor_GD {
	public function save( $destfilename = null, $mime_type = null ) {
		return new WP_Error( 'test_save_failed', 'The save failed.' );
	}
}

/**
 * GD editor that throws while saving.
 */
class TrustOptimizeTestThrowingEditor extends WP_Image_Editor_GD {
	public function save( $destfilename = null, $mime_type = null ) {
		throw new RuntimeException( 'boom' );
	}
}

/**
 * GD editor that encodes with 77 whatever quality it is asked for.
 */
class TrustOptimizeTestDeviatingEditor extends WP_Image_Editor_GD {
	public function set_quality( $quality = null, $dims = array() ) {
		return parent::set_quality( 77, $dims );
	}
}

/**
 * @covers \TrustOptimize\Features\Optimization\ImageConverter
 */
class ImageConverterTest extends WP_UnitTestCase {

	/**
	 * Uploads sub directory used by the test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Its path relative to uploads.
	 *
	 * @var string
	 */
	private $relative_dir;

	/**
	 * Variants.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Converter.
	 *
	 * @var ImageConverter
	 */
	private $converter;

	/**
	 * Attachments.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Tokens of the attempts the test holds, by attachment ID.
	 *
	 * @var string[]
	 */
	private $tokens = array();

	/**
	 * Capabilities.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	public function set_up() {
		parent::set_up();
		$this->relative_dir = 'converter-test-' . wp_generate_uuid4();
		$this->dir          = wp_upload_dir()['basedir'] . '/' . $this->relative_dir;
		wp_mkdir_p( $this->dir );

		update_option( CapabilityService::OPTION, array( 'webp' => true, 'avif' => true ) );
		$this->capabilities = new CapabilityService();
		$database           = new DatabaseManager();
		$this->variants     = new VariantRepository( $database );
		$this->attachments  = new AttachmentRepository( $database, $this->variants );
		$this->converter    = new ImageConverter( $this->variants, new AtomicImageWriter( new FileOwnership( $this->variants ) ), $this->capabilities, $this->attachments );
	}

	/**
	 * Convert a row as the worker that holds its attachment would.
	 *
	 * @param ImageConverter       $converter Converter.
	 * @param array                $row       Variant row.
	 * @param OptimizationSettings $settings  Settings.
	 * @return \TrustOptimize\Value\OptimizeResult
	 */
	private function convert_row( ImageConverter $converter, array $row, OptimizationSettings $settings ) {
		$id = (int) $row['attachment_id'];

		if ( ! isset( $this->tokens[ $id ] ) ) {
			$this->tokens[ $id ] = $this->attachments->claim( $id, AttachmentRepository::SOURCE_SYNC );
		}

		return $converter->convert( $row, $settings, $this->tokens[ $id ] );
	}

	public function tear_down() {
		foreach ( glob( $this->dir . '/*' ) ?: array() as $file ) {
			unlink( $file );
		}
		rmdir( $this->dir );
		parent::tear_down();
	}

	private function settings( $webp_quality = 80 ) {
		return new OptimizationSettings( array( 'webp', 'avif' ), array( 'webp', 'avif' ), array( 'webp' => $webp_quality, 'avif' => 60 ) );
	}

	/**
	 * Create a source file with a fast-compressing gradient and a pending row for it.
	 */
	private function source( $name, $type = 'jpg', $attachment_id = 901, $format = 'webp', $size_name = 'original' ) {
		$path = $this->dir . '/' . $name;
		$im   = imagecreatetruecolor( 200, 120 );
		for ( $x = 0; $x < 200; $x += 10 ) {
			imagefilledrectangle( $im, $x, 0, $x + 9, 119, imagecolorallocate( $im, $x, 255 - $x, 90 ) );
		}
		'png' === $type ? imagepng( $im, $path, 0 ) : imagejpeg( $im, $path, 95 );

		$id = $this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => $size_name,
				'format'               => $format,
				'source_relative_path' => $this->relative_dir . '/' . $name,
			)
		);

		return $this->variants->get_for_attachment( $attachment_id )[ array_search( $id, array_column( $this->variants->get_for_attachment( $attachment_id ), 'id' ), true ) ];
	}

	public function test_converts_to_done_and_records_the_outcome() {
		$row = $this->source( 'photo.jpg' );

		$result = $this->convert_row( $this->converter, $row, $this->settings() );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$done = $this->variants->get_servable_for_attachment( 901 )[0];
		$this->assertSame( $this->relative_dir . '/photo.jpg.webp', $done['relative_path'] );
		$this->assertSame( 80, $done['quality'] );
		$this->assertGreaterThan( 0, $done['file_size'] );
		$this->assertGreaterThan( $done['file_size'], $done['source_file_size'] );
		$this->assertSame( hash_file( 'sha256', $this->dir . '/photo.jpg.webp' ), $done['file_hash'] );
		$this->assertSame( 200, $done['width'] );
	}

	/**
	 * Regression: same stem in one directory must not overwrite anything.
	 */
	public function test_same_stem_files_do_not_overwrite_each_other_or_originals() {
		$jpg  = $this->source( 'photo.jpg', 'jpg', 902 );
		$png  = $this->source( 'photo.png', 'png', 903 );
		$logo = $this->source( 'logo.png', 'png', 904 );
		file_put_contents( $this->dir . '/logo.webp', 'the editor made this logo.webp by hand' );
		$before = array_map( 'md5_file', glob( $this->dir . '/*' ) );

		$this->assertTrue( $this->convert_row( $this->converter, $jpg, $this->settings() )->is_success() );
		$this->assertTrue( $this->convert_row( $this->converter, $png, $this->settings() )->is_success() );
		$this->assertTrue( $this->convert_row( $this->converter, $logo, $this->settings() )->is_success() );

		$this->assertSame( 'the editor made this logo.webp by hand', file_get_contents( $this->dir . '/logo.webp' ) );
		$this->assertFileExists( $this->dir . '/photo.jpg.webp' );
		$this->assertFileExists( $this->dir . '/photo.png.webp' );
		$this->assertFileExists( $this->dir . '/logo.png.webp' );
		foreach ( array( 'photo.jpg', 'photo.png', 'logo.png' ) as $original ) {
			$this->assertContains( md5_file( $this->dir . '/' . $original ), $before, "{$original} changed" );
		}
	}

	public function test_variant_that_is_not_smaller_is_removed_and_skipped() {
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
				'attachment_id'        => 905,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/noise.jpg',
			)
		);
		$row = $this->variants->get_for_attachment( 905 )[0];

		$result = $this->convert_row( $this->converter, $row, $this->settings( 100 ) );

		$this->assertTrue( $result->is_skipped() );
		$after = $this->variants->get_for_attachment( 905 )[0];
		$this->assertSame( VariantStatus::SKIPPED, $after['status'] );
		$this->assertSame( 'not_smaller', $after['reason'] );
		$this->assertNull( $after['relative_path'] );
		$this->assertFileDoesNotExist( $this->dir . '/noise.jpg.webp' );
	}

	/**
	 * Editor and format pairs the runtime can write.
	 *
	 * @return array
	 */
	public static function editor_format_provider() {
		$cases = array();
		foreach ( array( 'WP_Image_Editor_GD', 'WP_Image_Editor_Imagick' ) as $editor ) {
			foreach ( array( 'webp', 'avif' ) as $format ) {
				$cases[ "$editor $format" ] = array( $editor, $format );
			}
		}

		return $cases;
	}

	/**
	 * Make the given editor the only one WordPress offers, or skip when it cannot write the format.
	 */
	private function use_editor( $editor, $format ) {
		if ( ! class_exists( $editor ) || ! $editor::test() || ! $editor::supports_mime_type( 'image/' . $format ) ) {
			$this->markTestSkipped( "$editor cannot write $format here." );
		}

		add_filter(
			'wp_image_editors',
			static function () use ( $editor ) {
				return array( $editor );
			}
		);
	}

	private function quality_settings( $format, $quality ) {
		return new OptimizationSettings( array( $format ), array( $format ), array( $format => $quality ) );
	}

	/**
	 * What the quality filter and a core save without an explicit quality give right now.
	 *
	 * @return array
	 */
	private function core_quality_behaviour() {
		$out = array();
		foreach ( array( 'image/webp', 'image/avif', 'image/jpeg' ) as $mime ) {
			$out[ $mime ] = apply_filters( 'wp_editor_set_quality', 55, $mime );
		}

		$source = $this->dir . '/core-source.jpg';
		$im     = imagecreatetruecolor( 200, 120 );
		imagejpeg( $im, $source, 95 );
		$editor = wp_get_image_editor( $source );
		$saved  = $editor->save( $this->dir . '/core-out.webp', 'image/webp' );
		$out['saved_quality'] = $editor->get_quality();
		$out['saved_hash']    = is_wp_error( $saved ) ? $saved->get_error_code() : md5_file( $saved['path'] );

		return $out;
	}

	/**
	 * @dataProvider editor_format_provider
	 */
	public function test_the_configured_quality_is_applied_to_the_file( $editor, $format ) {
		$this->use_editor( $editor, $format );

		$low  = $this->source( 'q30.jpg', 'jpg', 930, $format );
		$this->assertTrue( $this->convert_row( $this->converter, $low, $this->quality_settings( $format, 30 ) )->is_success() );
		$path    = $this->dir . '/q30.jpg.' . $format;
		$low_row = $this->variants->get_for_attachment( 930 )[0];
		$low_hash = md5_file( $path );

		$high = $this->source( 'q90.jpg', 'jpg', 931, $format );
		$result = $this->convert_row( $this->converter, $high, $this->quality_settings( $format, 90 ) );
		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$high_row = $this->variants->get_for_attachment( 931 )[0];

		$this->assertSame( 30, $low_row['quality'] );
		$this->assertNull( $low_row['reason'] );
		$this->assertSame( 90, $high_row['quality'] );
		$this->assertNull( $high_row['reason'] );
		$this->assertNotSame( $low_hash, md5_file( $this->dir . '/q90.jpg.' . $format ), 'Quality 30 and 90 must give different files.' );
		$this->assertLessThan( (int) $high_row['file_size'], (int) $low_row['file_size'], 'For this fixture the lower quality is smaller.' );
	}

	public function test_a_filtered_quality_is_encoded_and_recorded() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
		add_filter(
			'trust_optimize_webp_quality',
			static function () {
				return 40;
			}
		);
		$settings = new OptimizationSettings( array( 'webp' ), array( 'webp' ), array( 'webp' => 40 ) );

		$this->assertTrue( $this->convert_row( $this->converter, $this->source( 'f.jpg', 'jpg', 932 ), $settings )->is_success() );
		$forty = md5_file( $this->dir . '/f.jpg.webp' );
		$this->assertSame( 40, $this->variants->get_for_attachment( 932 )[0]['quality'] );

		$this->assertTrue( $this->convert_row( $this->converter, $this->source( 'f2.jpg', 'jpg', 933 ), $this->quality_settings( 'webp', 41 ) )->is_success() );
		$this->assertNotSame( $forty, md5_file( $this->dir . '/f2.jpg.webp' ) );
	}

	public function test_the_override_is_gone_after_a_conversion_and_third_party_callbacks_stay() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
		$default_cb = static function ( $quality ) {
			return $quality;
		};
		$late_cb    = static function ( $quality, $mime ) {
			return 'image/webp' === $mime ? 71 : $quality;
		};
		add_filter( 'wp_editor_set_quality', $default_cb, 10, 2 );
		add_filter( 'wp_editor_set_quality', $late_cb, PHP_INT_MAX, 2 );
		$before = $this->core_quality_behaviour();

		$this->assertTrue( $this->convert_row( $this->converter, $this->source( 'o.jpg', 'jpg', 934 ), $this->quality_settings( 'webp', 30 ) )->is_success() );

		$this->assertSame( 10, has_filter( 'wp_editor_set_quality', $default_cb ) );
		$this->assertSame( PHP_INT_MAX, has_filter( 'wp_editor_set_quality', $late_cb ) );
		$this->assertSame( $before, $this->core_quality_behaviour() );
	}

	public function test_the_override_is_gone_after_a_failed_save_and_an_exception() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
		$before = $this->core_quality_behaviour();

		$cases = array(
			array( 'TrustOptimizeTestErroringEditor', null, 935 ),
			array( 'TrustOptimizeTestThrowingEditor', 'boom', 936 ),
		);

		foreach ( $cases as list( $editor, $exception, $id ) ) {
			remove_all_filters( 'wp_image_editors' );
			add_filter(
				'wp_image_editors',
				static function () use ( $editor ) {
					return array( $editor );
				}
			);
			$row = $this->source( 'e' . $id . '.jpg', 'jpg', $id );

			if ( null === $exception ) {
				$this->assertTrue( $this->convert_row( $this->converter, $row, $this->quality_settings( 'webp', 30 ) )->is_failed() );
				$this->assertSame( 'save_failed', $this->variants->get_for_attachment( $id )[0]['reason'] );
			} else {
				try {
					$this->convert_row( $this->converter, $row, $this->quality_settings( 'webp', 30 ) );
					$this->fail( 'The exception must propagate.' );
				} catch ( RuntimeException $e ) {
					$this->assertSame( $exception, $e->getMessage() );
				}
			}

			remove_all_filters( 'wp_image_editors' );
			$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
			$this->assertSame( $before, $this->core_quality_behaviour(), "After $editor" );
		}
	}

	public function test_core_defaults_return_when_no_other_callback_is_registered() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
		remove_all_filters( 'wp_editor_set_quality' );

		$this->assertTrue( $this->convert_row( $this->converter, $this->source( 'd.jpg', 'jpg', 937 ), $this->quality_settings( 'webp', 30 ) )->is_success() );

		$source = $this->dir . '/d.jpg';
		foreach ( array( 'webp' => 86, 'avif' => 82 ) as $format => $default ) {
			$editor = wp_get_image_editor( $source );
			if ( ! $editor::supports_mime_type( 'image/' . $format ) ) {
				continue;
			}
			$editor->save( $this->dir . '/core.' . $format, 'image/' . $format );
			$this->assertSame( $default, $editor->get_quality(), "A core save of $format gets the core default." );
		}
	}

	public function test_the_override_passes_other_mime_types_through() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );

		// The spy only records the calls core makes; it never fires the hook it is attached to.
		$calls = array();
		$spy   = static function ( $quality, $mime ) use ( &$calls ) {
			$calls[] = $mime;
			return $quality;
		};
		add_filter( 'wp_editor_set_quality', $spy, 10, 2 );

		// GD fires image_save_progressive inside save(), after the output format was resolved, while the override is
		// in force: ask the quality filter from there, through another hook, for the target and for another MIME.
		$during = array();
		$probe  = static function ( $interlace, $mime ) use ( &$during ) {
			$during[ $mime ] = array(
				'target' => apply_filters( 'wp_editor_set_quality', 12, 'image/webp' ),
				'other'  => apply_filters( 'wp_editor_set_quality', 12, 'image/jpeg' ),
			);
			return $interlace;
		};
		add_filter( 'image_save_progressive', $probe, 10, 2 );

		$this->assertTrue( $this->convert_row( $this->converter, $this->source( 'm.jpg', 'jpg', 938 ), $this->quality_settings( 'webp', 30 ) )->is_success() );

		$this->assertContains( 'image/webp', $calls, 'Core resolved the quality for the target MIME during the save.' );
		$this->assertSame(
			array(
				'image/webp' => array(
					'target' => 30,
					'other'  => 12,
				),
			),
			$during,
			'Inside the save the override answers for the target MIME only.'
		);
	}

	public function test_an_editor_that_refuses_the_quality_fails_the_variant_and_frees_the_path() {
		$this->use_editor( 'WP_Image_Editor_GD', 'webp' );
		remove_all_filters( 'wp_image_editors' );
		add_filter(
			'wp_image_editors',
			static function () {
				return array( 'TrustOptimizeTestRefusingEditor' );
			}
		);
		$row = $this->source( 'r.jpg', 'jpg', 939 );

		$result = $this->convert_row( $this->converter, $row, $this->quality_settings( 'webp', 30 ) );

		$this->assertTrue( $result->is_failed() );
		$after = $this->variants->get_for_attachment( 939 )[0];
		$this->assertSame( VariantStatus::FAILED, $after['status'] );
		$this->assertSame( 'quality_failed', $after['reason'] );
		$this->assertNull( $after['relative_path'] );
		$this->assertFileDoesNotExist( $this->dir . '/r.jpg.webp' );
		$this->assertSame( 1234, apply_filters( 'wp_editor_set_quality', 1234, 'image/webp' ), 'No override is left behind.' );
	}

	public function test_a_deviating_editor_is_recorded_and_the_next_sync_has_nothing_to_do() {
		update_option( 'trust_optimize_options', array( 'convert_to_webp' => 1, 'convert_to_avif' => 0, 'webp_quality' => 30 ) );
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		// The upload hook has already planned and queued it: start from a clean slate.
		ConversionQueue::cancel_tasks_for_attachment( $id );
		$this->variants->delete_for_attachment( $id );
		$this->attachments->delete( $id );

		add_filter(
			'wp_image_editors',
			static function () {
				return array( 'TrustOptimizeTestDeviatingEditor' );
			}
		);

		$result = Plugin::get_instance()->processor->sync( $id );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$rows = $this->variants->get_for_attachment( $id );
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( VariantStatus::DONE, $row['status'], $row['size_name'] );
			$this->assertSame( 30, $row['quality'], 'The row keeps the requested quality.' );
			$this->assertSame( 'quality_applied:77', $row['reason'], 'The row says which quality the editor applied.' );
		}

		$this->assertSame( 'up_to_date', Plugin::get_instance()->processor->sync( $id )->get_message(), 'A deviation does not make the variant stale.' );
	}

	public function test_missing_source_fails_with_reason() {
		$row = $this->source( 'gone.jpg', 'jpg', 906 );
		unlink( $this->dir . '/gone.jpg' );

		$result = $this->convert_row( $this->converter, $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'missing_file', $this->variants->get_for_attachment( 906 )[0]['reason'] );
	}

	public function test_a_foreign_file_at_the_target_fails_without_touching_it() {
		$row = $this->source( 'photo.jpg', 'jpg', 907 );
		file_put_contents( $this->dir . '/photo.jpg.webp', 'foreign' );

		$result = $this->convert_row( $this->converter, $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'target_exists_foreign', $this->variants->get_for_attachment( 907 )[0]['reason'] );
		$this->assertSame( 'foreign', file_get_contents( $this->dir . '/photo.jpg.webp' ) );
		$this->assertNull( $this->variants->get_for_attachment( 907 )[0]['relative_path'], 'A foreign file is not claimed by reserving its path.' );
	}

	/**
	 * A converter whose writer runs a callback right after the file is saved.
	 *
	 * @param callable $after_save Called once the file is in place, before the row is updated.
	 * @param bool     $in_place   Whether the callback runs after the move into place; otherwise right after the temporary file is written.
	 */
	private function converter_acting_after_save( callable $after_save, $in_place = true ) {
		$writer = new class( new FileOwnership( $this->variants ), $after_save, $in_place ) extends AtomicImageWriter {
			private $after_save;
			private $in_place;

			public function __construct( FileOwnership $ownership, callable $after_save, $in_place ) {
				parent::__construct( $ownership );
				$this->after_save = $after_save;
				$this->in_place   = $in_place;
			}

			public function write_temporary( WP_Image_Editor $editor, $target_path, $mime ) {
				$saved = parent::write_temporary( $editor, $target_path, $mime );

				if ( ! $this->in_place ) {
					( $this->after_save )();
				}

				return $saved;
			}

			public function publish( array $saved, $target_path, $expected_hash = null ) {
				$published = parent::publish( $saved, $target_path, $expected_hash );

				if ( $this->in_place ) {
					( $this->after_save )();
				}

				return $published;
			}
		};

		return new ImageConverter( $this->variants, $writer, $this->capabilities, $this->attachments );
	}

	/**
	 * A converter that drops the given variant row right after the file is saved,
	 * as a cleanup running in another request would.
	 *
	 * @param int  $row_id   Row to delete.
	 * @param bool $in_place Whether the row goes after the move into place; otherwise before it.
	 */
	private function converter_losing_the_row( $row_id, $in_place = true ) {
		return $this->converter_acting_after_save(
			function () use ( $row_id ) {
				$this->variants->delete( $row_id );
			},
			$in_place
		);
	}

	public function test_a_variant_whose_row_was_removed_meanwhile_is_discarded() {
		$row = $this->source( 'photo.jpg', 'jpg', 910 );

		$result = $this->convert_row( $this->converter_losing_the_row( $row['id'] ), $row, $this->settings() );

		$this->assertTrue( $result->is_skipped(), wp_json_encode( $result->to_array() ) );
		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg.webp', 'No file is left without a row.' );
		$this->assertSame( array(), $this->variants->get_for_attachment( 910 ) );
	}

	public function test_a_file_another_row_owns_is_kept_when_the_row_was_removed_meanwhile() {
		$this->source( 'photo.jpg', 'jpg', 911 );
		file_put_contents( $this->dir . '/photo.jpg.webp', 'variant of the other attachment' );
		$recorded = array(
			'relative_path' => $this->relative_dir . '/photo.jpg.webp',
			'file_hash'     => hash( 'sha256', 'variant of the other attachment' ),
		);
		$other    = $this->variants->upsert(
			array(
				'attachment_id'        => 912,
				'size_name'            => 'original',
				'format'               => 'webp',
				'status'               => VariantStatus::DONE,
				'source_relative_path' => $this->relative_dir . '/photo.jpg',
			) + $recorded
		);
		// Duplicated media share one source and so one target: both rows recorded the same file.
		$this->variants->upsert(
			array(
				'attachment_id' => 911,
				'size_name'     => 'original',
				'format'        => 'webp',
			) + $recorded
		);
		$row = $this->variants->get_for_attachment( 911 )[0];

		$result = $this->convert_row( $this->converter_losing_the_row( $row['id'] ), $row, $this->settings() );

		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileExists( $this->dir . '/photo.jpg.webp', 'The other row still points at the file.' );
		$this->assertSame( array( $other ), array_column( $this->variants->get_for_attachment( 912 ), 'id' ) );
	}

	public function test_a_not_smaller_outcome_reports_the_row_gone_too() {
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
				'attachment_id'        => 913,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/noise.jpg',
			)
		);
		$row = $this->variants->get_for_attachment( 913 )[0];

		$result = $this->convert_row( $this->converter_losing_the_row( $row['id'], false ), $row, $this->settings( 100 ) );

		$this->assertSame( 'row_gone', $result->get_message() );
		$this->assertFileDoesNotExist( $this->dir . '/noise.jpg.webp' );
	}

	public function test_a_run_killed_after_the_rename_leaves_its_file_for_the_operator() {
		$row     = $this->source( 'photo.jpg', 'jpg', 920 );
		$dying   = $this->converter_acting_after_save(
			static function () {
				throw new RuntimeException( 'killed between the rename and the row update' );
			}
		);
		$target  = $this->dir . '/photo.jpg.webp';

		try {
			$this->convert_row( $dying, $row, $this->settings() );
			$this->fail( 'The run was expected to die.' );
		} catch ( RuntimeException $killed ) {
			$this->assertFileExists( $target );
		}

		$crashed = $this->variants->get_for_attachment( 920 )[0];
		$this->assertSame( VariantStatus::PROCESSING, $crashed['status'] );
		$this->assertSame( $this->relative_dir . '/photo.jpg.webp', $crashed['relative_path'], 'The path is reserved for the row.' );
		$this->assertSame( array(), $this->variants->get_servable_for_attachment( 920 ), 'A reserved path is not served.' );

		// The operator released the dead attempt; the next one resets the row it left in processing.
		$this->attachments->release_lease( 920, $this->tokens[920] );
		$this->attachments->finish( 920, $this->tokens[920], AttachmentState::NONE );
		unset( $this->tokens[920] );
		$this->variants->transition( $crashed['id'], VariantStatus::PROCESSING, VariantStatus::PENDING );
		$hash   = hash_file( 'sha256', $target );
		$result = $this->convert_row( $this->converter, $this->variants->get_for_attachment( 920 )[0], $this->settings() );

		$this->assertTrue( $result->is_failed(), 'The row never recorded a hash, so the file is not provably its own.' );
		$this->assertSame( 'target_exists_foreign', $this->variants->get_for_attachment( 920 )[0]['reason'] );
		$this->assertSame( $hash, hash_file( 'sha256', $target ), 'The file is kept.' );
	}

	public function test_a_finished_variant_is_replaced_when_its_file_is_what_the_row_recorded() {
		$row = $this->source( 'photo.jpg', 'jpg', 922 );
		$this->assertTrue( $this->convert_row( $this->converter, $row, $this->settings( 80 ) )->is_success() );

		// The planner resets a stale row to pending and keeps its path and hash.
		$done = $this->variants->get_for_attachment( 922 )[0];
		$this->variants->transition( $done['id'], VariantStatus::DONE, VariantStatus::PENDING );
		$result = $this->convert_row( $this->converter, $this->variants->get_for_attachment( 922 )[0], $this->settings( 30 ) );

		$this->assertTrue( $result->is_success(), wp_json_encode( $result->to_array() ) );
		$after = $this->variants->get_servable_for_attachment( 922 )[0];
		$this->assertSame( 30, $after['quality'] );
		$this->assertSame( hash_file( 'sha256', $this->dir . '/photo.jpg.webp' ), $after['file_hash'] );
	}

	public function test_a_file_changed_behind_the_row_is_kept_and_reported() {
		$row = $this->source( 'photo.jpg', 'jpg', 923 );
		$this->assertTrue( $this->convert_row( $this->converter, $row, $this->settings() )->is_success() );
		file_put_contents( $this->dir . '/photo.jpg.webp', 'edited by hand' );
		$done = $this->variants->get_for_attachment( 923 )[0];
		$this->variants->transition( $done['id'], VariantStatus::DONE, VariantStatus::PENDING );

		$result = $this->convert_row( $this->converter, $this->variants->get_for_attachment( 923 )[0], $this->settings( 30 ) );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'target_exists_foreign', $this->variants->get_for_attachment( 923 )[0]['reason'] );
		$this->assertSame( 'edited by hand', file_get_contents( $this->dir . '/photo.jpg.webp' ) );
	}

	public function test_a_failed_conversion_releases_the_path_it_reserved() {
		$row = $this->source( 'gone.jpg', 'jpg', 921 );
		unlink( $this->dir . '/gone.jpg' );

		$this->assertTrue( $this->convert_row( $this->converter, $row, $this->settings() )->is_failed() );

		$after = $this->variants->get_for_attachment( 921 )[0];
		$this->assertSame( VariantStatus::FAILED, $after['status'] );
		$this->assertNull( $after['relative_path'], 'A failed row must not keep claiming a path nothing was written to.' );
	}

	public function test_unsupported_format_downgrades_the_capability() {
		// No editor can write "image/zzz", so the capability must be downgraded.
		update_option( CapabilityService::OPTION, array( 'webp' => true, 'zzz' => true ) );
		$row = $this->source( 'photo.jpg', 'jpg', 908, 'zzz' );

		$result = $this->convert_row( $this->converter, $row, $this->settings() );

		$this->assertTrue( $result->is_failed() );
		$this->assertSame( 'unsupported_format', $this->variants->get_for_attachment( 908 )[0]['reason'] );
		$this->assertFalse( $this->capabilities->supports( 'zzz' ) );
		$this->assertTrue( $this->capabilities->supports( 'webp' ), 'Only the failing format is downgraded.' );
	}

	public function test_a_variant_that_is_not_pending_is_left_alone() {
		$row = $this->source( 'photo.jpg', 'jpg', 909 );
		$this->variants->transition( $row['id'], VariantStatus::PENDING, VariantStatus::PROCESSING );

		$result = $this->convert_row( $this->converter, $row, $this->settings() );

		$this->assertTrue( $result->is_skipped() );
		$this->assertFileDoesNotExist( $this->dir . '/photo.jpg.webp' );
	}

	public function test_attachment_metadata_is_not_modified() {
		$id       = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$before   = wp_get_attachment_metadata( $id );
		$relative = get_post_meta( $id, '_wp_attached_file', true );
		$this->variants->upsert(
			array(
				'attachment_id'        => $id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $relative,
			)
		);

		$this->assertTrue( $this->convert_row( $this->converter, $this->variants->get_for_attachment( $id )[0], $this->settings() )->is_success() );

		$this->assertSame( $before, wp_get_attachment_metadata( $id ) );
	}

	/**
	 * A noisy source that WebP cannot make smaller.
	 *
	 * @param string $name          File name.
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $row_fields    Further columns of the pending row.
	 * @return array The row.
	 */
	private function noisy_source( $name, $attachment_id, array $row_fields = array() ) {
		$im = imagecreatetruecolor( 64, 64 );
		for ( $x = 0; $x < 64; $x++ ) {
			for ( $y = 0; $y < 64; $y++ ) {
				imagesetpixel( $im, $x, $y, imagecolorallocate( $im, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		imagejpeg( $im, $this->dir . '/' . $name, 5 );
		$this->variants->upsert(
			array(
				'attachment_id'        => $attachment_id,
				'size_name'            => 'original',
				'format'               => 'webp',
				'source_relative_path' => $this->relative_dir . '/' . $name,
			) + $row_fields
		);

		return $this->variants->get_for_attachment( $attachment_id )[0];
	}

	public function test_a_reset_row_whose_output_is_not_smaller_removes_its_own_previous_file_under_the_lease() {
		file_put_contents( $this->dir . '/noise.jpg.webp', 'previous variant' );
		$row = $this->noisy_source(
			'noise.jpg',
			930,
			array(
				'relative_path' => $this->relative_dir . '/noise.jpg.webp',
				'file_hash'     => hash( 'sha256', 'previous variant' ),
			)
		);

		$result = $this->convert_row( $this->converter, $row, $this->settings( 100 ) );

		$this->assertSame( 'not_smaller', $result->get_message() );
		$this->assertFileDoesNotExist( $this->dir . '/noise.jpg.webp' );
		$after = $this->variants->get_for_attachment( 930 )[0];
		$this->assertSame( array( VariantStatus::SKIPPED, null ), array( $after['status'], $after['relative_path'] ) );
		$this->assertNull( $this->attachments->get( 930 )['publishing_at'], 'The lease is given back.' );
	}

	public function test_a_foreign_file_at_the_target_of_a_variant_that_is_not_smaller_is_kept_and_reported() {
		file_put_contents( $this->dir . '/noise.jpg.webp', 'foreign' );
		$row = $this->noisy_source( 'noise.jpg', 931 );

		$result = $this->convert_row( $this->converter, $row, $this->settings( 100 ) );

		$this->assertTrue( $result->is_failed() );
		$after = $this->variants->get_for_attachment( 931 )[0];
		$this->assertSame( array( VariantStatus::FAILED, 'target_exists_foreign' ), array( $after['status'], $after['reason'] ) );
		$this->assertSame( 'foreign', file_get_contents( $this->dir . '/noise.jpg.webp' ) );
	}
}
