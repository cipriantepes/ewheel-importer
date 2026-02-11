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
     * Price rounding mode.
     *
     * @var string
     */
    private string $rounding_mode = 'none';

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
        $converted = round( $converted, 2 );

        // Apply price rounding
        return $this->apply_rounding( $converted );
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
     * Set the price rounding mode.
     *
     * @param string $mode One of: none, ceil, 99, nearest5, nearest10.
     * @return void
     */
    public function set_rounding_mode( string $mode ): void {
        $this->rounding_mode = $mode;
    }

    /**
     * Get the current rounding mode.
     *
     * @return string The rounding mode.
     */
    public function get_rounding_mode(): string {
        return $this->rounding_mode;
    }

    /**
     * Apply rounding to a converted price.
     *
     * @param float $price The price after conversion and markup.
     * @return float The rounded price.
     */
    private function apply_rounding( float $price ): float {
        switch ( $this->rounding_mode ) {
            case 'ceil':
                return (float) ceil( $price );
            case '99':
                return floor( $price ) + 0.99;
            case 'nearest5':
                return (float) ( ceil( $price / 5 ) * 5 );
            case 'nearest10':
                return (float) ( ceil( $price / 10 ) * 10 );
            case 'none':
            default:
                return $price;
        }
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
