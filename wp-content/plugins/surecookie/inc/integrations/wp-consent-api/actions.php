<?php
/**
 * WP Consent API - Actions
 *
 * Handles settings registration, frontend option injection,
 * and cookie registration with the WP Consent API.
 *
 * @package SureCookie\Inc\Integrations\WpConsentApi
 * @since 0.0.1-beta.1
 */

namespace SureCookie\Inc\Integrations\WpConsentApi;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Actions class.
 *
 * @since 0.0.1-beta.1
 */
class Actions {
	use GetInstance;

	/**
	 * Whether cookies have already been registered.
	 *
	 * @var bool
	 */
	private bool $cookies_registered = false;

	/**
	 * Constructor.
	 *
	 * @since 0.0.1-beta.1
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register SureCookie's own cookies with WP Consent API.
	 *
	 * This tells the WP Consent API what cookies SureCookie sets,
	 * their purpose, and expiry - visible in consent API dashboards.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	public function register_cookies(): void {
		if ( $this->cookies_registered || ! function_exists( 'wp_add_cookie_info' ) ) {
			return;
		}

		$this->cookies_registered = true;

		$consent_duration = Settings::get( 'consent_duration_days' );
		$consent_expiry   = ( ! empty( $consent_duration ) ? absint( $consent_duration ) : 365 ) . ' ' . __( 'days', 'surecookie' );

		wp_add_cookie_info(
			'surecookie_user_consent',
			__( 'SureCookie', 'surecookie' ),
			'functional',
			$consent_expiry,
			__( 'Stores the user\'s cookie consent preferences.', 'surecookie' )
		);

		wp_add_cookie_info(
			'surecookie_session_id',
			__( 'SureCookie', 'surecookie' ),
			'functional',
			__( '1 year', 'surecookie' ),
			__( 'Tracks consent log sessions for audit purposes.', 'surecookie' )
		);
	}

	/**
	 * Add WP Consent API availability flag to frontend localized data.
	 *
	 * @param array<string> $frontend_keys The current frontend setting keys.
	 * @return array<string>
	 * @since 0.0.1-beta.1
	 */
	public function add_frontend_options( array $frontend_keys ): array {
		return array_merge( $frontend_keys, [ 'wp_consent_api_enabled' ] );
	}

	/**
	 * Add the WP Consent API category map to frontend localized data.
	 *
	 * Makes PHP the single source of truth for the SureCookie → WP Consent API
	 * category mapping. JS reads `window.surecookiePublicSettings.wpConsentCategoryMap`.
	 *
	 * @param array<string, mixed> $data Frontend localized data.
	 * @return array<string, mixed>
	 * @since 0.0.1-beta.1
	 */
	public function add_category_map_to_frontend( array $data ): array {
		$data['wpConsentCategoryMap'] = Consent_Handler::get_full_category_map();
		return $data;
	}

	/**
	 * Add WP Consent API settings to the plugin settings dataset.
	 *
	 * @param array<string, mixed> $settings The current settings dataset.
	 * @return array<string, mixed>
	 * @since 0.0.1-beta.1
	 */
	public function add_settings_to_dataset( array $settings ): array {
		$settings['wp_consent_api_enabled'] = [
			'type'    => 'bool',
			'default' => true,
		];

		return $settings;
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 0.0.1-beta.1
	 */
	private function init_hooks(): void {
		// Register SureCookie's cookies with WP Consent API.
		add_action( 'init', [ $this, 'register_cookies' ], 20 );

		// Add settings to plugin dataset.
		add_filter( 'surecookie_plugin_settings_dataset', [ $this, 'add_settings_to_dataset' ] );

		// Add to frontend localized data so JS knows the API is available.
		add_filter( 'surecookie_frontend_setting_keys', [ $this, 'add_frontend_options' ] );

		// Pass the category map to the frontend so JS uses PHP as single source of truth.
		add_filter( 'surecookie_frontend_localize_data', [ $this, 'add_category_map_to_frontend' ] );
	}
}
