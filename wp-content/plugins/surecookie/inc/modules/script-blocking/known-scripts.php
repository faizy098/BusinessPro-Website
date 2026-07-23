<?php
/**
 * Known Scripts Database.
 *
 * Fetches and caches known third-party scripts from remote API.
 *
 * @package SureCookie\Inc\Modules\ScriptBlocking
 * @since 0.0.1
 */

namespace SureCookie\Inc\Modules\ScriptBlocking;

use SureCookie\Inc\Functions\Cache;
use SureCookie\Inc\Functions\Helper;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Known_Scripts
 *
 * Manages the database of known third-party scripts that should be blocked.
 *
 * @since 0.0.1
 */
class Known_Scripts {
	use GetInstance;

	/**
	 * Remote API URL for script data.
	 */
	protected const REMOTE_FILE_PATH = 'dataset/blocking-scripts.json';

	/**
	 * Transient key for cached data.
	 */
	protected const CACHE_KEY = 'surecookie_known_scripts';

	/**
	 * File cache path.
	 */
	protected const CACHE_FILE = 'script-blocking/known-scripts.json';

	/**
	 * Bundled baseline dataset shipped with the plugin. Guarantees blocking has
	 * patterns to match even when the remote fetch has never succeeded (offline,
	 * sandboxed, API down, or a brand-new install). The remote list still takes
	 * precedence when reachable; this is only the floor so blocking never no-ops.
	 */
	protected const BUNDLED_FILE = 'inc/modules/script-blocking/data/blocking-scripts.json';

	/**
	 * Cache duration in seconds (24 hours).
	 */
	protected const CACHE_DURATION = DAY_IN_SECONDS;

	/**
	 * File cache duration in seconds (7 days).
	 */
	protected const FILE_CACHE_DURATION = WEEK_IN_SECONDS;

	/**
	 * Cached scripts data.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $scripts = [];

	/**
	 * Constructor.
	 *
	 * @since 0.0.1
	 */
	private function __construct() {
		$this->load_data();
	}

