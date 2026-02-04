<?php
/**
 * Sync Batch Processor.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Config\Configuration;

/**
 * Handles processing of a single sync batch.
 */
class SyncBatchProcessor
{

    /**
     * Batch size.
     */
    private const BATCH_SIZE = 50;

    /**
     * Ewheel API client.
     *
     * @var EwheelApiClient
     */
    private EwheelApiClient $api_client;

    /**
     * WooCommerce Sync service.
     *
     * @var WooCommerceSync
     */
    private WooCommerceSync $woo_sync;

    /**
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

    /**
     * Constructor.
     *
     * @param EwheelApiClient $api_client API client.
     * @param WooCommerceSync $woo_sync   WooCommerce Sync.
     * @param Configuration   $config     Configuration.
     */
    public function __construct(
        EwheelApiClient $api_client,
        WooCommerceSync $woo_sync,
        Configuration $config
    ) {
        $this->api_client = $api_client;
        $this->woo_sync = $woo_sync;
        $this->config = $config;
    }

    /**
     * Process a single batch.
     *
     * @param int    $page      Page number (0-indexed).
     * @param string $sync_id   Unique ID for this sync session.
     * @param string $since     Optional date string for incremental sync.
     * @return void
     */
    public function process_batch(int $page, string $sync_id, string $since = ''): void
    {
        try {
            // Check status for limit
            $status = get_option('ewheel_importer_sync_status', []);
            $limit = isset($status['limit']) ? (int) $status['limit'] : 0;
            $processed = isset($status['processed']) ? (int) $status['processed'] : 0;

            // Check if limit already reached
            if ($limit > 0 && $processed >= $limit) {
                $this->finish_sync($sync_id);
                return;
            }

            // Check if sync should stop
            if (get_option('ewheel_importer_stop_sync_' . $sync_id)) {
                return;
            }

            // Fetch products for this page
            if (!empty($since)) {
                $products = $this->api_client->get_products_since($since, $page, self::BATCH_SIZE);
            } else {
                $products = $this->api_client->get_products($page, self::BATCH_SIZE, ['Active' => 1]);
            }

            if (empty($products)) {
                \Trotibike\EwheelImporter\Log\LiveLogger::log("Batch Info: No products returned from API. Batch complete (empty response).", 'info');
                $this->finish_sync($sync_id);
                return;
            }

            \Trotibike\EwheelImporter\Log\LiveLogger::log("Processing batch. Page: $page. Products found: " . count($products), 'info');

            // Apply limit to currenct batch if needed
            if ($limit > 0 && ($processed + count($products)) > $limit) {
                $remaining = $limit - $processed;
                $products = array_slice($products, 0, $remaining);
                \Trotibike\EwheelImporter\Log\LiveLogger::log("Limit reached. Truncating batch to $remaining items.", 'info');
            }

            $this->woo_sync->process_ewheel_products_batch($products);

            // Update progress
            $this->update_progress($sync_id, $page, count($products));

            // Schedule next batch if we got a full page AND limit not reached
            $current_processed = count($products);
            $total_processed = $processed + $current_processed;

            // SAFETY: Prevent infinite loops if API is misbehaving
            if ($page > 500) {
                \Trotibike\EwheelImporter\Log\LiveLogger::log("Safety Stop: Max pages (500) reached. Stopping.", 'error');
                $this->finish_sync($sync_id);
                return;
            }

            if ($current_processed >= self::BATCH_SIZE && ($limit === 0 || $total_processed < $limit)) {
                as_schedule_single_action(
                    time() + 5, // 5 seconds delay to be nice to the server
                    'ewheel_importer_process_batch',
                    [
                        'page' => $page + 1,
                        'sync_id' => $sync_id,
                        'since' => $since,
                    ]
                );
            } else {
                \Trotibike\EwheelImporter\Log\LiveLogger::log("Sync Finished. Total processed: $total_processed", 'success');
                $this->finish_sync($sync_id);
            }

        } catch (\Exception $e) {
            \Trotibike\EwheelImporter\Log\LiveLogger::log("Batch Error (Page $page): " . $e->getMessage(), 'error');
            error_log("Ewheel Importer Batch Error (Page $page): " . $e->getMessage());
            // Optionally update sync status to failed
        }
    }

    /**
     * Update sync progress.
     *
     * @param string $sync_id Unique sync ID.
     * @param int    $page    Current page.
     * @param int    $count   Items processed in this batch.
     */
    private function update_progress(string $sync_id, int $page, int $count): void
    {
        $status = get_option('ewheel_importer_sync_status', []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['processed'] += $count;
            $status['page'] = $page;
            $status['last_update'] = time();
            update_option('ewheel_importer_sync_status', $status);
        }
    }

    /**
     * Finish sync.
     *
     * @param string $sync_id Unique sync ID.
     */
    private function finish_sync(string $sync_id): void
    {
        $status = get_option('ewheel_importer_sync_status', []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['status'] = 'completed';
            $status['completed_at'] = time();
            update_option('ewheel_importer_sync_status', $status);

            // Update the global last sync time
            $this->config->update_last_sync();
        }
    }
}
