<?php
/**
 * Cookie Service
 *
 * Shared business logic for custom cookie and scanned cookie operations.
 * Used by both the REST API (inc/api/cookies.php) and the WordPress
 * Abilities integration (inc/integrations/wordpress/abilities/cookie-management.php).
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Services
 * @since      0.0.0-alpha.1
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Update;
use SureCookie\Inc\Functions\Validate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieService
 *
 * Handles custom cookie CRUD and scanned cookie operations.
 * Returns associative arrays (not JSON responses) so callers
 * can adapt the output to their transport layer.
 *
 * @since 0.0.0-alpha.1
 */
class CookieService {
	/**
	 * Get scanned cookies organized by category.
	 *
	 * Merges scanner-detected cookies with user-defined custom cookies,
	 * grouped under their assigned cookie categories.
	 *
	 * @return array{success: bool, message: string, cookies?: array<int, array<string, mixed>>, scanning_details?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function get_scanned_cookies(): array {
		$scanned_cookies       = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );
		$cookie_categories     = Settings::get( 'cookie_categories' );
		$final_cookies_dataset = [];

		if ( empty( $scanned_cookies ) || ! is_array( $scanned_cookies ) || empty( $cookie_categories ) ) {
			return [
				'success' => false,
				'message' => __( 'No scanned cookies found.', 'surecookie' ),
			];
		}

		$custom_category_cookies = Get::formatted_custom_cookies();

		foreach ( $cookie_categories as $category_data ) {
			$category_id = $category_data['id'];

			$category_based_cookies      = $scanned_cookies[ $category_id ] ?? [];
			$custom_cookies_for_category = $custom_category_cookies[ $category_id ] ?? [];
			$all_cookies                 = array_merge( $category_based_cookies, $custom_cookies_for_category );

			$final_cookies_dataset[] = [
				'id'          => $category_id,
				'name'        => $category_data['name'],
				'description' => $category_data['description'],
				'cookies'     => $all_cookies,
				'count'       => count( $all_cookies ),
			];
		}

		return [
			'success'          => true,
			'message'          => __( 'Scanned cookies retrieved successfully.', 'surecookie' ),
			'cookies'          => $final_cookies_dataset,
			'scanning_details' => Get::option( SURECOOKIE_SCANNED_DETAILS_OPTION, [ 'success' => true ], 'array' ),
		];
	}

	/**
	 * Get all custom cookies.
	 *
	 * @return array{success: bool, message: string, cookies: array<string, mixed>, count: int}
	 * @since 0.0.0-alpha.1
	 */
	public function get_custom_cookies(): array {
		$custom_cookies = Settings::get( 'custom_cookies' );

		return [
			'success' => true,
			'message' => __( 'Custom cookies retrieved successfully.', 'surecookie' ),
			'cookies' => $custom_cookies,
			'count'   => is_array( $custom_cookies ) ? count( $custom_cookies ) : 0,
		];
	}