	/**
	 * Load scripts data from cache or remote API.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function load_data(): void {
		// 1. Try transient cache first; it already holds the merged dataset, so
		// the hot path returns without reading the bundled file from disk.
		$cached_data = get_transient( self::CACHE_KEY );

		if ( $cached_data !== false && is_array( $cached_data ) && ! empty( $cached_data ) ) {
			$this->scripts = $cached_data;
			return;
		}

		// Bundled baseline is the guaranteed floor; remote/cache data is merged
		// over it (remote wins) so blocking is never left with an empty dataset.
		$bundled = $this->get_bundled_data();

		// 2. Try file cache next; merge over the bundled baseline.
		$file_cached_data = $this->get_file_cache();

		if ( $file_cached_data !== null ) {
			$this->scripts = $this->merge_datasets( $bundled, $file_cached_data );
			set_transient( self::CACHE_KEY, $this->scripts, self::CACHE_DURATION );
			return;
		}

		// 3. Fetch from remote API. On success merge over the baseline and cache
		// it; on failure fall back to the bundled baseline so blocking still runs.
		$api_data = $this->fetch_from_api();

		if ( $api_data !== null ) {
			$this->scripts = $this->merge_datasets( $bundled, $api_data );
			$this->set_file_cache( $this->scripts );
		} else {
			$this->scripts = $bundled;
		}

		// Cache in transient for request-time performance.
		set_transient( self::CACHE_KEY, $this->scripts, self::CACHE_DURATION );
	}

	/**
	 * Load the plugin-bundled baseline dataset.
	 *
	 * @since 1.2.3
	 * @return array<string, array<string, mixed>>
	 */
	public function get_bundled_data(): array {
		$path = SURECOOKIE_DIR . self::BUNDLED_FILE;

		if ( ! file_exists( $path ) ) {
			return [];
		}

		$data = wp_json_file_decode( $path, [ 'associative' => true ] );

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Get all known scripts grouped by category.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_scripts(): array {
		/**
		 * Filter the known scripts database.
		 *
		 * @since 0.0.1
		 * @param array<string, array<string, mixed>> $scripts Known scripts grouped by category.
		 */
		return apply_filters( 'surecookie_known_scripts', $this->scripts );
	}

	/**
	 * Get scripts for a specific category.
	 *
	 * @param string $category Category name (e.g., 'analytics', 'marketing').
	 * @since 0.0.1
	 * @return array<string, mixed>
	 */
	public function get_scripts_for_category( string $category ): array {
		$all_scripts = $this->get_all_scripts();
		return $all_scripts[ $category ] ?? [];
	}

	/**
	 * Clear the cached scripts data.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
		Cache::delete_file( self::CACHE_FILE );
		// Fall back to the bundled baseline (not empty) so blocking keeps running
		// until the next request repopulates from cache/remote.
		$this->scripts = $this->get_bundled_data();
	}

	/**
	 * Merge two datasets, category by category, with `$override` winning per
	 * service so remote/cached data stays authoritative while the bundled
	 * baseline fills any categories/services the override does not include.
	 *
	 * @param array<string, array<string, mixed>> $base     Baseline dataset (bundled).
	 * @param array<string, array<string, mixed>> $override Authoritative dataset (remote/cache).
	 * @since 1.2.3
	 * @return array<string, array<string, mixed>>
	 */
	private function merge_datasets( array $base, array $override ): array {
		foreach ( $override as $category => $services ) {
			if ( ! is_array( $services ) || ! isset( $base[ $category ] ) || ! is_array( $base[ $category ] ) ) {
				$base[ $category ] = $services;
				continue;
			}

			$base[ $category ] = array_merge( $base[ $category ], $services );
		}

		return $base;
	}

	/**
	 * Retrieve scripts from file cache.
	 *
	 * @since 0.0.1
	 * @return array<string, array<string, mixed>>|null
	 */
	private function get_file_cache(): ?array {
		$cache_raw = Cache::get_file( self::CACHE_FILE );

		if ( ! is_string( $cache_raw ) || $cache_raw === '' ) {
			return null;
		}

		$decoded = json_decode( $cache_raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return null;
		}

		if ( ! isset( $decoded['timestamp'] ) || ! is_int( $decoded['timestamp'] ) ) {
			return null;
		}

		$is_expired = time() - $decoded['timestamp'] > self::FILE_CACHE_DURATION;
		if ( $is_expired ) {
			return null;
		}

		return $decoded['data'];
	}

	/**
	 * Store scripts in file cache.
	 *
	 * @param array<string, array<string, mixed>> $scripts Scripts data.
	 * @since 0.0.1
	 * @return void
	 */
	private function set_file_cache( array $scripts ): void {
		$payload = wp_json_encode(
			[
				'timestamp' => time(),
				'data'      => $scripts,
			]
		);

		if ( ! is_string( $payload ) ) {
			return;
		}

		$stored = Cache::store_file( self::CACHE_FILE, $payload );

		if ( ! $stored ) {
			Logger::get_instance()->log( 'Unable to write known scripts file cache.' );
		}
	}

	/**
	 * Fetch data from remote API.
	 *
	 * @since 0.0.1
	 * @return array<string, mixed>|null Data on success, null on failure.
	 */
	private function fetch_from_api(): ?array {
		$response = wp_remote_get(
			Helper::get_agent_app_url() . self::REMOTE_FILE_PATH,
			[
				'timeout' => 10,
				'headers' => [
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			Logger::get_instance()->log( 'Known scripts API request failed: ' . $response->get_error_message() );
			return null;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			Logger::get_instance()->log( 'Known scripts API returned status: ' . $response_code );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			Logger::get_instance()->log( 'Known scripts API returned invalid JSON.' );
			return null;
		}

		return $data;
	}
}
