<?php
/**
 * Cookie Category Service
 *
 * Shared business logic for cookie category CRUD operations.
 * Used by both the REST API (inc/api/cookie-categories.php) and the WordPress
 * Abilities integration (inc/integrations/wordpress/abilities/cookie-categories.php).
 *
 * @package    SureCookie
 * @subpackage SureCookie/Inc/Services
 * @since      0.0.0-alpha.1
 */

namespace SureCookie\Inc\Services;

use SureCookie\Inc\Functions\Get;
use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Functions\Validate;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class CookieCategoryService
 *
 * Handles cookie category CRUD operations.
 * Returns associative arrays (not JSON responses) so callers
 * can adapt the output to their transport layer.
 *
 * @since 0.0.0-alpha.1
 */
class CookieCategoryService {
	/**
	 * Get all cookie categories.
	 *
	 * @return array{success: bool, message: string, categories: array<string, mixed>, count: int}
	 * @since 0.0.0-alpha.1
	 */
	public function get_categories(): array {
		$categories = Settings::get( 'cookie_categories' );

		return [
			'success'    => true,
			'message'    => __( 'Cookie categories retrieved successfully.', 'surecookie' ),
			'categories' => $categories,
			'count'      => is_array( $categories ) ? count( $categories ) : 0,
		];
	}

	/**
	 * Create a new cookie category.
	 *
	 * @param array<string, mixed> $data Category data with keys: name (required), description, required.
	 * @return array{success: bool, message: string, category?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function create_category( array $data ): array {
		$name        = sanitize_text_field( $data['name'] ?? '' );
		$description = sanitize_textarea_field( $data['description'] ?? '' );
		$required    = (bool) ( $data['required'] ?? false );

		if ( Validate::empty_string( $name ) ) {
			return [
				'success' => false,
				'message' => __( 'Category name is required.', 'surecookie' ),
			];
		}

		$categories  = Settings::get( 'cookie_categories' );
		$category_id = Get::unique_id( 'category_' );

		// Check if category already exists in the associative array.
		if ( isset( $categories[ $category_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'A category with this name already exists.', 'surecookie' ),
			];
		}

		$new_category = [
			'id'          => $category_id,
			'name'        => $name,
			'description' => $description,
			'required'    => $required,
		];

		// Add new category to the associative array using ID as key.
		$categories[ $category_id ] = $new_category;
		Settings::update( 'cookie_categories', $categories );

		return [
			'success'  => true,
			'message'  => __( 'Category created successfully.', 'surecookie' ),
			'category' => $new_category,
		];
	}

	/**
	 * Update an existing cookie category.
	 *
	 * Protects default category "required" status from being changed.
	 *
	 * @param string               $category_id The category ID to update.
	 * @param array<string, mixed> $data        Fields to update (name, description, required).
	 * @return array{success: bool, message: string, category?: array<string, mixed>}
	 * @since 0.0.0-alpha.1
	 */
	public function update_category( string $category_id, array $data ): array {
		$category_id = sanitize_text_field( $category_id );

		if ( empty( $category_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Category ID is required.', 'surecookie' ),
			];
		}

		$categories = Settings::get( 'cookie_categories' );

		// Check if category exists using associative array key.
		if ( ! isset( $categories[ $category_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Category not found.', 'surecookie' ),
			];
		}

		$category = $categories[ $category_id ];

		// Default categories have a fixed required status (only "essential" is mandatory).
		$default_category_ids = Get::default_cookie_categories_keys();
		$is_default_category  = in_array( $category_id, $default_category_ids, true );

		// Update fields if provided.
		if ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
			$category['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['description'] ) && is_string( $data['description'] ) ) {
			$category['description'] = sanitize_textarea_field( $data['description'] );
		}

		// Update required status if provided. Default categories keep their fixed status
		// (only "essential" is mandatory; the rest are opt-in per GDPR); custom categories are free.
		if ( isset( $data['required'] ) ) {
			$category['required'] = $is_default_category
				? ( $category_id === 'essential' )
				: (bool) $data['required'];
		}

		$categories[ $category_id ] = $category;
		Settings::update( 'cookie_categories', $categories );

		return [
			'success'  => true,
			'message'  => __( 'Category updated successfully.', 'surecookie' ),
			'category' => $category,
		];
	}

	/**
	 * Delete a cookie category.
	 *
	 * Default categories cannot be deleted. When a category has associated
	 * custom cookies, they are either transferred to "uncategorized" or deleted
	 * based on the $keep_cookies flag.
	 *
	 * @param string $category_id  The category ID to delete.
	 * @param bool   $keep_cookies Whether to transfer cookies to "uncategorized" (true) or delete them (false).
	 * @return array{success: bool, message: string, cookies_moved?: int, cookies_deleted?: int}
	 * @since 0.0.0-alpha.1
	 */
	public function delete_category( string $category_id, bool $keep_cookies = false ): array {
		$category_id = sanitize_text_field( $category_id );

		if ( empty( $category_id ) ) {
			return [
				'success' => false,
				'message' => __( 'Category ID is required.', 'surecookie' ),
			];
		}

		// Check if it's a default category - cannot be deleted.
		$default_category_ids = Get::default_cookie_categories_keys();
		if ( in_array( $category_id, $default_category_ids, true ) ) {
			return [
				'success' => false,
				'message' => __( 'Default categories cannot be deleted.', 'surecookie' ),
			];
		}

		$categories = Settings::get( 'cookie_categories' );

		// Check if category exists using associative array key.
		if ( ! isset( $categories[ $category_id ] ) ) {
			return [
				'success' => false,
				'message' => __( 'Category not found.', 'surecookie' ),
			];
		}

		$custom_cookies = Settings::get( 'custom_cookies' );

		$category_cookies = array_filter(
			$custom_cookies,
			static function ( $cookie ) use ( $category_id ) {
				return isset( $cookie['category'] ) && $cookie['category'] === $category_id;
			}
		);

		// If category cookies are present and keep_cookies is provided, transfer cookies to uncategorized category.
		$cookie_count = count( $category_cookies );
		if ( $cookie_count && $keep_cookies ) {
			// Update each cookie's category field to 'uncategorized'.
			foreach ( array_keys( $category_cookies ) as $cookie_id ) {
				if ( isset( $custom_cookies[ $cookie_id ] ) ) {
					$custom_cookies[ $cookie_id ]['category'] = 'uncategorized';
				}
			}
		} else {
			// Delete all cookies under this category.
			foreach ( array_keys( $category_cookies ) as $cookie_id ) {
				if ( isset( $custom_cookies[ $cookie_id ] ) ) {
					unset( $custom_cookies[ $cookie_id ] );
				}
			}
		}

		Settings::update( 'custom_cookies', $custom_cookies );

		// Delete the category from associative array & save.
		unset( $categories[ $category_id ] );
		Settings::update( 'cookie_categories', $categories );

		return [
			'success'         => true,
			'message'         => __( 'Category deleted successfully.', 'surecookie' ),
			'cookies_moved'   => $cookie_count > 0 && $keep_cookies ? $cookie_count : 0,
			'cookies_deleted' => $cookie_count > 0 && ! $keep_cookies ? $cookie_count : 0,
		];
	}
}
