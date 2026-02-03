<?php
/**
 * Exchange Rate Provider Interface.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Pricing;

/**
 * Interface for exchange rate providers.
 */
interface ExchangeRateProviderInterface {

    /**
     * Get the exchange rate between two currencies.
     *
     * @param string $from_currency The source currency code (e.g., 'EUR').
     * @param string $to_currency   The target currency code (e.g., 'RON').
     * @return float The exchange rate.
     * @throws \RuntimeException If unable to get the rate.
     */
    public function get_rate( string $from_currency, string $to_currency ): float;
}
