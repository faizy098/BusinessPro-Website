<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all plugin data if the user has opted in via
 * Settings > General Settings > "Delete Data on Uninstall".
 *
 * IMPORTANT: This file runs WITHOUT the plugin loaded.
 * No constants, classes, or autoloader are available.
 * All option keys are hardcoded and must be kept in sync
 * with SURECOOKIE_* constants in surecookie.php and runtime option keys.
 *
 * @link       https://surecookie.com
 * @since      0.0.1
 *
 * @package    SureCookie
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Check if the user has opted in to data deletion.
 *
 * @return bool
 */
function surecookie_should_delete_data() {
	$settings = get_option( 'surecookie_settings', [] );
	if ( ! is_array( $settings ) ) {
		return false;
	}
	return isset( $settings['delete_data_on_uninstall'] )
		&& $settings['delete_data_on_uninstall'] === true;
}

/*
 * Note: The deletion check is performed per-site (not globally) so that
 * in multisite each sub-site's own preference is respected.
 * See surecookie_should_delete_data() usage below.
 */

/**
 * All known option keys created by SureCookie.
 *
 * Sync with: SURECOOKIE_* constants in surecookie.php + runtime option keys.
 *
 * @return array<string>
 */
function surecookie_get_option_keys() {
	return [
		// Defined as constants in surecookie.php.
		'surecookie_settings',
		'surecookie_onboarding_user_details',
		'surecookie_onboarding_completed',
		'surecookie_scanned_details',
		'surecookie_scanned_cookies',
		'surecookie_scanned_logs',
		'surecookie_scanned_resources',
		'surecookie_nudges',
		// Runtime options (not constants).
		'surecookie_do_activation_redirect',
		'surecookie_cron_test_ok',
		'surecookie_active_scan',
		'surecookie_saved_version',
		// BSF Analytics options.
		'surecookie_usage_optin',
		'surecookie_usage_installed_time',
		'surecookie_tracked_version',
		'surecookie_first_scan_started_flag',
		'surecookie_first_scan_pages_count',
		'surecookie_first_scan_completed_flag',
		'surecookie_first_scan_pages_scanned',
		// Issue #473 - scanner SaaS credentials.
		'surecookie_site_credentials',
		'surecookie_last_known_quota',
		// Issue #742 - connected billing-portal account (Auth\Controller::SETTINGS_KEY).
		'surecookie_auth',
		'surecookie_onboarding_lead',
		'surecookie_first_successful_scan',
		'surecookie_db_version',
		'surecookie_consent_log_unique_key_migrated',
		// BSF Analytics event queue + schema version.
		'surecookie_analytics_events_version',
		'surecookie_usage_events_pending',
		'surecookie_usage_events_pushed',
		'surecookie_first_auto_scan_frequency',
		'surecookie_first_auto_scan_started_flag',
	];
}

/**
 * Delete all plugin options for the current site.
 *
 * @return void
 */
function surecookie_delete_options(): void {
	foreach ( surecookie_get_option_keys() as $option ) {
		delete_option( $option );
	}

	// Per-user "last viewed consent log" pointers are stored as
	// surecookie_last_viewed_consent_log_<user_id>, so the user-id suffix
	// makes them unknowable in advance - delete by prefix. The prefix is
	// specific enough to never match keys owned by the Pro plugin.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'surecookie_last_viewed_consent_log_' ) . '%'
		)
	);
}

/**
 * Delete all plugin transients for the current site.
 *
 * @return void
 */
function surecookie_delete_transients(): void {
	delete_transient( 'surecookie_known_scripts' );
	delete_transient( 'surecookie_geo_cache' );
	delete_transient( 'surecookie_state_events_checked' );
	// Issue #473 - scanner registration / verification transients.
	delete_transient( 'surecookie_registering_site' );
	delete_transient( 'surecookie_pending_verification' );
	// Mirror of SaasClient::SCAN_BYPASS_TRANSIENT_KEY (no autoloader here).
	delete_transient( 'surecookie_scan_bypass_token' );
	delete_transient( 'surecookie_scan_quota' );
	// Automatic-scanning runner state (Runner::ACTIVE_TRANSIENT / LOCK_TRANSIENT).
	delete_transient( 'surecookie_auto_scan_active' );
	delete_transient( 'surecookie_auto_scan_lock' );
	// Google Consent Mode service detection cache (ServiceDetector::TRANSIENT_KEY).
	delete_transient( 'surecookie_gcm_services_detected' );
}

/**
 * Unschedule all plugin cron events for the current site.
 *
 * @return void
 */
function surecookie_unschedule_crons(): void {
	wp_clear_scheduled_hook( 'surecookie_cleanup_consent_logs' );
	wp_clear_scheduled_hook( 'surecookie_poll_scan_status' );
	// Automatic-scanning events (Scheduler::RUN_HOOK / Runner::RETRY_HOOK).
	wp_clear_scheduled_hook( 'surecookie_auto_scan_run' );
	wp_clear_scheduled_hook( 'surecookie_auto_scan_retry' );
}

/**
 * Drop the consent log table for the current site.
 *
 * @return void
 */
function surecookie_drop_tables(): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}surecookie_consent_log" );
}

/**
 * Delete the file cache directory (wp-content/uploads/surecookie/).
 *
 * @return void
 */
function surecookie_delete_cache_files(): void {
	$upload_dir = wp_upload_dir();
	if ( empty( $upload_dir['basedir'] ) ) {
		return;
	}

	$cache_dir = trailingslashit( $upload_dir['basedir'] ) . 'surecookie';

	if ( ! is_dir( $cache_dir ) ) {
		return;
	}

	// Use WP_Filesystem_Direct explicitly - WP_Filesystem() can silently
	// fail on FTP/SSH servers where credentials are unavailable at uninstall.
	if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	}
	if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	}

	$filesystem = new WP_Filesystem_Direct( [] );
	$filesystem->delete( $cache_dir, true );
}

/**
 * Run all cleanup for a single site.
 *
 * @return void
 */
function surecookie_uninstall_site(): void {
	surecookie_delete_options();
	surecookie_delete_transients();
	surecookie_unschedule_crons();
	surecookie_drop_tables();
	surecookie_delete_cache_files();
}

/*
 * Main execution - multisite-aware cleanup.
 */
if ( is_multisite() ) {
	global $wpdb;

	// Get all active (non-archived, non-spam, non-deleted) site IDs.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$blog_ids = $wpdb->get_col(
		"SELECT blog_id FROM {$wpdb->blogs} WHERE archived = '0' AND spam = '0' AND deleted = '0'"
	);

	if ( is_array( $blog_ids ) ) {
		foreach ( $blog_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			if ( surecookie_should_delete_data() ) {
				surecookie_uninstall_site();
			}
			restore_current_blog();
		}
	}
} elseif ( surecookie_should_delete_data() ) {
	surecookie_uninstall_site();
}
