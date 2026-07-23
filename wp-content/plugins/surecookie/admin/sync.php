<?php
/**
 * Admin Sync
 *
 * Handles processing and storage of cookie scan results from SaaS API.
 *
 * @since 0.0.1
 * @package SureCookie
 */

namespace SureCookie\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

/**
 * Admin Sync
 *
 * @since 0.0.1
 */
class Sync {
	use GetInstance;

	/**
	 * Category mapping from SaaS tracking categories to plugin categories.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const CATEGORY_MAP = [
		'analytics'    => 'analytics',
		'advertising'  => 'marketing',
		'social'       => 'marketing',
		'social_media' => 'marketing',
		'video'        => 'marketing',
		'functional'   => 'functional',
		'payment'      => 'functional',
		'consent'      => 'essential',
	];

	/**
	 * Maximum number of scan-detected resources to store.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const MAX_RESOURCES = 500;

	/**
	 * Constructor - Hook into SaaS API results.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public function __construct() {
		add_action( 'surecookie_saas_scan_results_received', [ $this, 'process_saas_results' ], 10, 1 );
	}

	/**
	 * Process results from SaaS API scan.
	 *
	 * Stores cookies directly from the SaaS API response, grouped by category.
	 *
	 * @param array<string, mixed> $data The scan results from SaaS API.
	 * @since 0.0.1
	 * @return void
	 */
	public function process_saas_results( array $data ): void {
		Logger::get_instance()->save_log( 'Processing scanned results...' );

		// Extract pages data from API response.
		$pages = $data['pages'] ?? [];

		if ( empty( $pages ) ) {
			Logger::get_instance()->save_log( 'No pages found in scan results.' );
			$this->store_all_cookies_from_agent_app( [] );
			return;
		}

		// Group cookies by category and deduplicate.
		$cookies_by_category = $this->group_cookies_by_category( $pages );

		$cookies_count = $this->get_cookies_count( $cookies_by_category );
		Logger::get_instance()->save_log( '' ); // Blank line.
		Logger::get_instance()->save_log( sprintf( 'Found %d unique cookies in this scan.', $cookies_count ) );

		// Store cookies (merges with existing).
		$this->store_all_cookies_from_agent_app( $cookies_by_category );

		// Analytics: flag first scan completed for state detection on next admin load.
		if ( ! get_option( 'surecookie_first_scan_completed_flag', false ) ) {
			update_option( 'surecookie_first_scan_completed_flag', true, false );
			update_option( 'surecookie_first_scan_pages_scanned', count( $pages ), false );
		}

		// Rating-notice milestone: timestamp the first scan that actually discovered cookies.
		// Read by Rating_Notice to decide whether to prompt for a WordPress.org review.
		if ( $cookies_count > 0 && ! get_option( SURECOOKIE_FIRST_SUCCESSFUL_SCAN_OPTION, false ) ) {
			update_option( SURECOOKIE_FIRST_SUCCESSFUL_SCAN_OPTION, time(), false );
		}

		// Process and store scan-detected scripts and iframes (graceful fallback for older SaaS).
		$this->process_scanned_resources( $pages );

		/**
		 * Fires after a scan's results have been processed and persisted.
		 *
		 * Automatic Scanning (change detection / diff) subscribes to this to
		 * compare this scan's reported set against the previous scan and record
		 * a scan-history entry. Cookies are merged "sticky" into the option (a
		 * cookie absent from this scan is never auto-removed), so the diff must
		 * be computed from this reported set - not the accumulated option.
		 *
		 * Not fired when a scan returns no pages (failed/empty), to avoid
		 * mistaking an empty result for "everything removed".
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, array<int, array<string, mixed>>> $cookies_by_category This scan's reported cookies, grouped by category and deduped by signature_id.
		 * @param array<string, mixed>                            $context             Scan context: cookies_count, scan_type, scanned_at, pages_scanned, domains.
		 */
		do_action(
			'surecookie_scan_completed',
			$cookies_by_category,
			[
				'cookies_count' => $cookies_count,
				'scan_type'     => 'saas',
				'scanned_at'    => current_time( 'mysql' ),
				'pages_scanned' => count( $pages ),
				'domains'       => $this->extract_third_party_domains( $pages ),
			]
		);
	}

