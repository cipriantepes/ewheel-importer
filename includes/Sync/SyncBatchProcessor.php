<?php
/**
 * Sync Batch Processor.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Config\ProfileConfiguration;
use Trotibike\EwheelImporter\Log\PersistentLogger;
use Trotibike\EwheelImporter\Model\Profile;
use Trotibike\EwheelImporter\Repository\ProfileRepository;

/**
 * Handles processing of a single sync batch.
 */
class SyncBatchProcessor
{

    /**
     * API page size — must match the ewheel API's expected page size
     * for reliable pagination. Smaller values cause inconsistent results.
     */
    private const API_PAGE_SIZE = 50;

    /**
     * Processing sub-batch size — how many products to process per
     * Action Scheduler tick. Keeps translation load manageable.
     */
    private const SUB_BATCH_SIZE = 10;

    /**
     * Default batch size (for adaptive retry on failure).
     */
    private const DEFAULT_BATCH_SIZE = 50;

    /**
     * Minimum batch size before giving up.
     */
    private const MIN_BATCH_SIZE = 1;

    /**
     * Maximum consecutive failures before stopping.
     */
    private const MAX_FAILURES = 4;

    /**
     * Lock key prefix.
     */
    private const LOCK_KEY_PREFIX = 'ewheel_importer_sync_lock';

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
     * Profile repository.
     *
     * @var ProfileRepository
     */
    private ProfileRepository $profile_repository;

    /**
     * Constructor.
     *
     * @param EwheelApiClient   $api_client         API client.
     * @param WooCommerceSync   $woo_sync           WooCommerce Sync.
     * @param Configuration     $config             Configuration.
     * @param ProfileRepository $profile_repository Profile repository.
     */
    public function __construct(
        EwheelApiClient $api_client,
        WooCommerceSync $woo_sync,
        Configuration $config,
        ProfileRepository $profile_repository
    ) {
        $this->api_client = $api_client;
        $this->woo_sync = $woo_sync;
        $this->config = $config;
        $this->profile_repository = $profile_repository;
    }

    /**
     * Get lock key for a profile.
     *
     * @param int|null $profile_id Profile ID.
     * @return string
     */
    private function get_lock_key(?int $profile_id = null): string
    {
        if ($profile_id) {
            return self::LOCK_KEY_PREFIX . '_' . $profile_id;
        }
        return self::LOCK_KEY_PREFIX;
    }

    /**
     * Get status option key for a profile.
     *
     * @param int|null $profile_id Profile ID.
     * @return string
     */
    private function get_status_key(?int $profile_id = null): string
    {
        if ($profile_id) {
            return 'ewheel_importer_sync_status_' . $profile_id;
        }
        return 'ewheel_importer_sync_status';
    }

    /**
     * Get profile and configuration for a sync.
     *
     * @param int|null $profile_id Profile ID.
     * @return ProfileConfiguration|null
     */
    private function get_profile_config(?int $profile_id): ?ProfileConfiguration
    {
        if ($profile_id === null) {
            $profile = $this->profile_repository->find_default();
        } else {
            $profile = $this->profile_repository->find($profile_id);
        }

        if (!$profile) {
            return null;
        }

        return new ProfileConfiguration($profile, $this->config);
    }

