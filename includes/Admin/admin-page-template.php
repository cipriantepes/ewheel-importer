<?php
/**
 * Admin Page Template.
 *
 * @package Trotibike\EwheelImporter
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

$config = new \Trotibike\EwheelImporter\Config\Configuration();
$settings = $config->get_all();
$last_sync = $settings['last_sync'];
?>
<div class="wrap ewheel-importer-admin">
    <h1><?php esc_html_e('Ewheel Product Importer', 'ewheel-importer'); ?></h1>

    <div class="ewheel-importer-columns">
        <div class="ewheel-importer-main">
            <form method="post" action="options.php">
                <?php settings_fields('ewheel_importer_settings'); ?>

                <h2><?php esc_html_e('API Settings', 'ewheel-importer'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ewheel_importer_api_key">
                                <?php esc_html_e('Ewheel API Key', 'ewheel-importer'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="password" id="ewheel_importer_api_key" name="ewheel_importer_api_key"
                                value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text" />
                            <p class="description">
                                <?php esc_html_e('API key from ewheel.es', 'ewheel-importer'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Field Mapping', 'ewheel-importer'); ?></h2>
                <p class="description"><?php esc_html_e('Select which fields to synchronize from Ewheel.', 'ewheel-importer'); ?></p>
                <table class="form-table">
                    <?php
                    $fields = [
                        'name' => __('Product Name', 'ewheel-importer'),
                        'description' => __('Description', 'ewheel-importer'),
                        'short_description' => __('Short Description', 'ewheel-importer'),
                        'price' => __('Price (RRP)', 'ewheel-importer'),
                        'image' => __('Images', 'ewheel-importer'),
                        'categories' => __('Categories', 'ewheel-importer'),
                        'attributes' => __('Attributes', 'ewheel-importer'),
                    ];
                    $sync_fields = $config->get_sync_fields();
                    foreach ($fields as $key => $label) :
                    ?>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>">
                                    <?php echo esc_html($label); ?>
                                </label>
                            </th>
                            <td>
                                <input type="checkbox" id="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>"
                                    name="ewheel_importer_sync_fields[<?php echo esc_attr($key); ?>]" value="1"
                                    <?php checked(!empty($sync_fields[$key])); ?> />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e('Translation Settings', 'ewheel-importer'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                                <label for="ewheel_importer_translation_driver">
                                    <?php esc_html_e('Translation Engine', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="ewheel_importer_translation_driver"
                                    name="ewheel_importer_translation_driver">
                                    <option value="google" <?php selected($settings['translation_driver'], 'google'); ?>>
                                        <?php esc_html_e('Google Translate', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="deepl" <?php selected($settings['translation_driver'], 'deepl'); ?>>
                                        <?php esc_html_e('DeepL Translate', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="openrouter" <?php selected($settings['translation_driver'], 'openrouter'); ?>>
                                        <?php esc_html_e('OpenRouter (LLMs)', 'ewheel-importer'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Choose which service to use for translations', 'ewheel-importer'); ?>
                                </p>
                                <script>
                                    jQuery(document).ready(function($) {
                                        function toggleTranslationFields() {
                                            var driver = $('#ewheel_importer_translation_driver').val();
                                            $('#ewheel_importer_translate_api_key').closest('tr').toggle(driver === 'google');
                                            $('#ewheel_importer_deepl_api_key').closest('tr').toggle(driver === 'deepl');
                                            $('#ewheel_importer_openrouter_api_key').closest('tr').toggle(driver === 'openrouter');
                                            $('#ewheel_importer_openrouter_model').closest('tr').toggle(driver === 'openrouter');
                                        }
                                        $('#ewheel_importer_translation_driver').change(toggleTranslationFields);
                                        toggleTranslationFields();
                                    });
                                </script>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_deepl_api_key">
                                    <?php esc_html_e('DeepL API Key', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="password" id="ewheel_importer_deepl_api_key"
                                    name="ewheel_importer_deepl_api_key"
                                    value="<?php echo esc_attr($settings['deepl_api_key'] ?? ''); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('API key for DeepL (Free or Pro)', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_openrouter_api_key">
                                    <?php esc_html_e('OpenRouter API Key', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="password" id="ewheel_importer_openrouter_api_key"
                                    name="ewheel_importer_openrouter_api_key"
                                    value="<?php echo esc_attr($settings['openrouter_api_key'] ?? ''); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('API key from openrouter.ai', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_openrouter_model">
                                    <?php esc_html_e('OpenRouter Model', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="ewheel_importer_openrouter_model"
                                    name="ewheel_importer_openrouter_model"
                                    value="<?php echo esc_attr($settings['openrouter_model'] ?? 'google/gemini-flash-1.5'); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('Model ID (e.g. google/gemini-flash-1.5, meta-llama/llama-3-8b-instruct)', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_translate_api_key">
                                    <?php esc_html_e('Google Translate API Key', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="password" id="ewheel_importer_translate_api_key"
                                    name="ewheel_importer_translate_api_key"
                                    value="<?php echo esc_attr($settings['translate_api_key']); ?>"
                                    class="regular-text" />
                                <p class="description">
                                    <?php esc_html_e('API key for Google Translate (optional)', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e('Pricing Settings', 'ewheel-importer'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_exchange_rate">
                                    <?php esc_html_e('Exchange Rate (EUR to RON)', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" step="0.0001" min="0" id="ewheel_importer_exchange_rate"
                                    name="ewheel_importer_exchange_rate"
                                    value="<?php echo esc_attr($settings['exchange_rate']); ?>" class="small-text" />
                                <p class="description">
                                    <?php esc_html_e('Current exchange rate for EUR to RON conversion', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_markup_percent">
                                    <?php esc_html_e('Markup Percentage', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="number" step="0.1" min="0" id="ewheel_importer_markup_percent"
                                    name="ewheel_importer_markup_percent"
                                    value="<?php echo esc_attr($settings['markup_percent']); ?>" class="small-text" />
                                <span>%</span>
                                <p class="description">
                                    <?php esc_html_e('Percentage to add on top of converted price', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e('Sync Settings', 'ewheel-importer'); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_sync_frequency">
                                    <?php esc_html_e('Sync Frequency', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="ewheel_importer_sync_frequency" name="ewheel_importer_sync_frequency">
                                    <option value="manual" <?php selected($settings['sync_frequency'], 'manual'); ?>>
                                        <?php esc_html_e('Manual Only', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="daily" <?php selected($settings['sync_frequency'], 'daily'); ?>>
                                        <?php esc_html_e('Daily', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="weekly" <?php selected($settings['sync_frequency'], 'weekly'); ?>>
                                        <?php esc_html_e('Weekly', 'ewheel-importer'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('How often to automatically sync products', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_target_language">
                                    <?php esc_html_e('Target Language', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="ewheel_importer_target_language" name="ewheel_importer_target_language">
                                    <option value="ro" <?php selected($settings['target_language'], 'ro'); ?>>
                                        <?php esc_html_e('Romanian', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="en" <?php selected($settings['target_language'], 'en'); ?>>
                                        <?php esc_html_e('English', 'ewheel-importer'); ?>
                                    </option>
                                    <option value="es" <?php selected($settings['target_language'], 'es'); ?>>
                                        <?php esc_html_e('Spanish', 'ewheel-importer'); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Language to translate product content into', 'ewheel-importer'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', 'ewheel-importer')); ?>
            </form>
        </div>

        <div class="ewheel-importer-sidebar">
            <div class="ewheel-importer-box">
                <h3><?php esc_html_e('Connection Test', 'ewheel-importer'); ?></h3>
                <p>
                    <button type="button" id="ewheel-test-connection" class="button">
                        <?php esc_html_e('Test API Connection', 'ewheel-importer'); ?>
                    </button>
                    <span id="ewheel-connection-status"></span>
                </p>
            </div>

            <div class="ewheel-importer-box">
                <h3><?php esc_html_e('Manual Sync', 'ewheel-importer'); ?></h3>
                <p>
                    <label for="ewheel-sync-limit" style="margin-right: 10px;">
                        <?php esc_html_e('Test Limit:', 'ewheel-importer'); ?>
                    </label>
                    <input type="number" id="ewheel-sync-limit" min="0"
                        placeholder="<?php esc_attr_e('0 = All', 'ewheel-importer'); ?>" style="width: 80px;">
                    <span class="description">
                        <?php esc_html_e('Number of products to stop after (0 for all)', 'ewheel-importer'); ?>
                    </span>
                </p>
                <p>
                    <button type="button" id="ewheel-run-sync" class="button button-primary">
                        <?php esc_html_e('Run Sync Now', 'ewheel-importer'); ?>
                    </button>
                    <button type="button" id="ewheel-stop-sync" class="button button-secondary" style="display:none;">
                        <?php esc_html_e('Stop Sync', 'ewheel-importer'); ?>
                    </button>
                    <span id="ewheel-sync-status"></span>
                </p>
                <?php if ($last_sync): ?>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: last sync date/time */
                            esc_html__('Last sync: %s', 'ewheel-importer'),
                            esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync)))
                        );
                        ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="ewheel-importer-box">
                <h3><?php esc_html_e('How It Works', 'ewheel-importer'); ?></h3>
                <ul>
                    <li><?php esc_html_e('Products are fetched from ewheel.es API', 'ewheel-importer'); ?></li>
                    <li><?php esc_html_e('Names and descriptions are translated', 'ewheel-importer'); ?></li>
                    <li><?php esc_html_e('Prices are converted from EUR to RON', 'ewheel-importer'); ?></li>
                    <li><?php esc_html_e('Products are created or updated in WooCommerce', 'ewheel-importer'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>