	/**
	 * Extract the unique third-party script/iframe domains reported in this scan.
	 *
	 * Used by the scan-completed diff to surface newly-introduced tracker domains.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 1.2.0
	 * @return array<int, string> Unique third-party domains.
	 */
	private function extract_third_party_domains( array $pages ): array {
		$domains = [];

		foreach ( $pages as $page ) {
			foreach ( $page['scripts'] ?? [] as $script ) {
				if ( ! empty( $script['is_third_party'] ) && ! empty( $script['domain'] ) ) {
					$domains[ sanitize_text_field( $script['domain'] ) ] = true;
				}
			}

			foreach ( $page['iframes'] ?? [] as $iframe ) {
				if ( ! empty( $iframe['is_third_party'] ) && ! empty( $iframe['domain'] ) ) {
					$domains[ sanitize_text_field( $iframe['domain'] ) ] = true;
				}
			}
		}

		return array_keys( $domains );
	}

	/**
	 * Process and store scan-detected scripts and iframes.
	 *
	 * Extracts third-party scripts/iframes from scan results, maps categories,
	 * deduplicates by domain, and stores for the blocking engine.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan result pages.
	 * @since 0.0.0-alpha.2
	 * @return void
	 */
	private function process_scanned_resources( array $pages ): void {
		$scripts             = [];
		$iframes             = [];
		$seen_script_domains = [];
		$seen_iframe_domains = [];

		foreach ( $pages as $page ) {
			// Process scripts (graceful: skip if not present in response).
			foreach ( $page['scripts'] ?? [] as $script ) {
				if ( empty( $script['is_third_party'] ) ) {
					continue;
				}

				$domain = sanitize_text_field( $script['domain'] ?? '' );
				if ( empty( $domain ) || isset( $seen_script_domains[ $domain ] ) ) {
					continue;
				}

				$seen_script_domains[ $domain ] = true;

				$scripts[] = [
					'domain'     => $domain,
					'url'        => esc_url_raw( $script['url'] ?? '' ),
					'vendor'     => sanitize_text_field( $script['vendor_name'] ?? '' ),
					'category'   => $this->map_saas_category( $script['tracking_category'] ?? '' ),
					'scanned_at' => gmdate( 'c' ),
				];
			}

			// Process iframes (graceful: skip if not present in response).
			foreach ( $page['iframes'] ?? [] as $iframe ) {
				if ( empty( $iframe['is_third_party'] ) ) {
					continue;
				}

				$domain = sanitize_text_field( $iframe['domain'] ?? '' );
				if ( empty( $domain ) || isset( $seen_iframe_domains[ $domain ] ) ) {
					continue;
				}

				$seen_iframe_domains[ $domain ] = true;

				$iframes[] = [
					'domain'     => $domain,
					'url'        => esc_url_raw( $iframe['src'] ?? '' ),
					'vendor'     => sanitize_text_field( $iframe['vendor'] ?? '' ),
					'category'   => $this->map_saas_category( $iframe['tracking_category'] ?? '' ),
					'scanned_at' => gmdate( 'c' ),
				];
			}
		}

		// If neither scripts nor iframes were found, this is likely an older SaaS version. Skip silently.
		if ( empty( $scripts ) && empty( $iframes ) ) {
			return;
		}

		// Check merge/replace behavior setting (defaults to replace).
		$replace_on_scan = Settings::get( 'replace_scan_resources_on_scan' );
		$replace_on_scan = $replace_on_scan === false || $replace_on_scan === null ? true : (bool) $replace_on_scan;

		if ( ! $replace_on_scan ) {
			// Merge with existing data (union by domain).
			$existing = get_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, [] );

			if ( is_array( $existing ) ) {
				$scripts = $this->merge_resources_by_domain( $existing['scripts'] ?? [], $scripts );
				$iframes = $this->merge_resources_by_domain( $existing['iframes'] ?? [], $iframes );
			}
		}

		// Cap each type independently so iframes aren't silently dropped when scripts are large.
		$half_cap = (int) floor( self::MAX_RESOURCES / 2 );
		$scripts  = array_slice( $scripts, 0, $half_cap );
		$iframes  = array_slice( $iframes, 0, $half_cap );

		$resource_data = [
			'scripts'  => $scripts,
			'iframes'  => $iframes,
			'metadata' => [
				'last_scan_at' => gmdate( 'c' ),
				'version'      => 1,
			],
		];

		update_option( SURECOOKIE_SCANNED_RESOURCES_OPTION, $resource_data, false );

