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

// Get sync history stats
$sync_stats = \Trotibike\EwheelImporter\Sync\SyncHistoryManager::get_stats();
$recent_history = \Trotibike\EwheelImporter\Sync\SyncHistoryManager::get_recent(5);

// Get current sync status
$current_status = get_option('ewheel_importer_sync_status', []);
$is_running = !empty($current_status['status']) && in_array($current_status['status'], ['running', 'pausing'], true);
$is_paused = !empty($current_status['status']) && $current_status['status'] === 'paused';
?>
<div class="wrap ewheel-importer-admin">
    <h1><?php esc_html_e('Ewheel Product Importer', 'ewheel-importer'); ?></h1>

    <!-- Tabs -->
    <div class="ewheel-tabs">
        <div class="ewheel-tab active" data-tab="settings"><?php esc_html_e('Settings', 'ewheel-importer'); ?></div>
        <div class="ewheel-tab" data-tab="profiles"><?php esc_html_e('Import Profiles', 'ewheel-importer'); ?></div>
        <div class="ewheel-tab" data-tab="categories"><?php esc_html_e('Category Mapping', 'ewheel-importer'); ?></div>
        <div class="ewheel-tab" data-tab="history"><?php esc_html_e('Sync History', 'ewheel-importer'); ?></div>
        <div class="ewheel-tab" data-tab="logs"><?php esc_html_e('Error Logs', 'ewheel-importer'); ?></div>
    </div>

    <!-- Settings Tab -->
    <div class="ewheel-tab-content active" id="tab-settings">
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
                    <p class="description">
                        <?php esc_html_e('Select which fields to synchronize from Ewheel.', 'ewheel-importer'); ?>
                    </p>
                    <table class="form-table">
                        <?php
                        $sync_fields = $config->get_sync_fields();
                        $sync_protection = $config->get('sync_protection') ?: [];

                        // Helper to normalize values (legacy boolean check)
                        $get_val = function ($key, $default_map) use ($sync_fields) {
                            $val = $sync_fields[$key] ?? '';
                            if ($val === true || $val === '1' || $val === 1) {
                                return $default_map;
                            }
                            if ($val === false || $val === '0' || $val === 0 || $val === '') {
                                return 'none';
                            }
                            return $val;
                        };

                        // Text Fields (Name, Description, Short Desc)
                        $text_fields = [
                            'name' => __('Product Name', 'ewheel-importer'),
                            'description' => __('Description', 'ewheel-importer'),
                            'short_description' => __('Short Description', 'ewheel-importer'),
                        ];

                        foreach ($text_fields as $key => $label):
                            $default_for_key = [
                                'name' => 'name',
                                'description' => 'description',
                                'short_description' => 'description',
                            ][$key] ?? 'name';

                            $current_val = $get_val($key, $default_for_key);
                            $is_protected = !empty($sync_protection[$key]);
                            $custom_patterns = $config->get('custom_patterns') ?: [];
                            $custom_val = $custom_patterns[$key] ?? '';
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <select id="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>"
                                        name="ewheel_importer_sync_fields[<?php echo esc_attr($key); ?>]"
                                        class="ewheel-sync-field-select" data-key="<?php echo esc_attr($key); ?>">
                                        <option value="name" <?php selected($current_val, 'name'); ?>>Name</option>
                                        <option value="reference" <?php selected($current_val, 'reference'); ?>>Reference (SKU)</option>
                                        <option value="description" <?php selected($current_val, 'description'); ?>>Description</option>
                                        <option value="custom" <?php selected($current_val, 'custom'); ?>>Custom Pattern...</option>
                                        <option value="none" <?php selected($current_val, 'none'); ?>>None (Skip)</option>
                                    </select>

                                    <div id="ewheel_custom_pattern_<?php echo esc_attr($key); ?>"
                                        style="margin-top: 5px; <?php echo $current_val !== 'custom' ? 'display:none;' : ''; ?>">
                                        <input type="text"
                                            name="ewheel_importer_custom_patterns[<?php echo esc_attr($key); ?>]"
                                            value="<?php echo esc_attr($custom_val); ?>" class="regular-text"
                                            placeholder="{name} - {reference}">
                                        <p class="description">
                                            <?php esc_html_e('Available tags: {name}, {reference}, {price}, {description}', 'ewheel-importer'); ?>
                                        </p>
                                    </div>

                                    <br>
                                    <label>
                                        <input type="checkbox"
                                            name="ewheel_importer_sync_protection[<?php echo esc_attr($key); ?>]" value="1"
                                            <?php checked($is_protected); ?>>
                                        <small><?php esc_html_e('Protect on update (do not overwrite)', 'ewheel-importer'); ?></small>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Price Field -->
                        <?php
                        $price_val = $get_val('price', 'rrp');
                        $is_price_protected = !empty($sync_protection['price']);
                        ?>
                        <tr>
                            <th scope="row">
                                <label for="ewheel_importer_sync_fields_price">
                                    <?php esc_html_e('Price', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <select id="ewheel_importer_sync_fields_price" name="ewheel_importer_sync_fields[price]">
                                    <option value="rrp" <?php selected($price_val, 'rrp'); ?>>RRP (Recommended)</option>
                                    <option value="net" <?php selected($price_val, 'net'); ?>>Net (Cost)</option>
                                    <option value="none" <?php selected($price_val, 'none'); ?>>None (Skip)</option>
                                </select>
                                <br>
                                <label>
                                    <input type="checkbox" name="ewheel_importer_sync_protection[price]" value="1" <?php checked($is_price_protected); ?>>
                                    <small><?php esc_html_e('Protect on update', 'ewheel-importer'); ?></small>
                                </label>
                            </td>
                        </tr>

                        <!-- Toggle Fields (Images, Categories, Attrs) -->
                        <?php
                        $toggle_fields = [
                            'image' => __('Images', 'ewheel-importer'),
                            'categories' => __('Categories', 'ewheel-importer'),
                            'attributes' => __('Attributes', 'ewheel-importer'),
                        ];

                        foreach ($toggle_fields as $key => $label):
                            $toggle_val = $get_val($key, 'enabled');
                            if ($toggle_val !== 'none' && $toggle_val !== 'disabled')
                                $toggle_val = 'enabled';
                            else
                                $toggle_val = 'disabled';

                            $is_protected = !empty($sync_protection[$key]);
                            ?>
                            <tr>
                                <th scope="row">
                                    <label for="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>">
                                        <?php echo esc_html($label); ?>
                                    </label>
                                </th>
                                <td>
                                    <select id="ewheel_importer_sync_fields_<?php echo esc_attr($key); ?>"
                                        name="ewheel_importer_sync_fields[<?php echo esc_attr($key); ?>]">
                                        <option value="enabled" <?php selected($toggle_val, 'enabled'); ?>>Enabled</option>
                                        <option value="disabled" <?php selected($toggle_val, 'disabled'); ?>>Disabled</option>
                                    </select>
                                    <br>
                                    <label>
                                        <input type="checkbox"
                                            name="ewheel_importer_sync_protection[<?php echo esc_attr($key); ?>]"
                                            value="1" <?php checked($is_protected); ?>>
                                        <small><?php esc_html_e('Protect on update', 'ewheel-importer'); ?></small>
                                    </label>
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
                                <select id="ewheel_importer_translation_driver" name="ewheel_importer_translation_driver">
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
                            </td>
                        </tr>
                        <tr class="translation-row translation-deepl">
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
                            </td>
                        </tr>
                        <tr class="translation-row translation-openrouter">
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
                            </td>
                        </tr>
                        <tr class="translation-row translation-openrouter">
                            <th scope="row">
                                <label for="ewheel_importer_openrouter_model">
                                    <?php esc_html_e('OpenRouter Model', 'ewheel-importer'); ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" id="ewheel_importer_openrouter_model"
                                    name="ewheel_importer_openrouter_model"
                                    value="<?php echo esc_attr($settings['openrouter_model'] ?? 'google/gemini-2.0-flash:free'); ?>"
                                    class="regular-text" />
                            </td>
                        </tr>
                        <tr class="translation-row translation-google">
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
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save Settings', 'ewheel-importer')); ?>

                    <!-- Settings Export/Import -->
                    <div class="ewheel-settings-actions">
                        <button type="button" id="ewheel-export-settings" class="button">
                            <?php esc_html_e('Export Settings', 'ewheel-importer'); ?>
                        </button>
                        <button type="button" id="ewheel-import-settings" class="button">
                            <?php esc_html_e('Import Settings', 'ewheel-importer'); ?>
                        </button>
                        <input type="file" id="ewheel-import-file" accept=".json" style="display: none;">
                    </div>
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

                    <?php if ($is_running): ?>
                        <!-- Progress Display -->
                        <div id="ewheel-sync-progress">
                            <div class="ewheel-progress-container">
                                <div class="ewheel-progress-bar" style="width: 0%;">
                                    <span id="ewheel-progress-text">0%</span>
                                </div>
                            </div>
                            <p id="ewheel-sync-details">
                                <?php
                                printf(
                                    esc_html__('Processing: %d products', 'ewheel-importer'),
                                    $current_status['processed'] ?? 0
                                );
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>

                    <p>
                        <label for="ewheel-sync-limit" style="margin-right: 10px;">
                            <?php esc_html_e('Test Limit:', 'ewheel-importer'); ?>
                        </label>
                        <input type="number" id="ewheel-sync-limit" min="0"
                            placeholder="<?php esc_attr_e('0 = All', 'ewheel-importer'); ?>" style="width: 80px;">
                        <span class="description">
                            <?php esc_html_e('(0 for all)', 'ewheel-importer'); ?>
                        </span>
                    </p>
                    <p id="ewheel-sync-controls">
                        <!-- Run button: shown when idle -->
                        <button type="button" id="ewheel-run-sync" class="button button-primary" style="<?php echo ($is_running || $is_paused) ? 'display:none;' : ''; ?>">
                            <?php esc_html_e('Run Sync', 'ewheel-importer'); ?>
                        </button>

                        <!-- Pause button: shown when running -->
                        <button type="button" id="ewheel-pause-sync" class="button button-secondary" style="<?php echo $is_running ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('Pause', 'ewheel-importer'); ?>
                        </button>

                        <!-- Resume button: shown when paused -->
                        <button type="button" id="ewheel-resume-sync" class="button button-primary" style="<?php echo $is_paused ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('Resume', 'ewheel-importer'); ?>
                        </button>

                        <!-- Cancel button: shown when running or paused -->
                        <button type="button" id="ewheel-cancel-sync" class="button button-link-delete" style="<?php echo ($is_running || $is_paused) ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('Cancel', 'ewheel-importer'); ?>
                        </button>

                        <span id="ewheel-sync-status"></span>
                    </p>

                    <hr style="margin: 20px 0;">

                    <h3><?php esc_html_e('Activity Log', 'ewheel-importer'); ?></h3>
                    <div id="ewheel-activity-log"
                        style="background: #f0f0f1; border: 1px solid #ccc; padding: 10px; height: 200px; overflow-y: scroll; font-family: monospace; font-size: 11px; margin-top: 10px;">
                        <p><?php esc_html_e('Waiting for logs...', 'ewheel-importer'); ?></p>
                    </div>

                    <?php if ($last_sync): ?>
                        <p class="description" style="margin-top: 10px;">
                            <?php
                            printf(
                                esc_html__('Last sync: %s', 'ewheel-importer'),
                                esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync)))
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="ewheel-importer-box">
                    <h3><?php esc_html_e('Quick Stats', 'ewheel-importer'); ?></h3>
                    <div class="ewheel-stats-row" style="flex-direction: column;">
                        <div class="ewheel-stat-card">
                            <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['total_syncs'])); ?></div>
                            <div class="stat-label"><?php esc_html_e('Total Syncs', 'ewheel-importer'); ?></div>
                        </div>
                        <div class="ewheel-stat-card success">
                            <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['total_products_processed'])); ?></div>
                            <div class="stat-label"><?php esc_html_e('Products Processed', 'ewheel-importer'); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Profiles Tab -->
    <div class="ewheel-tab-content" id="tab-profiles">
        <div class="ewheel-importer-columns">
            <div class="ewheel-importer-main">
                <!-- Profile List View -->
                <div id="ewheel-profile-list-view">
                    <div class="ewheel-importer-box" style="max-width: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0;"><?php esc_html_e('Import Profiles', 'ewheel-importer'); ?></h3>
                            <button type="button" id="ewheel-add-profile" class="button button-primary">
                                <span class="dashicons dashicons-plus" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Add New Profile', 'ewheel-importer'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php esc_html_e('Create different import profiles with custom filters and settings. Each profile can filter products by category, status, and apply different pricing rules.', 'ewheel-importer'); ?>
                        </p>

                        <div id="ewheel-profiles-container">
                            <div class="ewheel-empty-state">
                                <span class="dashicons dashicons-admin-users"></span>
                                <p><?php esc_html_e('Loading profiles...', 'ewheel-importer'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Edit View -->
                <div id="ewheel-profile-edit-view" style="display: none;">
                    <div class="ewheel-importer-box" style="max-width: none;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 id="ewheel-profile-form-title" style="margin: 0;"><?php esc_html_e('Add New Profile', 'ewheel-importer'); ?></h3>
                            <button type="button" id="ewheel-cancel-profile" class="button">
                                <span class="dashicons dashicons-arrow-left-alt" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Back to Profiles', 'ewheel-importer'); ?>
                            </button>
                        </div>

                        <form id="ewheel-profile-form">
                            <input type="hidden" id="ewheel-profile-id" name="profile_id" value="">

                            <!-- Basic Info -->
                            <h4><?php esc_html_e('Basic Information', 'ewheel-importer'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-name"><?php esc_html_e('Profile Name', 'ewheel-importer'); ?> <span class="required">*</span></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ewheel-profile-name" name="name" class="regular-text" required>
                                        <p class="description"><?php esc_html_e('A descriptive name for this import profile.', 'ewheel-importer'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-active"><?php esc_html_e('Status', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" id="ewheel-profile-active" name="is_active" value="1" checked>
                                            <?php esc_html_e('Active', 'ewheel-importer'); ?>
                                        </label>
                                        <p class="description"><?php esc_html_e('Inactive profiles cannot be synced.', 'ewheel-importer'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <!-- API Filters -->
                            <h4><?php esc_html_e('API Filters', 'ewheel-importer'); ?></h4>
                            <p class="description"><?php esc_html_e('Filter which products to import from Ewheel.es.', 'ewheel-importer'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-filter-category"><?php esc_html_e('Category', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <select id="ewheel-profile-filter-category" name="filters[category]" class="regular-text">
                                            <option value=""><?php esc_html_e('-- All Categories --', 'ewheel-importer'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Filter by specific Ewheel category reference.', 'ewheel-importer'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php esc_html_e('Product Filters', 'ewheel-importer'); ?></th>
                                    <td>
                                        <label style="display: block; margin-bottom: 8px;">
                                            <input type="checkbox" name="filters[active]" value="1">
                                            <?php esc_html_e('Only active products', 'ewheel-importer'); ?>
                                        </label>
                                        <label style="display: block; margin-bottom: 8px;">
                                            <input type="checkbox" name="filters[hasImages]" value="1">
                                            <?php esc_html_e('Only products with images', 'ewheel-importer'); ?>
                                        </label>
                                        <label style="display: block;">
                                            <input type="checkbox" name="filters[hasVariants]" value="1">
                                            <?php esc_html_e('Only products with variants', 'ewheel-importer'); ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-filter-sku"><?php esc_html_e('Product Reference (SKU)', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="text" id="ewheel-profile-filter-sku" name="filters[productReference]" class="regular-text" placeholder="e.g., EW-">
                                        <p class="description"><?php esc_html_e('Filter by partial SKU match.', 'ewheel-importer'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <!-- Pricing Settings -->
                            <h4><?php esc_html_e('Pricing Settings', 'ewheel-importer'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Exchange Rate', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <label style="display: block; margin-bottom: 8px;">
                                            <input type="radio" name="settings[use_global_exchange_rate]" value="1" checked>
                                            <?php esc_html_e('Use global setting', 'ewheel-importer'); ?>
                                            <span class="description">(<?php echo esc_html($settings['exchange_rate']); ?>)</span>
                                        </label>
                                        <label style="display: flex; align-items: center;">
                                            <input type="radio" name="settings[use_global_exchange_rate]" value="0">
                                            <?php esc_html_e('Override:', 'ewheel-importer'); ?>
                                            <input type="number" step="0.0001" min="0" name="settings[exchange_rate]" class="small-text" style="margin-left: 10px;" placeholder="<?php echo esc_attr($settings['exchange_rate']); ?>">
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label><?php esc_html_e('Markup Percentage', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <label style="display: block; margin-bottom: 8px;">
                                            <input type="radio" name="settings[use_global_markup]" value="1" checked>
                                            <?php esc_html_e('Use global setting', 'ewheel-importer'); ?>
                                            <span class="description">(<?php echo esc_html($settings['markup_percent']); ?>%)</span>
                                        </label>
                                        <label style="display: flex; align-items: center;">
                                            <input type="radio" name="settings[use_global_markup]" value="0">
                                            <?php esc_html_e('Override:', 'ewheel-importer'); ?>
                                            <input type="number" step="0.1" min="0" name="settings[markup_percent]" class="small-text" style="margin-left: 10px;" placeholder="<?php echo esc_attr($settings['markup_percent']); ?>">
                                            <span style="margin-left: 5px;">%</span>
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <!-- Sync Settings -->
                            <h4><?php esc_html_e('Sync Settings', 'ewheel-importer'); ?></h4>
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-sync-frequency"><?php esc_html_e('Sync Frequency', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <select id="ewheel-profile-sync-frequency" name="settings[sync_frequency]">
                                            <option value="manual"><?php esc_html_e('Manual Only', 'ewheel-importer'); ?></option>
                                            <option value="daily"><?php esc_html_e('Daily', 'ewheel-importer'); ?></option>
                                            <option value="weekly"><?php esc_html_e('Weekly', 'ewheel-importer'); ?></option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="ewheel-profile-test-limit"><?php esc_html_e('Test Limit', 'ewheel-importer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="number" id="ewheel-profile-test-limit" name="settings[test_limit]" min="0" value="0" class="small-text">
                                        <p class="description"><?php esc_html_e('Limit products to sync for testing (0 = all products).', 'ewheel-importer'); ?></p>
                                    </td>
                                </tr>
                            </table>

                            <p class="submit">
                                <button type="submit" id="ewheel-save-profile" class="button button-primary">
                                    <?php esc_html_e('Save Profile', 'ewheel-importer'); ?>
                                </button>
                                <span id="ewheel-profile-save-status"></span>
                            </p>
                        </form>
                    </div>
                </div>
            </div>

            <div class="ewheel-importer-sidebar">
                <div class="ewheel-importer-box" id="ewheel-profile-sync-box">
                    <h3><?php esc_html_e('Profile Sync', 'ewheel-importer'); ?></h3>
                    <p class="description" id="ewheel-profile-sync-description">
                        <?php esc_html_e('Select a profile from the list to run sync.', 'ewheel-importer'); ?>
                    </p>

                    <div id="ewheel-profile-sync-controls" style="display: none;">
                        <p>
                            <strong id="ewheel-selected-profile-name"></strong>
                        </p>

                        <!-- Progress Display -->
                        <div id="ewheel-profile-sync-progress" style="display: none;">
                            <div class="ewheel-progress-container">
                                <div class="ewheel-progress-bar" style="width: 0%;">
                                    <span id="ewheel-profile-progress-text">0%</span>
                                </div>
                            </div>
                            <p id="ewheel-profile-sync-details"></p>
                        </div>

                        <p id="ewheel-profile-sync-buttons">
                            <!-- Run button: shown when idle -->
                            <button type="button" id="ewheel-run-profile-sync" class="button button-primary">
                                <?php esc_html_e('Run Sync', 'ewheel-importer'); ?>
                            </button>

                            <!-- Pause button: shown when running -->
                            <button type="button" id="ewheel-pause-profile-sync" class="button button-secondary" style="display: none;">
                                <?php esc_html_e('Pause', 'ewheel-importer'); ?>
                            </button>

                            <!-- Resume button: shown when paused -->
                            <button type="button" id="ewheel-resume-profile-sync" class="button button-primary" style="display: none;">
                                <?php esc_html_e('Resume', 'ewheel-importer'); ?>
                            </button>

                            <!-- Cancel button: shown when running or paused -->
                            <button type="button" id="ewheel-cancel-profile-sync" class="button button-link-delete" style="display: none;">
                                <?php esc_html_e('Cancel', 'ewheel-importer'); ?>
                            </button>
                        </p>
                        <p id="ewheel-profile-last-sync"></p>
                    </div>
                </div>

                <div class="ewheel-importer-box">
                    <h3><?php esc_html_e('Profile Tips', 'ewheel-importer'); ?></h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li><?php esc_html_e('Create profiles for different product categories', 'ewheel-importer'); ?></li>
                        <li><?php esc_html_e('Use different markups for different product types', 'ewheel-importer'); ?></li>
                        <li><?php esc_html_e('Set up scheduled syncs for each profile', 'ewheel-importer'); ?></li>
                        <li><?php esc_html_e('The Default profile cannot be deleted', 'ewheel-importer'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Mapping Tab -->
    <div class="ewheel-tab-content" id="tab-categories">
        <div class="ewheel-importer-box" style="max-width: none;">
            <h3><?php esc_html_e('Category Mapping', 'ewheel-importer'); ?></h3>
            <p class="description">
                <?php esc_html_e('Map Ewheel.es categories to your WooCommerce product categories. Manual mappings take precedence over auto-created mappings.', 'ewheel-importer'); ?>
            </p>

            <p style="margin: 15px 0;">
                <button type="button" id="ewheel-sync-categories" class="button button-primary">
                    <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                    <?php esc_html_e('Fetch Categories from Ewheel.es', 'ewheel-importer'); ?>
                </button>
                <span id="ewheel-category-sync-status"></span>
            </p>

            <div id="ewheel-category-mapping-container">
                <div class="ewheel-empty-state">
                    <span class="dashicons dashicons-category"></span>
                    <p><?php esc_html_e('Loading category mappings...', 'ewheel-importer'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- History Tab -->
    <div class="ewheel-tab-content" id="tab-history">
        <div class="ewheel-importer-box" style="max-width: none;">
            <h3><?php esc_html_e('Sync History', 'ewheel-importer'); ?></h3>

            <!-- Stats Row -->
            <div class="ewheel-stats-row">
                <div class="ewheel-stat-card">
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['total_syncs'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Total Syncs', 'ewheel-importer'); ?></div>
                </div>
                <div class="ewheel-stat-card success">
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['successful_syncs'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Successful', 'ewheel-importer'); ?></div>
                </div>
                <div class="ewheel-stat-card error">
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['failed_syncs'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Failed', 'ewheel-importer'); ?></div>
                </div>
                <div class="ewheel-stat-card">
                    <div class="stat-value"><?php echo esc_html(number_format_i18n($sync_stats['total_products_processed'])); ?></div>
                    <div class="stat-label"><?php esc_html_e('Products Processed', 'ewheel-importer'); ?></div>
                </div>
            </div>

            <?php if (empty($recent_history)): ?>
                <div class="ewheel-empty-state">
                    <span class="dashicons dashicons-update"></span>
                    <p><?php esc_html_e('No sync history yet. Run your first sync to see results here.', 'ewheel-importer'); ?></p>
                </div>
            <?php else: ?>
                <table class="ewheel-history-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Type', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Status', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Processed', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Created', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Updated', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Failed', 'ewheel-importer'); ?></th>
                            <th><?php esc_html_e('Duration', 'ewheel-importer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_history as $record): ?>
                            <tr>
                                <td>
                                    <?php
                                    echo esc_html(wp_date(
                                        get_option('date_format') . ' ' . get_option('time_format'),
                                        strtotime($record['started_at'])
                                    ));
                                    ?>
                                </td>
                                <td><?php echo esc_html(ucfirst($record['sync_type'])); ?></td>
                                <td>
                                    <span class="ewheel-status-badge <?php echo esc_attr($record['status']); ?>">
                                        <?php echo esc_html(ucfirst($record['status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($record['products_processed'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($record['products_created'])); ?></td>
                                <td><?php echo esc_html(number_format_i18n($record['products_updated'])); ?></td>
                                <td>
                                    <?php if ($record['products_failed'] > 0): ?>
                                        <span style="color: #721c24;"><?php echo esc_html(number_format_i18n($record['products_failed'])); ?></span>
                                    <?php else: ?>
                                        <?php echo esc_html(number_format_i18n($record['products_failed'])); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($record['duration_seconds'])) {
                                        echo esc_html(\Trotibike\EwheelImporter\Sync\SyncHistoryManager::format_duration(
                                            (int) $record['duration_seconds']
                                        ));
                                    } else {
                                        echo '&mdash;';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p style="margin-top: 15px;">
                <button type="button" id="ewheel-refresh-history" class="button">
                    <?php esc_html_e('Refresh History', 'ewheel-importer'); ?>
                </button>
            </p>
        </div>
    </div>

    <!-- Logs Tab -->
    <div class="ewheel-tab-content" id="tab-logs">
        <div class="ewheel-importer-box" style="max-width: none;">
            <h3><?php esc_html_e('Error Logs', 'ewheel-importer'); ?></h3>
            <p class="description">
                <?php esc_html_e('Persistent error logs from sync operations. These logs are stored in the database and persist across sessions.', 'ewheel-importer'); ?>
            </p>

            <!-- Filters -->
            <div class="ewheel-log-filters">
                <select id="ewheel-log-level-filter">
                    <option value=""><?php esc_html_e('All Levels', 'ewheel-importer'); ?></option>
                    <option value="error"><?php esc_html_e('Errors Only', 'ewheel-importer'); ?></option>
                    <option value="warning"><?php esc_html_e('Warnings', 'ewheel-importer'); ?></option>
                    <option value="info"><?php esc_html_e('Info', 'ewheel-importer'); ?></option>
                    <option value="success"><?php esc_html_e('Success', 'ewheel-importer'); ?></option>
                </select>
                <input type="text" id="ewheel-log-sku-filter" placeholder="<?php esc_attr_e('Filter by SKU...', 'ewheel-importer'); ?>">
                <button type="button" id="ewheel-filter-logs" class="button"><?php esc_html_e('Filter', 'ewheel-importer'); ?></button>
                <button type="button" id="ewheel-clear-persistent-logs" class="button" style="margin-left: auto;">
                    <?php esc_html_e('Clear All Logs', 'ewheel-importer'); ?>
                </button>
            </div>

            <div id="ewheel-persistent-logs-container">
                <div class="ewheel-empty-state">
                    <span class="dashicons dashicons-list-view"></span>
                    <p><?php esc_html_e('Loading logs...', 'ewheel-importer'); ?></p>
                </div>
            </div>

            <div class="ewheel-pagination" id="ewheel-logs-pagination" style="display: none;">
                <span class="ewheel-pagination-info" id="ewheel-logs-info"></span>
                <div class="ewheel-pagination-buttons">
                    <button type="button" id="ewheel-logs-prev" class="button" disabled>&laquo; <?php esc_html_e('Previous', 'ewheel-importer'); ?></button>
                    <button type="button" id="ewheel-logs-next" class="button"><?php esc_html_e('Next', 'ewheel-importer'); ?> &raquo;</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.ewheel-tab').on('click', function() {
        var tab = $(this).data('tab');
        $('.ewheel-tab').removeClass('active');
        $(this).addClass('active');
        $('.ewheel-tab-content').removeClass('active');
        $('#tab-' + tab).addClass('active');

        // Load logs when switching to logs tab
        if (tab === 'logs') {
            loadPersistentLogs();
        }

        // Load category mappings when switching to categories tab
        if (tab === 'categories') {
            loadCategoryMappings();
        }
    });

    // Custom pattern toggle
    $('.ewheel-sync-field-select').on('change', function() {
        var key = $(this).data('key');
        var val = $(this).val();
        if (val === 'custom') {
            $('#ewheel_custom_pattern_' + key).slideDown();
        } else {
            $('#ewheel_custom_pattern_' + key).slideUp();
        }
    });

    // Translation driver toggle
    function toggleTranslationFields() {
        var driver = $('#ewheel_importer_translation_driver').val();
        $('.translation-row').hide();
        $('.translation-' + driver).show();
    }
    $('#ewheel_importer_translation_driver').on('change', toggleTranslationFields);
    toggleTranslationFields();

    // Export settings
    $('#ewheel-export-settings').on('click', function() {
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_export_settings',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    var blob = new Blob([JSON.stringify(response.data, null, 2)], {type: 'application/json'});
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'ewheel-importer-settings.json';
                    a.click();
                    URL.revokeObjectURL(url);
                }
            }
        });
    });

    // Import settings
    $('#ewheel-import-settings').on('click', function() {
        $('#ewheel-import-file').click();
    });

    $('#ewheel-import-file').on('change', function(e) {
        var file = e.target.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e) {
                $.ajax({
                    url: ewheelImporter.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ewheel_import_settings',
                        nonce: ewheelImporter.nonce,
                        settings: e.target.result
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            };
            reader.readAsText(file);
        }
    });

    // Refresh history
    $('#ewheel-refresh-history').on('click', function() {
        location.reload();
    });

    // Persistent logs
    var logsOffset = 0;
    var logsLimit = 50;

    function loadPersistentLogs() {
        var level = $('#ewheel-log-level-filter').val();
        var sku = $('#ewheel-log-sku-filter').val();

        $('#ewheel-persistent-logs-container').html('<div class="ewheel-empty-state"><span class="ewheel-loading"></span> <?php esc_html_e('Loading logs...', 'ewheel-importer'); ?></div>');

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_persistent_logs',
                nonce: ewheelImporter.nonce,
                level: level,
                sku: sku,
                limit: logsLimit,
                offset: logsOffset
            },
            success: function(response) {
                if (response.success) {
                    renderLogs(response.data.logs, response.data.total);
                }
            }
        });
    }

    function renderLogs(logs, total) {
        if (logs.length === 0) {
            $('#ewheel-persistent-logs-container').html(
                '<div class="ewheel-empty-state"><span class="dashicons dashicons-yes-alt"></span><p><?php esc_html_e('No logs found.', 'ewheel-importer'); ?></p></div>'
            );
            $('#ewheel-logs-pagination').hide();
            return;
        }

        var html = '<table class="ewheel-log-table"><thead><tr>' +
            '<th><?php esc_html_e('Time', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Level', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Message', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('SKU', 'ewheel-importer'); ?></th>' +
            '</tr></thead><tbody>';

        logs.forEach(function(log) {
            html += '<tr class="level-' + log.level + '">' +
                '<td>' + log.created_at + '</td>' +
                '<td><span class="ewheel-status-badge ' + log.level + '">' + log.level.toUpperCase() + '</span></td>' +
                '<td>' + log.message + '</td>' +
                '<td>' + (log.product_sku || '-') + '</td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $('#ewheel-persistent-logs-container').html(html);

        // Pagination
        var start = logsOffset + 1;
        var end = Math.min(logsOffset + logsLimit, total);
        $('#ewheel-logs-info').text('<?php esc_html_e('Showing', 'ewheel-importer'); ?> ' + start + '-' + end + ' <?php esc_html_e('of', 'ewheel-importer'); ?> ' + total);
        $('#ewheel-logs-prev').prop('disabled', logsOffset === 0);
        $('#ewheel-logs-next').prop('disabled', logsOffset + logsLimit >= total);
        $('#ewheel-logs-pagination').show();
    }

    $('#ewheel-filter-logs').on('click', function() {
        logsOffset = 0;
        loadPersistentLogs();
    });

    $('#ewheel-logs-prev').on('click', function() {
        logsOffset = Math.max(0, logsOffset - logsLimit);
        loadPersistentLogs();
    });

    $('#ewheel-logs-next').on('click', function() {
        logsOffset += logsLimit;
        loadPersistentLogs();
    });

    $('#ewheel-clear-persistent-logs').on('click', function() {
        if (confirm('<?php esc_html_e('Are you sure you want to clear all logs? This cannot be undone.', 'ewheel-importer'); ?>')) {
            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_clear_logs',
                    nonce: ewheelImporter.nonce,
                    type: 'persistent'
                },
                success: function(response) {
                    if (response.success) {
                        loadPersistentLogs();
                    }
                }
            });
        }
    });

    // Category Mapping
    var wooCategories = [];
    var categoryMappingsLoaded = false;

    function loadCategoryMappings() {
        if (categoryMappingsLoaded) {
            return;
        }

        $('#ewheel-category-mapping-container').html(
            '<div class="ewheel-empty-state"><span class="ewheel-loading"></span> <?php esc_html_e('Loading category mappings...', 'ewheel-importer'); ?></div>'
        );

        // First load WooCommerce categories, then load mappings
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_woo_categories',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    wooCategories = response.data.categories || [];
                    loadMappingsTable();
                }
            }
        });
    }

    function loadMappingsTable() {
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_category_mappings',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderCategoryMappings(response.data);
                    categoryMappingsLoaded = true;
                }
            }
        });
    }

    function renderCategoryMappings(data) {
        var ewheelCategories = data.ewheel_categories || [];
        var currentMappings = data.mappings || {};

        if (ewheelCategories.length === 0) {
            $('#ewheel-category-mapping-container').html(
                '<div class="ewheel-empty-state">' +
                '<span class="dashicons dashicons-category"></span>' +
                '<p><?php esc_html_e('No Ewheel categories found. Click "Fetch Categories from Ewheel.es" to load them.', 'ewheel-importer'); ?></p>' +
                '</div>'
            );
            return;
        }

        var html = '<table class="ewheel-category-map-table">' +
            '<thead><tr>' +
            '<th><?php esc_html_e('Ewheel Category', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Reference', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('WooCommerce Category', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Status', 'ewheel-importer'); ?></th>' +
            '</tr></thead><tbody>';

        ewheelCategories.forEach(function(cat) {
            var ewheelRef = cat.reference || cat.Reference || '';
            // Handle name that might be string or object with translations
            var rawName = cat.name || cat.Name || ewheelRef;
            var ewheelName = (typeof rawName === 'object') ? (rawName.es || rawName.en || rawName.ro || Object.values(rawName)[0] || ewheelRef) : rawName;
            var mappedWooId = currentMappings[ewheelRef] || '';
            var isManual = data.manual_mappings && data.manual_mappings[ewheelRef];

            html += '<tr data-ewheel-ref="' + escapeHtml(ewheelRef) + '">' +
                '<td>' + escapeHtml(ewheelName) + '</td>' +
                '<td><code>' + escapeHtml(ewheelRef) + '</code></td>' +
                '<td>' +
                '<select class="ewheel-category-select" data-ewheel-ref="' + escapeHtml(ewheelRef) + '">' +
                '<option value=""><?php esc_html_e('-- Not Mapped --', 'ewheel-importer'); ?></option>';

            wooCategories.forEach(function(wooCat) {
                var indent = '';
                if (wooCat.parent > 0) {
                    indent = '&mdash; ';
                }
                var selected = (mappedWooId == wooCat.id) ? ' selected' : '';
                html += '<option value="' + wooCat.id + '"' + selected + '>' + indent + escapeHtml(wooCat.name) + '</option>';
            });

            html += '</select></td>' +
                '<td class="mapping-status">' +
                (mappedWooId ? (isManual ? '<span class="ewheel-status-badge completed"><?php esc_html_e('Manual', 'ewheel-importer'); ?></span>' : '<span class="ewheel-status-badge running"><?php esc_html_e('Auto', 'ewheel-importer'); ?></span>') : '<span class="ewheel-status-badge stopped"><?php esc_html_e('Unmapped', 'ewheel-importer'); ?></span>') +
                '</td></tr>';
        });

        html += '</tbody></table>';
        $('#ewheel-category-mapping-container').html(html);

        // Bind change events
        $('.ewheel-category-select').on('change', function() {
            var $select = $(this);
            var ewheelRef = $select.data('ewheel-ref');
            var wooId = $select.val();

            saveCategoryMapping(ewheelRef, wooId, $select.closest('tr').find('.mapping-status'));
        });
    }

    function saveCategoryMapping(ewheelRef, wooId, $statusCell) {
        $statusCell.html('<span class="ewheel-loading"></span>');

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_save_category_mapping',
                nonce: ewheelImporter.nonce,
                ewheel_ref: ewheelRef,
                woo_id: wooId
            },
            success: function(response) {
                if (response.success) {
                    if (wooId) {
                        $statusCell.html('<span class="ewheel-status-badge completed"><?php esc_html_e('Manual', 'ewheel-importer'); ?></span>');
                    } else {
                        $statusCell.html('<span class="ewheel-status-badge stopped"><?php esc_html_e('Unmapped', 'ewheel-importer'); ?></span>');
                    }
                } else {
                    $statusCell.html('<span class="ewheel-status-badge failed"><?php esc_html_e('Error', 'ewheel-importer'); ?></span>');
                }
            },
            error: function() {
                $statusCell.html('<span class="ewheel-status-badge failed"><?php esc_html_e('Error', 'ewheel-importer'); ?></span>');
            }
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Sync categories from Ewheel.es
    $('#ewheel-sync-categories').on('click', function() {
        var $btn = $(this);
        var $status = $('#ewheel-category-sync-status');

        $btn.prop('disabled', true);
        $status.html('<span class="ewheel-loading"></span> <?php esc_html_e('Fetching categories...', 'ewheel-importer'); ?>');

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_sync_categories',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.html('<span style="color: #155724;">' + response.data.message + '</span>');
                    // Reload the mappings table
                    categoryMappingsLoaded = false;
                    loadCategoryMappings();
                } else {
                    $status.html('<span style="color: #721c24;"><?php esc_html_e('Error:', 'ewheel-importer'); ?> ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color: #721c24;"><?php esc_html_e('Request failed', 'ewheel-importer'); ?></span>');
            }
        });
    });

    // ========================================
    // Profile Management
    // ========================================
    var profilesLoaded = false;
    var currentProfileId = null;
    var ewheelCategoriesForProfiles = [];

    // Load profiles when switching to profiles tab
    $('.ewheel-tab[data-tab="profiles"]').on('click', function() {
        if (!profilesLoaded) {
            loadProfiles();
            loadEwheelCategoriesForProfiles();
        }
    });

    function loadProfiles() {
        $('#ewheel-profiles-container').html(
            '<div class="ewheel-empty-state"><span class="ewheel-loading"></span> <?php esc_html_e('Loading profiles...', 'ewheel-importer'); ?></div>'
        );

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_profiles',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderProfiles(response.data.profiles);
                    profilesLoaded = true;
                } else {
                    $('#ewheel-profiles-container').html(
                        '<div class="ewheel-empty-state"><span class="dashicons dashicons-warning"></span><p>' + (response.data.message || '<?php esc_html_e('Error loading profiles', 'ewheel-importer'); ?>') + '</p></div>'
                    );
                }
            },
            error: function() {
                $('#ewheel-profiles-container').html(
                    '<div class="ewheel-empty-state"><span class="dashicons dashicons-warning"></span><p><?php esc_html_e('Error loading profiles', 'ewheel-importer'); ?></p></div>'
                );
            }
        });
    }

    function loadEwheelCategoriesForProfiles() {
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_category_mappings',
                nonce: ewheelImporter.nonce
            },
            success: function(response) {
                if (response.success && response.data.ewheel_categories) {
                    ewheelCategoriesForProfiles = response.data.ewheel_categories;
                    populateCategoryDropdown();
                }
            }
        });
    }

    function populateCategoryDropdown() {
        var $select = $('#ewheel-profile-filter-category');
        $select.find('option:not(:first)').remove();

        ewheelCategoriesForProfiles.forEach(function(cat) {
            var ref = cat.reference || cat.Reference || '';
            var rawName = cat.name || cat.Name || ref;
            var name = (typeof rawName === 'object') ? (rawName.es || rawName.en || rawName.ro || Object.values(rawName)[0] || ref) : rawName;
            $select.append('<option value="' + escapeHtml(ref) + '">' + escapeHtml(name) + ' (' + escapeHtml(ref) + ')</option>');
        });
    }

    function renderProfiles(profiles) {
        if (!profiles || profiles.length === 0) {
            $('#ewheel-profiles-container').html(
                '<div class="ewheel-empty-state"><span class="dashicons dashicons-admin-users"></span><p><?php esc_html_e('No profiles found. Click "Add New Profile" to create one.', 'ewheel-importer'); ?></p></div>'
            );
            return;
        }

        var html = '<table class="ewheel-profiles-table widefat striped">' +
            '<thead><tr>' +
            '<th><?php esc_html_e('Name', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Status', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Filters', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Last Sync', 'ewheel-importer'); ?></th>' +
            '<th><?php esc_html_e('Actions', 'ewheel-importer'); ?></th>' +
            '</tr></thead><tbody>';

        profiles.forEach(function(profile) {
            var statusClass = profile.is_active ? 'running' : 'stopped';
            var statusText = profile.is_active ? '<?php esc_html_e('Active', 'ewheel-importer'); ?>' : '<?php esc_html_e('Inactive', 'ewheel-importer'); ?>';
            var isDefault = profile.slug === 'default';

            // Build filters summary
            var filtersSummary = [];
            if (profile.filters && profile.filters.category) {
                filtersSummary.push('<?php esc_html_e('Category:', 'ewheel-importer'); ?> ' + profile.filters.category);
            }
            if (profile.filters && profile.filters.active) {
                filtersSummary.push('<?php esc_html_e('Active only', 'ewheel-importer'); ?>');
            }
            if (profile.filters && profile.filters.hasImages) {
                filtersSummary.push('<?php esc_html_e('With images', 'ewheel-importer'); ?>');
            }
            if (filtersSummary.length === 0) {
                filtersSummary.push('<?php esc_html_e('All products', 'ewheel-importer'); ?>');
            }

            html += '<tr data-profile-id="' + profile.id + '">' +
                '<td><strong>' + escapeHtml(profile.name) + '</strong>' + (isDefault ? ' <span class="ewheel-status-badge completed"><?php esc_html_e('Default', 'ewheel-importer'); ?></span>' : '') + '</td>' +
                '<td><span class="ewheel-status-badge ' + statusClass + '">' + statusText + '</span></td>' +
                '<td><small>' + filtersSummary.join(', ') + '</small></td>' +
                '<td>' + (profile.last_sync ? profile.last_sync : '<?php esc_html_e('Never', 'ewheel-importer'); ?>') + '</td>' +
                '<td>' +
                '<button type="button" class="button ewheel-edit-profile" data-id="' + profile.id + '"><?php esc_html_e('Edit', 'ewheel-importer'); ?></button> ' +
                '<button type="button" class="button ewheel-sync-profile" data-id="' + profile.id + '" data-name="' + escapeHtml(profile.name) + '"' + (!profile.is_active ? ' disabled' : '') + '><?php esc_html_e('Sync', 'ewheel-importer'); ?></button> ' +
                (isDefault ? '' : '<button type="button" class="button ewheel-delete-profile" data-id="' + profile.id + '" data-name="' + escapeHtml(profile.name) + '"><?php esc_html_e('Delete', 'ewheel-importer'); ?></button>') +
                '</td></tr>';
        });

        html += '</tbody></table>';
        $('#ewheel-profiles-container').html(html);

        // Bind events
        bindProfileEvents();
    }

    function bindProfileEvents() {
        // Edit profile
        $('.ewheel-edit-profile').off('click').on('click', function() {
            var profileId = $(this).data('id');
            loadProfileForEdit(profileId);
        });

        // Sync profile
        $('.ewheel-sync-profile').off('click').on('click', function() {
            var profileId = $(this).data('id');
            var profileName = $(this).data('name');
            selectProfileForSync(profileId, profileName);
        });

        // Delete profile
        $('.ewheel-delete-profile').off('click').on('click', function() {
            var profileId = $(this).data('id');
            var profileName = $(this).data('name');
            deleteProfile(profileId, profileName);
        });
    }

    function loadProfileForEdit(profileId) {
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_profile',
                nonce: ewheelImporter.nonce,
                profile_id: profileId
            },
            success: function(response) {
                if (response.success) {
                    populateProfileForm(response.data.profile);
                    showProfileEditView(profileId ? '<?php esc_html_e('Edit Profile', 'ewheel-importer'); ?>' : '<?php esc_html_e('Add New Profile', 'ewheel-importer'); ?>');
                }
            }
        });
    }

    function populateProfileForm(profile) {
        var $form = $('#ewheel-profile-form');

        // Reset form
        $form[0].reset();

        // Basic info
        $('#ewheel-profile-id').val(profile.id || '');
        $('#ewheel-profile-name').val(profile.name || '');
        $('#ewheel-profile-active').prop('checked', profile.is_active !== false);

        // Filters
        if (profile.filters) {
            $('#ewheel-profile-filter-category').val(profile.filters.category || '');
            $form.find('input[name="filters[active]"]').prop('checked', !!profile.filters.active);
            $form.find('input[name="filters[hasImages]"]').prop('checked', !!profile.filters.hasImages);
            $form.find('input[name="filters[hasVariants]"]').prop('checked', !!profile.filters.hasVariants);
            $('#ewheel-profile-filter-sku').val(profile.filters.productReference || '');
        }

        // Settings
        if (profile.settings) {
            // Exchange rate
            if (profile.settings.exchange_rate !== null && profile.settings.exchange_rate !== undefined) {
                $form.find('input[name="settings[use_global_exchange_rate]"][value="0"]').prop('checked', true);
                $form.find('input[name="settings[exchange_rate]"]').val(profile.settings.exchange_rate);
            } else {
                $form.find('input[name="settings[use_global_exchange_rate]"][value="1"]').prop('checked', true);
            }

            // Markup
            if (profile.settings.markup_percent !== null && profile.settings.markup_percent !== undefined) {
                $form.find('input[name="settings[use_global_markup]"][value="0"]').prop('checked', true);
                $form.find('input[name="settings[markup_percent]"]').val(profile.settings.markup_percent);
            } else {
                $form.find('input[name="settings[use_global_markup]"][value="1"]').prop('checked', true);
            }

            // Sync frequency
            $('#ewheel-profile-sync-frequency').val(profile.settings.sync_frequency || 'manual');

            // Test limit
            $('#ewheel-profile-test-limit').val(profile.settings.test_limit || 0);
        }
    }

    function showProfileEditView(title) {
        $('#ewheel-profile-form-title').text(title);
        $('#ewheel-profile-list-view').hide();
        $('#ewheel-profile-edit-view').show();
    }

    function showProfileListView() {
        $('#ewheel-profile-edit-view').hide();
        $('#ewheel-profile-list-view').show();
        loadProfiles();
    }

    // Add new profile button
    $('#ewheel-add-profile').on('click', function() {
        $('#ewheel-profile-form')[0].reset();
        $('#ewheel-profile-id').val('');
        populateCategoryDropdown();
        showProfileEditView('<?php esc_html_e('Add New Profile', 'ewheel-importer'); ?>');
    });

    // Cancel edit
    $('#ewheel-cancel-profile').on('click', function() {
        showProfileListView();
    });

    // Save profile
    $('#ewheel-profile-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $btn = $('#ewheel-save-profile');
        var $status = $('#ewheel-profile-save-status');

        // Build data object
        var data = {
            action: 'ewheel_save_profile',
            nonce: ewheelImporter.nonce,
            profile_id: $('#ewheel-profile-id').val(),
            name: $('#ewheel-profile-name').val(),
            is_active: $('#ewheel-profile-active').is(':checked') ? 1 : 0,
            filters: {
                category: $('#ewheel-profile-filter-category').val(),
                active: $form.find('input[name="filters[active]"]').is(':checked') ? 1 : 0,
                hasImages: $form.find('input[name="filters[hasImages]"]').is(':checked') ? 1 : 0,
                hasVariants: $form.find('input[name="filters[hasVariants]"]').is(':checked') ? 1 : 0,
                productReference: $('#ewheel-profile-filter-sku').val()
            },
            settings: {
                sync_frequency: $('#ewheel-profile-sync-frequency').val(),
                test_limit: $('#ewheel-profile-test-limit').val()
            }
        };

        // Handle pricing settings
        if ($form.find('input[name="settings[use_global_exchange_rate]"]:checked').val() === '0') {
            data.settings.exchange_rate = $form.find('input[name="settings[exchange_rate]"]').val();
        }
        if ($form.find('input[name="settings[use_global_markup]"]:checked').val() === '0') {
            data.settings.markup_percent = $form.find('input[name="settings[markup_percent]"]').val();
        }

        $btn.prop('disabled', true);
        $status.html('<span class="ewheel-loading"></span>');

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: data,
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $status.html('<span style="color: #155724;"><?php esc_html_e('Profile saved!', 'ewheel-importer'); ?></span>');
                    setTimeout(function() {
                        showProfileListView();
                    }, 1000);
                } else {
                    $status.html('<span style="color: #721c24;">' + (response.data.message || '<?php esc_html_e('Error saving profile', 'ewheel-importer'); ?>') + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color: #721c24;"><?php esc_html_e('Request failed', 'ewheel-importer'); ?></span>');
            }
        });
    });

    // Delete profile
    function deleteProfile(profileId, profileName) {
        if (!confirm('<?php esc_html_e('Are you sure you want to delete the profile', 'ewheel-importer'); ?> "' + profileName + '"?')) {
            return;
        }

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_delete_profile',
                nonce: ewheelImporter.nonce,
                profile_id: profileId
            },
            success: function(response) {
                if (response.success) {
                    loadProfiles();
                } else {
                    alert(response.data.message || '<?php esc_html_e('Error deleting profile', 'ewheel-importer'); ?>');
                }
            }
        });
    }

    // Select profile for sync
    function selectProfileForSync(profileId, profileName) {
        currentProfileId = profileId;
        $('#ewheel-selected-profile-name').text(profileName);
        $('#ewheel-profile-sync-description').hide();
        $('#ewheel-profile-sync-controls').show();

        // Check sync status
        checkProfileSyncStatus(profileId);
    }

    function checkProfileSyncStatus(profileId) {
        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_get_sync_status',
                nonce: ewheelImporter.nonce,
                profile_id: profileId
            },
            success: function(response) {
                if (response.success && response.data.status === 'running') {
                    $('#ewheel-run-profile-sync').hide();
                    $('#ewheel-stop-profile-sync').show();
                    $('#ewheel-profile-sync-progress').show();
                    updateProfileSyncProgress(response.data);
                } else {
                    $('#ewheel-run-profile-sync').show();
                    $('#ewheel-stop-profile-sync').hide();
                    $('#ewheel-profile-sync-progress').hide();
                }

                if (response.data && response.data.last_sync) {
                    $('#ewheel-profile-last-sync').html('<small><?php esc_html_e('Last sync:', 'ewheel-importer'); ?> ' + response.data.last_sync + '</small>');
                }
            }
        });
    }

    function updateProfileSyncProgress(data) {
        var percent = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
        $('#ewheel-profile-sync-progress .ewheel-progress-bar').css('width', percent + '%');
        $('#ewheel-profile-progress-text').text(percent + '%');
        $('#ewheel-profile-sync-details').text('<?php esc_html_e('Processing:', 'ewheel-importer'); ?> ' + data.processed + ' / ' + data.total);
    }

    // Run profile sync
    $('#ewheel-run-profile-sync').on('click', function() {
        if (!currentProfileId) return;

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_run_sync',
                nonce: ewheelImporter.nonce,
                profile_id: currentProfileId
            },
            success: function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    $('#ewheel-run-profile-sync').hide();
                    $('#ewheel-stop-profile-sync').show();
                    $('#ewheel-profile-sync-progress').show();

                    // Start polling for status
                    profileSyncPoller = setInterval(function() {
                        checkProfileSyncStatus(currentProfileId);
                    }, 2000);
                } else {
                    alert(response.data.message || '<?php esc_html_e('Error starting sync', 'ewheel-importer'); ?>');
                }
            }
        });
    });

    var profileSyncPoller = null;

    // Stop profile sync
    $('#ewheel-stop-profile-sync').on('click', function() {
        if (!currentProfileId) return;

        $.ajax({
            url: ewheelImporter.ajaxUrl,
            type: 'POST',
            data: {
                action: 'ewheel_stop_sync',
                nonce: ewheelImporter.nonce,
                profile_id: currentProfileId
            },
            success: function(response) {
                if (profileSyncPoller) {
                    clearInterval(profileSyncPoller);
                }
                $('#ewheel-run-profile-sync').show();
                $('#ewheel-stop-profile-sync').hide();
                loadProfiles();
            }
        });
    });
});
</script>
