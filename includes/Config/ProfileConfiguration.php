<?php
/**
 * Profile Configuration class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Config;

use Trotibike\EwheelImporter\Model\Profile;

/**
 * Wraps a Profile and provides Configuration-like interface.
 *
 * Falls back to global Configuration settings when profile setting is null.
 */
class ProfileConfiguration
{

    /**
     * The profile.
     *
     * @var Profile
     */
    private Profile $profile;

    /**
     * Global configuration for fallback.
     *
     * @var Configuration
     */
    private Configuration $global_config;

    /**
     * Constructor.
     *
     * @param Profile       $profile       The profile.
     * @param Configuration $global_config Global configuration for fallback.
     */
    public function __construct(Profile $profile, Configuration $global_config)
    {
        $this->profile = $profile;
        $this->global_config = $global_config;
    }

    /**
     * Get the profile.
     *
     * @return Profile
     */
    public function get_profile(): Profile
    {
        return $this->profile;
    }

    /**
     * Get profile ID.
     *
     * @return int|null
     */
    public function get_profile_id(): ?int
    {
        return $this->profile->get_id();
    }

    /**
     * Get profile name.
     *
     * @return string
     */
    public function get_profile_name(): string
    {
        return $this->profile->get_name();
    }

    /**
     * Get profile slug.
     *
     * @return string
     */
    public function get_profile_slug(): string
    {
        return $this->profile->get_slug();
    }

    /**
     * Get API filters for the profile.
     *
     * @return array
     */
    public function get_filters(): array
    {
        return $this->profile->get_filters();
    }

    /**
     * Get API filters formatted for the ewheel API request.
     *
     * @return array
     */
    public function get_api_filters(): array
    {
        return $this->profile->get_api_filters();
    }

    /**
     * Get exchange rate (profile or global fallback).
     *
     * @return float
     */
    public function get_exchange_rate(): float
    {
        $profile_rate = $this->profile->get_setting('exchange_rate');
        if ($profile_rate !== null) {
            return (float) $profile_rate;
        }
        return $this->global_config->get_exchange_rate();
    }

    /**
     * Get markup percentage (profile or global fallback).
     *
     * @return float
     */
    public function get_markup_percent(): float
    {
        $profile_markup = $this->profile->get_setting('markup_percent');
        if ($profile_markup !== null) {
            return (float) $profile_markup;
        }
        return $this->global_config->get_markup_percent();
    }

    /**
     * Get sync fields configuration (profile or global fallback).
     *
     * @return array
     */
    public function get_sync_fields(): array
    {
        $profile_fields = $this->profile->get_setting('sync_fields');
        if ($profile_fields !== null && is_array($profile_fields)) {
            return $profile_fields;
        }
        return $this->global_config->get_sync_fields();
    }

    /**
     * Get sync protection settings (profile or global fallback).
     *
     * @return array
     */
    public function get_sync_protection(): array
    {
        $profile_protection = $this->profile->get_setting('sync_protection');
        if ($profile_protection !== null && is_array($profile_protection)) {
            return $profile_protection;
        }
        return $this->global_config->get('sync_protection') ?: [];
    }

    /**
     * Get custom patterns (profile or global fallback).
     *
     * @return array
     */
    public function get_custom_patterns(): array
    {
        $profile_patterns = $this->profile->get_setting('custom_patterns');
        if ($profile_patterns !== null && is_array($profile_patterns)) {
            return $profile_patterns;
        }
        return $this->global_config->get('custom_patterns') ?: [];
    }

    /**
     * Get variation mode (profile or global fallback).
     *
     * @return string
     */
    public function get_variation_mode(): string
    {
        $profile_mode = $this->profile->get_setting('variation_mode');
        if ($profile_mode !== null) {
            return in_array($profile_mode, [Configuration::VARIATION_MODE_VARIABLE, Configuration::VARIATION_MODE_SIMPLE], true)
                ? $profile_mode
                : Configuration::VARIATION_MODE_VARIABLE;
        }
        return $this->global_config->get_variation_mode();
    }

