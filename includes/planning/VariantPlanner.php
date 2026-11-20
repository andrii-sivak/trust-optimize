<?php
/**
 * Decides which variants an attachment should have and reconciles them with the stored rows.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Planning;

use TrustOptimize\Admin\Settings;
use TrustOptimize\Capabilities\CapabilityService;
use TrustOptimize\Domain\AttachmentState;
use TrustOptimize\Domain\VariantStatus;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Storage\AttachmentRepository;
use TrustOptimize\Storage\VariantRepository;
use TrustOptimize\Utils\UploadsPath;

/**
 * Class VariantPlanner
 */
class VariantPlanner {

	/**
	 * Source MIME types that are converted. WebP/AVIF sources get no variants.
	 *
	 * @var string[]
	 */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png' );

	/**
	 * Variant repository.
	 *
	 * @var VariantRepository
	 */
	private $variants;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepository
	 */
	private $attachments;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Capability service.
	 *
	 * @var CapabilityService
	 */
	private $capabilities;

	/**
	 * Constructor.
	 *
	 * @param VariantRepository    $variants     Variant repository.
	 * @param AttachmentRepository $attachments  Attachment repository.
	 * @param Settings             $settings     Plugin settings.
	 * @param CapabilityService    $capabilities Capability service.
	 */
	public function __construct( VariantRepository $variants, AttachmentRepository $attachments, Settings $settings, CapabilityService $capabilities ) {
		$this->variants     = $variants;
		$this->attachments  = $attachments;
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
	}

	/**
	 * Plan an attachment: store the rows it should have and report what to delete.
	 *
	 * New variants become pending, stale or failed ones are reset to pending, and rows
	 * of disabled formats or vanished sizes are returned for deletion. A variant that a
	 * worker is converting is left alone.
	 *
	 * Under a claim ($under_claim) the attempt owns the attachment row, so the planner writes no
	 * attachment state: a skip comes back in the plan and the attempt's closing write stores it.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param bool       $under_claim   Whether the caller holds the attachment's claim.
	 * @param array|null $metadata      Filter snapshot, or null to read saved metadata.
	 * @return Plan
	 */
	public function plan( $attachment_id, $under_claim = false, $metadata = null ) {
		$attachment_id = (int) $attachment_id;
		$existing      = $this->variants->get_for_attachment( $attachment_id );
		$settings      = OptimizationSettings::from_options( $this->settings, $this->capabilities );
		$source        = $this->eligible_source( $attachment_id, $metadata );

		if ( isset( $source['skip'] ) ) {
			if ( ! $under_claim ) {
				$this->attachments->set_state( $attachment_id, AttachmentState::SKIPPED, $source['skip'] );
			}

			if ( 'unknown_dimensions' === $source['skip'] ) {
				$existing = self::unknown_dimensions_deletions( $existing, $settings );
			}

			return new Plan( $source['skip'], array(), $existing, array(), $source['width'], $source['height'] );
		}

		$desired = self::with_source_sizes( self::desired_variants( $source['relative_path'], $source['metadata'], $settings->enabled_formats(), $source['mime'] ) );
		// A file that keeps crashing workers is not retried by itself; an explicit sync resets the count,
		// and so does a new source file: it is another file than the one that crashed.
		$retry = $this->attachments->get_attempts( $attachment_id ) < AttachmentRepository::MAX_ATTEMPTS;

		if ( ! $retry && self::failed_source_changed( $existing, $desired ) ) {
			$this->attachments->reset_attempts( $attachment_id );
			$retry = true;
		}

		$actions = self::reconcile( $existing, $desired, $settings, $retry );

		foreach ( $actions['insert'] as $row ) {
			$this->variants->upsert( $row + array( 'attachment_id' => $attachment_id ) );
		}

		foreach ( $actions['reset'] as $row ) {
			$this->variants->upsert(
				array(
					'attachment_id'        => $attachment_id,
					'size_name'            => $row['size_name'],
					'format'               => $row['format'],
					'status'               => VariantStatus::PENDING,
					'source_relative_path' => $row['source_relative_path'],
					'width'                => $row['width'],
					'height'               => $row['height'],
					'source_file_size'     => $row['source_file_size'],
					'reason'               => null,
				)
			);
		}

		$doomed  = array_column( $actions['delete'], 'id' );
		$pending = array_values(
			array_filter(
				$this->variants->get_for_attachment( $attachment_id ),
				static function ( $row ) use ( $doomed ) {
					return VariantStatus::PENDING === $row['status'] && ! in_array( $row['id'], $doomed, true );
				}
			)
		);

		// With work to do the state is set by whoever queues or claims the attachment.
		if ( ! $under_claim && empty( $pending ) ) {
			$this->attachments->recompute( $attachment_id, true );
		}

		return new Plan( null, $pending, $actions['delete'], $actions['replaced'] );
	}

