<?php
/**
 * JSONL writer for conversion attempts.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Diagnostics;

use DateTimeImmutable;
use DateTimeZone;
use stdClass;
use Throwable;
use TrustOptimize\Admin\Settings;
use TrustOptimize\Settings\OptimizationSettings;
use TrustOptimize\Utils\UploadsPath;
use TrustOptimize\Value\OptimizeResult;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Local JSONL writer: flock, fflush and chmod have no WP_Filesystem equivalent.

/**
 * Class ConversionDiagnostics
 *
 * One writer per site. Recording defaults on; the stored string "0" turns it off.
 * A recording error never leaves this class.
 */
class ConversionDiagnostics {

	const OPTION       = 'trust_optimize_diagnostics';
	const PRUNE_OPTION = 'trust_optimize_log_pruned_at';
	const DIRECTORY    = 'to-logs';
	const LINE_MAX     = 1280;
	const FILE_MAX     = 8388608;
	const SITE_MAX     = 536870912; // 512 MiB.
	const RETENTION    = 60;

	/**
	 * Settings reader for the environment event.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Job id of the current attachment, or null outside a bulk.
	 *
	 * @var int|null
	 */
	private $job_id = null;

	/**
	 * Attempt count of the current attachment.
	 *
	 * @var int
	 */
	private $attempt = 0;

	/**
	 * Attempt source of the current attachment (queue or sync).
	 *
	 * @var string|null
	 */
	private $attempt_source = null;

	/**
	 * Environment hash last written in this process, keyed by job id.
	 *
	 * @var string[]
	 */
	private $env_hash_by_job = array();

