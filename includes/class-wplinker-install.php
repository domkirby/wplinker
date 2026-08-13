<?php
/**
 * Schema installation and upgrades.
 *
 * @package WPLinker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the custom routes table.
 */
class WPLinker_Install {

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'wplinker_db_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Installs the schema when the stored version is behind the code version.
	 *
	 * Covers the case where the plugin files are updated without the activation
	 * hook firing (e.g. an FTP/git deploy).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::VERSION_OPTION ) === WPLINKER_DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates or updates the routes table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = WPLinker_Routes::table();
		$collate = $wpdb->get_charset_collate();

		// The source_path index is capped at 191 characters so it stays inside
		// the 767 byte index limit on older MySQL/InnoDB builds using utf8mb4.
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_path varchar(255) NOT NULL DEFAULT '',
			destination_url text NOT NULL,
			status_code smallint(5) unsigned NOT NULL DEFAULT 301,
			match_type varchar(20) NOT NULL DEFAULT 'exact',
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY source_match (source_path(191),match_type),
			KEY match_type (match_type)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, WPLINKER_DB_VERSION, true );

		WPLinker_Routes::flush_cache();
	}
}