    /**
     * Process a single batch.
     *
     * @param int      $page       Page number (0-indexed).
     * @param string   $sync_id    Unique ID for this sync session.
     * @param string   $since      Optional date string for incremental sync.
     * @param int|null $profile_id Profile ID (null for default).
     * @param int      $offset     Offset within the API page (for sub-batching).
     * @return void
     */
    public function process_batch(int $page, string $sync_id, string $since = '', ?int $profile_id = null, int $offset = 0): void
    {
        // Defer expensive term/comment counting until batch completes
        wp_defer_term_counting(true);
        wp_defer_comment_counting(true);

        try {
            // Get profile configuration
            $profile_config = $this->get_profile_config($profile_id);
            if (!$profile_config) {
                PersistentLogger::error('Profile not found for sync', null, $sync_id, $profile_id);
                $this->fail_sync($sync_id, 'Profile not found', $profile_id);
                return;
            }

            $profile = $profile_config->get_profile();

            PersistentLogger::info("Batch start - page: {$page}, offset: {$offset}, sync_id: {$sync_id}, profile_id: " . ($profile_id ?: 'none'), null, $sync_id, $profile_id);
            PersistentLogger::info("Profile: " . $profile_config->get_profile_name() . ", variation_mode: " . $profile_config->get_variation_mode(), null, $sync_id, $profile_id);

            // Check status for limit and adaptive batch size
            $status = get_option($this->get_status_key($profile_id), []);

            // Verify this batch belongs to the current sync session
            if (!isset($status['id']) || $status['id'] !== $sync_id) {
                PersistentLogger::info(
                    sprintf('Stale batch ignored (batch sync_id: %s, current: %s). Skipping.', $sync_id, $status['id'] ?? 'none'),
                    null,
                    $sync_id,
                    $profile_id
                );
                return;
            }

            $limit = isset($status['limit']) ? (int) $status['limit'] : 0;
            $processed = isset($status['processed']) ? (int) $status['processed'] : 0;
            $batch_size = isset($status['batch_size']) ? (int) $status['batch_size'] : self::DEFAULT_BATCH_SIZE;
            $failure_count = isset($status['failure_count']) ? (int) $status['failure_count'] : 0;

            // Check if limit already reached
            if ($limit > 0 && $processed >= $limit) {
                $this->finish_sync($sync_id, $profile_id);
                return;
            }

            // Check if sync should stop
            if (get_option('ewheel_importer_stop_sync_' . $sync_id)) {
                PersistentLogger::info('Sync stopped by user request', null, $sync_id, $profile_id);
                $this->stop_sync($sync_id, $profile_id);
                return;
            }

            // Check if sync should pause
            if (get_option('ewheel_importer_pause_sync_' . $sync_id)) {
                PersistentLogger::info('Sync paused by user request', null, $sync_id, $profile_id);
                $this->pause_sync($sync_id, $profile_id);
                return;
            }

            // Create history record on first batch (deferred from start_sync for faster response)
            $history_created = !empty($status['history_created']);
            if (!$history_created) {
                $sync_type = ($status['type'] ?? 'full') === 'incremental'
                    ? SyncHistoryManager::TYPE_INCREMENTAL
                    : SyncHistoryManager::TYPE_FULL;

                SyncHistoryManager::create($sync_id, $sync_type, $profile_id);

                // Log sync start
                $sync_label = $page > 0
                    ? sprintf('Sync resumed from page %d (Profile: %s)', $page, $profile_config->get_profile_name())
                    : ($sync_type === SyncHistoryManager::TYPE_INCREMENTAL
                        ? sprintf('Incremental sync started (since: %s, Profile: %s)', $status['since'] ?? 'unknown', $profile_config->get_profile_name())
                        : sprintf('Sync started (Profile: %s)', $profile_config->get_profile_name()));
                PersistentLogger::info($sync_label, null, $sync_id, $profile_id);

                $status['history_created'] = true;
                update_option($this->get_status_key($profile_id), $status);
            }

            // On fresh sync (page 0), sync categories before products
            if ($page === 0) {
                PersistentLogger::info('Syncing categories before products...', null, $sync_id, $profile_id);
                try {
                    $category_map = $this->woo_sync->sync_categories();
                    PersistentLogger::info(
                        sprintf('Categories synced: %d categories created/updated', count($category_map)),
                        null,
                        $sync_id,
                        $profile_id
                    );
                } catch (\Throwable $e) {
                    PersistentLogger::error('Failed to sync categories: ' . $e->getMessage(), null, $sync_id, $profile_id);
                    // Continue with product sync anyway - categories might already exist
                }
            }

            // Build filters from profile
            $api_filters = $profile_config->get_api_filters();

            // Add incremental filter if provided
            if (!empty($since)) {
                $api_filters['NewerThan'] = $since;
            }

            // Always filter for active products unless explicitly set otherwise
            if (!isset($api_filters['active'])) {
                $api_filters['Active'] = 1;
            }

            PersistentLogger::info("API filters: " . wp_json_encode($api_filters), null, $sync_id, $profile_id);

            // Warm product lookup cache (3 queries replace ~2-4 per product)
            $lookup_cache = new ProductLookupCache();
            $lookup_cache->warm();
            $this->woo_sync->set_lookup_cache($lookup_cache);

            $cache_stats = $lookup_cache->get_stats();
            PersistentLogger::info(
                sprintf(
                    '[Performance] Lookup cache: %d SKUs, %d refs, %d bases',
                    $cache_stats['sku_count'],
                    $cache_stats['reference_count'],
                    $cache_stats['base_count']
                ),
                null,
                $sync_id,
                $profile_id
            );

            PersistentLogger::info("Calling API get_products page={$page}, page_size=" . self::API_PAGE_SIZE, null, $sync_id, $profile_id);

            // Always use fixed API page size for reliable pagination
            $products = $this->api_client->get_products($page, self::API_PAGE_SIZE, $api_filters);

            PersistentLogger::info("API returned " . count($products) . " products", null, $sync_id, $profile_id);

            if (empty($products)) {
                PersistentLogger::info("No products from API - ending sync", null, $sync_id, $profile_id);
                PersistentLogger::info(
                    sprintf('No products returned from API for profile "%s". Batch complete.', $profile->get_name()),
                    null,
                    $sync_id,
                    $profile_id
                );
                $this->finish_sync($sync_id, $profile_id);
                return;
            }

            // Sub-batch: slice products from offset
            $api_total = count($products);
            $products = array_slice($products, $offset, self::SUB_BATCH_SIZE);

            PersistentLogger::info(
                sprintf('Processing batch for profile "%s". Page: %d, offset: %d. Sub-batch: %d of %d API products', $profile->get_name(), $page, $offset, count($products), $api_total),
                null,
                $sync_id,
                $profile_id
            );

            if (empty($products)) {
                // Offset past end of page — move to next page
                PersistentLogger::info("Offset past end of page, advancing to next page", null, $sync_id, $profile_id);
                $this->schedule_next_page($page, $sync_id, $since, $profile_id, $api_total);
                return;
            }

            // Apply limit to current batch if needed
            if ($limit > 0 && ($processed + count($products)) > $limit) {
                $remaining = $limit - $processed;
                $products = array_slice($products, 0, $remaining);
                PersistentLogger::info("Limit reached. Truncating batch to $remaining items.", null, $sync_id, $profile_id);
            }

            PersistentLogger::info("Calling woo_sync->process_ewheel_products_batch with " . count($products) . " products", null, $sync_id, $profile_id);

            // Process products with profile configuration
            $batch_result = $this->woo_sync->process_ewheel_products_batch($products, $profile_config);

            PersistentLogger::info("WooSync result: created={$batch_result['created']}, updated={$batch_result['updated']}, errors={$batch_result['errors']}", null, $sync_id, $profile_id);

            // Update progress with detailed counts
            $this->update_progress($sync_id, $page, count($products), $batch_result, $profile_id);

            // Success - reset failure count (batch completed without exception)
            if ($failure_count > 0) {
                $this->update_batch_metrics($sync_id, $profile_id, $batch_size, 0);
            }

            $current_processed = count($products);
            $total_processed = $processed + $current_processed;
            $next_offset = $offset + self::SUB_BATCH_SIZE;

            // SAFETY: Prevent infinite loops if API is misbehaving
            if ($page > 500) {
                PersistentLogger::error('Safety Stop: Max pages (500) reached. Stopping.', null, $sync_id, $profile_id);
                $this->finish_sync($sync_id, $profile_id);
                return;
            }

            // Memory cleanup before scheduling next batch
            $this->cleanup_batch_memory();

            // Check if limit reached
            if ($limit > 0 && $total_processed >= $limit) {
                PersistentLogger::success(
                    sprintf('Sync Finished (limit reached) for profile "%s". Total processed: %d', $profile->get_name(), $total_processed),
                    null,
                    $sync_id,
                    $profile_id
                );
                $this->finish_sync($sync_id, $profile_id);
            } elseif ($next_offset < $api_total) {
                // More products on this API page — schedule next sub-batch
                as_schedule_single_action(
                    time() + 5, // Short delay between sub-batches (same API page)
                    'ewheel_importer_process_batch',
                    [
                        'page' => $page,
                        'sync_id' => $sync_id,
                        'since' => $since,
                        'profile_id' => $profile_id,
                        'offset' => $next_offset,
                    ]
                );
            } else {
                // All products on this page processed — check if there's a next page
                $this->schedule_next_page($page, $sync_id, $since, $profile_id, $api_total);
            }

        } catch (\Exception $e) {

            // Get current batch metrics for adaptive retry
            $status = get_option($this->get_status_key($profile_id), []);
            $batch_size = isset($status['batch_size']) ? (int) $status['batch_size'] : self::DEFAULT_BATCH_SIZE;
            $failure_count = isset($status['failure_count']) ? (int) $status['failure_count'] : 0;

            // Use adaptive retry instead of immediate failure
            $this->handle_batch_failure($sync_id, $profile_id, $page, $batch_size, $failure_count, $e, $since, $offset);
        } finally {
            // Re-enable term/comment counting
            wp_defer_term_counting(false);
            wp_defer_comment_counting(false);
        }
    }

