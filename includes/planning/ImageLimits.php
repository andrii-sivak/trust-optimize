<?php
/**
 * Limits that keep oversized images away from the image editors.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Planning;

/**
 * Class ImageLimits
 *
 * Decides from declared dimensions, without decoding a bitmap.
 */
final class ImageLimits {

	/**
	 * Largest image, in pixels, when nothing else is configured.
	 */
	const DEFAULT_MAX_PIXELS = 50000000;

	/**
	 * Memory an editor needs per pixel of a decoded image (bytes, estimate).
	 */
	const BYTES_PER_PIXEL = 5;

	/**
	 * Share of the memory still available that one conversion may use.
	 */
	const MEMORY_SHARE = 0.6;

	/**
	 * Why an image of these dimensions must not be converted.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string|null "too_large", "insufficient_memory", "unknown_dimensions", or null when it may be converted.
	 */
	public static function skip_reason( $width, $height ) {
		$width  = (int) $width;
		$height = (int) $height;

		if ( $width < 1 || $height < 1 ) {
			return 'unknown_dimensions';
		}

		$pixels = $width * $height;

		/**
		 * Filters the largest image (width x height) that is converted.
		 *
		 * @param int $max_pixels Pixels.
		 */
		if ( $pixels > (int) apply_filters( 'trust_optimize_max_pixels', self::DEFAULT_MAX_PIXELS ) ) {
			return 'too_large';
		}

		$limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		if ( $limit > 0 && $pixels * self::BYTES_PER_PIXEL > ( $limit - memory_get_usage( true ) ) * self::MEMORY_SHARE ) {
			return 'insufficient_memory';
		}

		return null;
	}

	/**
	 * Why the attached file must not be converted, from metadata or a JPEG/PNG header.
	 *
	 * Two positive metadata sides are trusted. The header is read only when they are
	 * missing, and only for JPEG and PNG: other types would decode through an editor.
	 *
	 * @param string $file     Absolute path of the attached file.
	 * @param mixed  $metadata Attachment metadata as stored by WordPress.
	 * @return string|null Same values as skip_reason().
	 */
	public static function skip_reason_for_file( $file, $metadata ) {
		$metadata = is_array( $metadata ) ? $metadata : array();
		$width    = self::positive_side( $metadata['width'] ?? null );
		$height   = self::positive_side( $metadata['height'] ?? null );

		if ( null !== $width && null !== $height ) {
			return self::skip_reason( $width, $height );
		}

		if ( ! is_string( $file ) || '' === $file || ! is_file( $file ) ) {
			return 'unknown_dimensions';
		}

		$mime = wp_get_image_mime( $file );

		if ( 'image/jpeg' !== $mime && 'image/png' !== $mime ) {
			return 'unknown_dimensions';
		}

		$size = wp_getimagesize( $file );

		if ( ! is_array( $size ) ) {
			return 'unknown_dimensions';
		}

		return self::skip_reason( $size[0] ?? 0, $size[1] ?? 0 );
	}

	/**
	 * A declared side that can be used as a pixel count.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return int|null Null when the value is not an integer >= 1.
	 */
	private static function positive_side( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$side = (int) $value;

		return $side >= 1 ? $side : null;
	}
}
