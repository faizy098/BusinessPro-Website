<?php
/**
 * Google Consent Mode Module Actions Initialization.
 *
 * @package SureCookie\Inc\Modules\GoogleConsentMode
 * @since 0.0.0-alpha.1
 */

namespace SureCookie\Inc\Modules\GoogleConsentMode;

use SureCookie\Inc\Functions\Settings;
use SureCookie\Inc\Traits\GetInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Actions class.
 *
 * @since 0.0.0-alpha.1
 */
class Actions {
	use GetInstance;

	/**
	 * GCM setting keys used across registration, frontend options, and cache invalidation.
	 *
	 * @since 0.0.0-alpha.2
	 */
	private const GCM_SETTING_KEYS = [
		'gcm_enabled',
		'gcm_wait_for_update',
		'gcm_region_defaults',
	];

	/**
	 * Snapshot of GCM settings before a save, used for change detection.
	 *
	 * @var array<string, mixed>
	 * @since 0.0.0-alpha.2
	 */
	private $previous_gcm_settings = [];

	/**
	 * Constructor.
	 *
	 * @since 0.0.0-alpha.1
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Add Google Consent Mode settings to plugin settings dataset.
	 *
	 * @param array<string, mixed> $settings The current settings dataset.
	 * @return array<string, mixed>
	 * @since 0.0.0-alpha.1
	 */
	public function add_gcm_settings_to_dataset( $settings ) {
		$gcm_settings = [
			'gcm_enabled'         => [
				'type'    => 'bool',
				'default' => false,
			],
			'gcm_wait_for_update' => [
				'type'    => 'int',
				'default' => 500,
			],
			'gcm_region_defaults' => [
				'type'    => 'array',
				'default' => [
					[
						'region'     => [ 'EU' ],
						'analytics'  => false,
						'marketing'  => false,
						'functional' => false,
					],
				],
			],
		];

		return array_merge( $settings, $gcm_settings );
	}

	/**
	 * Add Google Consent Mode settings to frontend options.
	 *
	 * @param array<string> $frontend_keys The current frontend setting keys.
	 * @return array<string>
	 * @since 0.0.0-alpha.1
	 */
	public function add_gcm_frontend_options( $frontend_keys ) {
		return array_merge( $frontend_keys, self::GCM_SETTING_KEYS );
	}

	/**
	 * Snapshot current GCM settings before save for change detection.
	 *
	 * @param array<string, mixed> $data The raw settings data before processing.
	 * @return void
	 * @since 0.0.0-alpha.2
	 */
	public function snapshot_gcm_settings( $data ): void {
		$all_settings = Settings::get();

		foreach ( self::GCM_SETTING_KEYS as $key ) {
			$this->previous_gcm_settings[ $key ] = $all_settings[ $key ] ?? null;
		}
	}

	/**
	 * Clear the Google service detection cache when GCM settings actually change.
	 *
	 * Compares new values against the pre-save snapshot to avoid unnecessary cache clears.
	 *
	 * @param array<string, mixed> $settings The saved settings data.
	 * @return void
	 * @since 0.0.0-alpha.2
	 */
	public function clear_gcm_service_cache( $settings ): void {
		foreach ( self::GCM_SETTING_KEYS as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}

			if ( ( $this->previous_gcm_settings[ $key ] ?? null ) !== $settings[ $key ] ) {
				Service_Detector::get_instance()->clear_cache();
				return;
			}
		}
	}

	/**
	 * Clear service detection cache when scanner results are updated.
	 *
	 * Called when Playwright scanner completes and stores new results.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	public function on_scanner_results_updated(): void {
		if ( ! Settings::get( 'gcm_enabled' ) ) {
			return;
		}
		Service_Detector::get_instance()->clear_cache();
	}

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 * @since 0.0.0-alpha.1
	 */
	private function init_hooks(): void {
		// Add Google Consent Mode settings to plugin configuration dataset.
		add_filter( 'surecookie_plugin_settings_dataset', [ $this, 'add_gcm_settings_to_dataset' ] );

		// Add Google Consent Mode settings to frontend options.
		add_filter( 'surecookie_frontend_setting_keys', [ $this, 'add_gcm_frontend_options' ] );

		// Snapshot GCM settings before save for change detection.
		add_action( 'surecookie_admin_settings_before_processing', [ $this, 'snapshot_gcm_settings' ] );

		// Clear service detection cache only when GCM settings actually change.
		add_action( 'surecookie_admin_settings_after_processing', [ $this, 'clear_gcm_service_cache' ] );

		// Clear service detection cache when scanner results are updated.
		// This ensures GCM detects newly found Google services.
		add_action( 'surecookie_scanner_results_updated', [ $this, 'on_scanner_results_updated' ] );
	}
}
