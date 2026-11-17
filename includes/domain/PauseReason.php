<?php
/**
 * Bulk job pause reason constants.
 *
 * @package TrustOptimize
 */

namespace TrustOptimize\Domain;

/**
 * Why a bulk job is paused (jobs.pause_reason, set only while the status is paused).
 */
final class PauseReason {

	const USER           = 'user';
	const DEACTIVATED    = 'deactivated';
	const LOW_DISK_SPACE = 'low_disk_space';
	const STOPPED        = 'stopped';
	const UNKNOWN        = 'unknown';

	/**
	 * Maximum length of the pause_reason column.
	 */
	const COLUMN_LENGTH = 32;

	/**
	 * All reasons.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array( self::USER, self::DEACTIVATED, self::LOW_DISK_SPACE, self::STOPPED, self::UNKNOWN );
	}
}
