<?php
/**
 * Plugin Name: Ewheel Importer
 * Plugin URI: https://trotibike.ro
 * Description: Import products from ewheel.es API into WooCommerce with automatic translation and price conversion.
 * Version: 1.0.0
 * Author: Trotibike
 * Author URI: https://trotibike.ro
 * License: GPL-2.0-or-later
 * Text Domain: ewheel-importer
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 *
 * @package Trotibike\EwheelImporter
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin constants.
 */
define( 'EWHEEL_IMPORTER_VERSION', '1.0.0' );
define( 'EWHEEL_IMPORTER_FILE', __FILE__ );
define( 'EWHEEL_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'EWHEEL_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader.
 */
require_once EWHEEL_IMPORTER_PATH . 'vendor/autoload.php';

/**
 * Main plugin class.
 */
final class Ewheel_Importer {

    /**
     * Single instance of the class.
     *
     * @var Ewheel_Importer|null
     */
    private static ?Ewheel_Importer $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return Ewheel_Importer
     */
    public static function instance(): Ewheel_Importer {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->check_requirements();
        $this->init_hooks();
    }

    /**
     * Check plugin requirements.
     *
     * @return void
     */
    private function check_requirements(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', [ $this, 'woocommerce_missing_notice' ] );
            return;
        }
    }

    /**
     * Display WooCommerce missing notice.
     *
     * @return void
     */
    public function woocommerce_missing_notice(): void {
        ?>
        <div class="notice notice-error">
            <p>
                <?php
                esc_html_e(
                    'Ewheel Importer requires WooCommerce to be installed and active.',
                    'ewheel-importer'
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    private function init_hooks(): void {
        // Load admin functionality
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // AJAX handlers
        add_action( 'wp_ajax_ewheel_run_sync', [ $this, 'ajax_run_sync' ] );
        add_action( 'wp_ajax_ewheel_test_connection', [ $this, 'ajax_test_connection' ] );

        // WP Cron
        add_action( 'ewheel_importer_cron_sync', [ $this, 'run_scheduled_sync' ] );
        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );

        // Activation/Deactivation
        register_activation_hook( EWHEEL_IMPORTER_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( EWHEEL_IMPORTER_FILE, [ $this, 'deactivate' ] );
    }

    /**
     * Add admin menu.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Ewheel Import', 'ewheel-importer' ),
            __( 'Ewheel Import', 'ewheel-importer' ),
            'manage_woocommerce',
            'ewheel-importer',
            [ $this, 'render_admin_page' ]
        );
    }

    /**
     * Register settings.
     *
     * @return void
     */
    public function register_settings(): void {
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_api_key' );
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_translate_api_key' );
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_exchange_rate' );
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_markup_percent' );
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_sync_frequency' );
        register_setting( 'ewheel_importer_settings', 'ewheel_importer_target_language' );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_admin_scripts( string $hook ): void {
        if ( 'woocommerce_page_ewheel-importer' !== $hook ) {
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
            [ 'jquery' ],
            EWHEEL_IMPORTER_VERSION,
            true
        );

        wp_localize_script(
            'ewheel-importer-admin',
            'ewheelImporter',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ewheel_importer_nonce' ),
                'strings' => [
                    'syncing'     => __( 'Syncing...', 'ewheel-importer' ),
                    'success'     => __( 'Sync completed!', 'ewheel-importer' ),
                    'error'       => __( 'Sync failed:', 'ewheel-importer' ),
                    'testing'     => __( 'Testing connection...', 'ewheel-importer' ),
                    'connected'   => __( 'Connection successful!', 'ewheel-importer' ),
                    'connFailed'  => __( 'Connection failed:', 'ewheel-importer' ),
                ],
            ]
        );
    }

    /**
     * Render admin page.
     *
     * @return void
     */
    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $last_sync = get_option( 'ewheel_importer_last_sync', __( 'Never', 'ewheel-importer' ) );

        ?>
        <div class="wrap ewheel-importer-admin">
            <h1><?php esc_html_e( 'Ewheel Product Importer', 'ewheel-importer' ); ?></h1>

            <div class="ewheel-importer-columns">
                <div class="ewheel-importer-main">
                    <form method="post" action="options.php">
                        <?php settings_fields( 'ewheel_importer_settings' ); ?>

                        <h2><?php esc_html_e( 'API Settings', 'ewheel-importer' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_api_key">
                                        <?php esc_html_e( 'Ewheel API Key', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="password"
                                           id="ewheel_importer_api_key"
                                           name="ewheel_importer_api_key"
                                           value="<?php echo esc_attr( get_option( 'ewheel_importer_api_key' ) ); ?>"
                                           class="regular-text">
                                    <button type="button" class="button" id="ewheel-test-connection">
                                        <?php esc_html_e( 'Test Connection', 'ewheel-importer' ); ?>
                                    </button>
                                    <span id="ewheel-connection-status"></span>
                                </td>
                            </tr>
                        </table>

                        <h2><?php esc_html_e( 'Translation Settings', 'ewheel-importer' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_translate_api_key">
                                        <?php esc_html_e( 'Google Translate API Key', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="password"
                                           id="ewheel_importer_translate_api_key"
                                           name="ewheel_importer_translate_api_key"
                                           value="<?php echo esc_attr( get_option( 'ewheel_importer_translate_api_key' ) ); ?>"
                                           class="regular-text">
                                    <p class="description">
                                        <?php esc_html_e( 'Required for automatic translation to Romanian.', 'ewheel-importer' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_target_language">
                                        <?php esc_html_e( 'Target Language', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <select id="ewheel_importer_target_language" name="ewheel_importer_target_language">
                                        <option value="ro" <?php selected( get_option( 'ewheel_importer_target_language', 'ro' ), 'ro' ); ?>>
                                            <?php esc_html_e( 'Romanian', 'ewheel-importer' ); ?>
                                        </option>
                                        <option value="en" <?php selected( get_option( 'ewheel_importer_target_language', 'ro' ), 'en' ); ?>>
                                            <?php esc_html_e( 'English', 'ewheel-importer' ); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <h2><?php esc_html_e( 'Pricing Settings', 'ewheel-importer' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_exchange_rate">
                                        <?php esc_html_e( 'EUR to RON Exchange Rate', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="number"
                                           id="ewheel_importer_exchange_rate"
                                           name="ewheel_importer_exchange_rate"
                                           value="<?php echo esc_attr( get_option( 'ewheel_importer_exchange_rate', '4.97' ) ); ?>"
                                           step="0.01"
                                           min="0"
                                           class="small-text">
                                    <p class="description">
                                        <?php esc_html_e( 'Current EUR to RON exchange rate.', 'ewheel-importer' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_markup_percent">
                                        <?php esc_html_e( 'Markup Percentage', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <input type="number"
                                           id="ewheel_importer_markup_percent"
                                           name="ewheel_importer_markup_percent"
                                           value="<?php echo esc_attr( get_option( 'ewheel_importer_markup_percent', '20' ) ); ?>"
                                           step="0.1"
                                           class="small-text">%
                                    <p class="description">
                                        <?php esc_html_e( 'Percentage to add to the converted price.', 'ewheel-importer' ); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <h2><?php esc_html_e( 'Sync Settings', 'ewheel-importer' ); ?></h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_sync_frequency">
                                        <?php esc_html_e( 'Sync Frequency', 'ewheel-importer' ); ?>
                                    </label>
                                </th>
                                <td>
                                    <select id="ewheel_importer_sync_frequency" name="ewheel_importer_sync_frequency">
                                        <option value="daily" <?php selected( get_option( 'ewheel_importer_sync_frequency', 'daily' ), 'daily' ); ?>>
                                            <?php esc_html_e( 'Daily', 'ewheel-importer' ); ?>
                                        </option>
                                        <option value="weekly" <?php selected( get_option( 'ewheel_importer_sync_frequency', 'daily' ), 'weekly' ); ?>>
                                            <?php esc_html_e( 'Weekly', 'ewheel-importer' ); ?>
                                        </option>
                                        <option value="manual" <?php selected( get_option( 'ewheel_importer_sync_frequency', 'daily' ), 'manual' ); ?>>
                                            <?php esc_html_e( 'Manual Only', 'ewheel-importer' ); ?>
                                        </option>
                                    </select>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(); ?>
                    </form>
                </div>

                <div class="ewheel-importer-sidebar">
                    <div class="ewheel-importer-box">
                        <h3><?php esc_html_e( 'Sync Status', 'ewheel-importer' ); ?></h3>
                        <p>
                            <strong><?php esc_html_e( 'Last Sync:', 'ewheel-importer' ); ?></strong>
                            <?php echo esc_html( $last_sync ); ?>
                        </p>
                        <button type="button" class="button button-primary" id="ewheel-run-sync">
                            <?php esc_html_e( 'Run Sync Now', 'ewheel-importer' ); ?>
                        </button>
                        <div id="ewheel-sync-status"></div>
                    </div>

                    <div class="ewheel-importer-box">
                        <h3><?php esc_html_e( 'Help', 'ewheel-importer' ); ?></h3>
                        <p><?php esc_html_e( 'This plugin imports products from ewheel.es API into your WooCommerce store.', 'ewheel-importer' ); ?></p>
                        <ul>
                            <li><?php esc_html_e( 'Products are matched by SKU', 'ewheel-importer' ); ?></li>
                            <li><?php esc_html_e( 'Prices are converted from EUR to RON', 'ewheel-importer' ); ?></li>
                            <li><?php esc_html_e( 'Descriptions are translated automatically', 'ewheel-importer' ); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for running sync.
     *
     * @return void
     */
    public function ajax_run_sync(): void {
        check_ajax_referer( 'ewheel_importer_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'ewheel-importer' ) ] );
        }

        try {
            $sync    = $this->get_sync_instance();
            $results = $sync->sync_products();

            $sync->update_last_sync_time();

            wp_send_json_success(
                [
                    'message' => sprintf(
                        /* translators: 1: number of created products, 2: number of updated products */
                        __( 'Sync complete! Created: %1$d, Updated: %2$d', 'ewheel-importer' ),
                        $results['created'],
                        $results['updated']
                    ),
                    'results' => $results,
                ]
            );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * AJAX handler for testing connection.
     *
     * @return void
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'ewheel_importer_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied', 'ewheel-importer' ) ] );
        }

        $api_key = get_option( 'ewheel_importer_api_key' );

        if ( empty( $api_key ) ) {
            wp_send_json_error( [ 'message' => __( 'API key not configured', 'ewheel-importer' ) ] );
        }

        try {
            $http_client = new \Trotibike\EwheelImporter\Api\WPHttpClient();
            $client      = new \Trotibike\EwheelImporter\Api\EwheelApiClient( $api_key, $http_client );

            // Try to fetch categories as a connection test
            $categories = $client->get_categories( 0, 1 );

            wp_send_json_success(
                [
                    'message' => __( 'Connection successful!', 'ewheel-importer' ),
                ]
            );
        } catch ( \Exception $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * Run scheduled sync.
     *
     * @return void
     */
    public function run_scheduled_sync(): void {
        $last_sync = get_option( 'ewheel_importer_last_sync' );

        try {
            $sync = $this->get_sync_instance();

            if ( $last_sync ) {
                // Incremental sync
                $sync->sync_products_since( $last_sync );
            } else {
                // Full sync
                $sync->sync_categories();
                $sync->sync_products();
            }

            $sync->update_last_sync_time();
        } catch ( \Exception $e ) {
            error_log( 'Ewheel Importer scheduled sync failed: ' . $e->getMessage() );
        }
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function add_cron_schedules( array $schedules ): array {
        $schedules['weekly'] = [
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly', 'ewheel-importer' ),
        ];

        return $schedules;
    }

    /**
     * Plugin activation.
     *
     * @return void
     */
    public function activate(): void {
        $frequency = get_option( 'ewheel_importer_sync_frequency', 'daily' );

        if ( $frequency !== 'manual' && ! wp_next_scheduled( 'ewheel_importer_cron_sync' ) ) {
            wp_schedule_event( time(), $frequency, 'ewheel_importer_cron_sync' );
        }
    }

    /**
     * Plugin deactivation.
     *
     * @return void
     */
    public function deactivate(): void {
        wp_clear_scheduled_hook( 'ewheel_importer_cron_sync' );
    }

    /**
     * Get sync instance.
     *
     * @return \Trotibike\EwheelImporter\Sync\WooCommerceSync
     */
    private function get_sync_instance(): \Trotibike\EwheelImporter\Sync\WooCommerceSync {
        $api_key        = get_option( 'ewheel_importer_api_key' );
        $translate_key  = get_option( 'ewheel_importer_translate_api_key' );
        $exchange_rate  = (float) get_option( 'ewheel_importer_exchange_rate', 4.97 );
        $markup_percent = (float) get_option( 'ewheel_importer_markup_percent', 20 );
        $target_lang    = get_option( 'ewheel_importer_target_language', 'ro' );

        // HTTP Client
        $http_client = new \Trotibike\EwheelImporter\Api\WPHttpClient();

        // Ewheel API Client
        $ewheel_client = new \Trotibike\EwheelImporter\Api\EwheelApiClient( $api_key, $http_client );

        // Translation Service
        $translation_service = new \Trotibike\EwheelImporter\Translation\GoogleTranslateService(
            $translate_key,
            $http_client
        );
        $translator = new \Trotibike\EwheelImporter\Translation\Translator(
            $translation_service,
            $target_lang
        );

        // Pricing Converter
        $rate_provider    = new \Trotibike\EwheelImporter\Pricing\FixedExchangeRateProvider(
            [ 'EUR_RON' => $exchange_rate ]
        );
        $pricing_converter = new \Trotibike\EwheelImporter\Pricing\PricingConverter(
            $rate_provider,
            'EUR',
            'RON',
            $markup_percent
        );

        // Product Transformer
        $transformer = new \Trotibike\EwheelImporter\Sync\ProductTransformer(
            $translator,
            $pricing_converter
        );

        return new \Trotibike\EwheelImporter\Sync\WooCommerceSync( $ewheel_client, $transformer );
    }
}

/**
 * Initialize the plugin.
 *
 * @return Ewheel_Importer
 */
function ewheel_importer(): Ewheel_Importer {
    return Ewheel_Importer::instance();
}

// Start the plugin
add_action( 'plugins_loaded', 'ewheel_importer' );
