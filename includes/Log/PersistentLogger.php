<?php
/**
 * Persistent Logger service.
 *
 * Stores logs in a database table for persistent error tracking.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Log;

/**
 * Service to handle persistent logging to a database table.
 */
class PersistentLogger
{
    /**
     * Table name without prefix.
     */
    public const TABLE_NAME = 'ewheel_sync_logs';

    /**
     * Maximum number of entries to keep.
     */
    private const MAX_ENTRIES = 1000;

    /**
     * Log levels.
     */
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_SUCCESS = 'success';

    /**
     * Add a log entry.
     *
     * @param string      $message    The message to log.
     * @param string      $level      The level (info, warning, error, success).
     * @param string|null $sku        Optional product SKU reference.
     * @param string|null $batch_id   Optional batch/sync ID.
     * @param int|null    $profile_id Optional profile ID.
     * @return bool
     */
    public static function log(
        string $message,
        string $level = self::LEVEL_INFO,
        ?string $sku = null,
        ?string $batch_id = null,
        ?int $profile_id = null
    ): bool {
        global $wpdb;
        if (null === $wpdb) {
            return false;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return false;
        }

        $result = $wpdb->insert(
            $table_name,
            [
                'level' => $level,
                'message' => $message,
                'product_sku' => $sku,
                'batch_id' => $batch_id,
                'profile_id' => $profile_id,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s']
        );

        // Also log to LiveLogger for real-time display
        LiveLogger::log($message, $level);

        // Auto-prune old entries
        self::prune_old_entries();

        return $result !== false;
    }

    /**
     * Log an info message.
     *
     * @param string      $message    The message.
     * @param string|null $sku        Optional SKU.
     * @param string|null $batch_id   Optional batch ID.
     * @param int|null    $profile_id Optional profile ID.
     * @return bool
     */
    public static function info(string $message, ?string $sku = null, ?string $batch_id = null, ?int $profile_id = null): bool
    {
        return self::log($message, self::LEVEL_INFO, $sku, $batch_id, $profile_id);
    }

    /**
     * Log a warning message.
     *
     * @param string      $message    The message.
     * @param string|null $sku        Optional SKU.
     * @param string|null $batch_id   Optional batch ID.
     * @param int|null    $profile_id Optional profile ID.
     * @return bool
     */
    public static function warning(string $message, ?string $sku = null, ?string $batch_id = null, ?int $profile_id = null): bool
    {
        return self::log($message, self::LEVEL_WARNING, $sku, $batch_id, $profile_id);
    }

    /**
     * Log an error message.
     *
     * @param string      $message    The message.
     * @param string|null $sku        Optional SKU.
     * @param string|null $batch_id   Optional batch ID.
     * @param int|null    $profile_id Optional profile ID.
     * @return bool
     */
    public static function error(string $message, ?string $sku = null, ?string $batch_id = null, ?int $profile_id = null): bool
    {
        return self::log($message, self::LEVEL_ERROR, $sku, $batch_id, $profile_id);
    }

    /**
     * Log a success message.
     *
     * @param string      $message    The message.
     * @param string|null $sku        Optional SKU.
     * @param string|null $batch_id   Optional batch ID.
     * @param int|null    $profile_id Optional profile ID.
     * @return bool
     */
    public static function success(string $message, ?string $sku = null, ?string $batch_id = null, ?int $profile_id = null): bool
    {
        return self::log($message, self::LEVEL_SUCCESS, $sku, $batch_id, $profile_id);
    }

    /**
     * Get logs with optional filtering.
     *
     * @param array $args {
     *     Optional. Arguments for filtering logs.
     *
     *     @type string   $level      Filter by level.
     *     @type string   $batch_id   Filter by batch ID.
     *     @type string   $sku        Filter by product SKU.
     *     @type int|null $profile_id Filter by profile ID.
     *     @type int      $limit      Maximum entries to return. Default 100.
     *     @type int      $offset     Offset for pagination. Default 0.
     *     @type string   $order      Order direction (ASC or DESC). Default DESC.
     * }
     * @return array
     */
    public static function get_logs(array $args = []): array
    {
        global $wpdb;
        if (null === $wpdb) {
            return [];
        }

        $defaults = [
            'level' => '',
            'batch_id' => '',
            'sku' => '',
            'profile_id' => null,
            'limit' => 100,
            'offset' => 0,
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return [];
        }

        $where = ['1=1'];
        $prepare_args = [];

        if (!empty($args['level'])) {
            $where[] = 'level = %s';
            $prepare_args[] = $args['level'];
        }

        if (!empty($args['batch_id'])) {
            $where[] = 'batch_id = %s';
            $prepare_args[] = $args['batch_id'];
        }

        if (!empty($args['sku'])) {
            $where[] = 'product_sku LIKE %s';
            $prepare_args[] = '%' . $wpdb->esc_like($args['sku']) . '%';
        }

        if ($args['profile_id'] !== null) {
            $where[] = 'profile_id = %d';
            $prepare_args[] = $args['profile_id'];
        }

        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';
        $limit = absint($args['limit']);
        $offset = absint($args['offset']);

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT * FROM `{$table_name}` WHERE {$where_clause} ORDER BY created_at {$order} LIMIT %d OFFSET %d";
        $prepare_args[] = $limit;
        $prepare_args[] = $offset;

        if (!empty($prepare_args)) {
            $sql = $wpdb->prepare($sql, $prepare_args);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    /**
     * Get total log count with optional filtering.
     *
     * @param array $args Same as get_logs().
     * @return int
     */
    public static function get_count(array $args = []): int
    {
        global $wpdb;
        if (null === $wpdb) {
            return 0;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return 0;
        }

        $where = ['1=1'];
        $prepare_args = [];

        if (!empty($args['level'])) {
            $where[] = 'level = %s';
            $prepare_args[] = $args['level'];
        }

        if (!empty($args['batch_id'])) {
            $where[] = 'batch_id = %s';
            $prepare_args[] = $args['batch_id'];
        }

        if (!empty($args['sku'])) {
            $where[] = 'product_sku LIKE %s';
            $prepare_args[] = '%' . $wpdb->esc_like($args['sku']) . '%';
        }

        if (isset($args['profile_id']) && $args['profile_id'] !== null) {
            $where[] = 'profile_id = %d';
            $prepare_args[] = $args['profile_id'];
        }

        $where_clause = implode(' AND ', $where);

        $sql = "SELECT COUNT(*) FROM `{$table_name}` WHERE {$where_clause}";

        if (!empty($prepare_args)) {
            $sql = $wpdb->prepare($sql, $prepare_args);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Get error count for a specific sync/batch.
     *
     * @param string   $batch_id   The batch/sync ID.
     * @param int|null $profile_id Optional profile ID.
     * @return int
     */
    public static function get_error_count_for_batch(string $batch_id, ?int $profile_id = null): int
    {
        $args = [
            'batch_id' => $batch_id,
            'level' => self::LEVEL_ERROR,
        ];

        if ($profile_id !== null) {
            $args['profile_id'] = $profile_id;
        }

        return self::get_count($args);
    }

    /**
     * Clear all logs.
     *
     * @return bool
     */
    public static function clear_all(): bool
    {
        global $wpdb;
        if (null === $wpdb) {
            return false;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return false;
        }

        $truncate_sql = "TRUNCATE TABLE `{$table_name}`";
        return $wpdb->query($truncate_sql) !== false;
    }

    /**
     * Clear logs for a specific profile.
     *
     * @param int $profile_id Profile ID.
     * @return int Number of rows deleted.
     */
    public static function clear_for_profile(int $profile_id): int
    {
        global $wpdb;
        if (null === $wpdb) {
            return 0;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return 0;
        }

        $delete_sql = $wpdb->prepare("DELETE FROM `{$table_name}` WHERE profile_id = %d", $profile_id);
        return (int) $wpdb->query($delete_sql);
    }

    /**
     * Clear logs older than a certain number of days.
     *
     * @param int $days Number of days to keep.
     * @return int Number of rows deleted.
     */
    public static function clear_older_than(int $days): int
    {
        global $wpdb;
        if (null === $wpdb) {
            return 0;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Check if table exists
        $check_sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($check_sql) !== $table_name) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $delete_sql = $wpdb->prepare("DELETE FROM `{$table_name}` WHERE created_at < %s", $cutoff);
        return (int) $wpdb->query($delete_sql);
    }

    /**
     * Prune old entries to keep table size under control.
     *
     * Keeps only the last MAX_ENTRIES entries.
     *
     * @return void
     */
    private static function prune_old_entries(): void
    {
        global $wpdb;
        if (null === $wpdb) {
            return;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Only run pruning occasionally (1% of the time)
        if (wp_rand(1, 100) !== 1) {
            return;
        }

        $count_sql = "SELECT COUNT(*) FROM `{$table_name}`";
        $count = (int) $wpdb->get_var($count_sql);

        if ($count > self::MAX_ENTRIES) {
            $to_delete = $count - self::MAX_ENTRIES;
            // Use subquery for SQLite compatibility (no DELETE...ORDER BY...LIMIT)
            $delete_sql = $wpdb->prepare(
                "DELETE FROM `{$table_name}` WHERE id IN (SELECT id FROM `{$table_name}` ORDER BY created_at ASC LIMIT %d)",
                $to_delete
            );
            $wpdb->query($delete_sql);
        }
    }

    /**
     * Install the database table.
     *
     * @return void
     */
    public static function install_table(): void
    {
        global $wpdb;
        if (null === $wpdb) {
            return;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            product_sku varchar(100) DEFAULT NULL,
            batch_id varchar(50) DEFAULT NULL,
            profile_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY batch_id (batch_id),
            KEY product_sku (product_sku),
            KEY profile_id (profile_id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
