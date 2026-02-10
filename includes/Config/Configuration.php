<?php
/**
 * Configuration class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Config;

/**
 * Centralized configuration management.
 *
 * Single Responsibility: Only handles configuration retrieval.
 */
class Configuration
{

    /**
     * Option prefix.
     */
    private const PREFIX = 'ewheel_importer_';

    /**
     * Variation mode: auto-detect per product.
     */
    public const VARIATION_MODE_AUTO = 'auto';

    /**
     * Variation mode: variable products.
     */
    public const VARIATION_MODE_VARIABLE = 'variable';

    /**
     * Variation mode: simple linked products.
     */
    public const VARIATION_MODE_SIMPLE = 'simple';

    /**
     * OpenRouter models transient key.
     */
    private const OPENROUTER_MODELS_TRANSIENT = 'ewheel_openrouter_models';

    /**
     * OpenRouter models cache duration (24 hours).
     */
    private const OPENROUTER_MODELS_CACHE_DURATION = 24 * HOUR_IN_SECONDS;

    /**
     * Default configuration values.
     */
    private const DEFAULTS = [
        'api_key' => '',
        'translate_api_key' => '',
        'deepl_api_key' => '',
        'openrouter_api_key' => '',
        'translation_driver' => 'google', // 'google', 'deepl', 'openrouter'
        'exchange_rate' => 4.97,
        'markup_percent' => 20.0,
        'sync_frequency' => 'daily',
        'target_language' => 'ro',
        'last_sync' => null,
        'sync_fields' => [
            'name' => true,
            'description' => true,
            'short_description' => true,
            'price' => true,
            'images' => true,
            'categories' => true,
            'attributes' => true,
        ],
        'sync_protection' => [
            'name' => false,
            'description' => false,
            'short_description' => false,
            'price' => false,
            'images' => false,
            'categories' => false,
            'attributes' => false,
        ],
        'custom_patterns' => [
            'name' => '',
            'description' => '',
            'short_description' => '',
        ],
        'openrouter_model' => 'google/gemini-2.0-flash:free',
        'variation_mode' => self::VARIATION_MODE_AUTO, // 'auto', 'variable', or 'simple'
        'notify_on_sync' => true,
    ];

    /**
     * Get a configuration value.
     *
     * @param string $key Configuration key.
     * @return mixed The configuration value.
     */
    public function get(string $key)
    {
        $default = self::DEFAULTS[$key] ?? null;
        return get_option(self::PREFIX . $key, $default);
    }

    /**
     * Set a configuration value.
     *
     * @param string $key   Configuration key.
     * @param mixed  $value The value to set.
     * @return bool Success.
     */
    public function set(string $key, $value): bool
    {
        return update_option(self::PREFIX . $key, $value);
    }

    /**
     * Get API key.
     *
     * @return string
     */
    public function get_api_key(): string
    {
        return (string) $this->get('api_key');
    }

    /**
     * Get translate API key.
     *
     * @return string
     */
    public function get_translate_api_key(): string
    {
        return (string) $this->get('translate_api_key');
    }

    /**
     * Get DeepL API key.
     *
     * @return string
     */
    public function get_deepl_api_key(): string
    {
        return (string) $this->get('deepl_api_key');
    }

    /**
     * Get OpenRouter API key.
     *
     * @return string
     */
    public function get_openrouter_api_key(): string
    {
        return (string) $this->get('openrouter_api_key');
    }

    /**
     * Get OpenRouter model.
     *
     * @return string
     */
    public function get_openrouter_model(): string
    {
        return (string) $this->get('openrouter_model');
    }

    /**
     * Get translation driver (google/deepl/openrouter).
     *
     * @return string
     */
    public function get_translation_driver(): string
    {
        return (string) $this->get('translation_driver');
    }

    /**
     * Get exchange rate.
     *
     * @return float
     */
    public function get_exchange_rate(): float
    {
        return (float) $this->get('exchange_rate');
    }

    /**
     * Get markup percentage.
     *
     * @return float
     */
    public function get_markup_percent(): float
    {
        return (float) $this->get('markup_percent');
    }

    /**
     * Get sync frequency.
     *
     * @return string
     */
    public function get_sync_frequency(): string
    {
        return (string) $this->get('sync_frequency');
    }

    /**
     * Get target language.
     *
     * @return string
     */
    public function get_target_language(): string
    {
        return (string) $this->get('target_language');
    }

    /**
     * Get last sync time.
     *
     * @return string|null
     */
    public function get_last_sync(): ?string
    {
        $value = $this->get('last_sync');
        return $value ?: null;
    }

    /**
     * Update last sync time to now.
     *
     * @return bool
     */
    public function update_last_sync(): bool
    {
        return $this->set('last_sync', gmdate('Y-m-d\TH:i:s'));
    }

    /**
     * Get sync fields configuration.
     *
     * @return array
     */
    public function get_sync_fields(): array
    {
        $defaults = self::DEFAULTS['sync_fields'];
        $saved = $this->get('sync_fields');

        if (!is_array($saved)) {
            return $defaults;
        }

        return array_merge($defaults, $saved);
    }

    /**
     * Get variation mode.
     *
     * @return string 'auto', 'variable', or 'simple'
     */
    public function get_variation_mode(): string
    {
        $mode = (string) $this->get('variation_mode');
        return in_array($mode, [self::VARIATION_MODE_AUTO, self::VARIATION_MODE_VARIABLE, self::VARIATION_MODE_SIMPLE], true)
            ? $mode
            : self::VARIATION_MODE_AUTO;
    }

    /**
     * Check if a product should use variable product mode.
     *
     * In 'auto' mode, decides per-product based on whether variants exist.
     *
     * @param bool $has_variants Whether the product has variants.
     * @return bool
     */
    public function is_variable_product_mode(bool $has_variants = false): bool
    {
        $mode = $this->get_variation_mode();
        if ($mode === self::VARIATION_MODE_AUTO) {
            return $has_variants;
        }
        return $mode === self::VARIATION_MODE_VARIABLE;
    }

    /**
     * Get all settings for admin form.
     *
     * @return array
     */
    public function get_all(): array
    {
        $settings = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $settings[$key] = $this->get($key);
        }
        return $settings;
    }

    /**
     * Get cached OpenRouter models.
     *
     * @return array|false Cached models or false if not cached.
     */
    public static function get_cached_openrouter_models()
    {
        return get_transient(self::OPENROUTER_MODELS_TRANSIENT);
    }

    /**
     * Set cached OpenRouter models.
     *
     * @param array $models Models to cache.
     * @return bool Success.
     */
    public static function set_cached_openrouter_models(array $models): bool
    {
        return set_transient(
            self::OPENROUTER_MODELS_TRANSIENT,
            $models,
            self::OPENROUTER_MODELS_CACHE_DURATION
        );
    }

    /**
     * Clear cached OpenRouter models.
     *
     * @return bool Success.
     */
    public static function clear_cached_openrouter_models(): bool
    {
        return delete_transient(self::OPENROUTER_MODELS_TRANSIENT);
    }
}
