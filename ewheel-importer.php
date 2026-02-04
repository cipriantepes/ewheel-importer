<?php
/**
 * Plugin Name: Ewheel Importer
 * Plugin URI: https://trotibike.ro
 * Description: Import products from ewheel.es API into WooCommerce with automatic translation and price conversion.
 * Version: 1.1.5
 * Author: Trotibike
 * Author URI: https://trotibike.ro
 * License: GPL-2.0-or-later
 * Text Domain: ewheel-importer
 * Domain Path: /languages
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
define('EWHEEL_IMPORTER_VERSION', '1.1.5');
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
use Trotibike\EwheelImporter\Admin\AdminPage;
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
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks(): void
    {
        // Admin
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // AJAX
        add_action('wp_ajax_ewheel_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_ewheel_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_ewheel_stop_sync', [$this, 'ajax_stop_sync']);
        add_action('wp_ajax_ewheel_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_ewheel_get_logs', [$this, 'ajax_get_logs']);

        // Cron
        add_action('ewheel_importer_cron_sync', [$this, 'run_scheduled_sync']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Activation/Deactivation
        register_activation_hook(EWHEEL_IMPORTER_FILE, [$this, 'activate']);
        register_deactivation_hook(EWHEEL_IMPORTER_FILE, [$this, 'deactivate']);

        // Action Scheduler Hook
        add_action('ewheel_importer_process_batch', [$this, 'process_batch_action'], 10, 3);
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

            // Re-use launcher directly or through factory
            $launcher = $this->container->get(\Trotibike\EwheelImporter\Sync\SyncLauncher::class);
            $sync_id = $launcher->start_sync($limit);

            wp_send_json_success(
                [
                    'message' => __('Sync started successfully. ID: ' . $sync_id, 'ewheel-importer'),
                    'sync_id' => $sync_id,
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

        $status = get_option('ewheel_importer_sync_status', []);

        // Add human readable last update
        if (!empty($status['last_update'])) {
            $status['last_update_human'] = human_time_diff($status['last_update'], time()) . ' ago';
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

        if (empty($sync_id)) {
            // Try to get running sync from status
            $status = get_option('ewheel_importer_sync_status', []);
            if (!empty($status['id']) && $status['status'] === 'running') {
                $sync_id = $status['id'];
            }
        }

        if (!empty($sync_id)) {
            update_option('ewheel_importer_stop_sync_' . $sync_id, true);

            // Update status immediately to reflect stopping
            $status = get_option('ewheel_importer_sync_status', []);
            if (isset($status['id']) && $status['id'] === $sync_id) {
                $status['status'] = 'stopping';
                update_option('ewheel_importer_sync_status', $status);
            }

            wp_send_json_success(['message' => __('Sync stopping...', 'ewheel-importer')]);
        } else {
            wp_send_json_error(['message' => __('No running sync found.', 'ewheel-importer')]);
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
     * @param int    $page    Page Number.
     * @param string $sync_id Sync ID.
     * @param string $since   Since date.
     * @return void
     */
    public function process_batch_action(int $page, string $sync_id, string $since): void
    {
        try {
            // WE NEED TO LOAD LOGGER!
            if (!class_exists(\Trotibike\EwheelImporter\Log\LiveLogger::class)) {
                require_once EWHEEL_IMPORTER_PATH . 'includes/Log/LiveLogger.php';
            }

            $container = ServiceFactory::build_container();
            $processor = $container->get(\Trotibike\EwheelImporter\Sync\SyncBatchProcessor::class);

            $processor->process_batch($page, $sync_id, $since);

        } catch (\Exception $e) {
            error_log('Ewheel Importer Batch Action Error: ' . $e->getMessage());
        }
    }

    /**
     * AJAX Get Logs.
     *
     * @return void
     */
    public function ajax_get_logs(): void
    {
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
