<?php
/**
 * Sync Launcher.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Log\PersistentLogger;
use Trotibike\EwheelImporter\Repository\ProfileRepository;

/**
 * Initiates the background sync process.
 */
class SyncLauncher
{
    /**
     * Lock transient key prefix.
     */
    private const LOCK_KEY_PREFIX = 'ewheel_importer_sync_lock';

    /**
     * Lock timeout in seconds (30 minutes).
     */
    private const LOCK_TIMEOUT = 1800;

    /**
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

    /**
     * Profile Repository.
     *
     * @var ProfileRepository
     */
    private ProfileRepository $profile_repository;

    /**
     * Constructor.
     *
     * @param Configuration     $config             Configuration.
     * @param ProfileRepository $profile_repository Profile Repository.
     */
    public function __construct(Configuration $config, ProfileRepository $profile_repository)
    {
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
     * Check if a sync is currently running.
     *
     * @param int|null $profile_id Profile ID (null for default/global).
     * @return bool
     */
    public function is_sync_running(?int $profile_id = null): bool
    {
        // Check transient lock
        $lock = get_transient($this->get_lock_key($profile_id));
        if ($lock) {
            // Check if the locked sync is actually paused (not running)
            $status = get_option($this->get_status_key($profile_id), []);
            if (!empty($status['status']) && $status['status'] === 'paused') {
                return false; // Paused sync doesn't block new sync or resume
            }
            return true;
        }

        // Also check sync status option
        $status = get_option($this->get_status_key($profile_id), []);
        if (!empty($status['status']) && $status['status'] === 'running') {
            // Verify sync hasn't been abandoned (no update in 30 minutes)
            $last_update = $status['last_update'] ?? $status['started_at'] ?? 0;
            if ((time() - $last_update) < self::LOCK_TIMEOUT) {
                return true;
            }
        }

        // Also check database history
        return SyncHistoryManager::has_running_sync($profile_id);
    }

    /**
     * Check if a sync is currently paused.
     *
     * @param int|null $profile_id Profile ID (null for default/global).
     * @return bool
     */
    public function is_sync_paused(?int $profile_id = null): bool
    {
        $status = get_option($this->get_status_key($profile_id), []);
        return !empty($status['status']) && $status['status'] === 'paused';
    }

    /**
     * Get the paused sync status.
     *
     * @param int|null $profile_id Profile ID.
     * @return array|null Sync status array or null if not paused.
     */
    public function get_paused_sync(?int $profile_id = null): ?array
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (!empty($status['status']) && $status['status'] === 'paused') {
            return $status;
        }
        return null;
    }

    /**
     * Get the currently running sync ID if any.
     *
     * @param int|null $profile_id Profile ID.
     * @return string|null
     */
    public function get_running_sync_id(?int $profile_id = null): ?string
    {
        $status = get_option($this->get_status_key($profile_id), []);
        if (!empty($status['id']) && !empty($status['status']) && $status['status'] === 'running') {
            return $status['id'];
        }

        return SyncHistoryManager::get_running_sync_id($profile_id);
    }

    /**
     * Acquire a sync lock.
     *
     * @param string   $sync_id    Sync ID.
     * @param int|null $profile_id Profile ID.
     * @return bool True if lock acquired.
     */
    private function acquire_lock(string $sync_id, ?int $profile_id = null): bool
    {
        if ($this->is_sync_running($profile_id)) {
            return false;
        }

        set_transient($this->get_lock_key($profile_id), $sync_id, self::LOCK_TIMEOUT);
        return true;
    }

    /**
     * Release the sync lock.
     *
     * @param string   $sync_id    Sync ID to verify ownership.
     * @param int|null $profile_id Profile ID.
     * @return bool
     */
    public function release_lock(string $sync_id, ?int $profile_id = null): bool
    {
        $lock_key = $this->get_lock_key($profile_id);
        $current_lock = get_transient($lock_key);
        if ($current_lock === $sync_id) {
            delete_transient($lock_key);
            return true;
        }
        return false;
    }

