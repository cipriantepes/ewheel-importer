<?php
/**
 * Plugin Name: Ewheel Importer
 * Plugin URI: https://trotibike.ro
 * Description: Import products from ewheel.es API into WooCommerce with automatic translation and price conversion.
 * Version:           1.6.2
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
define('EWHEEL_IMPORTER_VERSION', '1.6.0');
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
        // Register AJAX error handler for debugging
        add_action('admin_init', [$this, 'register_ajax_error_handler']);

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

        // Category translation AJAX handlers
        add_action('wp_ajax_ewheel_translate_categories', [$this, 'ajax_translate_categories']);
        add_action('wp_ajax_ewheel_save_category_translation', [$this, 'ajax_save_category_translation']);
        add_action('wp_ajax_ewheel_clear_category_translations', [$this, 'ajax_clear_category_translations']);

        // Profile AJAX handlers
        add_action('wp_ajax_ewheel_get_profiles', [$this, 'ajax_get_profiles']);
        add_action('wp_ajax_ewheel_get_profile', [$this, 'ajax_get_profile']);
        add_action('wp_ajax_ewheel_save_profile', [$this, 'ajax_save_profile']);
        add_action('wp_ajax_ewheel_delete_profile', [$this, 'ajax_delete_profile']);

        // OpenRouter model AJAX handlers
        add_action('wp_ajax_ewheel_get_openrouter_models', [$this, 'ajax_get_openrouter_models']);
        add_action('wp_ajax_ewheel_refresh_openrouter_models', [$this, 'ajax_refresh_openrouter_models']);

        // Queue management
        add_action('wp_ajax_ewheel_clear_queue', [$this, 'ajax_clear_queue']);
        add_action('wp_ajax_ewheel_get_queue_status', [$this, 'ajax_get_queue_status']);

        // Product count
        add_action('wp_ajax_ewheel_get_product_count', [$this, 'ajax_get_product_count']);

        // Cron
        add_action('ewheel_importer_cron_sync', [$this, 'run_scheduled_sync']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Activation/Deactivation
        register_activation_hook(EWHEEL_IMPORTER_FILE, [$this, 'activate']);
        register_deactivation_hook(EWHEEL_IMPORTER_FILE, [$this, 'deactivate']);

        // Action Scheduler Hook
        add_action('ewheel_importer_process_batch', [$this, 'process_batch_action'], 10, 4);

        // Brand Taxonomy
        add_action('init', [$this, 'register_product_brand_taxonomy'], 5);

        // Scooter Model Taxonomy
        add_action('init', [$this, 'register_product_model_taxonomy'], 5);

        // Product Badges (NEW/Discontinued)
        add_action('woocommerce_before_shop_loop_item_title', [$this, 'render_product_badges'], 10);
        add_action('woocommerce_before_single_product_summary', [$this, 'render_product_badges'], 10);

        // Frontend CSS for badges
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles']);
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
     * Enqueue frontend styles for product badges.
     *
     * @return void
     */
    public function enqueue_frontend_styles(): void
    {
        // Only load on WooCommerce pages
        if (!function_exists('is_woocommerce') || (!is_woocommerce() && !is_product())) {
            return;
        }

        // Inline CSS for badges (lightweight, no extra file needed)
        $badge_css = '
            .ewheel-badge {
                position: absolute;
                top: 10px;
                left: 10px;
                padding: 4px 10px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                border-radius: 3px;
                z-index: 10;
                line-height: 1.2;
                letter-spacing: 0.5px;
            }
            .ewheel-badge-new {
                background: #27ae60;
                color: #fff;
            }
            .ewheel-badge-discontinued {
                background: #e74c3c;
                color: #fff;
            }
            .woocommerce ul.products li.product,
            .woocommerce-page ul.products li.product,
            .single-product .product {
                position: relative;
            }
            .single-product .ewheel-badge {
                top: 15px;
                left: 15px;
                padding: 6px 12px;
                font-size: 12px;
            }
            .ewheel-badge + .ewheel-badge {
                top: 40px;
            }
            .single-product .ewheel-badge + .ewheel-badge {
                top: 50px;
            }
        ';

        wp_add_inline_style('woocommerce-general', $badge_css);
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
            // OpenRouter model strings
            'loadingModels' => __('Loading models...', 'ewheel-importer'),
            'modelsFromCache' => __('Models loaded from cache.', 'ewheel-importer'),
            'modelsFetched' => __('Models fetched from OpenRouter.', 'ewheel-importer'),
            'modelsAvailable' => __('models available.', 'ewheel-importer'),
            'selectModel' => __('Select a model', 'ewheel-importer'),
            'freeModels' => __('Free Models', 'ewheel-importer'),
            'paidModels' => __('Paid Models', 'ewheel-importer'),
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
     * Register AJAX error handler for debugging.
     *
     * Catches fatal errors during AJAX requests and logs them.
     *
     * @return void
     */
    public function register_ajax_error_handler(): void
    {
        // Only run during AJAX requests for this plugin
        if (!wp_doing_ajax()) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';

        // Only handle our plugin's AJAX actions
        if (strpos($action, 'ewheel_') !== 0) {
            return;
        }

        // Log AJAX request start
        error_log(sprintf(
            '[Ewheel AJAX] Request started: action=%s, user=%d, file=%s',
            $action,
            get_current_user_id(),
            __FILE__
        ));

        // Register shutdown handler to catch fatal errors
        register_shutdown_function(function () use ($action) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
                error_log(sprintf(
                    '[Ewheel AJAX FATAL] action=%s, error=%s, file=%s, line=%d',
                    $action,
                    $error['message'],
                    $error['file'],
                    $error['line']
                ));
            }
        });
    }

    /**
     * Log AJAX error helper.
     *
     * @param string     $action    AJAX action name.
     * @param \Throwable $exception Exception or Error.
     * @return void
     */
    private function log_ajax_error(string $action, \Throwable $exception): void
    {
        error_log(sprintf(
            '[Ewheel AJAX ERROR] action=%s, error=%s, file=%s, line=%d, trace=%s',
            $action,
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ));
    }

    /**
     * Log sync diagnostics before starting a sync.
     *
     * @param int|null $profile_id Profile ID.
     * @return void
     */
    private function log_sync_diagnostics(?int $profile_id = null): void
    {
        $diagnostics = [];

        // Check Action Scheduler
        $diagnostics['action_scheduler'] = function_exists('as_schedule_single_action') ? 'available' : 'MISSING';

        // Check profile
        $profile_repo = $this->container->get(\Trotibike\EwheelImporter\Repository\ProfileRepository::class);
        if ($profile_id) {
            $profile = $profile_repo->find($profile_id);
            $diagnostics['profile'] = $profile ? $profile->get_name() : 'NOT FOUND (ID: ' . $profile_id . ')';
        } else {
            $profile = $profile_repo->find_default();
            $diagnostics['profile'] = $profile ? 'Default: ' . $profile->get_name() : 'NO DEFAULT PROFILE';
        }

        // Check API configuration
        $config = $this->container->get(\Trotibike\EwheelImporter\Config\Configuration::class);
        $diagnostics['api_key'] = $config->get_api_key() ? 'configured (' . strlen($config->get_api_key()) . ' chars)' : 'NOT SET';

        // Check pending actions
        if (function_exists('as_get_scheduled_actions')) {
            $pending = as_get_scheduled_actions([
                'hook' => 'ewheel_importer_process_batch',
                'status' => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 10,
            ]);
            $diagnostics['pending_batches'] = count($pending);

            $running = as_get_scheduled_actions([
                'hook' => 'ewheel_importer_process_batch',
                'status' => \ActionScheduler_Store::STATUS_RUNNING,
                'per_page' => 10,
            ]);
            $diagnostics['running_batches'] = count($running);

            $failed = as_get_scheduled_actions([
                'hook' => 'ewheel_importer_process_batch',
                'status' => \ActionScheduler_Store::STATUS_FAILED,
                'per_page' => 10,
            ]);
            $diagnostics['failed_batches'] = count($failed);
        }

        // Check current sync status
        $status_key = $profile_id ? 'ewheel_importer_sync_status_' . $profile_id : 'ewheel_importer_sync_status';
        $current_status = get_option($status_key, []);
        $diagnostics['current_status'] = $current_status ? ($current_status['status'] ?? 'unknown') : 'none';

        // Check lock
        $lock_key = $profile_id ? 'ewheel_importer_sync_lock_' . $profile_id : 'ewheel_importer_sync_lock';
        $lock = get_transient($lock_key);
        $diagnostics['lock'] = $lock ? 'LOCKED (sync_id: ' . $lock . ')' : 'unlocked';

        error_log('[Ewheel Sync Diagnostics] ' . wp_json_encode($diagnostics));
    }

    /**
     * Check and create DB tables if missing (Self-healing).
     *
     * @return void
     */
    public function check_db_tables(): void
    {
        global $wpdb;

        // List of critical tables to check
        $tables = [
            $wpdb->prefix . 'ewheel_translations',
            $wpdb->prefix . \Trotibike\EwheelImporter\Log\PersistentLogger::TABLE_NAME,
            $wpdb->prefix . \Trotibike\EwheelImporter\Database\SchemaInstaller::SYNC_HISTORY_TABLE,
            $wpdb->prefix . \Trotibike\EwheelImporter\Database\SchemaInstaller::PROFILES_TABLE,
        ];

        $missing = false;
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                $missing = true;
                break;
            }
        }

        // Deep check for profile V2 migration
        if (!$missing && \Trotibike\EwheelImporter\Database\SchemaInstaller::needs_migration()) {
            $missing = true;
        }

        if ($missing) {
            error_log('Ewheel Importer: Detected missing tables or pending migration. Running installer.');
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
        // Debug Log
        error_log('Ewheel Importer: AJAX Run Sync Triggered');

        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 0;
            $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : null;

            error_log("Ewheel Importer: Starting Sync (Limit: $limit, Profile: " . ($profile_id ?? 'Default') . ")");

            // Pre-sync diagnostics
            $this->log_sync_diagnostics($profile_id);

            // Re-use launcher directly or through factory
            $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
            $sync_id = $launcher->start_sync($limit, $profile_id);

            error_log("Ewheel Importer: Sync Scheduled with ID $sync_id");

            // Verify the batch was actually scheduled
            if (function_exists('as_get_scheduled_actions')) {
                $pending = as_get_scheduled_actions([
                    'hook' => 'ewheel_importer_process_batch',
                    'status' => \ActionScheduler_Store::STATUS_PENDING,
                    'per_page' => 1,
                ]);
                error_log("Ewheel Importer: Pending batch actions after schedule: " . count($pending));
            }

            wp_send_json_success(
                [
                    'message' => __('Sync started successfully. ID: ' . $sync_id, 'ewheel-importer'),
                    'sync_id' => $sync_id,
                    'profile_id' => $profile_id,
                ]
            );
        } catch (\Throwable $e) {
            $this->log_ajax_error('ewheel_run_sync', $e);
            wp_send_json_error(['message' => $e->getMessage()]);
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
        error_log("=== EWHEEL BATCH START === Page: $page, SyncID: $sync_id, Profile: " . ($profile_id ?? 'Global'));

        try {
            error_log("[Ewheel] Step 1: Loading LiveLogger...");
            if (!class_exists(\Trotibike\EwheelImporter\Log\LiveLogger::class)) {
                require_once EWHEEL_IMPORTER_PATH . 'includes/Log/LiveLogger.php';
            }
            error_log("[Ewheel] Step 1: Done");

            error_log("[Ewheel] Step 2: Building container...");
            $container = ServiceFactory::build_container();
            error_log("[Ewheel] Step 2: Done");

            error_log("[Ewheel] Step 3: Getting SyncBatchProcessor...");
            $processor = $container->get(\Trotibike\EwheelImporter\Sync\SyncBatchProcessor::class);
            error_log("[Ewheel] Step 3: Done");

            error_log("[Ewheel] Step 4: Calling process_batch...");
            $processor->process_batch($page, $sync_id, $since, $profile_id);
            error_log("[Ewheel] Step 4: Done - process_batch completed");

        } catch (\Throwable $e) {
            error_log('=== EWHEEL BATCH ERROR === ' . $e->getMessage());
            error_log('File: ' . $e->getFile() . ':' . $e->getLine());
            error_log('Trace: ' . $e->getTraceAsString());
        }

        error_log("=== EWHEEL BATCH END ===");
    }

    /**
     * AJAX Test API Connection.
     *
     * @return void
     */
    public function ajax_test_connection(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

            if (empty($api_key)) {
                // Try from config if not provided
                $config = $this->container->get(\Trotibike\EwheelImporter\Config\Configuration::class);
                $api_key = $config->get_api_key();
            }

            if (empty($api_key)) {
                throw new \Exception(__('API Key is missing', 'ewheel-importer'));
            }

            // Create a temporary client to test the key
            $client = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_api_client($api_key);

            // Try to fetch 1 product to verify
            $products = $client->get_products(0, 1);

            if (is_array($products)) {
                wp_send_json_success(['message' => __('Connection successful!', 'ewheel-importer')]);
            } else {
                throw new \Exception(__('Invalid response from API', 'ewheel-importer'));
            }

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
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
     * AJAX Get Sync Status.
     *
     * @return void
     */
    public function ajax_get_sync_status(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Get profile ID from request
        $profile_id = isset($_REQUEST['profile_id']) ? absint($_REQUEST['profile_id']) : null;
        if ($profile_id === 0) {
            $profile_id = null;
        }

        // Build status key
        $status_key = $profile_id
            ? 'ewheel_importer_sync_status_' . $profile_id
            : 'ewheel_importer_sync_status';

        // Get stored status
        $status = get_option($status_key, []);

        // Check launcher for running state
        $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
        $is_running = $launcher->is_sync_running($profile_id);
        $is_paused = $launcher->is_sync_paused($profile_id);

        // Build response with full details
        $response = [
            'status' => $is_running ? 'running' : ($is_paused ? 'paused' : ($status['status'] ?? 'idle')),
            'is_running' => $is_running,
            'is_paused' => $is_paused,
            'id' => $status['id'] ?? null,
            'processed' => $status['processed'] ?? 0,
            'created' => $status['created'] ?? 0,
            'updated' => $status['updated'] ?? 0,
            'failed' => $status['failed'] ?? 0,
            'page' => $status['page'] ?? 0,
            'limit' => $status['limit'] ?? 0,
            'type' => $status['type'] ?? 'full',
            'started_at' => $status['started_at'] ?? null,
            'last_update' => $status['last_update'] ?? null,
            'completed_at' => $status['completed_at'] ?? null,
            'batch_size' => $status['batch_size'] ?? 10,
            'failure_count' => $status['failure_count'] ?? 0,
        ];

        // Add human-readable message
        if ($is_running) {
            $response['message'] = sprintf(
                __('Sync running: %d processed (%d created, %d updated, %d failed)', 'ewheel-importer'),
                $response['processed'],
                $response['created'],
                $response['updated'],
                $response['failed']
            );
        } elseif ($is_paused) {
            $response['message'] = __('Sync paused. Click Resume to continue.', 'ewheel-importer');
        } elseif ($response['status'] === 'completed') {
            $response['message'] = sprintf(
                __('Sync completed: %d processed (%d created, %d updated)', 'ewheel-importer'),
                $response['processed'],
                $response['created'],
                $response['updated']
            );
        } elseif ($response['status'] === 'failed') {
            $response['message'] = $status['error'] ?? __('Sync failed.', 'ewheel-importer');
        } elseif ($response['status'] === 'stopped') {
            $response['message'] = __('Sync stopped by user.', 'ewheel-importer');
        } else {
            $response['message'] = __('Sync is idle.', 'ewheel-importer');
        }

        wp_send_json_success($response);
    }

    /**
     * AJAX Pause Sync.
     *
     * @return void
     */
    public function ajax_pause_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
        $sync_id = $launcher->get_running_sync_id(); // Default profile

        if ($sync_id) {
            $status_key = 'ewheel_importer_sync_status'; // Default profile
            $status = get_option($status_key, []);
            if (!empty($status)) {
                $status['status'] = 'paused';
                $status['paused_at'] = time();
                update_option($status_key, $status);

                // Also set a specific pause flag
                update_option('ewheel_importer_pause_sync_' . $sync_id, true);

                wp_send_json_success(['message' => __('Sync paused.', 'ewheel-importer')]);
            }
        }

        wp_send_json_error(['message' => __('No running sync found to pause.', 'ewheel-importer')]);
    }

    /**
     * AJAX Resume Sync.
     *
     * @return void
     */
    public function ajax_resume_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
            $launcher->resume_sync();
            wp_send_json_success(['message' => __('Sync resumed.', 'ewheel-importer')]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Stop Sync.
     *
     * @return void
     */
    public function ajax_stop_sync(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
            return;
        }

        try {
            $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
            $sync_id = $launcher->get_running_sync_id();

            // Also check if paused
            if (!$sync_id) {
                $paused = $launcher->get_paused_sync();
                if ($paused) {
                    $sync_id = $paused['id'];
                }
            }

            // Force clear even without sync_id - clean up any stuck state
            // Clear status options for default profile
            delete_option('ewheel_importer_sync_status');

            // Clear the lock transient directly
            delete_transient('ewheel_importer_sync_lock');

            // Stop ALL running syncs in database history (critical for is_sync_running check)
            \Trotibike\EwheelImporter\Sync\SyncHistoryManager::stop_all_running();

            if ($sync_id) {
                // Release lock via launcher (may fail if ownership mismatch, but we cleared it above)
                $launcher->release_lock($sync_id);

                // Clear pause flag
                delete_option('ewheel_importer_pause_sync_' . $sync_id);

                // Update history to stopped (already handled by stop_all_running above, but keep for explicit logging)
                try {
                    \Trotibike\EwheelImporter\Sync\SyncHistoryManager::fail($sync_id, 'Stopped by user');
                } catch (\Throwable $t) {
                    // Ignore history update failures
                }
            }

            // Unschedule ALL pending batch actions
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('ewheel_importer_process_batch');
            }

            wp_send_json_success(['message' => __('Sync stopped.', 'ewheel-importer')]);
            return;

        } catch (\Throwable $e) {
            // Even on error, try to force cleanup
            delete_option('ewheel_importer_sync_status');
            delete_transient('ewheel_importer_sync_lock');
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('ewheel_importer_process_batch');
            }

            wp_send_json_error(['message' => 'Stop failed: ' . $e->getMessage()]);
            return;
        }
    }


    /**
     * AJAX Get Product Count.
     *
     * @return void
     */
    public function ajax_get_product_count(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        try {
            // Create client
            $config = $this->container->get(\Trotibike\EwheelImporter\Config\Configuration::class);
            $client = $this->container->get(\Trotibike\EwheelImporter\Api\EwheelApiClient::class);

            $filters = [];
            $count = $client->get_product_count($filters);

            wp_send_json_success([
                'count' => $count,
                'formatted' => number_format($count),
            ]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
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

            // Use cache-first approach with batch query to avoid N+1 database calls
            $translation_repo = new \Trotibike\EwheelImporter\Repository\TranslationRepository();
            $target_lang = $this->config->get_target_language();

            // First pass: extract source texts grouped by source language
            $category_data = [];
            $texts_by_lang = [];
            foreach ($categories as $cat) {
                $reference = $cat['reference'] ?? ($cat['Reference'] ?? '');
                $raw_name = $cat['name'] ?? ($cat['Name'] ?? '');
                $parent = $cat['parentReference'] ?? ($cat['ParentReference'] ?? null);

                // Extract source text from multilingual structure
                // Handles both: {"translations": [{"reference": "es", "value": "..."}]} and {"es": "text"}
                $source_text = '';
                $source_lang = 'es';
                if (is_array($raw_name)) {
                    // Complex format: {"translations": [...]}
                    if (isset($raw_name['translations']) && is_array($raw_name['translations'])) {
                        foreach (['en', 'es'] as $preferred_lang) {
                            foreach ($raw_name['translations'] as $t) {
                                if (isset($t['reference']) && $t['reference'] === $preferred_lang && !empty($t['value'])) {
                                    $source_text = $t['value'];
                                    $source_lang = $preferred_lang;
                                    break 2;
                                }
                            }
                        }
                        // Fallback to first available
                        if (empty($source_text) && !empty($raw_name['translations'][0]['value'])) {
                            $source_text = $raw_name['translations'][0]['value'];
                            $source_lang = $raw_name['translations'][0]['reference'] ?? 'es';
                        }
                        // Simple format: {"es": "text", "en": "text"}
                    } elseif (!empty($raw_name['es'])) {
                        $source_text = $raw_name['es'];
                        $source_lang = 'es';
                    } elseif (!empty($raw_name['en'])) {
                        $source_text = $raw_name['en'];
                        $source_lang = 'en';
                    } else {
                        $first_key = array_key_first($raw_name);
                        $source_text = $first_key ? ($raw_name[$first_key] ?: '') : '';
                        $source_lang = $first_key ?: 'es';
                    }
                } else {
                    $source_text = (string) $raw_name;
                }

                if (empty($source_text)) {
                    $source_text = $reference;
                }

                $category_data[] = [
                    'reference' => $reference,
                    'source_text' => $source_text,
                    'source_lang' => $source_lang,
                    'parent' => $parent,
                ];
                $texts_by_lang[$source_lang][] = $source_text;
            }

            // Batch query cache per source language (typically 1-2 queries total)
            $cache_map = [];
            foreach ($texts_by_lang as $src_lang => $texts) {
                $batch_result = $translation_repo->get_batch($texts, $src_lang, $target_lang);
                foreach ($texts as $text) {
                    $hash = $translation_repo->generate_hash($text, $src_lang, $target_lang);
                    if (isset($batch_result[$hash])) {
                        $cache_map[$hash] = $batch_result[$hash];
                    }
                }
            }

            // Second pass: build final list using cached translations
            $formatted = [];
            foreach ($category_data as $data) {
                $hash = $translation_repo->generate_hash($data['source_text'], $data['source_lang'], $target_lang);
                $name = $cache_map[$hash] ?? $data['source_text'];

                $formatted[] = [
                    'reference' => $data['reference'],
                    'name' => $name,
                    'parent' => $data['parent'],
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
     * Returns categories with original_name, display_name, and translation_status.
     * Display Priority: Manual override → Transient → Original name
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

        // Get translation caches
        $transient_translations = get_transient('ewheel_category_translations') ?: [];
        $manual_translation_overrides = get_option('ewheel_importer_category_translation_overrides', []);

        // Also fetch ewheel categories from API for profile dropdown
        $ewheel_categories = [];
        $api_key = $this->config->get_api_key();
        if (!empty($api_key)) {
            try {
                $client = \Trotibike\EwheelImporter\Factory\ServiceFactory::create_api_client($api_key);
                $categories = $client->get_all_categories();

                $target_lang = $this->config->get_target_language();

                // Build category list with original and display names
                foreach ($categories as $cat) {
                    $reference = $cat['reference'] ?? ($cat['Reference'] ?? '');
                    $raw_name = $cat['name'] ?? ($cat['Name'] ?? '');
                    $parent = $cat['parentReference'] ?? ($cat['ParentReference'] ?? null);

                    // Extract source text from multilingual structure
                    $source_text = '';
                    $source_lang = 'es';
                    if (is_array($raw_name)) {
                        if (isset($raw_name['translations']) && is_array($raw_name['translations'])) {
                            foreach (['en', 'es'] as $preferred_lang) {
                                foreach ($raw_name['translations'] as $t) {
                                    if (isset($t['reference']) && $t['reference'] === $preferred_lang && !empty($t['value'])) {
                                        $source_text = $t['value'];
                                        $source_lang = $preferred_lang;
                                        break 2;
                                    }
                                }
                            }
                            if (empty($source_text) && !empty($raw_name['translations'][0]['value'])) {
                                $source_text = $raw_name['translations'][0]['value'];
                                $source_lang = $raw_name['translations'][0]['reference'] ?? 'es';
                            }
                        } elseif (!empty($raw_name['en'])) {
                            $source_text = $raw_name['en'];
                            $source_lang = 'en';
                        } elseif (!empty($raw_name['es'])) {
                            $source_text = $raw_name['es'];
                            $source_lang = 'es';
                        } else {
                            $first_key = array_key_first($raw_name);
                            $source_text = $first_key ? ($raw_name[$first_key] ?: '') : '';
                            $source_lang = $first_key ?: 'es';
                        }
                    } else {
                        $source_text = (string) $raw_name;
                    }

                    if (empty($source_text)) {
                        $source_text = $reference;
                    }

                    // Determine display name and translation status
                    // Priority: Manual override → Transient → Original name
                    $display_name = $source_text;
                    $translation_status = 'original';
                    $is_manually_edited = false;

                    if (isset($manual_translation_overrides[$reference])) {
                        $display_name = $manual_translation_overrides[$reference];
                        $translation_status = 'override';
                        $is_manually_edited = true;
                    } elseif (isset($transient_translations[$reference])) {
                        $display_name = $transient_translations[$reference];
                        $translation_status = 'translated';
                    }

                    $ewheel_categories[] = [
                        'reference' => $reference,
                        'original_name' => $source_text,
                        'display_name' => $display_name,
                        'name' => $display_name, // For backwards compatibility
                        'translation_status' => $translation_status,
                        'is_manually_edited' => $is_manually_edited,
                        'source_lang' => $source_lang,
                        'parent' => $parent,
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
            'has_translations' => !empty($transient_translations),
            'translation_count' => count($transient_translations),
            'override_count' => count($manual_translation_overrides),
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
     * AJAX Translate Categories in Batch.
     *
     * Translates a batch of categories and saves to transient.
     * Rate limiting: 15 categories per batch, 1s delay between batches (handled by JS).
     *
     * @return void
     */
    public function ajax_translate_categories(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $categories_json = isset($_POST['categories']) ? wp_unslash($_POST['categories']) : '';
        $batch_index = isset($_POST['batch_index']) ? (int) $_POST['batch_index'] : 0;

        if (empty($categories_json)) {
            wp_send_json_error(['message' => __('No categories provided', 'ewheel-importer')]);
        }

        $categories = json_decode($categories_json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($categories)) {
            wp_send_json_error(['message' => __('Invalid categories data', 'ewheel-importer')]);
        }

        try {
            $translator = $this->container->get(\Trotibike\EwheelImporter\Translation\TranslatorInterface::class);
            $target_lang = $this->config->get_target_language();

            // Get existing transient translations
            $transient_translations = get_transient('ewheel_category_translations') ?: [];

            $translated = [];
            $errors = [];

            foreach ($categories as $cat) {
                $reference = $cat['reference'] ?? '';
                $original_name = $cat['original_name'] ?? '';
                $source_lang = $cat['source_lang'] ?? 'es';

                if (empty($reference) || empty($original_name)) {
                    continue;
                }

                // Skip if already in manual overrides
                $manual_overrides = get_option('ewheel_importer_category_translation_overrides', []);
                if (isset($manual_overrides[$reference])) {
                    $translated[$reference] = $manual_overrides[$reference];
                    continue;
                }

                // Skip if source language is same as target
                if ($source_lang === $target_lang) {
                    $translated[$reference] = $original_name;
                    $transient_translations[$reference] = $original_name;
                    continue;
                }

                try {
                    $translation = $translator->translate($original_name, $source_lang, $target_lang);
                    $translated[$reference] = $translation;
                    $transient_translations[$reference] = $translation;
                } catch (\Exception $e) {
                    $errors[] = sprintf('%s: %s', $reference, $e->getMessage());
                    // Keep original on error
                    $translated[$reference] = $original_name;
                }
            }

            // Save to transient with 7 days expiry
            set_transient('ewheel_category_translations', $transient_translations, 7 * DAY_IN_SECONDS);

            wp_send_json_success([
                'batch_index' => $batch_index,
                'translated' => $translated,
                'translated_count' => count($translated),
                'total_cached' => count($transient_translations),
                'errors' => $errors,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX Save Category Translation Override.
     *
     * Saves a manual translation edit to persistent option.
     *
     * @return void
     */
    public function ajax_save_category_translation(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        $reference = isset($_POST['reference']) ? sanitize_text_field(wp_unslash($_POST['reference'])) : '';
        $translation = isset($_POST['translation']) ? sanitize_text_field(wp_unslash($_POST['translation'])) : '';
        $action_type = isset($_POST['action_type']) ? sanitize_text_field(wp_unslash($_POST['action_type'])) : 'save';

        if (empty($reference)) {
            wp_send_json_error(['message' => __('Category reference is required', 'ewheel-importer')]);
        }

        $overrides = get_option('ewheel_importer_category_translation_overrides', []);

        if ($action_type === 'delete' || $action_type === 'revert') {
            // Remove the manual override, revert to transient or original
            unset($overrides[$reference]);
            update_option('ewheel_importer_category_translation_overrides', $overrides);

            // Get the display name after revert
            $transient_translations = get_transient('ewheel_category_translations') ?: [];
            $display_name = $transient_translations[$reference] ?? null;
            $status = $display_name ? 'translated' : 'original';

            wp_send_json_success([
                'message' => __('Translation reverted', 'ewheel-importer'),
                'reference' => $reference,
                'display_name' => $display_name,
                'translation_status' => $status,
            ]);
        } else {
            // Save the manual override
            if (empty($translation)) {
                wp_send_json_error(['message' => __('Translation text is required', 'ewheel-importer')]);
            }

            $overrides[$reference] = $translation;
            update_option('ewheel_importer_category_translation_overrides', $overrides);

            wp_send_json_success([
                'message' => __('Translation saved', 'ewheel-importer'),
                'reference' => $reference,
                'display_name' => $translation,
                'translation_status' => 'override',
            ]);
        }
    }

    /**
     * AJAX Clear Category Translations Cache.
     *
     * Clears the transient cache but preserves manual overrides.
     *
     * @return void
     */
    public function ajax_clear_category_translations(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Delete the transient cache
        delete_transient('ewheel_category_translations');

        // Get count of preserved manual overrides
        $overrides = get_option('ewheel_importer_category_translation_overrides', []);
        $override_count = count($overrides);

        wp_send_json_success([
            'message' => sprintf(
                /* translators: %d: number of manual edits preserved */
                __('Translation cache cleared. %d manual edits preserved.', 'ewheel-importer'),
                $override_count
            ),
            'overrides_preserved' => $override_count,
        ]);
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
     * AJAX: Get OpenRouter Models (with cache).
     *
     * @return void
     */
    public function ajax_get_openrouter_models(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Check cache first
        $cached_models = Configuration::get_cached_openrouter_models();
        if ($cached_models !== false) {
            wp_send_json_success([
                'models' => $cached_models,
                'from_cache' => true,
            ]);
            return;
        }

        // Fetch from API
        $this->fetch_and_cache_openrouter_models();
    }

    /**
     * AJAX: Refresh OpenRouter Models (force refresh).
     *
     * @return void
     */
    public function ajax_refresh_openrouter_models(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Clear cache and fetch fresh
        Configuration::clear_cached_openrouter_models();
        $this->fetch_and_cache_openrouter_models();
    }

    /**
     * AJAX: Get Queue Status (Action Scheduler).
     *
     * @return void
     */
    public function ajax_get_queue_status(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        if (!function_exists('as_get_scheduled_actions')) {
            wp_send_json_success([
                'pending' => 0,
                'failed' => 0,
                'available' => false
            ]);
        }

        // Check Action Scheduler queue for stuck batches
        // Using 'ids' is faster and sufficient for counts
        $pending_actions = as_get_scheduled_actions([
            'hook' => 'ewheel_importer_process_batch',
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ], 'ids');

        $failed_actions = as_get_scheduled_actions([
            'hook' => 'ewheel_importer_process_batch',
            'status' => \ActionScheduler_Store::STATUS_FAILED,
        ], 'ids');

        $pending_count = is_array($pending_actions) ? count($pending_actions) : 0;
        $failed_count = is_array($failed_actions) ? count($failed_actions) : 0;

        wp_send_json_success([
            'pending' => $pending_count,
            'failed' => $failed_count,
            'available' => true,
            'html' => $this->render_queue_status_html($pending_count, $failed_count)
        ]);
    }

    /**
     * Render Queue Status HTML.
     * 
     * @param int $pending
     * @param int $failed
     * @return string
     */
    private function render_queue_status_html(int $pending, int $failed): string
    {
        if ($pending === 0 && $failed === 0) {
            return '';
        }

        ob_start();
        ?>
        <p class="description" style="margin-top: 10px; padding: 8px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <strong><?php esc_html_e('Queue Issue:', 'ewheel-importer'); ?></strong>
            <?php echo esc_html($pending); ?>         <?php esc_html_e('pending', 'ewheel-importer'); ?>,
            <?php echo esc_html($failed); ?>         <?php esc_html_e('failed batches', 'ewheel-importer'); ?>
            <button type="button" id="ewheel-clear-queue" class="button button-small" style="margin-left: 10px;">
                <?php esc_html_e('Clear Queue', 'ewheel-importer'); ?>
            </button>
            <a href="<?php echo esc_url(admin_url('tools.php?page=action-scheduler&s=ewheel_importer_process_batch')); ?>"
                style="margin-left: 5px;">
                <?php esc_html_e('View', 'ewheel-importer'); ?>
            </a>
        </p>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX: Clear Action Scheduler queue and sync locks.
     *
     * @return void
     */
    public function ajax_clear_queue(): void
    {
        check_ajax_referer('ewheel_importer_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'ewheel-importer')]);
        }

        // Clear all pending ewheel batch actions
        as_unschedule_all_actions('ewheel_importer_process_batch');

        // Delete failed actions from Action Scheduler tables
        global $wpdb;
        $table_name = $wpdb->prefix . 'actionscheduler_actions';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name} WHERE hook = %s AND status IN ('failed', 'canceled')",
                    'ewheel_importer_process_batch'
                )
            );
        }

        // Clear sync status and locks
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ewheel_importer_sync_status%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ewheel_importer_stop_sync%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%ewheel_importer_pause_sync%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%ewheel_importer_sync_lock%'");

        // Update sync history: mark any 'running' as 'stopped'
        \Trotibike\EwheelImporter\Sync\SyncHistoryManager::stop_all_running();

        wp_send_json_success(['message' => __('Queue cleared successfully', 'ewheel-importer')]);
    }

    /**
     * Fetch OpenRouter models from API and cache them.
     *
     * @return void
     */
    private function fetch_and_cache_openrouter_models(): void
    {
        $api_key = $this->config->get_openrouter_api_key();

        if (empty($api_key)) {
            wp_send_json_error([
                'message' => __('OpenRouter API key not configured. Please enter your API key first.', 'ewheel-importer'),
                'models' => [],
            ]);
            return;
        }

        try {
            $http_client = $this->container->get(\Trotibike\EwheelImporter\Api\HttpClientInterface::class);
            $response = $http_client->get(
                'https://openrouter.ai/api/v1/models',
                ['Authorization' => 'Bearer ' . $api_key]
            );

            if (empty($response['data'])) {
                wp_send_json_error([
                    'message' => __('No models returned from OpenRouter', 'ewheel-importer'),
                    'models' => [],
                ]);
                return;
            }

            // Format models for dropdown
            $models = [];
            foreach ($response['data'] as $model) {
                $id = $model['id'] ?? '';
                $name = $model['name'] ?? $id;

                // Build display name with pricing info
                $display_name = $name;

                // Check if it's a free model
                $is_free = false;
                if (isset($model['pricing'])) {
                    $prompt_price = floatval($model['pricing']['prompt'] ?? 1);
                    $completion_price = floatval($model['pricing']['completion'] ?? 1);
                    $is_free = ($prompt_price == 0 && $completion_price == 0);
                }
                if (strpos($id, ':free') !== false) {
                    $is_free = true;
                }

                if ($is_free) {
                    $display_name .= ' (Free)';
                }

                // Add context length if available
                if (isset($model['context_length']) && $model['context_length'] > 0) {
                    $context_k = round($model['context_length'] / 1000);
                    $display_name .= ' [' . $context_k . 'k ctx]';
                }

                $models[] = [
                    'id' => $id,
                    'name' => $name,
                    'display_name' => $display_name,
                    'is_free' => $is_free,
                    'context_length' => $model['context_length'] ?? 0,
                ];
            }

            // Sort: free models first, then alphabetically
            usort($models, function ($a, $b) {
                if ($a['is_free'] !== $b['is_free']) {
                    return $b['is_free'] - $a['is_free']; // Free first
                }
                return strcasecmp($a['name'], $b['name']);
            });

            // Cache the models
            Configuration::set_cached_openrouter_models($models);

            wp_send_json_success([
                'models' => $models,
                'from_cache' => false,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage(),
                'models' => [],
            ]);
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
     * Register product_brand taxonomy for WooCommerce products.
     *
     * @return void
     */
    public function register_product_brand_taxonomy(): void
    {
        if (taxonomy_exists('product_brand')) {
            return;
        }

        $labels = [
            'name'              => __('Brands', 'ewheel-importer'),
            'singular_name'     => __('Brand', 'ewheel-importer'),
            'search_items'      => __('Search Brands', 'ewheel-importer'),
            'all_items'         => __('All Brands', 'ewheel-importer'),
            'parent_item'       => null,
            'parent_item_colon' => null,
            'edit_item'         => __('Edit Brand', 'ewheel-importer'),
            'update_item'       => __('Update Brand', 'ewheel-importer'),
            'add_new_item'      => __('Add New Brand', 'ewheel-importer'),
            'new_item_name'     => __('New Brand Name', 'ewheel-importer'),
            'menu_name'         => __('Brands', 'ewheel-importer'),
        ];

        register_taxonomy('product_brand', ['product'], [
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'brand', 'with_front' => false],
        ]);
    }

    /**
     * Register product_model taxonomy for WooCommerce products.
     *
     * @return void
     */
    public function register_product_model_taxonomy(): void
    {
        if (taxonomy_exists('product_model')) {
            return;
        }

        $labels = [
            'name'              => __('Models', 'ewheel-importer'),
            'singular_name'     => __('Model', 'ewheel-importer'),
            'search_items'      => __('Search Models', 'ewheel-importer'),
            'all_items'         => __('All Models', 'ewheel-importer'),
            'parent_item'       => null,
            'parent_item_colon' => null,
            'edit_item'         => __('Edit Model', 'ewheel-importer'),
            'update_item'       => __('Update Model', 'ewheel-importer'),
            'add_new_item'      => __('Add New Model', 'ewheel-importer'),
            'new_item_name'     => __('New Model Name', 'ewheel-importer'),
            'menu_name'         => __('Models', 'ewheel-importer'),
        ];

        register_taxonomy('product_model', ['product'], [
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'model', 'with_front' => false],
        ]);
    }

    /**
     * Render product badges for NEW and Discontinued status.
     *
     * @return void
     */
    public function render_product_badges(): void
    {
        global $product;

        if (!$product instanceof \WC_Product) {
            return;
        }

        $is_new = $product->get_meta('_ewheel_new');
        $is_obsolete = $product->get_meta('_ewheel_obsolete');

        if ($is_new === '1' || $is_new === 'true' || $is_new === true) {
            echo '<span class="ewheel-badge ewheel-badge-new">' . esc_html__('NOU', 'ewheel-importer') . '</span>';
        }

        if ($is_obsolete === '1' || $is_obsolete === 'true' || $is_obsolete === true) {
            echo '<span class="ewheel-badge ewheel-badge-discontinued">' . esc_html__('Discontinuat', 'ewheel-importer') . '</span>';
        }
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
