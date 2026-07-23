<?php
/**
 * Maintenance.
 *
 * @package SureCookie
 * @subpackage SureCookie/Core
 * @since 0.0.1
 */

namespace SureCookie\Core;

use SureCookie\Inc\Database\ConsentLog;
use SureCookie\Inc\Database\Init as DB_Init;
use SureCookie\Inc\Traits\GetInstance;
use SureCookie\Inc\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Update Compatibility
 *
 * @package SureCookie
 */
class Maintenance {
	use GetInstance;

	/**
	 *  Constructor
	 */
	public function __construct() {
		if ( is_admin() ) {
			add_action( 'admin_init', self::class . '::init' );
		} else {
			add_action( 'init', self::class . '::init' );
		}
	}

	/**
	 * Init
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function init(): void {
		// DB schema versioning is independent of the plugin version below SURECOOKIE_DB_VERSION can change without a plugin release, so this must run before the saved-version early returns.
		self::maybe_upgrade_db();

		do_action( 'surecookie_update_before' );

		// Get auto saved version number.
		$saved_version = get_option( 'surecookie_saved_version', '' );

		// Update auto saved version number.
		if ( ! $saved_version ) {
			update_option( 'surecookie_saved_version', SURECOOKIE_VERSION );
			return;
		}

		// If equals then return.
		if ( version_compare( strval( $saved_version ), SURECOOKIE_VERSION, '=' ) ) {
			return;
		}

		self::manage_backward();

		// Update auto saved version number.
		update_option( 'surecookie_saved_version', SURECOOKIE_VERSION );

		do_action( 'surecookie_update_after' );

		// Finally flush rewrite rules.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Manage backward compatibility.
	 *
	 * @since 0.0.1
	 * @return void
	 */
	public static function manage_backward(): void {
		$saved_version = strval( get_option( 'surecookie_saved_version', false ) );

		// If the saved version is already set then manage some backward compatibility.
		if ( ! $saved_version ) {
			return;
		}

		self::migrate_consent_log_unique_key();
	}

