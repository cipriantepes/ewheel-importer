<?php
/**
 * Pricing Converter class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Pricing;

/**
 * Handles price conversion between currencies with markup.
 */
class PricingConverter {

    /**
     * The exchange rate provider.
     *
     * @var ExchangeRateProviderInterface
     */
    private ExchangeRateProviderInterface $rate_provider;

    /**
     * The source currency code.
     *
     * @var string
     */
    private string $source_currency;

    /**
     * The target currency code.
     *
     * @var string
     */
    private string $target_currency;

    /**
     * The markup percentage.
     *
     * @var float
     */
    private float $markup_percentage;

    /**
     * Cached exchange rate.
     *
     * @var float|null
     */
    private ?float $cached_rate = null;

    /**
     * Constructor.
     *
     * @param ExchangeRateProviderInterface $rate_provider     The exchange rate provider.
     * @param string                        $source_currency   The source currency code (e.g., 'EUR').
     * @param string                        $target_currency   The target currency code (e.g., 'RON').
     * @param float                         $markup_percentage The markup percentage (e.g., 20 for 20%).
     */
    public function __construct(
        ExchangeRateProviderInterface $rate_provider,
        string $source_currency,
        string $target_currency,
        float $markup_percentage = 0
    ) {
        $this->rate_provider     = $rate_provider;
        $this->source_currency   = strtoupper( $source_currency );
        $this->target_currency   = strtoupper( $target_currency );
        $this->markup_percentage = $markup_percentage;
    }

    /**
     * Convert a price from source to target currency with markup.
     *
     * @param float $price The price in source currency.
     * @return float The converted price in target currency.
     * @throws \InvalidArgumentException If price is negative.
     */
    public function convert( float $price ): float {
        if ( $price < 0 ) {
            throw new \InvalidArgumentException( 'Price cannot be negative' );
        }

        if ( $price === 0.0 ) {
            return 0.0;
        }

        $rate = $this->get_cached_rate();

        // Convert currency
        $converted = $price * $rate;

        // Apply markup
        if ( $this->markup_percentage !== 0.0 ) {
            $markup_multiplier = 1 + ( $this->markup_percentage / 100 );
            $converted         = $converted * $markup_multiplier;
        }

        // Round to 2 decimal places
        return round( $converted, 2 );
    }

    /**
     * Convert multiple prices at once.
     *
     * @param array $prices Array of prices in source currency.
     * @return array Array of converted prices.
     */
    public function convert_batch( array $prices ): array {
        return array_map( [ $this, 'convert' ], $prices );
    }

    /**
     * Format a price for display.
     *
     * @param float $price The price to format.
     * @return string The formatted price.
     */
    public function format_price( float $price ): string {
        return number_format( $price, 2, '.', '' );
    }

    /**
     * Get the target currency.
     *
     * @return string The target currency code.
     */
    public function get_target_currency(): string {
        return $this->target_currency;
    }

    /**
     * Get the markup percentage.
     *
     * @return float The markup percentage.
     */
    public function get_markup_percentage(): float {
        return $this->markup_percentage;
    }

    /**
     * Get the cached exchange rate, fetching it if necessary.
     *
     * @return float The exchange rate.
     */
    private function get_cached_rate(): float {
        if ( $this->cached_rate === null ) {
            $this->cached_rate = $this->rate_provider->get_rate(
                $this->source_currency,
                $this->target_currency
            );
        }

        return $this->cached_rate;
    }

    /**
     * Clear the cached exchange rate.
     *
     * @return void
     */
    public function clear_rate_cache(): void {
        $this->cached_rate = null;
    }
}
