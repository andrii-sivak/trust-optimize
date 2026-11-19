<?php
/**
 * Image Converter class
 *
 * @package TrustOptimize\Features\Optimization
 */

namespace TrustOptimize\Features\Optimization;

use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Files\AtomicImageWriter;
use TrustOptimize\Naming\VariantNaming;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;
use TrustOptimize\Value\OptimizeResult;

/**
 * Class ImageConverter
 *
 * Converts one pending variant row into an image file and records the outcome.
 */
class ImageConverter {

	/**
	 * Result message of a conversion that left the lease held: the file may be in place while its row is not done.
	 */
	const REASON_PUBLISH_INCOMPLETE = 'publish_incomplete';

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Atomic file writer.
	 *
	 * @var AtomicImageWriter
	 */
	private $writer;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Attachment repository (the publication lease lives on the attachment row).
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants     Variant repository.
	 * @param AtomicImageWriter    $writer       Atomic file writer.
	 * @param CapabilityService    $capabilities Capability service.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 */
	public function __construct( VariantRepository $variants, AtomicImageWriter $writer, CapabilityService $capabilities, AttachmentRepository $attachments ) {
		$this->variants     = $variants;
		$this->writer       = $writer;
		$this->capabilities = $capabilities;
		$this->attachments  = $attachments;
	}

