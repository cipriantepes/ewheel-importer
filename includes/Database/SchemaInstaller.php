<?php
/**
 * Schema Installer.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Database;

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
     * Install or update the database schema.
     *
     * @return void
     */
    public static function install(): void
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
}