		// Bust the known-scripts cache so the merged dataset rebuilds on next page load.
		delete_transient( 'surecookie_known_scripts' );

		// Clear the Scan_Scripts static cache.
		\SureCookie\Inc\Modules\ScriptBlocking\Scan_Scripts::clear_cache();

		// Fire action so GCM service detector clears its cache and re-detects Google services.
		do_action( 'surecookie_scanner_results_updated' );

		Logger::get_instance()->save_log(
			sprintf( 'Stored %d scripts and %d iframes from scan results.', count( $scripts ), count( $iframes ) )
		);
	}

	/**
	 * Map SaaS tracking category to plugin category.
	 *
	 * @param string $saas_category SaaS tracking category.
	 * @since 0.0.0-alpha.2
	 * @return string Plugin category.
	 */
	private function map_saas_category( string $saas_category ): string {
		if ( empty( $saas_category ) ) {
			return 'marketing'; // Unknown defaults to most restrictive.
		}

		return self::CATEGORY_MAP[ $saas_category ] ?? 'marketing';
	}

	/**
	 * Merge two resource arrays by domain (new entries win on conflict).
	 *
	 * @param array<int, array<string, mixed>> $existing Existing resources.
	 * @param array<int, array<string, mixed>> $new_resources New resources.
	 * @since 0.0.0-alpha.2
	 * @return array<int, array<string, mixed>> Merged resources.
	 */
	private function merge_resources_by_domain( array $existing, array $new_resources ): array {
		$by_domain = [];

		// Index existing by domain.
		foreach ( $existing as $resource ) {
			$domain = $resource['domain'] ?? '';
			if ( ! empty( $domain ) ) {
				$by_domain[ $domain ] = $resource;
			}
		}

		// New entries overwrite existing on conflict.
		foreach ( $new_resources as $resource ) {
			$domain = $resource['domain'] ?? '';
			if ( ! empty( $domain ) ) {
				$by_domain[ $domain ] = $resource;
			}
		}

		return array_values( $by_domain );
	}

	/**
	 * Group cookies by category from scan results.
	 *
	 * @param array<int, array<string, mixed>> $pages Scan results pages.
	 * @since 0.0.1
	 * @return array<string, array<int, array<string, mixed>>> Cookies grouped by category.
	 */
	private function group_cookies_by_category( array $pages ): array {
		$cookies_by_category = array_fill_keys( Get::default_cookie_categories_keys(), [] );
		$seen_signatures     = [];

		foreach ( $pages as $page ) {
			foreach ( $page['cookies'] ?? [] as $cookie ) {
				$signature_id = $cookie['signature_id'] ?? '';

				// Skip if no signature or already seen.
				if ( empty( $signature_id ) || isset( $seen_signatures[ $signature_id ] ) ) {
					continue;
				}

				$seen_signatures[ $signature_id ] = true;
				$category                         = $cookie['category'] ?? 'uncategorized';

				// Validate category exists.
				if ( ! isset( $cookies_by_category[ $category ] ) ) {
					$category = 'uncategorized';
				}

				// Add transformed cookie.
				$cookies_by_category[ $category ][] = $this->transform_cookie_data( $cookie, $category );
			}
		}

		return $cookies_by_category;
	}

	/**
	 * Transform cookie data to minimal required format.
	 *
	 * @param array<string, mixed> $cookie Raw cookie data from API.
	 * @param string               $category Cookie category.
	 * @since 0.0.1
	 * @return array<string, mixed> Minimal cookie data.
	 */
	private function transform_cookie_data( array $cookie, string $category ): array {
		// Use signature_id as the unique identifier.
		$signature_id = $cookie['signature_id'] ?? '';

		return [
			// Cookie properties.
			'name'         => $cookie['name'] ?? '',
			'value'        => $cookie['value'] ?? '',
			'domain'       => $cookie['domain'] ?? '',
			'path'         => $cookie['path'] ?? '/',
			'expires'      => $cookie['expires_at'] ?? null,
			'httpOnly'     => ! empty( $cookie['http_only'] ),
			'secure'       => ! empty( $cookie['secure'] ),
			'sameSite'     => $cookie['same_site'] ?? 'lax',
			'category'     => $category,

			// Cookie policy display fields.
			'provider'     => $cookie['provider'] ?? '',
			'description'  => $cookie['description'] ?? '',
			'purpose'      => $cookie['purpose'] ?? '',

			// Unique identifier for deduplication.
			'signature_id' => $signature_id,
		];
	}

	/**
	 * Store cookies from scan, merging with existing ones.
	 *
	 * Simple logic: If same signature_id exists, replace it. Otherwise, add new.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $new_cookies New cookies by category.
	 * @since 0.0.1
	 * @return void
	 */
	private function store_all_cookies_from_agent_app( array $new_cookies ): void {
		// Get existing cookies.
		$existing_cookies = get_option( SURECOOKIE_SCANNED_COOKIES_OPTION, [] );
		if ( ! is_array( $existing_cookies ) ) {
			$existing_cookies = [];
		}

		// Initialize all categories.
		$default_categories = Get::default_cookie_categories_keys();
		foreach ( $default_categories as $category ) {
			if ( ! isset( $existing_cookies[ $category ] ) ) {
				$existing_cookies[ $category ] = [];
			}
		}

		// Track changes.
		$new_count     = 0;
		$updated_count = 0;

		// Process each new cookie.
		foreach ( $new_cookies as $category => $cookies ) {
			foreach ( $cookies as $new_cookie ) {
				$signature_id = $new_cookie['signature_id'] ?? '';

				if ( empty( $signature_id ) ) {
					continue; // Skip cookies without signature.
				}

				// Remove old cookie with same signature_id from ALL categories.
				$found_existing = false;
				foreach ( $existing_cookies as $existing_category => $existing_category_cookies ) {
					foreach ( $existing_category_cookies as $index => $existing_cookie ) {
						if ( ( $existing_cookie['signature_id'] ?? '' ) === $signature_id ) {
							unset( $existing_cookies[ $existing_category ][ $index ] );
							$existing_cookies[ $existing_category ] = array_values( $existing_cookies[ $existing_category ] );
							$found_existing                         = true;
							break 2; // Exit both loops.
						}
					}
				}

				// Add new cookie to its category.
				$existing_cookies[ $category ][] = $new_cookie;

				// Track if new or updated.
				if ( $found_existing ) {
					$updated_count++;
				} else {
					$new_count++;
				}
			}
		}

		// Save to database.
		Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $existing_cookies );

		// Update scan history.
		$total_cookies = $this->get_cookies_count( $existing_cookies );
		$this->update_scan_history( $total_cookies );

		// Log results.
		if ( $new_count > 0 && $updated_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d new, %d updated (total: %d).', $new_count, $updated_count, $total_cookies ) );
		} elseif ( $new_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d new (total: %d).', $new_count, $total_cookies ) );
		} elseif ( $updated_count > 0 ) {
			Logger::get_instance()->save_log( sprintf( '%d updated (total: %d).', $updated_count, $total_cookies ) );
		} else {
			Logger::get_instance()->save_log( sprintf( 'No changes (total: %d).', $total_cookies ) );
		}
	}

	/**
	 * Get cookies count.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $all_cookies All cookies array.
	 * @since 0.0.1
	 * @return int
	 */
	private function get_cookies_count( array $all_cookies ): int {
		if ( empty( $all_cookies ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $all_cookies as $cookies ) {
			$count += count( $cookies );
		}

		return $count;
	}

	/**
	 * Update scan history option.
	 *
	 * Stores only the latest scan record.
	 *
	 * @param int $cookies_count Number of cookies found.
	 * @since 0.0.1
	 * @return void
	 */
	private function update_scan_history( int $cookies_count ): void {
		$option = get_option( SURECOOKIE_SCANNED_DETAILS_OPTION, [] );

		if ( ! is_array( $option ) ) {
			$option = [];
		}

		$total_scans = isset( $option['total_scans'] ) ? (int) $option['total_scans'] : 0;

		// Merge (don't replace) so the change-detection keys (reported_snapshot/
		// changes) written by the Automatic Scanning recorder survive this
		// basic-field update and remain available as the diff baseline for the
		// next scan.
		$history = array_merge(
			$option,
			[
				'date'          => current_time( 'mysql' ),
				'cookies_count' => $cookies_count,
				'scan_type'     => 'saas',
				'total_scans'   => $total_scans + 1, // Required for future analytics.
				'success'       => true,
			]
		);

		Update::option( SURECOOKIE_SCANNED_DETAILS_OPTION, $history );
	}
}
