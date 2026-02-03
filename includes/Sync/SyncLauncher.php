<?php
/**
 * Sync Launcher.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Config\Configuration;

/**
 * Initiates the background sync process.
 */
class SyncLauncher
{

    /**
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

    /**
     * Constructor.
     *
     * @param Configuration $config Configuration.
     */
    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    /**
     * Start a full sync.
     *
     * @param int $limit Optional limit of products to sync. 0 for unlimited.
     * @return string Sync ID.
     */
    public function start_sync(int $limit = 0): string
    {
        $sync_id = uniqid('sync_');

        // Initialize sync status
        update_option(
            'ewheel_importer_sync_status',
            [
                'id' => $sync_id,
                'status' => 'running',
                'started_at' => time(),
                'processed' => 0,
                'page' => 0,
                'limit' => $limit,
                'type' => 'full',
            ]
        );

        // Schedule first batch immediately
        as_schedule_single_action(
            time(),
            'ewheel_importer_process_batch',
            [
                'page' => 0,
                'sync_id' => $sync_id,
                'since' => '',
            ]
        );

        return $sync_id;
    }
}
