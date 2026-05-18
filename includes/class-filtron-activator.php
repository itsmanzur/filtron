<?php
/**
 * Plugin activation: database tables and default options.
 *
 * @package Filtron
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Filtron_Activator
 */
class Filtron_Activator {

	/**
	 * Option: settings array.
	 */
	public const OPTION_SETTINGS = 'filtron_settings';

	/**
	 * Option: DB schema version.
	 */
	public const OPTION_DB_VERSION = 'filtron_db_version';

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		$prev_db = get_option( self::OPTION_DB_VERSION, '' );

		if ( $prev_db !== FILTRON_DB_VERSION ) {
			self::maybe_create_tables();
			update_option( self::OPTION_DB_VERSION, FILTRON_DB_VERSION );
			if ( '' !== $prev_db ) {
				self::set_index_needs_rebuild( true );
			}
		}

		self::set_default_options();
	}

	/**
	 * Create or upgrade tables. Safe to call from upgrades when DB version changes.
	 */
	public static function maybe_create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$groups  = $wpdb->prefix . 'filtron_groups';
		$items   = $wpdb->prefix . 'filtron_items';
		$index   = $wpdb->prefix . 'filtron_index';
		$analytics = $wpdb->prefix . 'filtron_analytics';

		$sql_groups = "CREATE TABLE {$groups} (
	id int unsigned NOT NULL AUTO_INCREMENT,
	name varchar(100) NOT NULL,
	post_type varchar(50) NOT NULL DEFAULT 'product',
	display_loc varchar(50) NOT NULL DEFAULT 'sidebar',
	sort_order tinyint unsigned NOT NULL DEFAULT 0,
	is_active tinyint(1) NOT NULL DEFAULT 1,
	created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	KEY idx_post_type (post_type,is_active)
) ENGINE=InnoDB {$charset_collate};";

		$sql_items = "CREATE TABLE {$items} (
	id int unsigned NOT NULL AUTO_INCREMENT,
	group_id int unsigned NOT NULL,
	filter_type varchar(30) NOT NULL,
	source_type varchar(20) NOT NULL,
	source_key varchar(100) NOT NULL,
	label varchar(100) NOT NULL,
	sort_order tinyint unsigned NOT NULL DEFAULT 0,
	config_json longtext NULL,
	is_active tinyint(1) NOT NULL DEFAULT 1,
	PRIMARY KEY  (id),
	KEY idx_group_active (group_id,is_active,sort_order)
) ENGINE=InnoDB {$charset_collate};";

		$sql_index = "CREATE TABLE {$index} (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	post_id bigint unsigned NOT NULL,
	post_type varchar(50) NOT NULL,
	filter_key varchar(100) NOT NULL,
	filter_value varchar(200) NOT NULL,
	filter_value_num decimal(18,4) NULL,
	PRIMARY KEY  (id),
	KEY idx_main (post_type,filter_key,filter_value,post_id),
	KEY idx_numeric (post_type,filter_key,filter_value_num),
	KEY idx_post (post_id)
) ENGINE=InnoDB {$charset_collate};";

		// DESC on last index column not supported by dbDelta; same columns indexed.
		$sql_analytics = "CREATE TABLE {$analytics} (
	id bigint unsigned NOT NULL AUTO_INCREMENT,
	group_id int unsigned NOT NULL,
	filter_combo_json longtext NOT NULL,
	combo_hash char(32) NOT NULL,
	result_count int unsigned NOT NULL DEFAULT 0,
	usage_count int unsigned NOT NULL DEFAULT 0,
	last_used_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY  (id),
	UNIQUE KEY idx_combo (group_id,combo_hash),
	KEY idx_zero (result_count,usage_count),
	KEY idx_popular (group_id,usage_count)
) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $sql_groups );
		dbDelta( $sql_items );
		dbDelta( $sql_index );
		dbDelta( $sql_analytics );

		self::maybe_add_items_foreign_key( $groups, $items );
	}

	/**
	 * dbDelta does not create FOREIGN KEY; add after tables exist (InnoDB).
	 *
	 * @param string $groups_table Full table name filtron_groups.
	 * @param string $items_table  Full table name filtron_items.
	 */
	private static function maybe_add_items_foreign_key( string $groups_table, string $items_table ): void {
		global $wpdb;

		if ( ! self::table_exists( $items_table ) || ! self::table_exists( $groups_table ) ) {
			return;
		}

		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
				WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = %s',
				DB_NAME,
				$items_table,
				'fk_fi_group',
				'FOREIGN KEY'
			)
		);

		if ( (int) $count > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static DDL, names from $wpdb->prefix.
		$wpdb->query(
			"ALTER TABLE `{$items_table}` ADD CONSTRAINT fk_fi_group FOREIGN KEY (group_id) REFERENCES `{$groups_table}`(id) ON DELETE CASCADE"
		);
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full prefixed table name.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_settings(): array {
		return array(
			'delete_data_on_uninstall' => false,
			'index_needs_rebuild'      => false,
			'frontend_debug'           => false,
		);
	}

	/**
	 * Mark index stale (e.g. after schema change).
	 *
	 * @param bool $needed Whether rebuild is recommended.
	 */
	public static function set_index_needs_rebuild( bool $needed ): void {
		$opts = get_option( self::OPTION_SETTINGS, self::default_settings() );
		if ( ! is_array( $opts ) ) {
			$opts = self::default_settings();
		}
		$opts                        = wp_parse_args( $opts, self::default_settings() );
		$opts['index_needs_rebuild'] = $needed;
		update_option( self::OPTION_SETTINGS, $opts );
	}

	/**
	 * Set default options when missing.
	 */
	private static function set_default_options(): void {
		if ( false === get_option( self::OPTION_SETTINGS ) ) {
			add_option( self::OPTION_SETTINGS, self::default_settings() );
			return;
		}
		$cur = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $cur ) ) {
			$cur = array();
		}
		if ( isset( $cur['remove_data_on_uninstall'] ) && ! isset( $cur['delete_data_on_uninstall'] ) ) {
			$cur['delete_data_on_uninstall'] = ! empty( $cur['remove_data_on_uninstall'] );
		}
		unset( $cur['remove_data_on_uninstall'] );
		$merged = wp_parse_args( $cur, self::default_settings() );
		if ( $merged !== $cur ) {
			update_option( self::OPTION_SETTINGS, $merged );
		}
	}
}
