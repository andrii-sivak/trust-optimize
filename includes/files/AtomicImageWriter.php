<?php
/**
 * Atomic, collision-safe writing of generated image files.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Files;

use TrustOptimize\Utils\UploadsPath;
use WP_Error;
use WP_Image_Editor;

/**
 * Class AtomicImageWriter
 */
class AtomicImageWriter {

	/**
	 * File ownership checks.
	 *
	 * @var FileOwnership
	 */
	private $ownership;

	/**
	 * Constructor.
	 *
	 * @param FileOwnership $ownership File ownership checks.
	 */
	public function __construct( FileOwnership $ownership ) {
		$this->ownership = $ownership;
	}

	/**
	 * Write an editor's image to a temporary file next to its target.
	 *
	 * The temporary name ends with the target extension: WP_Image_Editor::get_output_format()
	 * replaces an extension that does not match the MIME type.
	 *
	 * @param WP_Image_Editor $editor      Editor holding the image.
	 * @param string          $target_path Absolute target path inside uploads.
	 * @param string          $mime        Expected MIME type of the output.
	 * @return array|WP_Error Editor result whose 'path' is the temporary file; hand it to publish() or delete it.
	 */
	public function write_temporary( WP_Image_Editor $editor, $target_path, $mime ) {
		$target_path = wp_normalize_path( $target_path );

		if ( ! UploadsPath::is_inside( $target_path ) ) {
			return new WP_Error( 'target_outside_uploads', 'Target path is outside the uploads directory.' );
		}

		$temp_path = $target_path . '.tmp-' . wp_generate_uuid4() . '.' . pathinfo( $target_path, PATHINFO_EXTENSION );
		$saved     = $editor->save( $temp_path, $mime );

		if ( is_wp_error( $saved ) ) {
			$this->discard( $temp_path );
			return $saved;
		}

		$saved_path = isset( $saved['path'] ) ? wp_normalize_path( $saved['path'] ) : $temp_path;

		if ( ( $saved['mime-type'] ?? '' ) !== $mime || ! file_exists( $saved_path ) ) {
			$this->discard( $temp_path );
			$this->discard( $saved_path );
			return new WP_Error(
				'unexpected_output',
				sprintf( 'Expected %s output, got %s.', $mime, $saved['mime-type'] ?? 'nothing' )
			);
		}

		$saved['path'] = $saved_path;

		return $saved;
	}

	/**
	 * Whether the file at a target path is the one a variant row recorded, so it may be replaced or deleted.
	 *
	 * The file must be byte for byte what the row stored when it last finished, and no attachment may have
	 * registered it as its original. A row that never finished has no hash and owns nothing.
	 *
	 * @param string      $target_path   Absolute target path inside uploads.
	 * @param string|null $expected_hash SHA-256 the variant row recorded for the file, null when it has none.
	 * @return bool
	 */
	public function owns_target( $target_path, $expected_hash ) {
		$target_path = wp_normalize_path( $target_path );

		if ( empty( $expected_hash ) || ! UploadsPath::is_inside( $target_path ) || ! is_file( $target_path ) ) {
			return false;
		}

		$relative = ltrim( substr( $target_path, strlen( UploadsPath::basedir() ) ), '/' );

		return hash_file( 'sha256', $target_path ) === $expected_hash && ! $this->ownership->is_attachment_file( $relative );
	}

	/**
	 * Move a temporary file into place, replacing the file at the target when the variant row owns it.
	 *
	 * @param array       $saved         Result of write_temporary().
	 * @param string      $target_path   Absolute target path inside uploads.
	 * @param string|null $expected_hash SHA-256 the variant row recorded for the file at the target, null when it has none.
	 * @return array|WP_Error The result with 'path' and 'file' pointing at the target. On failure the temporary file is deleted.
	 */
	public function publish( array $saved, $target_path, $expected_hash = null ) {
		$target_path = wp_normalize_path( $target_path );

		if ( file_exists( $target_path ) && ! $this->owns_target( $target_path, $expected_hash ) ) {
			$this->discard( $saved['path'] );
			return new WP_Error( 'target_exists_foreign', 'Target file exists and is not the TrustOptimize variant its row recorded.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace; WP_Filesystem has no equivalent.
		if ( ! rename( $saved['path'], $target_path ) ) {
			$this->discard( $saved['path'] );
			return new WP_Error( 'rename_failed', 'Could not move the generated file into place.' );
		}

		$saved['path'] = $target_path;
		$saved['file'] = basename( $target_path );

		return $saved;
	}

	/**
	 * Remove a temporary file if it exists.
	 *
	 * @param string $path Absolute path.
	 */
	private function discard( $path ) {
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