    /**
     * Update sync progress.
     *
     * @param string     $sync_id      Unique sync ID.
     * @param int        $page         Current page.
     * @param int        $count        Items processed in this batch.
     * @param array|null $batch_result Optional batch result with created/updated/failed counts.
     * @param int|null   $profile_id   Profile ID.
     */
    private function update_progress(string $sync_id, int $page, int $count, ?array $batch_result = null, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['processed'] = ($status['processed'] ?? 0) + $count;
            $status['page'] = $page;
            $status['last_update'] = time();

            // Update detailed counts if available
            if ($batch_result !== null) {
                $status['created'] = ($status['created'] ?? 0) + ($batch_result['created'] ?? 0);
                $status['updated'] = ($status['updated'] ?? 0) + ($batch_result['updated'] ?? 0);
                $status['failed'] = ($status['failed'] ?? 0) + ($batch_result['failed'] ?? 0);
            }

            update_option($this->get_status_key($profile_id), $status);

            // Update history database
            SyncHistoryManager::update($sync_id, [
                'products_processed' => $status['processed'],
                'products_created' => $status['created'] ?? 0,
                'products_updated' => $status['updated'] ?? 0,
                'products_failed' => $status['failed'] ?? 0,
            ]);
        }
    }

    /**
     * Finish sync successfully.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param int|null $profile_id Profile ID.
     */
    private function finish_sync(string $sync_id, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            // Schedule stock sync before marking as completed
            $status['status'] = 'syncing_stock';
            $status['last_update'] = time();
            update_option($this->get_status_key($profile_id), $status);

            PersistentLogger::info('Product sync finished. Scheduling stock synchronization...', null, $sync_id, $profile_id);

            as_schedule_single_action(
                time() + 2,
                'ewheel_importer_sync_stock',
                [
                    'sync_id' => $sync_id,
                    'profile_id' => $profile_id,
                ]
            );
        }
    }

    /**
     * Process stock synchronization after product sync completes.
     *
     * Also runs product lifecycle reconciliation for full syncs.
     *
     * @param string   $sync_id    Sync ID.
     * @param int|null $profile_id Profile ID.
     * @return void
     */
    public function process_stock_sync(string $sync_id, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (!isset($status['id']) || $status['id'] !== $sync_id) {
            return;
        }

        PersistentLogger::info('Starting stock synchronization...', null, $sync_id, $profile_id);

        // Warm lookup cache for stock sync SKU lookups
        $lookup_cache = new ProductLookupCache();
        $lookup_cache->warm();
        $this->woo_sync->set_lookup_cache($lookup_cache);

        try {
            $stock_result = $this->woo_sync->sync_stock();

            PersistentLogger::success(
                sprintf('Stock sync complete: %d updated, %d skipped',
                    $stock_result['updated'], $stock_result['skipped']),
                null, $sync_id, $profile_id
            );
        } catch (\Throwable $e) {
            PersistentLogger::error(
                'Stock sync failed: ' . $e->getMessage(),
                null, $sync_id, $profile_id
            );
        }

        // Note: lifecycle reconciliation is disabled because the sync uses Active=1
        // filter — we only see a subset of the catalog. Reconciling against a filtered
        // subset would incorrectly unpublish inactive-but-still-sellable products.

        // Now mark as fully completed
        $this->complete_sync($sync_id, $profile_id);
    }

    /**
     * Complete sync — update status, timestamps, history, and release lock.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param int|null $profile_id Profile ID.
     */
    private function complete_sync(string $sync_id, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['status'] = 'completed';
            $status['completed_at'] = time();
            update_option($this->get_status_key($profile_id), $status);

            // Update the profile's last sync time
            if ($profile_id) {
                $this->profile_repository->update_last_sync($profile_id);
            }

            // Also update the global last sync time for backwards compatibility
            $this->config->update_last_sync();

            // Mark history as completed
            SyncHistoryManager::complete($sync_id);

            // Release lock
            delete_transient($this->get_lock_key($profile_id));

            PersistentLogger::success('Sync fully completed.', null, $sync_id, $profile_id);

            // Email notification
            if ($this->config->get('notify_on_sync')) {
                $this->send_sync_email('completed', $status, $profile_id);
            }
        }
    }

    /**
     * Mark sync as stopped by user.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param int|null $profile_id Profile ID.
     */
    private function stop_sync(string $sync_id, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['status'] = 'stopped';
            $status['completed_at'] = time();
            update_option($this->get_status_key($profile_id), $status);

            // Mark history as stopped
            SyncHistoryManager::stop($sync_id);

            // Clean up stop flag
            delete_option('ewheel_importer_stop_sync_' . $sync_id);

            // Release lock
            delete_transient($this->get_lock_key($profile_id));
        }
    }

    /**
     * Mark sync as paused by user.
     *
     * Does NOT release the lock - sync can be resumed.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param int|null $profile_id Profile ID.
     */
    private function pause_sync(string $sync_id, ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['status'] = 'paused';
            $status['paused_at'] = time();
            update_option($this->get_status_key($profile_id), $status);

            // Mark history as paused
            SyncHistoryManager::pause($sync_id);

            // Clean up pause flag
            delete_option('ewheel_importer_pause_sync_' . $sync_id);

            // Note: We do NOT release the lock - sync can be resumed
            // But we should extend the lock timeout
            set_transient($this->get_lock_key($profile_id), $sync_id, 3600 * 24); // 24 hours for paused sync
        }
    }

    /**
     * Mark sync as failed.
     *
     * @param string   $sync_id    Unique sync ID.
     * @param string   $reason     Failure reason.
     * @param int|null $profile_id Profile ID.
     */
    private function fail_sync(string $sync_id, string $reason = '', ?int $profile_id = null): void
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['status'] = 'failed';
            $status['completed_at'] = time();
            $status['error'] = $reason;
            update_option($this->get_status_key($profile_id), $status);

            // Mark history as failed
            SyncHistoryManager::fail($sync_id, $reason);

            // Release lock
            delete_transient($this->get_lock_key($profile_id));

            // Email notification
            if ($this->config->get('notify_on_sync')) {
                $this->send_sync_email('failed', $status, $profile_id);
            }
        }
    }

    /**
     * Send email notification about sync result.
     *
     * @param string   $type       'completed' or 'failed'.
     * @param array    $status     Sync status array.
     * @param int|null $profile_id Profile ID.
     */
    private function send_sync_email(string $type, array $status, ?int $profile_id): void
    {
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $site_name = get_bloginfo('name');
        $subject = sprintf('[%s] Sync %s', $site_name, $type);

        $started = $status['started_at'] ?? time();
        $duration = human_time_diff($started, time());

        $lines = [
            sprintf('Sync %s.', $type),
            '',
            sprintf('Processed: %d', $status['processed'] ?? 0),
            sprintf('Created: %d', $status['created'] ?? 0),
            sprintf('Updated: %d', $status['updated'] ?? 0),
            sprintf('Failed: %d', $status['failed'] ?? 0),
            sprintf('Duration: %s', $duration),
        ];

        if ($type === 'failed' && !empty($status['error'])) {
            $lines[] = '';
            $lines[] = sprintf('Error: %s', $status['error']);
        }

        wp_mail($admin_email, $subject, implode("\n", $lines));
    }

    /**
     * Clean up memory after processing a batch.
     *
     * Applies WP All Import patterns to prevent memory exhaustion.
     *
     * @return void
     */
    private function cleanup_batch_memory(): void
    {
        // Clear WordPress object cache
        wp_cache_flush();

        // Clear WooCommerce session if available
        if (function_exists('WC') && WC()->session) {
            WC()->session = null;
        }

        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }

    /**
     * Schedule the next API page or finish sync.
     *
     * @param int      $page       Current page number.
     * @param string   $sync_id    Unique sync ID.
     * @param string   $since      Incremental sync date filter.
     * @param int|null $profile_id Profile ID.
     * @param int      $api_total  Number of products returned by the API for the current page.
     */
    private function schedule_next_page(int $page, string $sync_id, string $since, ?int $profile_id, int $api_total): void
    {
        // Always schedule the next page. The ewheel API sometimes returns fewer
        // than PageSize products on a page even when more pages exist (e.g. 49
        // instead of 50). The empty-page check at the top of process_batch()
        // handles termination when the API returns 0 products.
        PersistentLogger::info(
            sprintf('Page %d complete (%d products). Scheduling page %d...', $page, $api_total, $page + 1),
            null,
            $sync_id,
            $profile_id
        );

        as_schedule_single_action(
            time() + 15, // Delay between API pages (new HTTP request)
            'ewheel_importer_process_batch',
            [
                'page' => $page + 1,
                'sync_id' => $sync_id,
                'since' => $since,
                'profile_id' => $profile_id,
                'offset' => 0,
            ]
        );
    }

    /**
     * Handle batch failure with adaptive retry.
     *
     * Reduces batch size and reschedules the same page for retry.
     * Gives up after MAX_FAILURES consecutive failures.
     *
     * @param string     $sync_id       Unique sync ID.
     * @param int|null   $profile_id    Profile ID.
     * @param int        $page          Current page number.
     * @param int        $batch_size    Current batch size.
     * @param int        $failure_count Current failure count.
     * @param \Exception $e             The exception that caused the failure.
     * @param string     $since         Incremental sync date filter.
     * @return void
     */
    private function handle_batch_failure(
        string $sync_id,
        ?int $profile_id,
        int $page,
        int $batch_size,
        int $failure_count,
        \Exception $e,
        string $since,
        int $offset = 0
    ): void {
        $failure_count++;
        $new_batch_size = max(self::MIN_BATCH_SIZE, (int) ceil($batch_size / 2));

        PersistentLogger::warning(
            sprintf(
                'Batch failed. Reducing batch size from %d to %d. Failure %d/%d. Error: %s',
                $batch_size,
                $new_batch_size,
                $failure_count,
                self::MAX_FAILURES,
                $e->getMessage()
            ),
            null,
            $sync_id,
            $profile_id
        );

        // Check if we should give up
        if ($failure_count > self::MAX_FAILURES) {
            PersistentLogger::error(
                'Max failures reached (' . self::MAX_FAILURES . '). Stopping sync.',
                null,
                $sync_id,
                $profile_id
            );
            $this->fail_sync($sync_id, 'Too many failures: ' . $e->getMessage(), $profile_id);
            return;
        }

        // If batch size is already at minimum and still failing, give up
        if ($batch_size <= self::MIN_BATCH_SIZE) {
            PersistentLogger::error(
                'Batch size at minimum (' . self::MIN_BATCH_SIZE . ') and still failing. Stopping sync.',
                null,
                $sync_id,
                $profile_id
            );
            $this->fail_sync($sync_id, 'Failed at minimum batch size: ' . $e->getMessage(), $profile_id);
            return;
        }

        // Update status with new batch size and failure count
        $this->update_batch_metrics($sync_id, $profile_id, $new_batch_size, $failure_count);

        // Reschedule same page/offset with smaller batch size
        as_schedule_single_action(
            time() + 10, // Longer delay after failure (10 seconds)
            'ewheel_importer_process_batch',
            [
                'page' => $page, // SAME page - retry with smaller batch
                'sync_id' => $sync_id,
                'since' => $since,
                'profile_id' => $profile_id,
                'offset' => $offset, // Resume from same offset
            ]
        );

        PersistentLogger::info(
            sprintf('Rescheduled page %d with batch size %d', $page, $new_batch_size),
            null,
            $sync_id,
            $profile_id
        );
    }

    /**
     * Update batch metrics in sync status.
     *
     * @param string   $sync_id       Unique sync ID.
     * @param int|null $profile_id    Profile ID.
     * @param int      $batch_size    Current batch size.
     * @param int      $failure_count Current failure count.
     * @return void
     */
    private function update_batch_metrics(
        string $sync_id,
        ?int $profile_id,
        int $batch_size,
        int $failure_count
    ): void {
        $status = get_option($this->get_status_key($profile_id), []);

        if (isset($status['id']) && $status['id'] === $sync_id) {
            $status['batch_size'] = $batch_size;
            $status['failure_count'] = $failure_count;
            $status['last_update'] = time();
            update_option($this->get_status_key($profile_id), $status);
        }
    }

    /**
     * Check if memory usage is approaching the limit.
     *
     * @return bool True if memory is at critical level (85%+ of limit).
     */
    private function is_memory_critical(): bool
    {
        $memory_limit = $this->get_memory_limit_bytes();
        if ($memory_limit <= 0) {
            return false; // Unlimited memory
        }

        $current_usage = memory_get_usage(true);
        $threshold = 0.85; // 85% of limit

        return ($current_usage / $memory_limit) >= $threshold;
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int Memory limit in bytes, or -1 if unlimited.
     */
    private function get_memory_limit_bytes(): int
    {
        $limit = ini_get('memory_limit');

        if ($limit === '-1') {
            return -1; // Unlimited
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
                // Fall through
            case 'm':
                $value *= 1024;
                // Fall through
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}