    /**
     * Check if using variable product mode.
     *
     * @return bool
     */
    public function is_variable_product_mode(): bool
    {
        return $this->get_variation_mode() === Configuration::VARIATION_MODE_VARIABLE;
    }

    /**
     * Get sync frequency for this profile.
     *
     * @return string 'manual', 'daily', or 'weekly'
     */
    public function get_sync_frequency(): string
    {
        $frequency = $this->profile->get_setting('sync_frequency');
        return in_array($frequency, ['manual', 'daily', 'weekly'], true) ? $frequency : 'manual';
    }

    /**
     * Get test limit (0 = unlimited).
     *
     * @return int
     */
    public function get_test_limit(): int
    {
        return (int) ($this->profile->get_setting('test_limit') ?: 0);
    }

    /**
     * Get last sync time for this profile.
     *
     * @return string|null
     */
    public function get_last_sync(): ?string
    {
        return $this->profile->get_last_sync();
    }

    /**
     * Get category mappings (profile overrides + global base).
     *
     * Profile-specific mappings override global mappings.
     *
     * @return array
     */
    public function get_category_mappings(): array
    {
        $global_mappings = get_option('ewheel_importer_category_mappings', []);
        $profile_mappings = $this->profile->get_category_mappings();

        // Profile mappings override global
        return array_merge($global_mappings, $profile_mappings);
    }

    /**
     * Check if a specific field should be synced.
     *
     * @param string $field Field name.
     * @return bool
     */
    public function should_sync_field(string $field): bool
    {
        $fields = $this->get_sync_fields();
        return !empty($fields[$field]);
    }

    /**
     * Check if a specific field is protected from updates.
     *
     * @param string $field Field name.
     * @return bool
     */
    public function is_field_protected(string $field): bool
    {
        $protection = $this->get_sync_protection();
        return !empty($protection[$field]);
    }

    /**
     * Get global API key (always from global config).
     *
     * @return string
     */
    public function get_api_key(): string
    {
        return $this->global_config->get_api_key();
    }

    /**
     * Get translation settings (always from global config).
     *
     * @return array
     */
    public function get_translation_settings(): array
    {
        return [
            'driver'           => $this->global_config->get_translation_driver(),
            'api_key'          => $this->global_config->get_translate_api_key(),
            'deepl_api_key'    => $this->global_config->get_deepl_api_key(),
            'openrouter_api_key' => $this->global_config->get_openrouter_api_key(),
            'openrouter_model' => $this->global_config->get_openrouter_model(),
            'target_language'  => $this->global_config->get_target_language(),
        ];
    }

    /**
     * Get target language (always from global config).
     *
     * @return string
     */
    public function get_target_language(): string
    {
        return $this->global_config->get_target_language();
    }

    /**
     * Check if profile uses global settings for a specific setting.
     *
     * @param string $setting Setting key.
     * @return bool
     */
    public function uses_global_setting(string $setting): bool
    {
        return $this->profile->get_setting($setting) === null;
    }

    /**
     * Get all profile settings for admin form display.
     *
     * @return array
     */
    public function get_all_for_display(): array
    {
        return [
            'profile'           => $this->profile->to_array(),
            'effective'         => [
                'exchange_rate'   => $this->get_exchange_rate(),
                'markup_percent'  => $this->get_markup_percent(),
                'sync_fields'     => $this->get_sync_fields(),
                'sync_protection' => $this->get_sync_protection(),
                'custom_patterns' => $this->get_custom_patterns(),
                'variation_mode'  => $this->get_variation_mode(),
                'sync_frequency'  => $this->get_sync_frequency(),
                'test_limit'      => $this->get_test_limit(),
            ],
            'uses_global'       => [
                'exchange_rate'   => $this->uses_global_setting('exchange_rate'),
                'markup_percent'  => $this->uses_global_setting('markup_percent'),
                'sync_fields'     => $this->uses_global_setting('sync_fields'),
                'sync_protection' => $this->uses_global_setting('sync_protection'),
                'custom_patterns' => $this->uses_global_setting('custom_patterns'),
                'variation_mode'  => $this->uses_global_setting('variation_mode'),
            ],
        ];
    }
}