	/**
	 * Convert one pending variant for the attempt that holds its attachment.
	 *
	 * The row moves pending -> processing -> done | skipped | failed, every step guarded by the attempt's
	 * token. A variant that is not smaller than its source is not kept. A format that no editor can write
	 * is downgraded in the capability service.
	 *
	 * The file is written to a temporary name first. Moving it into place replaces a file that may be served,
	 * so that step runs under the attempt's lease on the attachment row: a worker that lost its attempt (it was
	 * recovered as dead) cannot take the lease and publishes nothing.
	 *
	 * @param array                $variant_row Row from the variants table.
	 * @param OptimizationSettings $settings    Current settings (quality).
	 * @param string               $token       Token of the attempt that holds the attachment.
	 * @return OptimizeResult Success (done), skipped (reason in message; row_gone when the row was removed meanwhile, attempt_lost when the attempt no longer holds the attachment) or failed (reason in message; publish_incomplete when the file may be in place but its row is not done, which leaves the lease held). Data 'lease_kept' when the lease could not be released.
	 */
	public function convert( array $variant_row, OptimizationSettings $settings, $token ) {
		$id            = (int) $variant_row['id'];
		$attachment_id = (int) $variant_row['attachment_id'];
		$format        = (string) $variant_row['format'];
		$mime          = 'image/' . $format;

		$source_relative = (string) $variant_row['source_relative_path'];
		$target_relative = VariantNaming::target_relative_path( $source_relative, $format );
		$target_path     = UploadsPath::absolute( $target_relative );

		// The row claims its target path before anything is written, so the file this run is about to
		// create is attributed to the row. A file that no row owns is not ours to claim; the writer refuses it.
		$reserve = null !== $target_path && ( ! file_exists( $target_path ) || $this->variants->owns( $target_relative ) );
		$claimed = $reserve && empty( $variant_row['relative_path'] );

		// The file already at the target may be replaced only when it is what this row recorded.
		$owned_hash = ( $variant_row['relative_path'] ?? null ) === $target_relative && ! empty( $variant_row['file_hash'] ) ? $variant_row['file_hash'] : null;

		if ( ! $this->variants->transition( $id, VariantStatus::PENDING, VariantStatus::PROCESSING, $reserve ? array( 'relative_path' => $target_relative ) : array(), $token ) ) {
			return OptimizeResult::skipped( 'not_pending' );
		}

		$source_path = UploadsPath::absolute( $source_relative );

		if ( null === $source_path || ! is_file( $source_path ) ) {
			return $this->fail( $id, $token, 'missing_file', 'Source file is missing: ' . $source_relative, $claimed );
		}

		$source_size = (int) wp_filesize( $source_path );
		$quality     = $settings->quality_for( $format );
		$editor      = $this->get_editor_for_target_mime( $source_path, $mime );

		if ( is_wp_error( $editor ) ) {
			if ( 'trust_optimize_unsupported_target_mime' === $editor->get_error_code() ) {
				$this->capabilities->downgrade( $format, $editor->get_error_message() );

				return $this->fail( $id, $token, 'unsupported_format', $editor->get_error_message(), $claimed, $source_size );
			}

			return $this->fail( $id, $token, 'no_editor', $editor->get_error_message(), $claimed, $source_size );
		}

		// WordPress asks the editor for its own default quality when the output MIME differs from the source's, so the
		// explicit value is only the first half: a refusal here means the encoder would keep an older value.
		$quality_set = $editor->set_quality( $quality );

		if ( is_wp_error( $quality_set ) ) {
			return $this->fail( $id, $token, 'quality_failed', $quality_set->get_error_message(), $claimed, $source_size );
		}

		// A file at the target that is not what this row recorded is not ours: say so before spending the encoding on it.
		if ( null !== $target_path && file_exists( $target_path ) && ! $this->writer->owns_target( $target_path, $owned_hash ) ) {
			return $this->fail( $id, $token, 'target_exists_foreign', 'Target file exists and is not the TrustOptimize variant its row recorded.', $claimed, $source_size );
		}

		$saved = null === $target_path ? new \WP_Error( 'target_outside_uploads', 'Invalid target path.' ) : $this->save_with_quality( $editor, $target_path, $mime, $quality );

		if ( is_wp_error( $saved ) ) {
			return $this->fail( $id, $token, $this->failure_reason( $saved ), $saved->get_error_message(), $claimed, $source_size );
		}

		$file_size = (int) wp_filesize( $saved['path'] );

		if ( $file_size >= $source_size ) {
			$skipped = $this->variants->transition(
				$id,
				VariantStatus::PROCESSING,
				VariantStatus::SKIPPED,
				array(
					'relative_path'    => null,
					'file_hash'        => null,
					'quality'          => $quality,
					'file_size'        => $file_size,
					'source_file_size' => $source_size,
					'reason'           => 'not_smaller',
				),
				$token
			);
			wp_delete_file( $saved['path'] );

			if ( ! $skipped || ! file_exists( $target_path ) || ! $this->writer->owns_target( $target_path, $owned_hash ) ) {
				return OptimizeResult::skipped( $skipped ? 'not_smaller' : 'row_gone' );
			}

			// The row's previous file may be served until now: it goes only inside a publication that holds the attempt.
			$lease = $this->attachments->take_lease( $attachment_id, $token );

			if ( true !== $lease ) {
				return null === $lease ? OptimizeResult::skipped( 'not_smaller' ) : OptimizeResult::skipped( 'attempt_lost' );
			}

			$this->discard_unowned_file( $target_path, $target_relative );
			$data = $this->attachments->release_lease( $attachment_id, $token ) ? array() : array( 'lease_kept' => true );

			return OptimizeResult::skipped( 'not_smaller', $data );
		}

		$lease = $this->attachments->take_lease( $attachment_id, $token );

		if ( true !== $lease ) {
			wp_delete_file( $saved['path'] );

			if ( null === $lease ) {
				return $this->fail( $id, $token, 'publish_unsafe', 'The lease for replacing the file could not be taken.', $claimed, $source_size );
			}

			return OptimizeResult::skipped( 'attempt_lost' );
		}

		$saved = $this->writer->publish( $saved, $target_path, $owned_hash );

		if ( is_wp_error( $saved ) ) {
			return $this->fail( $id, $token, $this->failure_reason( $saved ), $saved->get_error_message(), $claimed, $source_size, $attachment_id );
		}

		$published = $this->variants->transition(
			$id,
			VariantStatus::PROCESSING,
			VariantStatus::DONE,
			array(
				'relative_path'    => $target_relative,
				'width'            => (int) ( $saved['width'] ?? 0 ),
				'height'           => (int) ( $saved['height'] ?? 0 ),
				'quality'          => $quality,
				'file_size'        => $file_size,
				'source_file_size' => $source_size,
				'file_hash'        => (string) hash_file( 'sha256', $saved['path'] ),
				'reason'           => null,
			),
			$token
		);

		// The file is in place but the database did not take the change: whoever looks at it later decides, so the lease stays.
		if ( null === $published ) {
			return OptimizeResult::failed( self::REASON_PUBLISH_INCOMPLETE, array( 'The file was written but its row could not be updated.' ) );
		}

		$data = $this->attachments->release_lease( $attachment_id, $token ) ? array() : array( 'lease_kept' => true );

		// The row was removed while the file was being written: nothing may point at the file now.
		if ( ! $published ) {
			$this->discard_unowned_file( $saved['path'], $target_relative );

			return OptimizeResult::skipped( 'row_gone', $data );
		}

		return OptimizeResult::success( 'done', array( 'relative_path' => $target_relative ) + $data );
	}

	/**
	 * Write the temporary file with the requested quality in force for the target MIME.
	 *
	 * Core resolves the quality again inside save() through wp_editor_set_quality, which replaces the value set on
	 * the editor with its default. The override lives only for this save, and only our own callback is removed.
	 *
	 * @param \WP_Image_Editor $editor      Editor holding the source.
	 * @param string           $target_path Absolute target path.
	 * @param string           $mime        Target MIME type.
	 * @param int              $quality     Requested quality.
	 * @return array|\WP_Error Writer result.
	 */
	private function save_with_quality( $editor, $target_path, $mime, $quality ) {
		$override = static function ( $value, $mime_type ) use ( $mime, $quality ) {
			return $mime_type === $mime ? $quality : $value;
		};

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		add_filter( 'wp_editor_set_quality', $override, PHP_INT_MAX, 2 );

		try {
			return $this->writer->write_temporary( $editor, $target_path, $mime );
		} finally {
			remove_filter( 'wp_editor_set_quality', $override, PHP_INT_MAX );
		}
	}

