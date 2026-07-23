<?php
/**
 * IpManager.
 *
 * @package SureCookie\Inc\Traits;
 * @since 0.0.1
 */

namespace SureCookie\Inc\Traits;

use SureCookie\Inc\Functions\Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * IpManager
 *
 * @since 0.0.1
 */
trait IpManager {
	/**
	 * Static cache for country data to avoid duplicate API calls
	 *
	 * @var array<string, array{code: string, name: string}>|null
	 */
	private static ?array $country_cache = null;

	/**
	 * Get country from IP address using Laravel MaxMind API
	 *
	 * @param string $ip The IP address to get country for.
	 * @return string The country name (like 'India', 'United States', 'France').
	 * @since 0.0.1
	 */
	public static function get_country_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return $data['code'];
	}

	/**
	 * Get country name from IP address for consent logs and display
	 *
	 * @param string $ip The IP address to get country for.
	 * @return string The country name (like 'India', 'United States', 'France').
	 * @since 0.0.1
	 */
	public static function get_country_name_from_ip( string $ip ): string {
		$data = self::get_country_data( $ip );
		return $data['name'];
	}

	/**
	 * Anonymize an IP address using WordPress core's privacy function.
	 *
	 * IPv4: Zeros the last octet (e.g., 192.168.1.123 -> 192.168.1.0).
	 * IPv6: Zeros the last 64 bits via inet_pton/inet_ntop.
	 * Handles dual-stack (::ffff:0.0.1.x) and returns safe defaults for malformed IPs.
	 *
	 * @param string $ip The IP address to anonymize.
	 * @since 0.0.1
	 * @return string The anonymized IP address.
	 */
	public static function anonymize_ip( string $ip ): string {
		return wp_privacy_anonymize_ip( $ip );
	}

	/**
	 * Get country data from IP address - returns both code and name with caching
	 *
	 * @param string $ip The IP address to get country for.
	 * @return array{code: string, name: string} Country data array with code and name.
	 * @since 0.0.1
	 */
	private static function get_country_data( string $ip ): array {
		// Return in-memory cache if available (same PHP request only).
		if ( isset( self::$country_cache[ $ip ] ) ) {
			return self::$country_cache[ $ip ];
		}

		// Check persistent geo cache (single transient array - only 2 rows in wp_options, capped at 500 entries).
		$geo_cache = get_transient( 'surecookie_geo_cache' );
		if ( ! is_array( $geo_cache ) ) {
			$geo_cache = [];
		}

		// Use a salted hash as the cache key to avoid storing raw IPs in the DB (GDPR / privacy compliance).
		$ip_hash = self::hash_ip( $ip );

		if ( isset( $geo_cache[ $ip_hash ] ) ) {
			self::$country_cache[ $ip ] = $geo_cache[ $ip_hash ];
			return $geo_cache[ $ip_hash ];
		}

		// Return Localhost for local IPs.
		if ( self::is_local_ip( $ip ) ) {
			$data                       = [
				'code' => 'Localhost',
				'name' => 'Localhost',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		// Return special codes for private IPs.
		if ( self::is_private_ip( $ip ) ) {
			$data                       = [
				'code' => 'XX',
				'name' => 'Private Network',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		// Use raw IP for geolocation accuracy (anonymized .0 addresses may not resolve correctly).
		// This is SureCookie's own Agent API - the IP is used transiently for lookup, not stored.
		// Privacy is enforced at the storage layer (consent-logs.php anonymizes before DB insert).
		$api_url = Helper::get_agent_app_url() . 'api/geolocation/country';
		$url     = add_query_arg( 'ip', $ip, $api_url );

		// Make GET request.
		$response = wp_remote_get( $url, [ 'timeout' => 5 ] );

		// Handle request errors.
		if ( is_wp_error( $response ) ) {
			$data                       = [
				'code' => 'Unknown',
				'name' => 'Unknown',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code !== 200 ) {
			$data                       = [
				'code' => 'Unknown',
				'name' => 'Unknown',
			];
			self::$country_cache[ $ip ] = $data;
			return $data;
		}

		// Parse response data.
		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract country code and name with proper fallbacks.
		$country_code = $response_data['country_code'] ?? 'Unknown';
		$country_name = $response_data['country_name'] ?? $country_code;

		$data = [
			'code' => $country_code,
			'name' => $country_name,
		];

		// Cache in memory for this request.
		self::$country_cache[ $ip ] = $data;

		// Persist to single transient (cap at 500 entries to prevent unbounded growth).
		$geo_cache[ $ip_hash ] = $data;
		if ( count( $geo_cache ) > 500 ) {
			$geo_cache = array_slice( $geo_cache, -250, null, true );
		}
		set_transient( 'surecookie_geo_cache', $geo_cache, DAY_IN_SECONDS );

		return $data;
	}

	/**
	 * Hash an IP address for use as a privacy-safe cache key.
	 *
	 * Uses wp_hash() (HMAC with site-specific AUTH_KEY salt) so the hash
	 * cannot be reversed via rainbow tables, unlike plain md5/sha256.
	 *
	 * @param string $ip The IP address to hash.
	 * @return string A salted, irreversible hash of the IP.
	 * @since 0.0.1
	 */
	private static function hash_ip( string $ip ): string {
		return wp_hash( $ip );
	}

	/**
	 * Check if IP is localhost.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if private/localhost.
	 * @since 0.0.1
	 */
	private static function is_local_ip( string $ip ): bool {
		if ( $ip === '127.0.0.1' || $ip === '::1' ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if IP is private.
	 *
	 * @param string $ip IP address to check.
	 * @return bool True if private/localhost.
	 * @since 0.0.1
	 */
	private static function is_private_ip( string $ip ): bool {
		return strpos( $ip, '192.168.' ) === 0
			|| strpos( $ip, '10.' ) === 0
			|| ( strpos( $ip, '172.' ) === 0 && (bool) preg_match( '/^172\.(1[6-9]|2[0-9]|3[01])\./', $ip ) );
	}

	/**
	 * Get client's IP address.
	 *
	 * Uses REMOTE_ADDR by default (secure, cannot be spoofed).
	 * Only trusts proxy headers when explicitly enabled via filter.
	 *
	 * @since 0.0.1
	 * @return string Client IP address.
	 */
	private static function get_client_ip(): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '127.0.0.1';

		/**
		 * Filter: Enable trusting proxy headers (X-Forwarded-For, CF-Connecting-IP, etc.).
		 *
		 * Only enable this if your site is behind a trusted reverse proxy or CDN.
		 *
		 * @param bool $trust Whether to trust proxy headers. Default false.
		 * @since 0.0.1
		 */
		$trust_proxy = (bool) apply_filters( 'surecookie_trust_proxy_headers', false );

		if ( ! $trust_proxy ) {
			return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '127.0.0.1';
		}

		// When proxy trust is enabled, check proxy headers in priority order.
		$proxy_keys = [
			'HTTP_CF_CONNECTING_IP', // CloudFlare.
			'HTTP_X_FORWARDED_FOR',  // Proxy.
			'HTTP_X_REAL_IP',        // Nginx proxy.
		];

		foreach ( $proxy_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );

				// Handle comma-separated IPs (from proxies) - take first (client IP).
				if ( strpos( $ip, ',' ) !== false ) {
					$ips = explode( ',', $ip );
					$ip  = trim( $ips[0] );
				}

				// Validate IP and reject private/reserved ranges from proxy headers.
				if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					return $ip;
				}
			}
		}

		// Fallback to REMOTE_ADDR.
		return filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '127.0.0.1';
	}

	/**
	 * Check if the given URL is a localhost or development site.
	 *
	 * @param string $url The site URL to check.
	 * @since 0.0.1
	 * @return bool True if localhost, false otherwise.
	 */
	private static function is_local_url( string $url ): bool {
		// Allow local scanning via wp-config.php constant for development/testing.
		if ( defined( 'SURECOOKIE_ALLOW_LOCAL_SCAN' ) && SURECOOKIE_ALLOW_LOCAL_SCAN ) {
			return false;
		}

		if ( apply_filters( 'surecookie_bypass_local_url_check', false ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || ! is_string( $host ) ) {
			return false;
		}

		// Normalize: lowercase and strip IPv6 brackets so "[::1]" matches "::1".
		$host = strtolower( trim( $host, '[]' ) );

		// IP-literal hosts are local only when loopback (127.0.0.0/8, ::1) or in
		// a private range. Anchored so public domains that merely contain "10."
		// or "192.168." (e.g. "web10.example.com") are not misclassified.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// inet_pton() normalizes every IPv6 loopback form (::1 and the full
			// 0:0:0:0:0:0:0:1) to the same packed bytes; 127.0.0.0/8 covers
			// IPv4 loopback.
			return inet_pton( $host ) === inet_pton( '::1' )
				|| strpos( $host, '127.' ) === 0
				|| self::is_private_ip( $host );
		}

		// Hostnames: exactly "localhost", or a dev-only TLD suffix. Suffix-anchored
		// so "localhostingpros.com" and "shop.localfoods.com" are not misclassified.
		if ( $host === 'localhost' ) {
			return true;
		}

		foreach ( [ '.localhost', '.local' ] as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
