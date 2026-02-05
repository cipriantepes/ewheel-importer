<?php
/**
 * Plugin Name: Ewheel Importer
 * Plugin URI: https://trotibike.ro
 * Description: Import products from ewheel.es API into WooCommerce with automatic translation and price conversion.
 * Version:           1.2.6
 * Author:            Trotibike
 * Author URI:        https://trotibike.ro
 * License:           GPL-2.0-or-later
 * Text Domain:       ewheel-importer
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.4
 *
 * @package Trotibike\EwheelImporter
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin constants.
 */
define('EWHEEL_IMPORTER_VERSION', '1.2.6');
define('EWHEEL_IMPORTER_FILE', __FILE__);
define('EWHEEL_IMPORTER_PATH', plugin_dir_path(__FILE__));
define('EWHEEL_IMPORTER_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader.
 */
require_once EWHEEL_IMPORTER_PATH . 'vendor/autoload.php';

use Trotibike\EwheelImporter\Factory\ServiceFactory;
use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Container\ServiceContainer;
use Trotibike\EwheelImporter\Sync\SyncLauncher;
use Trotibike\EwheelImporter\Admin\AdminPage;
use Trotibike\EwheelImporter\Model\Profile;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Main plugin class.
 *
 * Single Responsibility: Bootstraps the plugin, delegates to specialized classes.
 */
final class Ewheel_Importer
{
    /**
     * GitHub Repository URL.
     * Replace this with your actual GitHub repository URL.
     */
    private const GITHUB_REPO = 'https://github.com/cipriantepes/ewheel-importer';

    /**
     * Single instance of the class.
     *
     * @var Ewheel_Importer|null
     */
    private static ?Ewheel_Importer $instance = null;

    /**
     * Configuration instance.
     *
     * @var Configuration
     */
    private Configuration $config;

    /**
     * Service container.
     *
     * @var ServiceContainer
     */
    private ServiceContainer $container;

    /**
     * Get the singleton instance.
     *
     * @return Ewheel_Importer
     */
    public static function instance(): Ewheel_Importer
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->container = ServiceFactory::build_container();
        $this->config = $this->container->get(Configuration::class);

        if (!$this->check_requirements()) {
            return;
        }

        $this->init_hooks();
        $this->init_updater();
    }

    /**
     * Initialize the update checker.
     *
     * @return void
     */
    private function init_updater(): void
    {
        if (class_exists(PucFactory::class)) {
            $myUpdateChecker = PucFactory::buildUpdateChecker(
                self::GITHUB_REPO,
                EWHEEL_IMPORTER_FILE,
                'ewheel-importer'
            );

            // Optional: Set the branch that contains the stable release.
            $myUpdateChecker->getVcsApi()->enableReleaseAssets();
        }
    }

    /**
     * Check plugin requirements.
     *
     * @return bool True if requirements are met.
     */
    private function check_requirements(): bool
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return false;
        }

        // Action Scheduler is required for background sync processing
        if (!function_exists('as_schedule_single_action')) {
            add_action('admin_notices', [$this, 'action_scheduler_missing_notice']);
            return false;
        }

        return true;
    }

    /**
     * Display WooCommerce missing notice.
     *
     * @return void
     */
    public function woocommerce_missing_notice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Ewheel Importer requires WooCommerce to be installed and active.', 'ewheel-importer')
        );
    }

    /**
     * Display Action Scheduler missing notice.
     *
     * @return void
     */
    public function action_scheduler_missing_notice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Ewheel Importer requires Action Scheduler. Please ensure WooCommerce is active and up to date.', 'ewheel-importer')
        );
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks(): void
    {
        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'check_db_tables']); // Self-healing DB
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX
        add_action('wp_ajax_ewheel_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_ewheel_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_ewheel_stop_sync', [$this, 'ajax_stop_sync']);
        add_action('wp_ajax_ewheel_pause_sync', [$this, 'ajax_pause_sync']);
        add_action('wp_ajax_ewheel_resume_sync', [$this, 'ajax_resume_sync']);
        add_action('wp_ajax_ewheel_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_ewheel_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_ewheel_get_sync_history', [$this, 'ajax_get_sync_history']);
        add_action('wp_ajax_ewheel_get_persistent_logs', [$this, 'ajax_get_persistent_logs']);
        add_action('wp_ajax_ewheel_clear_logs', [$this, 'ajax_clear_logs']);
        add_action('wp_ajax_ewheel_export_settings', [$this, 'ajax_export_settings']);
        add_action('wp_ajax_ewheel_import_settings', [$this, 'ajax_import_settings']);
        add_action('wp_ajax_ewheel_get_ewheel_categories', [$this, 'ajax_get_ewheel_categories']);
        add_action('wp_ajax_ewheel_get_woo_categories', [$this, 'ajax_get_woo_categories']);
        add_action('wp_ajax_ewheel_get_category_mappings', [$this, 'ajax_get_category_mappings']);
        add_action('wp_ajax_ewheel_save_category_mapping', [$this, 'ajax_save_category_mapping']);
        add_action('wp_ajax_ewheel_sync_categories', [$this, 'ajax_sync_categories']);

        // Profile AJAX handlers
        add_action('wp_ajax_ewheel_get_profiles', [$this, 'ajax_get_profiles']);
        add_action('wp_ajax_ewheel_get_profile', [$this, 'ajax_get_profile']);
        add_action('wp_ajax_ewheel_save_profile', [$this, 'ajax_save_profile']);
        add_action('wp_ajax_ewheel_delete_profile', [$this, 'ajax_delete_profile']);

        // Cron
        add_action('ewheel_importer_cron_sync', [$this, 'run_scheduled_sync']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Activation/Deactivation
        register_activation_hook(EWHEEL_IMPORTER_FILE, [$this, 'activate']);
        register_deactivation_hook(EWHEEL_IMPORTER_FILE, [$this, 'deactivate']);

        // Action Scheduler Hook
        add_action('ewheel_importer_process_batch', [$this, 'process_batch_action'], 10, 4);
    }

    /**
     * Add admin menu.
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Ewheel Import', 'ewheel-importer'),
            __('Ewheel Import', 'ewheel-importer'),
            'manage_woocommerce',
            'ewheel-importer',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings(): void
    {
        $settings = [
            'api_key',
            'translate_api_key',
            'deepl_api_key',
            'openrouter_api_key',
            'openrouter_model',
            'translation_driver',
            'exchange_rate',
            'markup_percent',
            'sync_frequency',
            'target_language',
            'sync_fields',
            'sync_protection',
        ];

        foreach ($settings as $setting) {
            register_setting('ewheel_importer_settings', 'ewheel_importer_' . $setting);
        }
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook Current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts(string $hook): void
    {
        if ('woocommerce_page_ewheel-importer' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'ewheel-importer-admin',
            EWHEEL_IMPORTER_URL . 'assets/admin.css',
            [],
            EWHEEL_IMPORTER_VERSION
        );

        wp_enqueue_script(
            'ewheel-importer-admin',
            EWHEEL_IMPORTER_URL . 'assets/admin.js',
            ['jquery'],
            EWHEEL_IMPORTER_VERSION,
            true
        );

        wp_localize_script(
            'ewheel-importer-admin',
            'ewheelImporter',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ewheel_importer_nonce'),
                'strings' => $this->get_js_strings(),
            ]
        );
    }

    /**
     * Get JavaScript translation strings.
     *
     * @return array
     */
    private function get_js_strings(): array
    {
        return [
            'syncing' => __('Syncing...', 'ewheel-importer'),
            'success' => __('Sync completed!', 'ewheel-importer'),
            'error' => __('Sync failed:', 'ewheel-importer'),
            'testing' => __('Testing connection...', 'ewheel-importer'),
            'connected' => __('Connection successful!', 'ewheel-importer'),
            'connFailed' => __('Connection failed:', 'ewheel-importer'),
        ];
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_admin_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        require_once EWHEEL_IMPORTER_PATH . 'includes/Admin/admin-page-template.php';
    }

    /**
     * Check and create DB tables if missing (Self-healing).
     *
     * @return void
     */
    public function check_db_tables(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ewheel_translations';

        // Lightweight check: if main table missing, re-run activation logic
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $this->activate();
        }
    }

    /**
     * AJAX: Run sync.
     *
     * @return void
     */
    public function ajax_run_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 0;
            $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

            // Re-use launcher directly or through factory
            $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
            $sync_id = $launcher->start_sync($limit, $profile_id);

            wp_send_json_success(
                [
                    'message' => __('Sync started successfully. ID: ' . $sync_id, 'ewheel-importer'),
                    'sync_id' => $sync_id,
                    'profile_id' => $profile_id,
                ]
            );
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Get sync status.
     *
     * @return void
     */
    public function ajax_get_sync_status(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

        // Get profile-specific or global status
        $status_key = $profile_id ? 'ewheel_importer_sync_status_' . $profile_id : 'ewheel_importer_sync_status';
        $status = get_option($status_key, []);

        // Add human readable last update
        if (!empty($status['last_update'])) {
            $status['last_update_human'] = human_time_diff($status['last_update'], time()) . ' ago';
        }

        // Add profile last sync if available
        if ($profile_id) {
            try {
                $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);
                $profile = $profile_repo->find($profile_id);
                if ($profile && $profile->get_last_sync()) {
                    $status['last_sync'] = wp_date(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        strtotime($profile->get_last_sync())
                    );
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        wp_send_json_success($status);
    }

    /**
     * AJAX: Stop sync.
     *
     * @return void
     */
    public function ajax_stop_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field(wp_unslash($_POST['sync_id'])) : '';
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

        // Determine status key based on profile
        $status_key = $profile_id ? 'ewheel_importer_sync_status_' . $profile_id : 'ewheel_importer_sync_status';

        if (empty($sync_id)) {
            // Try to get running or pausing sync from status
            $status = get_option($status_key, []);
            $active_statuses = ['running', 'pausing'];
            if (!empty($status['id']) && in_array($status['status'], $active_statuses, true)) {
                $sync_id = $status['id'];
            }
        }

        if (!empty($sync_id)) {
            update_option('ewheel_importer_stop_sync_' . $sync_id, true);
            // Also clear any pause flag so stop takes precedence
            delete_option('ewheel_importer_pause_sync_' . $sync_id);

            // Update status immediately to reflect stopping
            $status = get_option($status_key, []);
            if (isset($status['id']) && $status['id'] === $sync_id) {
                $status['status'] = 'stopping';
                update_option($status_key, $status);
            }

            wp_send_json_success(['message' => __('Sync stopping...', 'ewheel-importer')]);
        } else {
            wp_send_json_error(['message' => __('No running sync found.', 'ewheel-importer')]);
        }
    }

    /**
     * AJAX: Pause sync.
     *
     * @return void
     */
    public function ajax_pause_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $sync_id = isset($_POST['sync_id']) ? sanitize_text_field(wp_unslash($_POST['sync_id'])) : '';
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

        // Determine status key based on profile
        $status_key = $profile_id ? 'ewheel_importer_sync_status_' . $profile_id : 'ewheel_importer_sync_status';

        if (empty($sync_id)) {
            // Try to get running sync from status
            $status = get_option($status_key, []);
            if (!empty($status['id']) && $status['status'] === 'running') {
                $sync_id = $status['id'];
            }
        }

        if (!empty($sync_id)) {
            update_option('ewheel_importer_pause_sync_' . $sync_id, true);

            // Update status immediately to reflect pausing
            $status = get_option($status_key, []);
            if (isset($status['id']) && $status['id'] === $sync_id) {
                $status['status'] = 'pausing';
                update_option($status_key, $status);
            }

            wp_send_json_success(['message' => __('Sync pausing...', 'ewheel-importer')]);
        } else {
            wp_send_json_error(['message' => __('No running sync found.', 'ewheel-importer')]);
        }
    }

    /**
     * AJAX: Resume sync.
     *
     * @return void
     */
    public function ajax_resume_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

        try {
            $sync_launcher = $this->container->get(SyncLauncher::class);
            $sync_id = $sync_launcher->resume_sync($profile_id);

            wp_send_json_success([
                'message' => __('Sync resumed!', 'ewheel-importer'),
                'sync_id' => $sync_id,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Test API connection.
     *
     * @return void
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $api_key = $this->config->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API key not configured', 'ewheel-importer')]);
        }

        try {
            $client = ServiceFactory::create_api_client($api_key);
            $categories = $client->get_categories(0, 1);

            wp_send_json_success(['message' => __('Connection successful!', 'ewheel-importer')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Run scheduled sync.
     *
     * @return void
     */
    public function run_scheduled_sync(): void
    {
        try {
            $sync_service = ServiceFactory::create_sync_service();
            $last_sync = $this->config->get_last_sync();

            if ($last_sync) {
                $sync_service->sync_incremental($last_sync);
            } else {
                $sync_service->sync_all();
            }
        } catch (\Exception $e) {
            error_log('Ewheel Importer Scheduled Sync Error: ' . $e->getMessage());
        }
    }

    /**
     * Process a single batch via Action Scheduler.
     *
     * @param int      $page       Page Number.
     * @param string   $sync_id    Sync ID.
     * @param string   $since      Since date.
     * @param int|null $profile_id Profile ID (null for default).
     * @return void
     */
    public function process_batch_action(int $page, string $sync_id, string $since, ?int $profile_id = null): void
    {
        try {
            // WE NEED TO LOAD LOGGER!
            if (!class_exists(\Trotibike\EwheelImporter\Log\LiveLogger::class)) {
                require_once EWHEEL_IMPORTER_PATH . 'includes/Log/LiveLogger.php';
            }

            $container = ServiceFactory::build_container();
            $processor = $container->get(\Trotibike\EwheelImporter\Sync\SyncBatchProcessor::class);

            $processor->process_batch($page, $sync_id, $since, $profile_id);

        } catch (\Exception $e) {
            error_log('Ewheel Importer Batch Action Error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX Get Logs (Live Logger).
     *
     * @return void
     */
    public function ajax_get_logs(): void
    {
        // Check capability first
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $nonce = $_POST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'ewheel_importer_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }

        // Ensure Logger is loaded if autoloader failed (fallback)
        if (!class_exists(\Trotibike\EwheelImporter\Log\LiveLogger::class)) {
            require_once EWHEEL_IMPORTER_PATH . 'includes/Log/LiveLogger.php';
        }

        wp_send_json_success(\Trotibike\EwheelImporter\Log\LiveLogger::get_logs());
    }

    /**
     * AJAX Get Sync History.
     *
     * @return void
     */
    public function ajax_get_sync_history(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 10;

        $history = \Trotibike\EwheelImporter\Sync\SyncHistoryManager::get_recent($limit);
        $stats = \Trotibike\EwheelImporter\Sync\SyncHistoryManager::get_stats();

        // Format durations for display
        foreach ($history as &$record) {
            if (!empty($record['duration_seconds'])) {
                $record['duration_formatted'] = \Trotibike\EwheelImporter\Sync\SyncHistoryManager::format_duration(
                    (int) $record['duration_seconds']
                );
            }
            if (!empty($record['started_at'])) {
                $record['started_at_formatted'] = wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    strtotime($record['started_at'])
                );
            }
        }

        wp_send_json_success([
            'history' => $history,
            'stats' => $stats,
        ]);
    }

    /**
     * AJAX Get Persistent Logs.
     *
     * @return void
     */
    public function ajax_get_persistent_logs(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $args = [
            'level' => isset($_POST['level']) ? sanitize_text_field(wp_unslash($_POST['level'])) : '',
            'batch_id' => isset($_POST['batch_id']) ? sanitize_text_field(wp_unslash($_POST['batch_id'])) : '',
            'sku' => isset($_POST['sku']) ? sanitize_text_field(wp_unslash($_POST['sku'])) : '',
            'limit' => isset($_POST['limit']) ? absint($_POST['limit']) : 100,
            'offset' => isset($_POST['offset']) ? absint($_POST['offset']) : 0,
        ];

        $logs = \Trotibike\EwheelImporter\Log\PersistentLogger::get_logs($args);
        $total = \Trotibike\EwheelImporter\Log\PersistentLogger::get_count($args);

        wp_send_json_success([
            'logs' => $logs,
            'total' => $total,
        ]);
    }

    /**
     * AJAX Clear Logs.
     *
     * @return void
     */
    public function ajax_clear_logs(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'live';

        if ($type === 'persistent') {
            \Trotibike\EwheelImporter\Log\PersistentLogger::clear_all();
        } else {
            \Trotibike\EwheelImporter\Log\LiveLogger::clear();
        }

        wp_send_json_success(['message' => __('Logs cleared successfully', 'ewheel-importer')]);
    }

    /**
     * AJAX Export Settings.
     *
     * @return void
     */
    public function ajax_export_settings(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $settings = $this->config->get_all();

        // Remove sensitive data for export
        unset($settings['api_key']);
        unset($settings['translate_api_key']);
        unset($settings['deepl_api_key']);
        unset($settings['openrouter_api_key']);
        unset($settings['last_sync']);

        wp_send_json_success([
            'settings' => $settings,
            'version' => EWHEEL_IMPORTER_VERSION,
            'exported_at' => current_time('mysql'),
        ]);
    }

    /**
     * AJAX Import Settings.
     *
     * @return void
     */
    public function ajax_import_settings(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $settings_json = isset($_POST['settings']) ? wp_unslash($_POST['settings']) : '';

        if (empty($settings_json)) {
            wp_send_json_error(['message' => __('No settings data provided', 'ewheel-importer')]);
        }

        $data = json_decode($settings_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Invalid JSON data', 'ewheel-importer')]);
        }

        if (empty($data['settings'])) {
            wp_send_json_error(['message' => __('No settings found in import data', 'ewheel-importer')]);
        }

        $allowed_keys = [
            'exchange_rate',
            'markup_percent',
            'sync_frequency',
            'target_language',
            'sync_fields',
            'sync_protection',
            'custom_patterns',
            'translation_driver',
            'openrouter_model',
            'variation_mode',
        ];

        $imported = 0;
        foreach ($data['settings'] as $key => $value) {
            if (in_array($key, $allowed_keys, true)) {
                $this->config->set($key, $value);
                $imported++;
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of settings imported */
                __('%d settings imported successfully', 'ewheel-importer'),
                $imported
            ),
        ]);
    }

    /**
     * AJAX Get Ewheel Categories from API.
     *
     * @return void
     */
    public function ajax_get_ewheel_categories(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $api_key = $this->config->get_api_key();

        if (empty($api_key)) {
            wp_send_json_error(['message' => __('API key not configured', 'ewheel-importer')]);
        }

        try {
            $client = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_api_client($api_key);
            $categories = $client->get_all_categories();

            // Get translator to extract names from multilingual structures
            $translator = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_translator($this->config);

            // Format categories for display
            $formatted = [];
            foreach ($categories as $cat) {
                $reference = $cat['reference'] ?? ($cat['Reference'] ?? '');
                $raw_name = $cat['name'] ?? ($cat['Name'] ?? '');

                // Extract actual text from multilingual structure
                // Handles both {"es": "..."} and {"translations": [...]} formats
                $name = $translator->translate_multilingual($raw_name);

                // Fallback to reference if extraction failed
                if (empty($name)) {
                    $name = $reference;
                }

                $formatted[] = [
                    'reference' => $reference,
                    'name' => $name,
                    'parent' => $cat['parentReference'] ?? ($cat['ParentReference'] ?? null),
                ];
            }

            wp_send_json_success(['categories' => $formatted]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Get WooCommerce Categories.
     *
     * @return void
     */
    public function ajax_get_woo_categories(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            wp_send_json_error(['message' => $terms->get_error_message()]);
        }

        $categories = [];
        foreach ($terms as $term) {
            $categories[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => $term->parent,
                'count' => $term->count,
            ];
        }

        // Build hierarchical structure for display
        $hierarchical = $this->build_category_hierarchy($categories);

        wp_send_json_success(['categories' => $hierarchical]);
    }

    /**
     * Build category hierarchy for display.
     *
     * @param array $categories Flat list of categories.
     * @param int   $parent_id  Parent ID.
     * @param int   $depth      Current depth.
     * @return array
     */
    private function build_category_hierarchy(array $categories, int $parent_id = 0, int $depth = 0): array
    {
        $result = [];

        foreach ($categories as $cat) {
            if ((int) $cat['parent'] === $parent_id) {
                $cat['depth'] = $depth;
                $cat['display_name'] = str_repeat('— ', $depth) . $cat['name'];
                $result[] = $cat;

                // Get children
                $children = $this->build_category_hierarchy($categories, (int) $cat['id'], $depth + 1);
                $result = array_merge($result, $children);
            }
        }

        return $result;
    }

    /**
     * AJAX Get Current Category Mappings.
     *
     * @return void
     */
    public function ajax_get_category_mappings(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Get auto-created mappings from term meta
        $category_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\CategoryRepository::class);
        $auto_mappings = $category_repo->get_mapping();

        // Get manual mappings from option
        $manual_mappings = get_option('ewheel_importer_category_mappings', []);

        // Merge (manual takes precedence)
        $all_mappings = array_merge($auto_mappings, $manual_mappings);

        // Also fetch ewheel categories from API for profile dropdown
        $ewheel_categories = [];
        $api_key = $this->config->get_api_key();
        if (!empty($api_key)) {
            try {
                $client = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_api_client($api_key);
                $categories = $client->get_all_categories();

                // Get translator to extract names from multilingual structures
                $translator = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_translator($this->config);

                foreach ($categories as $cat) {
                    $reference = $cat['reference'] ?? ($cat['Reference'] ?? '');
                    $raw_name = $cat['name'] ?? ($cat['Name'] ?? '');

                    // Extract actual text from multilingual structure
                    $name = $translator->translate_multilingual($raw_name);
                    if (empty($name)) {
                        $name = $reference;
                    }

                    $ewheel_categories[] = [
                        'reference' => $reference,
                        'name' => $name,
                    ];
                }
            } catch (\Exception $e) {
                // Silent fail - categories will just be empty
            }
        }

        wp_send_json_success([
            'mappings' => $all_mappings,
            'auto_mappings' => $auto_mappings,
            'manual_mappings' => $manual_mappings,
            'ewheel_categories' => $ewheel_categories,
        ]);
    }

    /**
     * AJAX Save Category Mapping.
     *
     * @return void
     */
    public function ajax_save_category_mapping(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $ewheel_ref = isset($_POST['ewheel_ref']) ? sanitize_text_field(wp_unslash($_POST['ewheel_ref'])) : '';
        $woo_cat_id = isset($_POST['woo_cat_id']) ? absint($_POST['woo_cat_id']) : 0;

        if (empty($ewheel_ref)) {
            wp_send_json_error(['message' => __('Ewheel reference is required', 'ewheel-importer')]);
        }

        // Get existing manual mappings
        $mappings = get_option('ewheel_importer_category_mappings', []);

        if ($woo_cat_id > 0) {
            // Set mapping
            $mappings[$ewheel_ref] = $woo_cat_id;
        } else {
            // Remove mapping (revert to auto)
            unset($mappings[$ewheel_ref]);
        }

        update_option('ewheel_importer_category_mappings', $mappings);

        wp_send_json_success(['message' => __('Mapping saved successfully', 'ewheel-importer')]);
    }

    /**
     * AJAX Sync Categories from API.
     *
     * @return void
     */
    public function ajax_sync_categories(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $woo_sync = $this->container->get(\Trotibike\EwheelImporter\Sync\WooCommerceSync::class);
            $result = $woo_sync->sync_categories();

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: number of categories synced */
                    __('%d categories synced successfully', 'ewheel-importer'),
                    count($result)
                ),
                'count' => count($result),
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Get All Profiles.
     *
     * @return void
     */
    public function ajax_get_profiles(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);

            // Ensure table exists
            if (!$profile_repo->table_exists()) {
                \Trotibike\EwheelImporter\Database\SchemaInstaller::install();
            }

            $profiles = $profile_repo->find_all();

            // Convert to arrays for JSON
            $profiles_array = array_map(function ($profile) {
                $data = $profile->to_array();
                // Format last_sync for display
                if (!empty($data['last_sync'])) {
                    $data['last_sync'] = wp_date(
                        get_option('date_format') . ' ' . get_option('time_format'),
                        strtotime($data['last_sync'])
                    );
                }
                return $data;
            }, $profiles);

            wp_send_json_success(['profiles' => $profiles_array]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Get Single Profile.
     *
     * @return void
     */
    public function ajax_get_profile(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        if (empty($profile_id)) {
            // Return empty profile for new profile creation
            wp_send_json_success([
                'profile' => [
                    'id' => null,
                    'name' => '',
                    'slug' => '',
                    'is_active' => true,
                    'filters' => Profile::DEFAULT_FILTERS,
                    'settings' => Profile::DEFAULT_SETTINGS,
                    'category_mappings' => [],
                ],
            ]);
            return;
        }

        try {
            $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);
            $profile = $profile_repo->find($profile_id);

            if (!$profile) {
                wp_send_json_error(['message' => __('Profile not found', 'ewheel-importer')]);
            }

            wp_send_json_success(['profile' => $profile->to_array()]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Save Profile.
     *
     * @return void
     */
    public function ajax_save_profile(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';

        if (empty($name)) {
            wp_send_json_error(['message' => __('Profile name is required', 'ewheel-importer')]);
        }

        try {
            $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);

            // Ensure table exists
            if (!$profile_repo->table_exists()) {
                \Trotibike\EwheelImporter\Database\SchemaInstaller::install();
            }

            // Build profile data
            $profile = $profile_id ? $profile_repo->find($profile_id) : new Profile();

            if ($profile_id && !$profile) {
                wp_send_json_error(['message' => __('Profile not found', 'ewheel-importer')]);
            }

            $profile->set_name($name);
            $profile->set_active(!empty($_POST['is_active']));

            // Build filters
            $filters = [];
            if (isset($_POST['filters']) && is_array($_POST['filters'])) {
                $raw_filters = wp_unslash($_POST['filters']);
                $filters['category'] = sanitize_text_field($raw_filters['category'] ?? '');
                $filters['active'] = !empty($raw_filters['active']);
                $filters['hasImages'] = !empty($raw_filters['hasImages']);
                $filters['hasVariants'] = !empty($raw_filters['hasVariants']);
                $filters['productReference'] = sanitize_text_field($raw_filters['productReference'] ?? '');
            }
            $profile->set_filters($filters);

            // Build settings
            $settings = [];
            if (isset($_POST['settings']) && is_array($_POST['settings'])) {
                $raw_settings = wp_unslash($_POST['settings']);

                // Exchange rate - only set if override is selected
                if (isset($raw_settings['exchange_rate']) && $raw_settings['exchange_rate'] !== '') {
                    $settings['exchange_rate'] = (float) $raw_settings['exchange_rate'];
                }

                // Markup - only set if override is selected
                if (isset($raw_settings['markup_percent']) && $raw_settings['markup_percent'] !== '') {
                    $settings['markup_percent'] = (float) $raw_settings['markup_percent'];
                }

                // Sync frequency
                $settings['sync_frequency'] = sanitize_text_field($raw_settings['sync_frequency'] ?? 'manual');

                // Test limit
                $settings['test_limit'] = absint($raw_settings['test_limit'] ?? 0);
            }
            $profile->set_settings($settings);

            // Save
            $saved_id = $profile_repo->save($profile);

            wp_send_json_success([
                'message' => __('Profile saved successfully', 'ewheel-importer'),
                'profile_id' => $saved_id,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Delete Profile.
     *
     * @return void
     */
    public function ajax_delete_profile(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $profile_id = isset($_POST['profile_id']) ? absint($_POST['profile_id']) : 0;

        if (empty($profile_id)) {
            wp_send_json_error(['message' => __('Profile ID is required', 'ewheel-importer')]);
        }

        try {
            $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);
            $profile_repo->delete($profile_id);

            wp_send_json_success(['message' => __('Profile deleted successfully', 'ewheel-importer')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedules(array $schedules): array
    {
        $schedules['weekly'] = [
            'interval' => 604800, // WEEK_IN_SECONDS
            'display' => __('Once Weekly', 'ewheel-importer'),
        ];
        return $schedules;
    }

    /**
     * Plugin activation.
     *
     * @return void
     */
    public function activate(): void
    {
        \Trotibike\EwheelImporter\Database\SchemaInstaller::install();

        $frequency = $this->config->get_sync_frequency();

        if ($frequency !== 'manual' && !wp_next_scheduled('ewheel_importer_cron_sync')) {
            wp_schedule_event(time(), $frequency, 'ewheel_importer_cron_sync');
        }
    }

    /**
     * Plugin deactivation.
     *
     * @return void
     */
    public function deactivate(): void
    {
        wp_clear_scheduled_hook('ewheel_importer_cron_sync');
    }
}

/**
 * Get the plugin instance.
 *
 * @return Ewheel_Importer
 */
function ewheel_importer(): Ewheel_Importer
{
    return Ewheel_Importer::instance();
}

// Initialize
// Initialize
add_action('plugins_loaded', 'ewheel_importer');

/**
 * Declare HPOS compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', EWHEEL_IMPORTER_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', EWHEEL_IMPORTER_FILE, true);
    }
});
