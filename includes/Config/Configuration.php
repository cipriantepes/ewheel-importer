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
     * Variation mode: variable products.
     */
    public const VARIATION_MODE_VARIABLE = 'variable';

    /**
     * Variation mode: simple linked products.
     */
    public const VARIATION_MODE_SIMPLE = 'simple';

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
        'openrouter_model' => 'google/gemini-flash-1.5',
        'variation_mode' => self::VARIATION_MODE_VARIABLE, // 'variable' or 'simple'
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
     * @return string 'variable' or 'simple'
     */
    public function get_variation_mode(): string
    {
        $mode = (string) $this->get('variation_mode');
        return in_array($mode, [self::VARIATION_MODE_VARIABLE, self::VARIATION_MODE_SIMPLE], true)
            ? $mode
            : self::VARIATION_MODE_VARIABLE;
    }

    /**
     * Check if using variable product mode.
     *
     * @return bool
     */
    public function is_variable_product_mode(): bool
    {
        return $this->get_variation_mode() === self::VARIATION_MODE_VARIABLE;
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
}
