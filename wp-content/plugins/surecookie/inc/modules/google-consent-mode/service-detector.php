<?php
/**
 * Google Consent Mode - Service Detector
 *
 * Detects if the site uses Google services (GTM, GA, Ads) with multiple detection methods.
 * Checks: Playwright scanner results, enqueued scripts, and inline scripts.
 *
 * @package SureCookie
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Service_Detector class.
 *
 * Detects Google services on the site using multiple methods with transient caching.
 * Detection order:
 * 1. Playwright scanner results (most accurate - detects hardcoded, injected, etc.)
 * 2. WordPress enqueued scripts ($wp_scripts registry)
 * 3. Inline gtag() calls in page source
 *
 * @since 0.0.0-alpha.1
 */
class Service_Detector {
	use GetInstance;

	/**
	 * Transient key for caching detection results.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private const TRANSIENT_KEY = 'surecookie_gcm_services_detected';

	/**
	 * Cache duration (24 hours).
	 *
	 * @since 0.0.0-alpha.1
	 */
	private const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * Google service URL patterns to detect.
	 *
	 * @since 0.0.0-alpha.1
	 */
	private const GOOGLE_PATTERNS = [
		'googletagmanager.com/gtm.js',      // GTM container.
		'googletagmanager.com/gtag/js',     // GA4 via gtag.
		'google-analytics.com/analytics.js', // Legacy GA.
		'google-analytics.com/collect',      // GA tracking endpoint.
		'googleadservices.com',              // Google Ads conversion tracking.
		'googlesyndication.com',             // Google AdSense / Publisher ads.
	];

	/**
	 * Check if site has Google services.
	 *
	 * Uses multiple detection methods with caching.
	 * Returns cached result if available, otherwise detects and caches.
	 *
	 * @return bool True if Google services detected, false otherwise.
	 * @since 0.0.0-alpha.1
	 */
	public function has_google_services() {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( $cached !== false ) {
			return (bool) $cached;
		}

		$detected = $this->detect_services();
		set_transient( self::TRANSIENT_KEY, $detected, self::CACHE_DURATION );

		return $detected;
	}

	/**
	 * Clear cached detection results.
	 *
	 * Call this when settings change or scanner results update.
	 *
	 * @return bool True if cache cleared successfully.
	 * @since 0.0.0-alpha.1
	 */
	public function clear_cache() {
		return delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Detect Google services on the site.
	 *
	 * Uses multiple detection methods:
	 * 1. Playwright scanner results (if available)
	 * 2. WordPress enqueued scripts
	 * 3. Inline Google scripts
	 *
	 * @return bool True if any Google service detected.
	 * @since 0.0.0-alpha.1
	 */
	private function detect_services() {
		// Method 1: Check Playwright scanner results (most comprehensive).
		if ( $this->detect_from_scanner() ) {
			return true;
		}

		// Method 2: Check WordPress enqueued scripts (fallback for sites without scanner).
		if ( $this->detect_from_enqueued_scripts() ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect Google services from Playwright scanner results.
	 *
	 * Scanner detects scripts that are:
	 * - Hardcoded in theme/template files
	 * - Injected by page builders (Elementor, Divi, etc.)
	 * - Dynamically loaded via JavaScript
	 * - Embedded in custom code blocks
	 *
	 * @return bool True if Google services found in scanner data.
	 * @since 0.0.0-alpha.1
	 */
	private function detect_from_scanner() {
		// Get scan results if available.
		$scanned_resources = Get::option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [], 'array' );

		if ( empty( $scanned_resources ) ) {
			return false;
		}

		// Check scanned scripts for Google patterns.
		foreach ( (array) ( $scanned_resources['scripts'] ?? [] ) as $script ) {
			if ( ! is_array( $script ) ) {
				continue;
			}

			$src = $script['src'] ?? $script['url'] ?? '';
			if ( $this->matches_google_pattern( $src ) ) {
				return true;
			}
		}

		// Check scanned iframes for Google patterns.
		foreach ( (array) ( $scanned_resources['iframes'] ?? [] ) as $iframe ) {
			if ( ! is_array( $iframe ) ) {
				continue;
			}

			$src = $iframe['src'] ?? $iframe['url'] ?? '';
			if ( $this->matches_google_pattern( $src ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect Google services from WordPress enqueued scripts.
	 *
	 * Fallback method for sites that don't use the scanner yet.
	 *
	 * @return bool True if Google services found in $wp_scripts.
	 * @since 0.0.0-alpha.1
	 */
	private function detect_from_enqueued_scripts() {
		global $wp_scripts;

		if ( ! $wp_scripts instanceof \WP_Scripts ) {
			return false;
		}

		// Check enqueued scripts for Google patterns.
		foreach ( $wp_scripts->queue as $handle ) {
			$dep = $wp_scripts->registered[ $handle ] ?? null;
			if ( $dep === null || ! is_string( $dep->src ) ) {
				continue;
			}

			if ( $this->matches_google_pattern( $dep->src ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a URL matches any Google service pattern.
	 *
	 * @param string $url URL to check.
	 * @return bool True if URL matches a Google pattern.
	 * @since 0.0.0-alpha.1
	 */
	private function matches_google_pattern( $url ) {
		if ( empty( $url ) || ! is_string( $url ) ) {
			return false;
		}

		foreach ( self::GOOGLE_PATTERNS as $pattern ) {
			if ( stripos( $url, $pattern ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