	/**
	 * Re-run dbDelta when SURECOOKIE_DB_VERSION changed, so column additions
	 * apply without a deactivate/reactivate cycle.
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function maybe_upgrade_db(): void {
		$stored = is_multisite()
			? get_network_option( null, 'surecookie_db_version' )
			: get_option( 'surecookie_db_version' );

		if ( $stored === SURECOOKIE_DB_VERSION ) {
			return;
		}

		try {
			DB_Init::create_db_tables();

			// dbDelta() swallows SQL errors (e.g. a failed ALTER on a locked table) - verify the column landed before storing the version, otherwise the upgrade would never retry.
			if ( self::schema_upgrade_applied() ) {
				self::store_db_version();
			}
		} catch ( \Throwable $e ) {
			Logger::get_instance()->log( 'SureCookie: schema upgrade failed - ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Persist the current DB schema version (network-wide on multisite).
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public static function store_db_version(): void {
		if ( is_multisite() ) {
			update_network_option( null, 'surecookie_db_version', SURECOOKIE_DB_VERSION );
		} else {
			update_option( 'surecookie_db_version', SURECOOKIE_DB_VERSION );
		}
	}

	/**
	 * Spot-check the newest column added by the current SURECOOKIE_DB_VERSION.
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	private static function schema_upgrade_applied(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One-shot schema verification; %i is the WP 6.2+ identifier placeholder.
		$column = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $wpdb->prefix . SURECOOKIE_CONSENT_LOG_DB, 'is_forwarded' ) );

		return ! empty( $column );
	}

	/**
	 * Apply the consent-log UNIQUE KEY migration introduced for issue #457.
	 *
	 * `dbDelta` only runs on activation; WP auto-updates and file-swaps never
	 * trigger it. Without this step, existing installs would run the
	 * `INSERT … ON DUPLICATE KEY UPDATE` in {@see ConsentLog::upsert()}
	 * without the `UNIQUE KEY (session_id, timestamp)` the atomic upsert
	 * relies on - silently degrading to plain `INSERT` and leaving the race
	 * condition in place.
	 *
	 * Flow:
	 *   1. Exit fast if the migration flag is already set (idempotent).
	 *   2. (MySQL only) Drop duplicate `(session_id, timestamp)` rows - `dbDelta`
	 *      silently skips `ALTER TABLE ADD UNIQUE KEY` on duplicates. SQLite is a
	 *      fresh-install-only target with no legacy rows, and its driver does not
	 *      support multi-table `DELETE ... JOIN`, so this step is skipped there.
	 *   3. Re-run the table DDL via `ConsentLog::create()`.
	 *   4. Store the flag only after `session_unique` is confirmed present, so a
	 *      partially-created table (#381) retries instead of permanently masking
	 *      a missing key. MySQL confirms via `information_schema.STATISTICS`; on
	 *      SQLite that view is unreliable (WordPress/sqlite-database-integration#146),
	 *      so a successful `CREATE TABLE` - which carries the key inline - is the signal.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private static function migrate_consent_log_unique_key(): void {
		$flag = 'surecookie_consent_log_unique_key_migrated';
		if ( get_option( $flag ) ) {
			return;
		}

		global $wpdb;

		$consent_log = ConsentLog::get_instance();
		$table       = $consent_log->get_tablename();
		$is_sqlite   = self::is_sqlite();

		// Step 1 (MySQL only): dedupe pre-existing duplicate rows that would make
		// `dbDelta` silently skip the UNIQUE KEY on ALTER. Multi-table
		// `DELETE ... JOIN` is MySQL-specific and errors on the SQLite driver.
		if ( ! $is_sqlite ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One-time upgrade dedup; the table name is a server-controlled constant from wpdb prefix + plugin.
			$dedup_sql = $wpdb->prepare(
				'DELETE t1 FROM %i AS t1 INNER JOIN %i AS t2 ON t1.session_id = t2.session_id AND t1.timestamp = t2.timestamp AND t1.id < t2.id',
				$table,
				$table
			);
			if ( is_string( $dedup_sql ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $dedup_sql is the output of $wpdb->prepare() above.
				$wpdb->query( $dedup_sql );
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// Step 2: (re)apply the schema. `create()` runs the CREATE TABLE DDL
		// (idempotent via dbDelta), which carries session_unique inline on both
		// engines and back-fills a partially-created table, and returns whether
		// the table then exists.
		$table_ready = $consent_log->create( $consent_log->get_columns_definition() );

		// Step 3 (SQLite): `information_schema.STATISTICS` is unreliable on the
		// SQLite driver, so a successful CREATE TABLE - which defines
		// session_unique inline - is the signal that the key is present.
		if ( $is_sqlite ) {
			if ( $table_ready ) {
				update_option( $flag, time() );
			}
			return;
		}

		// Step 3 (MySQL): verify the UNIQUE KEY actually landed. `dbDelta`
		// swallows the 1062 duplicate-key error, so a failed migration is
		// otherwise invisible - leaving the flag unset lets it retry.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder
		$index_exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME   = %s
				   AND INDEX_NAME   = %s',
				$table,
				'session_unique'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnsupportedPlaceholder

		if ( $index_exists >= 1 ) {
			update_option( $flag, time() );
		}
	}

	/**
	 * Whether WordPress is running on the SQLite database driver (WordPress
	 * Playground / WP Studio) rather than MySQL/MariaDB.
	 *
	 * Detects the engine explicitly via the SQLite integration's own signals -
	 * `DB_ENGINE` (which its drop-in sets to `sqlite`) with the
	 * `SQLITE_DB_DROPIN_VERSION` constant as a fallback. An `information_schema`
	 * capability probe is deliberately avoided: the SQLite driver emulates
	 * `information_schema.TABLES`, so a probe returns true there and cannot tell
	 * the engines apart (WordPress/sqlite-database-integration#146).
	 *
	 * @since 1.2.3
	 * @return bool
	 */
	private static function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && DB_ENGINE === 'sqlite' )
			|| defined( 'SQLITE_DB_DROPIN_VERSION' );
	}
}