	/**
	 * Variants an attachment should have.
	 *
	 * @param string   $original_relative_path Original (or -scaled) file relative to uploads.
	 * @param array    $metadata               Attachment metadata.
	 * @param string[] $formats                Formats the user enabled (including ones that cannot be created right now).
	 * @param string   $default_mime           MIME type assumed for sizes that do not state one.
	 * @return array[] Entries with size_name, format, source_relative_path, width, height.
	 */
	public static function desired_variants( $original_relative_path, array $metadata, array $formats, $default_mime = 'image/jpeg' ) {
		$sources = array(
			$original_relative_path => array(
				'size_name' => 'original',
				'width'     => (int) ( $metadata['width'] ?? 0 ),
				'height'    => (int) ( $metadata['height'] ?? 0 ),
			),
		);

		$directory = dirname( $original_relative_path );
		$directory = '.' === $directory ? '' : $directory . '/';

		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size_name => $size ) {
			if ( empty( $size['file'] ) || ! self::is_convertible_mime( $size['mime-type'] ?? $default_mime ) ) {
				continue;
			}

			$path = $directory . $size['file'];
			if ( isset( $sources[ $path ] ) ) {
				continue; // Several sizes may share one file.
			}

			$sources[ $path ] = array(
				'size_name' => (string) $size_name,
				'width'     => (int) ( $size['width'] ?? 0 ),
				'height'    => (int) ( $size['height'] ?? 0 ),
			);
		}

		$desired = array();
		foreach ( $sources as $path => $source ) {
			foreach ( $formats as $format ) {
				$desired[] = array(
					'size_name'            => $source['size_name'],
					'format'               => $format,
					'source_relative_path' => $path,
					'width'                => $source['width'],
					'height'               => $source['height'],
				);
			}
		}