    /**
     * Start a full sync.
     *
     * @param int      $limit      Optional limit of products to sync. 0 for unlimited.
     * @param int|null $profile_id Optional profile ID.
     * @return string Sync ID.
     * @throws \Exception If a sync is already running.
     */
    public function start_sync(int $limit = 0, ?int $profile_id = null): string
    {
        $sync_id = uniqid('sync_');

        // Check for concurrent sync (quick check)
        if (!$this->acquire_lock($sync_id, $profile_id)) {
            $running_id = $this->get_running_sync_id($profile_id);
            throw new \Exception(
                sprintf(
                    __('A sync is already in progress for this profile (ID: %s). Please wait for it to complete or stop it first.', 'ewheel-importer'),
                    $running_id ?: 'unknown'
                )
            );
        }

        // Initialize sync status (minimal - just enough to track state)
        update_option(
            $this->get_status_key($profile_id),
            [
                'id' => $sync_id,
                'status' => 'running',
                'started_at' => time(),
                'last_update' => time(),
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0,
                'page' => 0,
                'limit' => $limit,
                'type' => 'full',
                'profile_id' => $profile_id,
                'batch_size' => 10,
                'failure_count' => 0,
            ],
            false // Don't autoload
        );

        // Schedule first batch with a tiny delay to let this request complete first
        as_schedule_single_action(
            time() + 1,
            'ewheel_importer_process_batch',
            [
                'page' => 0,
                'sync_id' => $sync_id,
                'since' => '',
                'profile_id' => $profile_id,
            ]
        );

        return $sync_id;
    }

    /**
     * Start an incremental sync.
     *
     * @param string|null $since      Date to sync from (null uses last sync time).
     * @param int|null    $profile_id Optional profile ID.
     * @return string Sync ID.
     * @throws \Exception If a sync is already running.
     */
    public function start_incremental_sync(?string $since = null, ?int $profile_id = null): string
    {
        $sync_id = uniqid('sync_');

        // Check for concurrent sync (quick check)
        if (!$this->acquire_lock($sync_id, $profile_id)) {
            $running_id = $this->get_running_sync_id($profile_id);
            throw new \Exception(
                sprintf(
                    __('A sync is already in progress for this profile (ID: %s). Please wait for it to complete or stop it first.', 'ewheel-importer'),
                    $running_id ?: 'unknown'
                )
            );
        }

        // Get last sync time from profile if not provided
        if ($since === null) {
            if ($profile_id) {
                $profile = $this->profile_repository->find($profile_id);
                if ($profile) {
                    $since = $profile->get_last_sync();
                }
            } else {
                $since = $this->config->get_last_sync();
            }
        }

        // Initialize sync status (minimal - history record created in first batch)
        update_option(
            $this->get_status_key($profile_id),
            [
                'id' => $sync_id,
                'status' => 'running',
                'started_at' => time(),
                'last_update' => time(),
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'failed' => 0,
                'page' => 0,
                'limit' => 0,
                'type' => 'incremental',
                'since' => $since,
                'profile_id' => $profile_id,
                'batch_size' => 10,
                'failure_count' => 0,
            ],
            false // Don't autoload
        );

        // Schedule first batch with a tiny delay
        as_schedule_single_action(
            time() + 1,
            'ewheel_importer_process_batch',
            [
                'page' => 0,
                'sync_id' => $sync_id,
                'since' => $since ?: '',
                'profile_id' => $profile_id,
            ]
        );

        return $sync_id;
    }

    /**
     * Resume a paused sync.
     *
     * @param int|null $profile_id Profile ID.
     * @return string Sync ID.
     * @throws \Exception If no paused sync exists or resume fails.
     */
    public function resume_sync(?int $profile_id = null): string
    {
        $paused_status = $this->get_paused_sync($profile_id);

        if (!$paused_status) {
            throw new \Exception(
                __('No paused sync found to resume.', 'ewheel-importer')
            );
        }

        $sync_id = $paused_status['id'];
        $next_page = ($paused_status['page'] ?? 0) + 1; // Resume from next page
        $since = $paused_status['since'] ?? '';

        // Clear any lingering pause flag
        delete_option('ewheel_importer_pause_sync_' . $sync_id);

        // Update status back to running
        $paused_status['status'] = 'running';
        $paused_status['last_update'] = time();
        $paused_status['resumed_at'] = time();
        unset($paused_status['paused_at']);
        update_option($this->get_status_key($profile_id), $paused_status);

        // Update history to running
        SyncHistoryManager::resume($sync_id);

        // Refresh the lock
        set_transient($this->get_lock_key($profile_id), $sync_id, self::LOCK_TIMEOUT);

        // Log resume
        $profile_name = 'Default';
        if ($profile_id) {
            $profile = $this->profile_repository->find($profile_id);
            if ($profile) {
                $profile_name = $profile->get_name();
            }
        }
        PersistentLogger::info(
            sprintf('Sync resumed at page %d (Profile: %s)', $next_page, $profile_name),
            null,
            $sync_id,
            $profile_id
        );

        // Schedule next batch immediately
        as_schedule_single_action(
            time(),
            'ewheel_importer_process_batch',
            [
                'page' => $next_page,
                'sync_id' => $sync_id,
                'since' => $since,
                'profile_id' => $profile_id,
            ]
        );

        return $sync_id;
    }
}
