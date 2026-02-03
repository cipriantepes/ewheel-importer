<?php
/**
 * Fixed Exchange Rate Provider implementation.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Pricing;

/**
 * Exchange rate provider using a fixed/configured rate.
 */
class FixedExchangeRateProvider implements ExchangeRateProviderInterface {

    /**
     * The fixed exchange rates.
     *
     * @var array
     */
    private array $rates;

    /**
     * Constructor.
     *
     * @param array $rates Array of rates in format ['EUR_RON' => 4.97, ...].
     */
    public function __construct( array $rates = [] ) {
        $this->rates = $rates;
    }

    /**
     * Get the exchange rate between two currencies.
     *
     * @param string $from_currency The source currency code.
     * @param string $to_currency   The target currency code.
     * @return float The exchange rate.
     * @throws \RuntimeException If rate not found.
     */
    public function get_rate( string $from_currency, string $to_currency ): float {
        $from = strtoupper( $from_currency );
        $to   = strtoupper( $to_currency );

        // Same currency = 1:1
        if ( $from === $to ) {
            return 1.0;
        }

        $key = $from . '_' . $to;

        if ( isset( $this->rates[ $key ] ) ) {
            return (float) $this->rates[ $key ];
        }

        // Try reverse rate
        $reverse_key = $to . '_' . $from;
        if ( isset( $this->rates[ $reverse_key ] ) ) {
            return 1.0 / (float) $this->rates[ $reverse_key ];
        }

        throw new \RuntimeException(
            sprintf( 'Exchange rate not found for %s to %s', $from, $to )
        );
    }

    /**
     * Set a rate.
     *
     * @param string $from_currency The source currency.
     * @param string $to_currency   The target currency.
     * @param float  $rate          The exchange rate.
     * @return void
     */
    public function set_rate( string $from_currency, string $to_currency, float $rate ): void {
        $key                 = strtoupper( $from_currency ) . '_' . strtoupper( $to_currency );
        $this->rates[ $key ] = $rate;
    }
}
