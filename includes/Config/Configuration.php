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
class Configuration {

    /**
     * Option prefix.
     */
    private const PREFIX = 'ewheel_importer_';

    /**
     * Default configuration values.
     */
    private const DEFAULTS = [
        'api_key'          => '',
        'translate_api_key' => '',
        'exchange_rate'    => 4.97,
        'markup_percent'   => 20.0,
        'sync_frequency'   => 'daily',
        'target_language'  => 'ro',
        'last_sync'        => null,
    ];

    /**
     * Get a configuration value.
     *
     * @param string $key Configuration key.
     * @return mixed The configuration value.
     */
    public function get( string $key ) {
        $default = self::DEFAULTS[ $key ] ?? null;
        return get_option( self::PREFIX . $key, $default );
    }

    /**
     * Set a configuration value.
     *
     * @param string $key   Configuration key.
     * @param mixed  $value The value to set.
     * @return bool Success.
     */
    public function set( string $key, $value ): bool {
        return update_option( self::PREFIX . $key, $value );
    }

    /**
     * Get API key.
     *
     * @return string
     */
    public function get_api_key(): string {
        return (string) $this->get( 'api_key' );
    }

    /**
     * Get translate API key.
     *
     * @return string
     */
    public function get_translate_api_key(): string {
        return (string) $this->get( 'translate_api_key' );
    }

    /**
     * Get exchange rate.
     *
     * @return float
     */
    public function get_exchange_rate(): float {
        return (float) $this->get( 'exchange_rate' );
    }

    /**
     * Get markup percentage.
     *
     * @return float
     */
    public function get_markup_percent(): float {
        return (float) $this->get( 'markup_percent' );
    }

    /**
     * Get sync frequency.
     *
     * @return string
     */
    public function get_sync_frequency(): string {
        return (string) $this->get( 'sync_frequency' );
    }

    /**
     * Get target language.
     *
     * @return string
     */
    public function get_target_language(): string {
        return (string) $this->get( 'target_language' );
    }

    /**
     * Get last sync time.
     *
     * @return string|null
     */
    public function get_last_sync(): ?string {
        $value = $this->get( 'last_sync' );
        return $value ?: null;
    }

    /**
     * Update last sync time to now.
     *
     * @return bool
     */
    public function update_last_sync(): bool {
        return $this->set( 'last_sync', gmdate( 'Y-m-d\TH:i:s' ) );
    }

    /**
     * Get all settings for admin form.
     *
     * @return array
     */
    public function get_all(): array {
        $settings = [];
        foreach ( array_keys( self::DEFAULTS ) as $key ) {
            $settings[ $key ] = $this->get( $key );
        }
        return $settings;
    }
}
