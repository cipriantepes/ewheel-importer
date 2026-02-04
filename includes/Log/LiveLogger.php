<?php
/**
 * Live Logger service.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Log;

/**
 * Service to handle live logging to a transient for admin display.
 */
class LiveLogger
{
    /**
     * Transient key for logs.
     */
    private const TRANSIENT_KEY = 'ewheel_importer_live_logs';

    /**
     * Max logs to keep.
     */
    private const MAX_LOGS = 100;

    /**
     * Add a log entry.
     *
     * @param string $message The message to log.
     * @param string $type    The type (info, error, success).
     * @return void
     */
    public static function log(string $message, string $type = 'info'): void
    {
        $logs = get_transient(self::TRANSIENT_KEY);
        if (!is_array($logs)) {
            $logs = [];
        }

        $entry = [
            'time' => current_time('H:i:s'),
            'message' => $message,
            'type' => $type,
        ];

        // Prepend new log
        array_unshift($logs, $entry);

        // Limit size
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }

        set_transient(self::TRANSIENT_KEY, $logs, 24 * 3600);
    }

    /**
     * Get logs.
     *
     * @return array
     */
    public static function get_logs(): array
    {
        $logs = get_transient(self::TRANSIENT_KEY);
        return is_array($logs) ? $logs : [];
    }

    /**
     * Clear logs.
     *
     * @return void
     */
    public static function clear(): void
    {
        delete_transient(self::TRANSIENT_KEY);
    }
}