	/**
	 * Delete a file the converter has just written unless a variant row still points at it.
	 *
	 * @param string $path          Absolute path of the file.
	 * @param string $relative_path Path relative to uploads.
	 */
	private function discard_unowned_file( $path, $relative_path ) {
		if ( ! $this->variants->owns( $relative_path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Mark a variant failed.
	 *
	 * @param int      $id            Variant row id.
	 * @param string   $token         Token of the attempt.
	 * @param string   $reason        Machine-readable reason.
	 * @param string   $message       Human-readable detail.
	 * @param bool     $release       Whether to give up the path this run reserved for a row that had none.
	 * @param int|null $source_size   Size of the source file when it was measured, so a changed source is recognised later.
	 * @param int|null $lease_holder  Attachment ID whose publication lease this run holds and gives up now.
	 * @return OptimizeResult
	 */
	private function fail( $id, $token, $reason, $message, $release = false, $source_size = null, $lease_holder = null ) {
		$fields = array( 'reason' => $reason );

		if ( $release ) {
			$fields['relative_path'] = null;
		}

		if ( null !== $source_size ) {
			$fields['source_file_size'] = $source_size;
		}

		$this->variants->transition( $id, VariantStatus::PROCESSING, VariantStatus::FAILED, $fields, $token );

		$data = null !== $lease_holder && ! $this->attachments->release_lease( $lease_holder, $token ) ? array( 'lease_kept' => true ) : array();

		return OptimizeResult::failed( $reason, array( $message ), $data );
	}

	/**
	 * Reason stored for a writer error.
	 *
	 * @param \WP_Error $error Writer error.
	 * @return string
	 */
	private function failure_reason( \WP_Error $error ) {
		$known = array( 'target_exists_foreign', 'target_outside_uploads', 'unexpected_output', 'rename_failed' );

		return in_array( $error->get_error_code(), $known, true ) ? $error->get_error_code() : 'save_failed';
	}

	/**
	 * Get an image editor that supports the requested output mime type.
	 *
	 * @param string $source_path Source image path.
	 * @param string $target_mime Required output mime type.
	 * @return \WP_Image_Editor|\WP_Error
	 */
	private function get_editor_for_target_mime( $source_path, $target_mime ) {
		$this->load_image_editor_classes();

		$editor = wp_get_image_editor( $source_path );

		if ( is_wp_error( $editor ) ) {
			return $editor;
		}

		$editor_class = get_class( $editor );

		if ( is_callable( array( $editor_class, 'supports_mime_type' ) )
			&& call_user_func( array( $editor_class, 'supports_mime_type' ), $target_mime ) ) {
			return $editor;
		}

		$implementations = array_merge(
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
			(array) apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) ),
			array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' )
		);
		$implementations = array_values( array_unique( array_filter( $implementations ) ) );
		$claimed         = false;

		foreach ( $implementations as $implementation ) {
			if ( $implementation === $editor_class ) {
				continue;
			}

			if ( ! class_exists( $implementation ) ) {
				continue;
			}

			if ( ! is_callable( array( $implementation, 'test' ) ) || ! call_user_func( array( $implementation, 'test' ) ) ) {
				continue;
			}

			if ( ! is_callable( array( $implementation, 'supports_mime_type' ) ) || ! call_user_func( array( $implementation, 'supports_mime_type' ), $target_mime ) ) {
				continue;
			}

			$claimed   = true;
			$candidate = new $implementation( $source_path );
			$loaded    = $candidate->load();

			if ( ! is_wp_error( $loaded ) ) {
				return $candidate;
			}
		}

		// An editor claims the MIME type but could not load this file: that is not a missing format.
		if ( $claimed ) {
			return new \WP_Error(
				'trust_optimize_editor_load_failed',
				sprintf( 'An image editor for %s could not load the source file.', $target_mime )
			);
		}

		return new \WP_Error(
			'trust_optimize_unsupported_target_mime',
			sprintf( 'No available image editor supports target mime type: %s', $target_mime )
		);
	}

	/**
	 * Load bundled WordPress image editor classes before explicit fallback checks.
	 *
	 * The function wp_get_image_editor() may load only the selected implementation. When the
	 * selected editor cannot save a target MIME, fallback implementations such as
	 * GD must be loaded before class_exists()/supports_mime_type() checks.
	 *
	 * @return void
	 */
	private function load_image_editor_classes() {
		if ( defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-image-editor.php';
			require_once ABSPATH . 'wp-includes/class-wp-image-editor-gd.php';
			require_once ABSPATH . 'wp-includes/class-wp-image-editor-imagick.php';
		}
	}
}
