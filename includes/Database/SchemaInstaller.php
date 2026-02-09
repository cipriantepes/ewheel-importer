<?php
/**
 * Schema Installer.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Database;

use Trotibike\EwheelImporter\Log\PersistentLogger;
use Trotibike\EwheelImporter\Repository\ProfileRepository;

/**
 * Handles database schema installation and updates.
 */
class SchemaInstaller
{

    /**
     * Table name without prefix.
     */
    public const TABLE_NAME = 'ewheel_translations';

    /**
     * Sync history table name.
     */
    public const SYNC_HISTORY_TABLE = 'ewheel_sync_history';

    /**
     * Profiles table name.
     */
    public const PROFILES_TABLE = 'ewheel_profiles';

    /**
     * DB version option name.
     */
    private const DB_VERSION_OPTION = 'ewheel_importer_db_version';

    /**
     * Current DB version.
     */
    private const CURRENT_DB_VERSION = '2.0.0';

    /**
     * Install or update the database schema.
     *
     * @return void
     */
    public static function install(): void
    {
        self::install_translations_table();
        self::install_sync_logs_table();
        self::install_sync_history_table();
        self::install_profiles_table();
        self::run_migrations();

        update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);
    }

    /**
     * Install the translations table.
     *
     * @return void
     */
    private static function install_translations_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_hash char(32) NOT NULL,
            source_text longtext NOT NULL,
            translated_text longtext NOT NULL,
            source_lang varchar(10) NOT NULL,
            target_lang varchar(10) NOT NULL,
            service varchar(20) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_hash (source_hash),
            KEY source_lang (source_lang),
            KEY target_lang (target_lang)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Install the sync logs table.
     *
     * @return void
     */
    private static function install_sync_logs_table(): void
    {
        PersistentLogger::install_table();
    }

    /**
     * Install the sync history table.
     *
     * @return void
     */
    private static function install_sync_history_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::SYNC_HISTORY_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sync_id varchar(50) NOT NULL,
            profile_id bigint(20) unsigned DEFAULT NULL,
            sync_type varchar(20) NOT NULL DEFAULT 'full',
            status varchar(20) NOT NULL DEFAULT 'running',
            products_processed int(11) unsigned NOT NULL DEFAULT 0,
            products_created int(11) unsigned NOT NULL DEFAULT 0,
            products_updated int(11) unsigned NOT NULL DEFAULT 0,
            products_failed int(11) unsigned NOT NULL DEFAULT 0,
            error_count int(11) unsigned NOT NULL DEFAULT 0,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            duration_seconds int(11) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY sync_id (sync_id),
            KEY profile_id (profile_id),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Install the profiles table.
     *
     * @return void
     */
    private static function install_profiles_table(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::PROFILES_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            filters longtext DEFAULT NULL,
            settings longtext DEFAULT NULL,
            category_mappings longtext DEFAULT NULL,
            last_sync datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_active (is_active)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Run database migrations.
     *
     * @return void
     */
    private static function run_migrations(): void
    {
        $current_version = get_option(self::DB_VERSION_OPTION, '1.0.0');

        // Migration from 1.x to 2.0 (add profile support)
        if (version_compare($current_version, '2.0.0', '<')) {
            self::migrate_to_v2();
        }
    }

    /**
     * Migrate to version 2.0 (add profile support).
     *
     * @return void
     */
    private static function migrate_to_v2(): void
    {
        global $wpdb;

        // Add profile_id column to sync_history if not exists
        $history_table = $wpdb->prefix . self::SYNC_HISTORY_TABLE;
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'profile_id'",
                DB_NAME,
                $history_table
            )
        );

        if (empty($column_exists)) {
            $alter_sql = "ALTER TABLE `{$history_table}` ADD COLUMN profile_id bigint(20) unsigned DEFAULT NULL AFTER sync_id";
            $wpdb->query($alter_sql);
            $index_sql = "ALTER TABLE `{$history_table}` ADD KEY profile_id (profile_id)";
            $wpdb->query($index_sql);
        }

        // Add profile_id column to sync_logs if not exists
        $logs_table = $wpdb->prefix . PersistentLogger::TABLE_NAME;
        $column_exists = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'profile_id'",
                DB_NAME,
                $logs_table
            )
        );

        if (empty($column_exists)) {
            $alter_sql = "ALTER TABLE `{$logs_table}` ADD COLUMN profile_id bigint(20) unsigned DEFAULT NULL AFTER batch_id";
            $wpdb->query($alter_sql);
            $index_sql = "ALTER TABLE `{$logs_table}` ADD KEY profile_id (profile_id)";
            $wpdb->query($index_sql);
        }

        // Create default profile from existing settings
        self::create_default_profile();

        // Update existing sync history to use default profile
        self::migrate_history_to_default_profile();
    }

    /**
     * Create the default profile from existing settings.
     *
     * @return void
     */
    private static function create_default_profile(): void
    {
        global $wpdb;

        $profiles_table = $wpdb->prefix . self::PROFILES_TABLE;

        // Check if default profile already exists
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $profiles_table WHERE slug = %s",
                'default'
            )
        );

        if ($exists) {
            return;
        }

        // Get existing settings
        $settings = [
            'exchange_rate'   => get_option('ewheel_importer_exchange_rate', 4.97),
            'markup_percent'  => get_option('ewheel_importer_markup_percent', 20.0),
            'sync_fields'     => get_option('ewheel_importer_sync_fields', null),
            'sync_protection' => get_option('ewheel_importer_sync_protection', null),
            'custom_patterns' => get_option('ewheel_importer_custom_patterns', null),
            'variation_mode'  => get_option('ewheel_importer_variation_mode', 'variable'),
            'sync_frequency'  => get_option('ewheel_importer_sync_frequency', 'daily'),
            'test_limit'      => 0,
        ];

        // Get existing category mappings
        $category_mappings = get_option('ewheel_importer_category_mappings', []);

        // Get last sync time
        $last_sync = get_option('ewheel_importer_last_sync', null);

        // Insert default profile
        $wpdb->insert(
            $profiles_table,
            [
                'name'              => 'Default',
                'slug'              => 'default',
                'is_active'         => 1,
                'filters'           => wp_json_encode([]),
                'settings'          => wp_json_encode($settings),
                'category_mappings' => wp_json_encode($category_mappings),
                'last_sync'         => $last_sync,
                'created_at'        => current_time('mysql', true),
                'updated_at'        => current_time('mysql', true),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Migrate existing sync history to default profile.
     *
     * @return void
     */
    private static function migrate_history_to_default_profile(): void
    {
        global $wpdb;

        $profiles_table = $wpdb->prefix . self::PROFILES_TABLE;
        $history_table = $wpdb->prefix . self::SYNC_HISTORY_TABLE;

        // Get default profile ID
        $default_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM $profiles_table WHERE slug = %s",
                'default'
            )
        );

        if (!$default_id) {
            return;
        }

        // Update all existing history entries without a profile_id
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $history_table SET profile_id = %d WHERE profile_id IS NULL",
                $default_id
            )
        );

        // Update all existing log entries without a profile_id
        $logs_table = $wpdb->prefix . PersistentLogger::TABLE_NAME;
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $logs_table SET profile_id = %d WHERE profile_id IS NULL",
                $default_id
            )
        );
    }

    /**
     * Check if a table exists.
     *
     * @param string $table_name Table name without prefix.
     * @return bool
     */
    public static function table_exists(string $table_name): bool
    {
        global $wpdb;

        $full_name = $wpdb->prefix . $table_name;
        return $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $full_name)
        ) === $full_name;
    }

    /**
     * Get current database version.
     *
     * @return string
     */
    public static function get_db_version(): string
    {
        return get_option(self::DB_VERSION_OPTION, '1.0.0');
    }

    /**
     * Check if migration is needed.
     *
     * @return bool
     */
    public static function needs_migration(): bool
    {
        return version_compare(self::get_db_version(), self::CURRENT_DB_VERSION, '<');
    }
}
