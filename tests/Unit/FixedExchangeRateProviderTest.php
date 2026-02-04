<?php
/**
 * Tests for the Fixed Exchange Rate Provider.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Pricing\FixedExchangeRateProvider;

/**
 * Test case for FixedExchangeRateProvider.
 */
class FixedExchangeRateProviderTest extends TestCase {

    /**
     * Test getting rate for EUR to RON.
     */
    public function test_get_rate_eur_to_ron(): void {
        $provider = new FixedExchangeRateProvider( [ 'EUR_RON' => 4.97 ] );
        $rate     = $provider->get_rate( 'EUR', 'RON' );

        $this->assertEquals( 4.97, $rate );
    }

    /**
     * Test getting rate for same currency returns 1.
     */
    public function test_same_currency_returns_one(): void {
        $provider = new FixedExchangeRateProvider( [ 'EUR_RON' => 4.97 ] );
        $rate     = $provider->get_rate( 'EUR', 'EUR' );

        $this->assertEquals( 1.0, $rate );
    }

    /**
     * Test missing rate throws exception.
     */
    public function test_missing_rate_throws_exception(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Exchange rate not found' );

        $provider = new FixedExchangeRateProvider( [] );
        $provider->get_rate( 'EUR', 'RON' );
    }

    /**
     * Test decimal rate precision.
     */
    public function test_decimal_rate_precision(): void {
        $provider = new FixedExchangeRateProvider( [ 'EUR_RON' => 4.9732 ] );
        $rate     = $provider->get_rate( 'EUR', 'RON' );

        $this->assertEquals( 4.9732, $rate );
    }

    /**
     * Test reverse rate calculation.
     */
    public function test_reverse_rate(): void {
        $provider = new FixedExchangeRateProvider( [ 'EUR_RON' => 5.0 ] );
        $rate     = $provider->get_rate( 'RON', 'EUR' );

        $this->assertEquals( 0.2, $rate );
    }

    /**
     * Test set_rate method.
     */
    public function test_set_rate(): void {
        $provider = new FixedExchangeRateProvider( [] );
        $provider->set_rate( 'USD', 'RON', 4.5 );

        $rate = $provider->get_rate( 'USD', 'RON' );

        $this->assertEquals( 4.5, $rate );
    }

    /**
     * Test case insensitive currency codes.
     */
    public function test_case_insensitive(): void {
        $provider = new FixedExchangeRateProvider( [ 'EUR_RON' => 4.97 ] );
        $rate     = $provider->get_rate( 'eur', 'ron' );

        $this->assertEquals( 4.97, $rate );
    }
}
