<?php
/**
 * Utils Script Blocking.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Utils
 *
 * @since 0.0.1
 */
class Utils {
	/**
	 * Check whether script and content blocking feature is enabled.
	 *
	 * @since 0.0.1
	 * @return bool
	 */
	public static function is_blocking_enabled(): bool {
		static $cached = null;

		if ( $cached !== null ) {
			return $cached;
		}

		$banner_enabled  = (bool) Settings::get( 'banner_enabled' );
		$feature_enabled = (bool) Settings::get( 'blocking_enabled' );
		$status          = $banner_enabled && $feature_enabled;

		$cached = (bool) apply_filters( 'surecookie_is_blocking_enabled', $status );

		return $cached;
	}

	/**
	 * Check if blocking should be processed based on geo-location rules.
	 *
	 * @since 0.0.1
	 * @return bool True if blocking should proceed, false to bypass.
	 */
	public static function should_process_based_on_geo(): bool {
		return (bool) apply_filters( 'surecookie_should_process_blocking_geo', true );
	}
}