	/**
	 * Create a new custom cookie.
	 *
	 * @param array<string, mixed> $data Cookie data with keys: name (required), category (required),
	 *                                   description, duration, provider, purpose, domain, type.
	 * @return array{success: bool, message: string, cookie?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function create_custom_cookie( array $data ): array {
		$name        = sanitize_text_field( $data['name'] ?? '' );
		$category    = sanitize_text_field( $data['category'] ?? '' );
		$description = sanitize_textarea_field( $data['description'] ?? '' );
		$duration    = sanitize_text_field( (string) ( $data['duration'] ?? '' ) );
		$provider    = sanitize_text_field( $data['provider'] ?? '' );
		$purpose     = sanitize_textarea_field( $data['purpose'] ?? '' );
		$domain      = esc_url_raw( $data['domain'] ?? '' );
		$type        = sanitize_text_field( $data['type'] ?? 'custom' );

		// Validate required fields.
		if ( empty( $name ) || ! is_string( $name ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie name is required.', 'surecookie' ),
			];
		}

		if ( empty( $category ) || ! is_string( $category ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie category is required.', 'surecookie' ),
			];
		}

		// Get existing cookies.
		$custom_cookies = Settings::get( 'custom_cookies' );

		// Check for duplicate cookie name (O(1)).
		if ( isset( $custom_cookies[ $name ] ) ) {
			return [
				'success' => false,
				'message' => __( 'A cookie with this name already exists.', 'surecookie' ),
			];
		}

		$cookie_id = Get::unique_id( 'cookie_' );

		// Calculate expiration date.
		$expires = null;
		$days    = absint( $duration );
		if ( $days ) {
			$expires = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$days} days" ) );
		}

		$new_cookie = [
			'id'          => $cookie_id,
			'name'        => $name,
			'description' => $description,
			'category'    => $category,
			'duration'    => $duration,
			'provider'    => $provider,
			'purpose'     => $purpose,
			'domain'      => $domain,
			'type'        => $type,
			'expires'     => $expires,
		];

		// Set a new cookie.
		$custom_cookies[ $cookie_id ] = $new_cookie;
		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie created successfully.', 'surecookie' ),
			'cookie'  => $new_cookie,
		];
	}

	/**
	 * Update an existing custom cookie.
	 *
	 * @param string               $cookie_id The cookie ID to update.
	 * @param array<string, mixed> $data      Fields to update (name, category, description, duration, provider, purpose, domain).
	 * @return array{success: bool, message: string, cookie?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function update_custom_cookie( string $cookie_id, array $data ): array {
		$cookie_id = sanitize_text_field( $cookie_id );

		if ( empty( $cookie_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie ID is required.', 'surecookie' ),
			];
		}

		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		if ( ! isset( $custom_cookies[ $cookie_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found.', 'surecookie' ),
			];
		}

		$cookie = $custom_cookies[ $cookie_id ];

		if ( Validate::not_empty( $data['name'] ?? '' ) ) {
			$cookie['name'] = sanitize_text_field( $data['name'] );
		}

		if ( Validate::not_empty( $data['category'] ?? '' ) ) {
			$cookie['category'] = sanitize_text_field( $data['category'] );
		}

		if ( Validate::not_empty( $data['description'] ?? '' ) ) {
			$cookie['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( Validate::not_empty( $data['provider'] ?? '' ) ) {
			$cookie['provider'] = sanitize_text_field( $data['provider'] );
		}

		if ( Validate::not_empty( $data['purpose'] ?? '' ) ) {
			$cookie['purpose'] = sanitize_textarea_field( $data['purpose'] );
		}

		if ( Validate::not_empty( $data['domain'] ?? '' ) ) {
			$cookie['domain'] = esc_url_raw( $data['domain'] );
		}

		// Duration & expires.
		if ( absint( $data['duration'] ?? 0 ) ) {
			$days               = absint( $data['duration'] );
			$cookie['duration'] = $days;
			$cookie['expires']  = gmdate( 'Y-m-d H:i:s', (int) strtotime( "+{$days} days" ) );
		}

		$custom_cookies[ $cookie_id ] = $cookie;

		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie updated successfully.', 'surecookie' ),
			'cookie'  => $cookie,
		];
	}

	/**
	 * Delete a custom cookie.
	 *
	 * @param string $cookie_id The cookie ID to delete.
	 * @return array{success: bool, message: string}
	 * @since 0.0.0-alpha.1
	 */
	public function delete_custom_cookie( string $cookie_id ): array {
		$cookie_id = sanitize_text_field( $cookie_id );

		if ( empty( $cookie_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie ID is required.', 'surecookie' ),
			];
		}

		$custom_cookies = (array) Settings::get( 'custom_cookies' );

		if ( ! isset( $custom_cookies[ $cookie_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found.', 'surecookie' ),
			];
		}

		unset( $custom_cookies[ $cookie_id ] );

		Settings::update( 'custom_cookies', $custom_cookies );

		return [
			'success' => true,
			'message' => __( 'Custom cookie deleted successfully.', 'surecookie' ),
		];
	}

	/**
	 * Move a scanned cookie from one category to another.
	 *
	 * @param string $cookie_name      The name of the cookie to move.
	 * @param string $current_category  The source category ID.
	 * @param string $new_category      The target category ID.
	 * @return array{success: bool, message: string, cookie_name?: string, old_category?: string, new_category?: string}
	 * @since 0.0.0-alpha.1
	 */
	public function update_scanned_cookie_category( string $cookie_name, string $current_category, string $new_category ): array {
		$cookie_name      = sanitize_text_field( $cookie_name );
		$current_category = sanitize_text_field( $current_category );
		$new_category     = sanitize_text_field( $new_category );

		// Validate required fields.
		if ( empty( $cookie_name ) ) {
			return [
				'success' => false,
				'message' => __( 'Cookie name is required.', 'surecookie' ),
			];
		}

		if ( empty( $current_category ) ) {
			return [
				'success' => false,
				'message' => __( 'Current category is required.', 'surecookie' ),
			];
		}

		if ( empty( $new_category ) ) {
			return [
				'success' => false,
				'message' => __( 'New category is required.', 'surecookie' ),
			];
		}

		// No change needed if categories are the same.
		if ( $current_category === $new_category ) {
			return [
				'success' => true,
				'message' => __( 'Cookie is already in the selected category.', 'surecookie' ),
			];
		}

		// Get scanned cookies from the database option.
		$scanned_cookies = Get::option( SURECOOKIE_SCANNED_COOKIES_OPTION, [], 'array' );

		if ( empty( $scanned_cookies ) || ! is_array( $scanned_cookies ) ) {
			return [
				'success' => false,
				'message' => __( 'No scanned cookies found.', 'surecookie' ),
			];
		}

		// Check if current category exists in scanned cookies.
		if ( ! isset( $scanned_cookies[ $current_category ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Current category not found in scanned cookies.', 'surecookie' ),
			];
		}

		// Find the cookie in the current category.
		$cookie_data  = null;
		$cookie_index = null;

		foreach ( $scanned_cookies[ $current_category ] as $index => $cookie ) {
			if ( isset( $cookie['name'] ) && $cookie['name'] === $cookie_name ) {
				$cookie_data  = $cookie;
				$cookie_index = $index;
				break;
			}
		}

		if ( $cookie_data === null ) {
			return [
				'success' => false,
				'message' => __( 'Cookie not found in the specified category.', 'surecookie' ),
			];
		}

		// Remove cookie from current category.
		array_splice( $scanned_cookies[ $current_category ], $cookie_index, 1 );

		// Initialize new category array if it doesn't exist.
		if ( ! isset( $scanned_cookies[ $new_category ] ) ) {
			$scanned_cookies[ $new_category ] = [];
		}

		// Add cookie to new category.
		$scanned_cookies[ $new_category ][] = $cookie_data;

		// Save updated scanned cookies.
		Update::option( SURECOOKIE_SCANNED_COOKIES_OPTION, $scanned_cookies );

		return [
			'success'      => true,
			'message'      => __( 'Cookie category updated successfully.', 'surecookie' ),
			'cookie_name'  => $cookie_name,
			'old_category' => $current_category,
			'new_category' => $new_category,
		];
	}
}