		return $desired;
	}

	/**
	 * Add the current size of each source file, so that reconcile() can tell a replaced file from the same one.
	 *
	 * @param array[] $desired Output of desired_variants().
	 * @return array[] The same entries with source_file_size (0 when the file cannot be measured).
	 */
	private static function with_source_sizes( array $desired ) {
		$sizes = array();

		foreach ( $desired as &$want ) {
			$path = $want['source_relative_path'];

			if ( ! isset( $sizes[ $path ] ) ) {
				$absolute       = UploadsPath::absolute( $path );
				$sizes[ $path ] = null === $absolute ? 0 : (int) wp_filesize( $absolute );
			}

			$want['source_file_size'] = $sizes[ $path ];
		}

		return $desired;
	}

	/**
	 * Compare stored rows with the desired variants.
	 *
	 * A done or skipped row is stale when the settings changed or when the source file now has another
	 * size (replaced in place). An unknown size, on either side, never makes a row stale.
	 *
	 * @param array[]              $existing Stored rows.
	 * @param array[]              $desired  Output of desired_variants(), optionally with source_file_size.
	 * @param OptimizationSettings $settings     Current settings.
	 * @param bool                 $retry_failed Whether failed rows go back to pending.
	 * @return array{insert:array[],reset:array[],delete:array[],replaced:array[]}
	 */
	public static function reconcile( array $existing, array $desired, OptimizationSettings $settings, $retry_failed = true ) {
		$result = array(
			'insert'   => array(),
			'reset'    => array(),
			'delete'   => array(),
			'replaced' => array(),
		);

		$by_key = array();
		foreach ( $existing as $row ) {
			$by_key[ $row['size_name'] . '|' . $row['format'] ] = $row;
		}

		foreach ( $desired as $want ) {
			$key = $want['size_name'] . '|' . $want['format'];

			// Enabled but unsupported here: nothing new, and what exists stays as it is.
			if ( ! $settings->is_plannable( $want['format'] ) ) {
				unset( $by_key[ $key ] );
				continue;
			}

			if ( ! isset( $by_key[ $key ] ) ) {
				$result['insert'][] = $want;
				continue;
			}

			$row = $by_key[ $key ];
			unset( $by_key[ $key ] );

			// A worker is converting it: the next plan sees how that ended.
			if ( VariantStatus::PROCESSING === $row['status'] ) {
				continue;
			}

			if ( $row['source_relative_path'] !== $want['source_relative_path'] ) {
				$result['replaced'][] = $row;
				$result['reset'][]    = $want;
				continue;
			}

			$retry = $retry_failed && VariantStatus::FAILED === $row['status'];
			$stale = ( in_array( $row['status'], array( VariantStatus::DONE, VariantStatus::SKIPPED ), true ) && $settings->is_stale( $row ) )
				|| ( in_array( $row['status'], array( VariantStatus::DONE, VariantStatus::SKIPPED, VariantStatus::FAILED ), true ) && self::source_changed( $row, $want ) );

			if ( $retry || $stale ) {
				$result['reset'][] = $want;
			}
		}

		// What is left has no desired counterpart: format disabled by the user or size gone.
		$result['delete'] = array_values(
			array_filter(
				$by_key,
				static function ( $row ) {
					return VariantStatus::PROCESSING !== $row['status'];
				}
			)
		);

		return $result;
	}

	/**
	 * Whether a failed row was built from a source file that has another size now.
	 *
	 * @param array[] $existing Stored rows.
	 * @param array[] $desired  Output of desired_variants() with source_file_size.
	 * @return bool
	 */
	private static function failed_source_changed( array $existing, array $desired ) {
		$wanted = array();
		foreach ( $desired as $want ) {
			$wanted[ $want['size_name'] . '|' . $want['format'] ] = $want;
		}

		foreach ( $existing as $row ) {
			$want = $wanted[ $row['size_name'] . '|' . $row['format'] ] ?? null;

			if ( VariantStatus::FAILED === $row['status'] && null !== $want && $row['source_relative_path'] === $want['source_relative_path'] && self::source_changed( $row, $want ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the source file has another size than when the row was built.
	 *
	 * @param array $row  Stored row.
	 * @param array $want Desired variant.
	 * @return bool
	 */
	private static function source_changed( array $row, array $want ) {
		$built_from = (int) ( $row['source_file_size'] ?? 0 );
		$now        = (int) ( $want['source_file_size'] ?? 0 );

		return $built_from > 0 && $now > 0 && $built_from !== $now;
	}

	/**
	 * Whether a MIME type is converted.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private static function is_convertible_mime( $mime ) {
		return in_array( $mime, self::SOURCE_MIMES, true );
	}

	/**
	 * Source data of an attachment, or the reason it is not eligible.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Filter snapshot, or null to read saved metadata.
	 * @return array Eligible source, or skip, width and height when the attachment is not converted.
	 */
	private function eligible_source( $attachment_id, $metadata ) {
		$mime = get_post_mime_type( $attachment_id );
		if ( ! self::is_convertible_mime( $mime ) ) {
			return array(
				'skip'   => 'unsupported_mime',
				'width'  => null,
				'height' => null,
			);
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) ) {
			return array(
				'skip'   => 'missing_file',
				'width'  => null,
				'height' => null,
			);
		}

		$relative = UploadsPath::relative( $file );
		if ( null === $relative ) {
			return array(
				'skip'   => 'outside_uploads',
				'width'  => null,
				'height' => null,
			);
		}

		$metadata = null === $metadata ? wp_get_attachment_metadata( $attachment_id ) : $metadata;
		$inspect  = ImageLimits::inspect( $file, $metadata );

		if ( null !== $inspect['reason'] ) {
			return array(
				'skip'   => $inspect['reason'],
				'width'  => $inspect['width'],
				'height' => $inspect['height'],
			);
		}

		return array(
			'relative_path' => $relative,
			'metadata'      => is_array( $metadata ) ? $metadata : array(),
			'mime'          => $mime,
		);
	}

	/**
	 * Rows removed when dimensions cannot be established.
	 *
	 * A done variant that still matches its own source file and whose format is enabled stays:
	 * that source is not being replaced, only the attached file's size is unknown.
	 *
	 * @param array[]              $existing Stored rows.
	 * @param OptimizationSettings $settings Current settings.
	 * @return array[] Rows to delete.
	 */
	private static function unknown_dimensions_deletions( array $existing, OptimizationSettings $settings ) {
		$sizes = array();

		foreach ( $existing as $row ) {
			$path = $row['source_relative_path'];

			if ( ! isset( $sizes[ $path ] ) ) {
				$absolute       = UploadsPath::absolute( $path );
				$sizes[ $path ] = null === $absolute ? 0 : (int) wp_filesize( $absolute );
			}
		}

		return array_values(
			array_filter(
				$existing,
				static function ( $row ) use ( $settings, $sizes ) {
					if ( VariantStatus::DONE !== $row['status'] ) {
						return true;
					}

					if ( ! $settings->is_enabled( $row['format'] ) ) {
						return true;
					}

					return self::source_changed(
						$row,
						array(
							'source_file_size' => $sizes[ $row['source_relative_path'] ],
						)
					);
				}
			)
		);
	}
}