	/**
	 * Cached cgroup sample for this process: array, or false when unavailable.
	 *
	 * @var array|false|null
	 */
	private $cgroup_cache = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Age cleanup on admin loads, at most once an hour.
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'maybe_prune' ) );
	}

	/**
	 * Whether recording is on.
	 *
	 * A missing row and the string "1" are on. The stored string "0" is off.
	 * Any other stored value is off. A failure to read the option is off.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		try {
			$value = get_option( self::OPTION );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
			return false;
		}

		if ( false === $value ) {
			return true;
		}

		return '1' === $value;
	}

	/**
	 * Directory of this site's log files.
	 *
	 * @return string Empty when uploads cannot be resolved.
	 */
	public function directory() {
		$base = UploadsPath::basedir();

		return '' === $base ? '' : $base . '/' . self::DIRECTORY;
	}

	/**
	 * Remember job and attempt fields taken from the claimed attachment row.
	 *
	 * @param int|null    $job_id         Job id, or null outside a bulk.
	 * @param int         $attempt        Attempt count.
	 * @param string|null $attempt_source queue or sync.
	 */
	public function set_attempt_context( $job_id, $attempt, $attempt_source ) {
		try {
			$this->job_id         = null === $job_id || '' === $job_id ? null : (int) $job_id;
			$this->attempt        = (int) $attempt;
			$this->attempt_source = is_string( $attempt_source ) && '' !== $attempt_source ? $attempt_source : null;
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * Open a variant attempt. Generates conversion_id; writes nothing yet.
	 *
	 * @param array                $variant_row Variant row.
	 * @param OptimizationSettings $settings    Quality settings for this run.
	 * @return stdClass|null Session when recording is on.
	 */
	public function begin_variant( array $variant_row, OptimizationSettings $settings ) {
		try {
			if ( ! $this->is_enabled() ) {
				return null;
			}

			$session                       = new stdClass();
			$session->conversion_id        = bin2hex( random_bytes( 16 ) );
			$session->row                  = $variant_row;
			$session->settings             = $settings;
			$session->source_bytes         = null;
			$session->started              = false;
			$session->editor               = null;
			$session->quality_reported     = null;
			$session->output_bytes         = null;
			$session->result_reason        = null;
			$session->wall_load_ms         = null;
			$session->wall_encode_ms       = null;
			$session->cpu_load_user_ms     = null;
			$session->cpu_load_system_ms   = null;
			$session->cpu_encode_user_ms   = null;
			$session->cpu_encode_system_ms = null;
			$session->cpu                  = null;

			return $session;
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
			return null;
		}
	}

	/**
	 * Fresh conversion id for events that are not a variant start (planner skip).
	 *
	 * @return string 32 hex characters.
	 */
	public function conversion_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
			return '';
		}
	}

	/**
	 * Write the start line after the source file is known, before the editor opens.
	 *
	 * @param stdClass $session      Variant session.
	 * @param int      $source_bytes Size already measured with wp_filesize.
	 */
	public function record_start( stdClass $session, $source_bytes ) {
		try {
			$session->source_bytes = (int) $source_bytes;
			$session->started      = true;
			$this->maybe_write_environment();
			$this->write_event( $this->variant_start_payload( $session ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * Wall and CPU time of a phase (load or encode).
	 *
	 * @param stdClass $session Session.
	 * @param string   $phase   load or encode.
	 * @param callable $work    The call to time.
	 * @return mixed The call's return value.
	 */
	public function measure( stdClass $session, $phase, callable $work ) {
		$before = null;
		$start  = null;

		try {
			$before = $this->sample_rusage();
			$start  = hrtime( true );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}

		$result = $work();

		try {
			if ( null !== $start ) {
				$session->{"wall_{$phase}_ms"} = (int) round( ( hrtime( true ) - $start ) / 1e6 );
			}

			if ( null === $before ) {
				$session->cpu = 'unavailable';
				return $result;
			}

			$after = $this->sample_rusage();
			if ( null === $after ) {
				$session->cpu = 'unavailable';
				return $result;
			}

			$session->{"cpu_{$phase}_user_ms"}   = $this->rusage_delta_ms( $before, $after, 'utime' );
			$session->{"cpu_{$phase}_system_ms"} = $this->rusage_delta_ms( $before, $after, 'stime' );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}

		return $result;
	}

	/**
	 * Write the result line for this convert() return.
	 *
	 * @param stdClass       $session Variant session.
	 * @param OptimizeResult $result  Return value of convert().
	 */
	public function record_result( stdClass $session, OptimizeResult $result ) {
		try {
			$this->write_event( $this->variant_result_payload( $session, $result ) );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * One line for a planner skip that never opened an editor.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $reason        Skip reason already decided.
	 * @param int|null $source_w      Width the planner already had.
	 * @param int|null $source_h      Height the planner already had.
	 */
	public function record_attachment_skip( $attachment_id, $reason, $source_w, $source_h ) {
		try {
			if ( ! $this->is_enabled() ) {
				return;
			}

			$id = $this->conversion_id();
			if ( '' === $id ) {
				return;
			}

			$this->maybe_write_environment();
			$this->write_event(
				array(
					'v'              => 1,
					'event'          => 'attachment_skip',
					'ts'             => current_time( 'mysql', true ),
					'blog_id'        => get_current_blog_id(),
					'job_id'         => $this->job_id,
					'attachment_id'  => (int) $attachment_id,
					'attempt'        => $this->attempt,
					'attempt_source' => $this->attempt_source,
					'conversion_id'  => $id,
					'reason'         => (string) $reason,
					'source_w'       => $source_w,
					'source_h'       => $source_h,
					'source_bytes'   => null,
					'env'            => $this->current_env_hash(),
				)
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		}
	}

	/**
	 * Append one event. Public so a test can drive the writer without an editor.
	 *
	 * @param array $payload Event fields.
	 * @return bool True when the line was appended.
	 */
	public function write_event( array $payload ) {
		try {
			if ( ! $this->is_enabled() ) {
				return false;
			}

			return $this->append( $payload );
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
			return false;
		}
	}

	/**
	 * Delete JSONL files older than 60 days. Uses the same non-blocking lock as a write.
	 *
	 * @return bool True when the pass ran (lock acquired).
	 */
	public function prune_old_files() {
		$dir = $this->directory();
		if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
			return true;
		}

		$lock = $this->try_lock( $dir );
		if ( null === $lock ) {
			return false;
		}

		try {
			$cutoff = time() - ( self::RETENTION * \DAY_IN_SECONDS );
			foreach ( $this->jsonl_files( $dir ) as $path ) {
				$mtime = $this->file_mtime( $path );
				if ( false !== $mtime && $mtime < $cutoff ) {
					wp_delete_file( $path );
				}
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			unset( $throwable );
		} finally {
			$this->unlock( $lock );
		}

		return true;
	}

	/**
	 * Hourly age cleanup from admin_init for administrators.
	 */
	public function maybe_prune() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last = get_option( self::PRUNE_OPTION );
		if ( is_string( $last ) && strtotime( $last . ' UTC' ) > time() - \HOUR_IN_SECONDS ) {
			return;
		}

		if ( ! $this->prune_old_files() ) {
			return;
		}

		update_option( self::PRUNE_OPTION, current_time( 'mysql', true ), false );
	}

	/**
	 * Unique rotated file name for a UTC instant. Exposed for the same-second test.
	 *
	 * @param string $stem YYYYMMDDTHHMMSS.uuuuuuZ without extension.
	 * @param string $dir  Directory.
	 * @return string Absolute path ending in .jsonl.
	 */
	public function rotated_path( $stem, $dir ) {
		$path = $dir . '/' . $stem . '.jsonl';
		if ( ! file_exists( $path ) ) {
			return $path;
		}

		$n = 2;
		while ( file_exists( $dir . '/' . $stem . '-' . $n . '.jsonl' ) ) {
			++$n;
		}

		return $dir . '/' . $stem . '-' . $n . '.jsonl';
	}

	/**
	 * Append one encoded line under the lock.
	 *
	 * @param array $payload Event fields.
	 * @return bool True when the line was appended.
	 */
	protected function append( array $payload ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return false;
		}

		$line = $json . "\n";
		if ( strlen( $line ) > self::LINE_MAX ) {
			return false;
		}

		$dir = $this->ensure_directory();
		if ( '' === $dir ) {
			return false;
		}

		$lock = $this->try_lock( $dir );
		if ( null === $lock ) {
			return false;
		}

		try {
			$this->enforce_site_cap( $dir, strlen( $line ) );
			$path = $this->active_path( $dir );
			if ( is_link( $path ) ) {
				return false;
			}
			$size = $this->file_size( $path );
			if ( false !== $size && $size + strlen( $line ) > self::FILE_MAX ) {
				$rotated = $this->rotated_path( $this->rotation_stem(), $dir );
				if ( ! rename( $path, $rotated ) ) {
					return false;
				}
				$this->chmod_file( $rotated );
				$path = $this->active_path( $dir );
				$size = false;
			}

			$sum = $this->jsonl_bytes( $dir );
			if ( $sum + strlen( $line ) > self::SITE_MAX ) {
				return false;
			}

			$before = false === $size ? 0 : $size;
			$handle = fopen( $path, 'ab' );
			if ( false === $handle ) {
				return false;
			}

			$written = fwrite( $handle, $line );
			$ok      = false !== $written && strlen( $line ) === $written;
			$flushed = $ok ? fflush( $handle ) : false;
			if ( ! $ok || false === $flushed ) {
				ftruncate( $handle, $before );
				fflush( $handle );
				fclose( $handle );
				return false;
			}

			fclose( $handle );
			$this->chmod_file( $path );

			return true;
		} finally {
			$this->unlock( $lock );
		}
	}

	/**
	 * Sample this process's rusage, or null when getrusage is missing.
	 *
	 * @return array|null
	 */
	protected function sample_rusage() {
		if ( ! function_exists( 'getrusage' ) ) {
			return null;
		}

		$usage = defined( 'RUSAGE_SELF' ) ? getrusage( \RUSAGE_SELF ) : getrusage();

		return is_array( $usage ) ? $usage : null;
	}

	/**
	 * Cgroup memory.current and oom_kill for this process.
	 *
	 * Cache the directory. If the initial memory sample is unavailable, skip later probes.
	 *
	 * @return array{current:int,oom_kill:int|null}|null
	 */
	protected function sample_cgroup() {
		if ( false === $this->cgroup_cache ) {
			return null;
		}

		if ( is_array( $this->cgroup_cache ) && isset( $this->cgroup_cache['current'] ) ) {
			$fresh = $this->read_cgroup_numbers( $this->cgroup_cache['dir'] );
			return $fresh;
		}

		$dir = $this->cgroup_dir();
		if ( null === $dir ) {
			$this->cgroup_cache = false;
			return null;
		}

		$numbers = $this->read_cgroup_numbers( $dir );
		if ( null === $numbers ) {
			$this->cgroup_cache = false;
			return null;
		}

		$this->cgroup_cache = array(
			'dir'      => $dir,
			'current'  => $numbers['current'],
			'oom_kill' => $numbers['oom_kill'],
		);

		return $numbers;
	}

	/**
	 * Reason the cgroup could not be read: not_found or not_readable.
	 *
	 * @return string
	 */
	protected function cgroup_unavailable_reason() {
		$root = '/sys/fs/cgroup/memory.current';
		if ( ! file_exists( $root ) && ! $this->process_cgroup_path() ) {
			return 'not_found';
		}

		return 'not_readable';
	}

	/**
	 * Cgroup limit files for the environment event.
	 *
	 * @return array<string,string>|null
	 */
	protected function sample_cgroup_limits() {
		$dir = is_array( $this->cgroup_cache ) ? ( $this->cgroup_cache['dir'] ?? null ) : $this->cgroup_dir();
		if ( null === $dir ) {
			return null;
		}

		$memory = $this->read_sysfs( $dir . '/memory.max' );
		$swap   = $this->read_sysfs( $dir . '/memory.swap.max' );
		$cpu    = $this->read_sysfs( $dir . '/cpu.max' );

		if ( null === $memory && null === $swap && null === $cpu ) {
			return null;
		}

		return array(
			'memory_max' => null === $memory ? '' : trim( $memory ),
			'swap_max'   => null === $swap ? '' : trim( $swap ),
			'cpu_max'    => null === $cpu ? '' : trim( $cpu ),
		);
	}

	/**
	 * Write environment if this process has not yet, or the fingerprint changed.
	 */
	private function maybe_write_environment() {
		$payload = $this->environment_payload();
		$hash    = $this->fingerprint( $payload );
		$key     = null === $this->job_id ? '' : (string) $this->job_id;

		if ( ( $this->env_hash_by_job[ $key ] ?? null ) === $hash ) {
			return;
		}

		$payload['env'] = $hash;
		if ( $this->write_event( $payload ) ) {
			$this->env_hash_by_job[ $key ] = $hash;
		}
	}

	/**
	 * Hash last written for the current job, computing it when needed.
	 *
	 * @return string
	 */
	private function current_env_hash() {
		$key = null === $this->job_id ? '' : (string) $this->job_id;
		if ( isset( $this->env_hash_by_job[ $key ] ) ) {
			return $this->env_hash_by_job[ $key ];
		}

		return $this->fingerprint( $this->environment_payload() );
	}

	/**
	 * Fields of a variant_start line.
	 *
	 * @param stdClass $session Session.
	 * @return array
	 */
	private function variant_start_payload( stdClass $session ) {
		$row     = $session->row;
		$payload = array(
			'v'                 => 1,
			'event'             => 'variant_start',
			'ts'                => current_time( 'mysql', true ),
			'blog_id'           => get_current_blog_id(),
			'job_id'            => $this->job_id,
			'attachment_id'     => (int) $row['attachment_id'],
			'variant_id'        => (int) $row['id'],
			'attempt'           => $this->attempt,
			'attempt_source'    => $this->attempt_source,
			'conversion_id'     => $session->conversion_id,
			'size'              => (string) $row['size_name'],
			'format'            => (string) $row['format'],
			'source_w'          => $this->positive_or_null( $row['width'] ?? null ),
			'source_h'          => $this->positive_or_null( $row['height'] ?? null ),
			'source_bytes'      => $session->source_bytes,
			'quality_requested' => $session->settings->quality_for( (string) $row['format'] ),
		);

		return $this->with_memory_fields( $payload );
	}

	/**
	 * Fields of a variant_result line.
	 *
	 * @param stdClass       $session Session.
	 * @param OptimizeResult $result  convert() return.
	 * @return array
	 */
	private function variant_result_payload( stdClass $session, OptimizeResult $result ) {
		$row     = $session->row;
		$status  = $result->is_success() ? 'done' : ( $result->is_failed() ? 'failed' : 'skipped' );
		$reason  = $result->is_success() ? ( $session->result_reason ?? null ) : $result->get_message();
		$payload = array(
			'v'                 => 1,
			'event'             => 'variant_result',
			'ts'                => current_time( 'mysql', true ),
			'blog_id'           => get_current_blog_id(),
			'job_id'            => $this->job_id,
			'attachment_id'     => (int) $row['attachment_id'],
			'variant_id'        => (int) $row['id'],
			'attempt'           => $this->attempt,
			'attempt_source'    => $this->attempt_source,
			'conversion_id'     => $session->conversion_id,
			'size'              => (string) $row['size_name'],
			'format'            => (string) $row['format'],
			'status'            => $status,
			'reason'            => $reason,
			'editor'            => $session->editor,
			'quality_requested' => $session->settings->quality_for( (string) $row['format'] ),
			'quality_reported'  => $session->quality_reported,
			'output_bytes'      => $session->output_bytes,
			'wall_load_ms'      => $session->wall_load_ms,
			'wall_encode_ms'    => $session->wall_encode_ms,
			'wall_scope'        => 'process_wall_clock',
		);

		if ( 'unavailable' === $session->cpu ) {
			$payload['cpu'] = 'unavailable';
		} else {
			$payload['cpu_load_user_ms']     = $session->cpu_load_user_ms;
			$payload['cpu_load_system_ms']   = $session->cpu_load_system_ms;
			$payload['cpu_encode_user_ms']   = $session->cpu_encode_user_ms;
			$payload['cpu_encode_system_ms'] = $session->cpu_encode_system_ms;
			$payload['cpu_scope']            = 'rusage_self';
		}

		return $this->with_memory_fields( $payload );
	}

	/**
	 * PHP allocator and cgroup fields. Does not copy php_bytes into a cgroup field.
	 *
	 * @param array $payload Event.
	 * @return array
	 */
	private function with_memory_fields( array $payload ) {
		$payload['php_bytes']        = memory_get_usage( true );
		$payload['php_peak_bytes']   = memory_get_peak_usage( true );
		$payload['php_memory_scope'] = 'php_allocator_pages_excludes_native';

		$cgroup = $this->sample_cgroup();
		if ( null === $cgroup ) {
			$payload['cgroup']        = 'unavailable';
			$payload['cgroup_reason'] = $this->cgroup_unavailable_reason();
		} else {
			$payload['cgroup_memory_current_bytes'] = $cgroup['current'];
			$payload['cgroup_memory_scope']         = 'cgroup_including_neighbours';
			$payload['cgroup_oom_kill']             = null === $cgroup['oom_kill'] ? 'unavailable' : $cgroup['oom_kill'];
			$payload['cgroup_oom_scope']            = 'cgroup_counter';
			if ( null === $cgroup['oom_kill'] ) {
				$payload['cgroup_oom_reason'] = 'not_readable';
			}
		}

		$payload['env'] = $this->current_env_hash();

		return $payload;
	}

	/**
	 * Fields of an environment line.
	 *
	 * @return array
	 */
	private function environment_payload() {
		$editors = array_values(
			array_unique(
				array_filter(
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
					(array) apply_filters( 'wp_image_editors', array( 'WP_Image_Editor_Imagick', 'WP_Image_Editor_GD' ) )
				)
			)
		);

		$gd      = function_exists( 'gd_info' ) ? gd_info() : array();
		$payload = array(
			'v'                    => 1,
			'event'                => 'environment',
			'ts'                   => current_time( 'mysql', true ),
			'blog_id'              => get_current_blog_id(),
			'job_id'               => $this->job_id,
			'php'                  => \PHP_VERSION,
			'sapi'                 => \PHP_SAPI,
			'wp'                   => get_bloginfo( 'version' ),
			'memory_limit_bytes'   => wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ),
			'max_execution_time_s' => (int) ini_get( 'max_execution_time' ),
			'gd'                   => isset( $gd['GD Version'] ) ? (string) $gd['GD Version'] : 'unavailable',
			'gd_webp'              => ! empty( $gd['WebP Support'] ),
			'gd_avif'              => ! empty( $gd['AVIF Support'] ),
			'imagick_ext'          => phpversion( 'imagick' ) ? phpversion( 'imagick' ) : 'unavailable',
			'imagemagick'          => $this->imagemagick_version(),
			'editors'              => $editors,
			'convert_to_webp'      => (int) $this->settings->get( 'convert_to_webp' ),
			'convert_to_avif'      => (int) $this->settings->get( 'convert_to_avif' ),
			'webp_quality'         => (int) $this->settings->get( 'webp_quality' ),
			'avif_quality'         => (int) $this->settings->get( 'avif_quality' ),
		);

		$limits = $this->imagick_limits();
		if ( is_array( $limits ) ) {
			$payload['imagick_limits'] = $limits;
		} else {
			$payload['imagick_limits']        = 'unavailable';
			$payload['imagick_limits_reason'] = $limits;
		}

		$cgroup_limits = $this->sample_cgroup_limits();
		if ( null !== $cgroup_limits ) {
			$payload['cgroup_memory_max'] = $cgroup_limits['memory_max'];
			$payload['cgroup_swap_max']   = $cgroup_limits['swap_max'];
			$payload['cgroup_cpu_max']    = $cgroup_limits['cpu_max'];
			$payload['cgroup_scope']      = 'process_cgroup_limits';
		}

		return $payload;
	}

	/**
	 * Environment fingerprint stored on every line.
	 *
	 * @param array $payload Environment payload.
	 * @return string First 12 hex characters of sha256 of canonical JSON.
	 */
	private function fingerprint( array $payload ) {
		unset( $payload['ts'], $payload['job_id'], $payload['env'] );
		$this->ksort_recursive( $payload );
		$json = wp_json_encode( $payload );

		return substr( hash( 'sha256', is_string( $json ) ? $json : '' ), 0, 12 );
	}

	/**
	 * Sort array keys recursively for a stable hash.
	 *
	 * @param array $data Data.
	 */
	private function ksort_recursive( array &$data ) {
		ksort( $data );
		foreach ( $data as &$value ) {
			if ( is_array( $value ) ) {
				$this->ksort_recursive( $value );
			}
		}
	}

	/**
	 * ImageMagick version with URLs removed.
	 *
	 * @return string
	 */
	private function imagemagick_version() {
		if ( ! class_exists( 'Imagick' ) ) {
			return 'unavailable';
		}

		try {
			$info = \Imagick::getVersion();
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return 'unavailable';
		}

		$string = is_array( $info ) ? (string) ( $info['versionString'] ?? '' ) : '';
		$string = preg_replace( '#https?://\S+#i', '', $string );
		$string = trim( preg_replace( '/\s+/', ' ', (string) $string ) );

		return '' === $string ? 'unavailable' : $string;
	}

	/**
	 * Imagick resource limits without decoding an image.
	 *
	 * @return array<string,int>|string Map of limits, or a reason when unavailable.
	 */
	private function imagick_limits() {
		if ( ! class_exists( 'Imagick' ) ) {
			return 'not_found';
		}

		$types = array(
			'memory' => defined( 'Imagick::RESOURCETYPE_MEMORY' ) ? \Imagick::RESOURCETYPE_MEMORY : 1,
			'map'    => defined( 'Imagick::RESOURCETYPE_MAP' ) ? \Imagick::RESOURCETYPE_MAP : 2,
			'area'   => defined( 'Imagick::RESOURCETYPE_AREA' ) ? \Imagick::RESOURCETYPE_AREA : 3,
			'file'   => defined( 'Imagick::RESOURCETYPE_FILE' ) ? \Imagick::RESOURCETYPE_FILE : 4,
			'disk'   => defined( 'Imagick::RESOURCETYPE_DISK' ) ? \Imagick::RESOURCETYPE_DISK : 5,
			'thread' => defined( 'Imagick::RESOURCETYPE_THREAD' ) ? \Imagick::RESOURCETYPE_THREAD : 6,
		);

		$limits = array();
		try {
			foreach ( $types as $name => $type ) {
				$limits[ $name ] = (int) \Imagick::getResourceLimit( $type );
			}
		} catch ( Throwable $throwable ) {
			unset( $throwable );
			return 'not_readable';
		}

		return $limits;
	}

	/**
	 * Positive dimension, or null when the row has none yet.
	 *
	 * @param mixed $value Raw width or height.
	 * @return int|null
	 */
	private function positive_or_null( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$n = (int) $value;

		return $n >= 1 ? $n : null;
	}

	/**
	 * CPU milliseconds between two getrusage snapshots.
	 *
	 * @param array  $before Before rusage.
	 * @param array  $after  After rusage.
	 * @param string $which  utime or stime.
	 * @return int
	 */
	private function rusage_delta_ms( array $before, array $after, $which ) {
		$sec  = ( $after[ "ru_{$which}.tv_sec" ] ?? 0 ) - ( $before[ "ru_{$which}.tv_sec" ] ?? 0 );
		$usec = ( $after[ "ru_{$which}.tv_usec" ] ?? 0 ) - ( $before[ "ru_{$which}.tv_usec" ] ?? 0 );

		return (int) round( ( $sec * 1000000 + $usec ) / 1000 );
	}

	/**
	 * UTC stem for a rotated file name.
	 *
	 * @return string
	 */
	private function rotation_stem() {
		$now = DateTimeImmutable::createFromFormat( 'U.u', sprintf( '%.6F', microtime( true ) ) );
		if ( ! $now ) {
			$now = new DateTimeImmutable( 'now' );
		}

		return $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis.u' ) . 'Z';
	}

	/**
	 * Active JSONL path for the current UTC day.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private function active_path( $dir ) {
		return $dir . '/' . gmdate( 'Y-m-d' ) . '.jsonl';
	}

	/**
	 * Create to-logs and the deny files.
	 *
	 * @return string Directory, or empty on failure.
	 */
	private function ensure_directory() {
		$dir = $this->directory();
		if ( '' === $dir || is_link( $dir ) ) {
			return '';
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		if ( is_link( $dir ) || ! is_dir( $dir ) ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@chmod( $dir, 0750 );
		$this->write_guard( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		$this->write_guard(
			$dir . '/web.config',
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"
		);
		$this->write_guard( $dir . '/index.php', "<?php\nexit;\n" );

		return $dir;
	}

	/**
	 * Write a deny file when it is missing.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File body.
	 */
	private function write_guard( $path, $contents ) {
		if ( is_file( $path ) ) {
			return;
		}

		file_put_contents( $path, $contents );
		$this->chmod_file( $path );
	}

	/**
	 * Best-effort 0640 on a log file.
	 *
	 * @param string $path Absolute path.
	 */
	private function chmod_file( $path ) {
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@chmod( $path, 0640 );
		}
	}

	/**
	 * Non-blocking exclusive lock. Null when the lock is busy or cannot be created.
	 *
	 * @param string $dir Directory.
	 * @return resource|null
	 */
	private function try_lock( $dir ) {
		$path = $dir . '/write.lock';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$handle = @fopen( $path, 'cb+' );
		if ( false === $handle ) {
			return null;
		}

		if ( ! flock( $handle, \LOCK_EX | \LOCK_NB ) ) {
			fclose( $handle );
			return null;
		}

		$this->chmod_file( $path );

		return $handle;
	}

	/**
	 * Release write.lock.
	 *
	 * @param resource $handle Lock handle.
	 */
	private function unlock( $handle ) {
		flock( $handle, \LOCK_UN );
		fclose( $handle );
	}

	/**
	 * Delete oldest rotated JSONL files until $needed more bytes fit under the site cap.
	 *
	 * @param string $dir    Directory.
	 * @param int    $needed Bytes about to be appended.
	 */
	private function enforce_site_cap( $dir, $needed ) {
		$active = $this->active_path( $dir );
		while ( $this->jsonl_bytes( $dir ) + $needed > self::SITE_MAX ) {
			$oldest = $this->oldest_rotated( $dir, $active );
			if ( null === $oldest ) {
				return;
			}
			wp_delete_file( $oldest );
			clearstatcache( true, $oldest );
			if ( file_exists( $oldest ) ) {
				return;
			}
		}
	}

	/**
	 * Oldest JSONL file that is not the active file this write has open.
	 *
	 * @param string $dir    Directory.
	 * @param string $active Active file, never deleted here.
	 * @return string|null
	 */
	private function oldest_rotated( $dir, $active ) {
		$oldest = null;
		$mtime  = null;
		foreach ( $this->jsonl_files( $dir ) as $path ) {
			if ( $path === $active ) {
				continue;
			}
			$time = $this->file_mtime( $path );
			if ( false === $time ) {
				continue;
			}
			if ( null === $mtime || $time < $mtime ) {
				$mtime  = $time;
				$oldest = $path;
			}
		}

		return $oldest;
	}

	/**
	 * Total size of *.jsonl in the directory.
	 *
	 * @param string $dir Directory.
	 * @return int
	 */
	private function jsonl_bytes( $dir ) {
		$total = 0;
		foreach ( $this->jsonl_files( $dir ) as $path ) {
			$size   = $this->file_size( $path );
			$total += false === $size ? 0 : $size;
		}

		return $total;
	}

	/**
	 * Size of a real (non-symlink) file, after clearing the stat cache for that path.
	 *
	 * @param string $path Absolute path.
	 * @return int|false
	 */
	private function file_size( $path ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}

		return filesize( $path );
	}

	/**
	 * Modification time of a real (non-symlink) file, after clearing the stat cache for that path.
	 *
	 * @param string $path Absolute path.
	 * @return int|false
	 */
	private function file_mtime( $path ) {
		clearstatcache( true, $path );
		if ( is_link( $path ) || ! is_file( $path ) ) {
			return false;
		}

		return filemtime( $path );
	}

	/**
	 * JSONL files in the directory.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private function jsonl_files( $dir ) {
		$files = glob( $dir . '/*.jsonl' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$files,
				static function ( $path ) {
					return is_file( $path ) && ! is_link( $path );
				}
			)
		);
	}

	/**
	 * Directory that holds this process's cgroup files, or null.
	 *
	 * @return string|null
	 */
	protected function cgroup_dir() {
		$relative = $this->process_cgroup_path();
		if ( null !== $relative ) {
			$dir = '/sys/fs/cgroup' . $relative;
			if ( is_readable( $dir . '/memory.current' ) ) {
				return $dir;
			}
		}

		if ( is_readable( '/sys/fs/cgroup/memory.current' ) ) {
			return '/sys/fs/cgroup';
		}

		return null;
	}

	/**
	 * Relative cgroup path from /proc/self/cgroup (v2), or null.
	 *
	 * @return string|null
	 */
	private function process_cgroup_path() {
		if ( ! is_readable( '/proc/self/cgroup' ) ) {
			return null;
		}

		$lines = file( '/proc/self/cgroup', \FILE_IGNORE_NEW_LINES );
		if ( ! is_array( $lines ) ) {
			return null;
		}

		foreach ( $lines as $line ) {
			if ( preg_match( '/^0::(.*)$/', $line, $match ) ) {
				return $match[1];
			}
		}

		return null;
	}

	/**
	 * Current memory and oom_kill from a cgroup directory.
	 *
	 * @param string $dir Cgroup directory.
	 * @return array{current:int,oom_kill:int|null}|null
	 */
	private function read_cgroup_numbers( $dir ) {
		$current = $this->read_sysfs( $dir . '/memory.current' );
		if ( null === $current || ! is_numeric( trim( $current ) ) ) {
			return null;
		}

		$oom = null;
		try {
			$events = $this->read_sysfs( $dir . '/memory.events' );
		} catch ( Throwable $throwable ) {
			// A host error handler can throw even for a suppressed read warning.
			$events = null;
		}
		if ( is_string( $events ) && preg_match( '/^oom_kill[ \t]+([0-9]+)[ \t]*$/m', $events, $match ) ) {
			$digits = ltrim( $match[1], '0' );
			$digits = '' === $digits ? '0' : $digits;
			// A counter beyond PHP's integer range must not become a saturated value.
			if ( (string) (int) $digits === $digits ) {
				$oom = (int) $digits;
			}
		}

		return array(
			'current'  => (int) trim( $current ),
			'oom_kill' => $oom,
		);
	}

	/**
	 * Read a small sysfs file.
	 *
	 * @param string $path Absolute sysfs path.
	 * @return string|null
	 */
	private function read_sysfs( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = @file_get_contents( $path );

		return false === $data ? null : $data;
	}
}
