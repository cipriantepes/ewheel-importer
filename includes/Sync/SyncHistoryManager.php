<?php
/**
 * Sync History Manager.
 *
 * Manages sync history records in the database.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Database\SchemaInstaller;
use Trotibike\EwheelImporter\Log\PersistentLogger;

/**
 * Manages sync history in the database.
 */
class SyncHistoryManager
{
    /**
     * Status constants.
     */
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_PAUSED = 'paused';

    /**
     * Type constants.
     */
    public const TYPE_FULL = 'full';
    public const TYPE_INCREMENTAL = 'incremental';

    /**
     * Create a new sync history record.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param string   $sync_type  Sync type (full or incremental).
     * @param int|null $profile_id Profile ID.
     * @return bool
     */
    public static function create(string $sync_id, string $sync_type = self::TYPE_FULL, ?int $profile_id = null): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        // Check if table exists
        if (!self::table_exists()) {
            return false;
        }

        return $wpdb->insert(
            $table_name,
            [
                'sync_id' => $sync_id,
                'profile_id' => $profile_id,
                'sync_type' => $sync_type,
                'status' => self::STATUS_RUNNING,
                'started_at' => current_time('mysql'),
            ],
            ['%s', '%d', '%s', '%s', '%s']
        ) !== false;
    }

    /**
     * Update sync progress.
     *
     * @param string $sync_id   Sync ID.
     * @param array  $data      Data to update.
     * @return bool
     */
    public static function update(string $sync_id, array $data): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return false;
        }

        $allowed_fields = [
            'status',
            'products_processed',
            'products_created',
            'products_updated',
            'products_failed',
            'error_count',
            'completed_at',
            'duration_seconds',
        ];

        $update_data = [];
        $formats = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $formats[] = is_int($data[$field]) ? '%d' : '%s';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        return $wpdb->update(
            $table_name,
            $update_data,
            ['sync_id' => $sync_id],
            $formats,
            ['%s']
        ) !== false;
    }

    /**
     * Increment a counter field.
     *
     * @param string $sync_id Sync ID.
     * @param string $field   Field to increment (products_processed, products_created, etc.).
     * @param int    $amount  Amount to increment by.
     * @return bool
     */
    public static function increment(string $sync_id, string $field, int $amount = 1): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return false;
        }

        $allowed_fields = [
            'products_processed',
            'products_created',
            'products_updated',
            'products_failed',
            'error_count',
        ];

        if (!in_array($field, $allowed_fields, true)) {
            return false;
        }

        return $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name SET $field = $field + %d WHERE sync_id = %s",
                $amount,
                $sync_id
            )
        ) !== false;
    }

    /**
     * Mark sync as completed.
     *
     * @param string $sync_id Sync ID.
     * @return bool
     */
    public static function complete(string $sync_id): bool
    {
        $record = self::get($sync_id);
        if (!$record) {
            return false;
        }

        $started = strtotime($record['started_at']);
        $now = time();
        $duration = $now - $started;

        // Get error count from persistent logger
        $error_count = PersistentLogger::get_error_count_for_batch($sync_id);

        return self::update($sync_id, [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => current_time('mysql'),
            'duration_seconds' => $duration,
            'error_count' => $error_count,
        ]);
    }

    /**
     * Mark sync as failed.
     *
     * @param string $sync_id Sync ID.
     * @param string $reason  Optional failure reason.
     * @return bool
     */
    public static function fail(string $sync_id, string $reason = ''): bool
    {
        $record = self::get($sync_id);
        if (!$record) {
            return false;
        }

        $started = strtotime($record['started_at']);
        $now = time();
        $duration = $now - $started;

        if (!empty($reason)) {
            PersistentLogger::error("Sync failed: $reason", null, $sync_id, $record['profile_id'] ?? null);
        }

        return self::update($sync_id, [
            'status' => self::STATUS_FAILED,
            'completed_at' => current_time('mysql'),
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * Mark sync as stopped by user.
     *
     * @param string $sync_id Sync ID.
     * @return bool
     */
    public static function stop(string $sync_id): bool
    {
        $record = self::get($sync_id);
        if (!$record) {
            return false;
        }

        $started = strtotime($record['started_at']);
        $now = time();
        $duration = $now - $started;

        return self::update($sync_id, [
            'status' => self::STATUS_STOPPED,
            'completed_at' => current_time('mysql'),
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * Mark sync as paused by user.
     *
     * @param string $sync_id Sync ID.
     * @return bool
     */
    public static function pause(string $sync_id): bool
    {
        return self::update($sync_id, [
            'status' => self::STATUS_PAUSED,
        ]);
    }

    /**
     * Resume a paused sync (set back to running).
     *
     * @param string $sync_id Sync ID.
     * @return bool
     */
    public static function resume(string $sync_id): bool
    {
        return self::update($sync_id, [
            'status' => self::STATUS_RUNNING,
        ]);
    }

    /**
     * Get a paused sync for a profile.
     *
     * @param int|null $profile_id Profile ID.
     * @return array|null
     */
    public static function get_paused_sync(?int $profile_id = null): ?array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return null;
        }

        if ($profile_id !== null) {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE status = %s AND profile_id = %d ORDER BY started_at DESC LIMIT 1",
                    self::STATUS_PAUSED,
                    $profile_id
                ),
                ARRAY_A
            );
        } else {
            $result = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE status = %s ORDER BY started_at DESC LIMIT 1",
                    self::STATUS_PAUSED
                ),
                ARRAY_A
            );
        }

        return $result ?: null;
    }

    /**
     * Get a sync history record by ID.
     *
     * @param string $sync_id Sync ID.
     * @return array|null
     */
    public static function get(string $sync_id): ?array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return null;
        }

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE sync_id = %s", $sync_id),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Get recent sync history.
     *
     * @param int      $limit      Maximum number of records.
     * @param int|null $profile_id Profile ID (null for all profiles).
     * @return array
     */
    public static function get_recent(int $limit = 10, ?int $profile_id = null): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return [];
        }

        if ($profile_id !== null) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE profile_id = %d ORDER BY started_at DESC LIMIT %d",
                    $profile_id,
                    $limit
                ),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name ORDER BY started_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get sync statistics.
     *
     * @param int|null $profile_id Profile ID (null for all profiles).
     * @return array
     */
    public static function get_stats(?int $profile_id = null): array
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return [
                'total_syncs' => 0,
                'successful_syncs' => 0,
                'failed_syncs' => 0,
                'total_products_processed' => 0,
                'avg_duration_seconds' => 0,
            ];
        }

        $where = '';
        $params = [];

        if ($profile_id !== null) {
            $where = 'WHERE profile_id = %d';
            $params[] = $profile_id;
        }

        $sql = "SELECT
            COUNT(*) as total_syncs,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_syncs,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_syncs,
            SUM(products_processed) as total_products_processed,
            AVG(duration_seconds) as avg_duration_seconds
        FROM $table_name $where";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }

        $stats = $wpdb->get_row($sql, ARRAY_A);

        return $stats ?: [
            'total_syncs' => 0,
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'total_products_processed' => 0,
            'avg_duration_seconds' => 0,
        ];
    }

    /**
     * Check if there's an active sync running.
     *
     * @param int|null $profile_id Profile ID (null to check any profile).
     * @return bool
     */
    public static function has_running_sync(?int $profile_id = null): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return false;
        }

        if ($profile_id !== null) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE status = %s AND profile_id = %d",
                    self::STATUS_RUNNING,
                    $profile_id
                )
            );
        } else {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE status = %s",
                    self::STATUS_RUNNING
                )
            );
        }

        return $count > 0;
    }

    /**
     * Get the currently running sync ID if any.
     *
     * @param int|null $profile_id Profile ID (null for any profile).
     * @return string|null
     */
    public static function get_running_sync_id(?int $profile_id = null): ?string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return null;
        }

        if ($profile_id !== null) {
            $sync_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT sync_id FROM $table_name WHERE status = %s AND profile_id = %d ORDER BY started_at DESC LIMIT 1",
                    self::STATUS_RUNNING,
                    $profile_id
                )
            );
        } else {
            $sync_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT sync_id FROM $table_name WHERE status = %s ORDER BY started_at DESC LIMIT 1",
                    self::STATUS_RUNNING
                )
            );
        }

        return $sync_id ?: null;
    }

    /**
     * Get profile ID for a sync.
     *
     * @param string $sync_id Sync ID.
     * @return int|null
     */
    public static function get_profile_id(string $sync_id): ?int
    {
        $record = self::get($sync_id);
        if (!$record || !isset($record['profile_id'])) {
            return null;
        }
        return (int) $record['profile_id'] ?: null;
    }

    /**
     * Clean up old history records.
     *
     * @param int $keep Number of records to keep.
     * @return int Number of records deleted.
     */
    public static function cleanup(int $keep = 50): int
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        if (!self::table_exists()) {
            return 0;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        if ($count <= $keep) {
            return 0;
        }

        $to_delete = $count - $keep;

        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name ORDER BY started_at ASC LIMIT %d",
                $to_delete
            )
        );
    }

    /**
     * Check if the table exists.
     *
     * @return bool
     */
    private static function table_exists(): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . SchemaInstaller::SYNC_HISTORY_TABLE;

        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
    }

    /**
     * Format duration for display.
     *
     * @param int $seconds Duration in seconds.
     * @return string
     */
    public static function format_duration(int $seconds): string
    {
        if ($seconds < 60) {
            return sprintf(_n('%d second', '%d seconds', $seconds, 'ewheel-importer'), $seconds);
        }

        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf(
                /* translators: 1: minutes, 2: seconds */
                __('%1$dm %2$ds', 'ewheel-importer'),
                $minutes,
                $remaining_seconds
            );
        }

        $hours = floor($minutes / 60);
        $remaining_minutes = $minutes % 60;

        return sprintf(
            /* translators: 1: hours, 2: minutes, 3: seconds */
            __('%1$dh %2$dm %3$ds', 'ewheel-importer'),
            $hours,
            $remaining_minutes,
            $remaining_seconds
        );
    }
}
