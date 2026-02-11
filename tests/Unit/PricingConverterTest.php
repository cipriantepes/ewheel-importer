<?php
/**
 * Tests for the Pricing Converter module.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Trotibike\EwheelImporter\Pricing\ExchangeRateProviderInterface;
use Mockery;

/**
 * Test case for PricingConverter.
 */
class PricingConverterTest extends TestCase {

    /**
     * Create a mock exchange rate provider with specific behavior.
     *
     * @param float  $rate Exchange rate to return.
     * @param string $from Source currency.
     * @param string $to   Target currency.
     * @return ExchangeRateProviderInterface
     */
    private function create_rate_provider_expecting( float $rate, string $from = 'EUR', string $to = 'RON' ): ExchangeRateProviderInterface {
        $provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $provider->shouldReceive( 'get_rate' )
            ->once()
            ->with( $from, $to )
            ->andReturn( $rate );
        return $provider;
    }

    /**
     * Test converting EUR to RON with exchange rate.
     */
    public function test_convert_eur_to_ron(): void {
        $rate_provider = $this->create_rate_provider_expecting( 4.97 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        $result = $converter->convert( 100.00 );

        $this->assertEquals( 497.00, $result );
    }

    /**
     * Test converting with markup percentage.
     */
    public function test_convert_with_markup_percentage(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 20 );

        $result = $converter->convert( 100.00 );

        // 100 EUR * 5.00 = 500 RON + 20% = 600 RON
        $this->assertEquals( 600.00, $result );
    }

    /**
     * Test converting with negative markup (discount).
     */
    public function test_convert_with_negative_markup(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', -10 );

        $result = $converter->convert( 100.00 );

        // 100 EUR * 5.00 = 500 RON - 10% = 450 RON
        $this->assertEquals( 450.00, $result );
    }

    /**
     * Test zero price returns zero.
     */
    public function test_zero_price_returns_zero(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $rate_provider->shouldNotReceive( 'get_rate' );

        $converter = new PricingConverter( $rate_provider, 'EUR', 'RON', 20 );
        $result    = $converter->convert( 0 );

        $this->assertEquals( 0, $result );
    }

    /**
     * Test negative price throws exception.
     */
    public function test_negative_price_throws_exception(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Price cannot be negative' );

        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->convert( -50 );
    }

    /**
     * Test rounding to 2 decimal places.
     */
    public function test_rounds_to_two_decimal_places(): void {
        $rate_provider = $this->create_rate_provider_expecting( 4.9732 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        $result = $converter->convert( 99.99 );

        // 99.99 * 4.9732 = 497.2726868 should round to 497.27
        $this->assertEquals( 497.27, $result );
    }

    /**
     * Test same currency conversion returns price with markup only.
     */
    public function test_same_currency_returns_price_with_markup(): void {
        $rate_provider = $this->create_rate_provider_expecting( 1.00, 'EUR', 'EUR' );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'EUR', 25 );

        $result = $converter->convert( 100.00 );

        $this->assertEquals( 125.00, $result );
    }

    /**
     * Test caches exchange rate.
     */
    public function test_caches_exchange_rate(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        // Only get_rate is called once despite multiple conversions
        $converter->convert( 100.00 );
        $converter->convert( 200.00 );
        $converter->convert( 300.00 );
    }

    /**
     * Test batch conversion.
     */
    public function test_convert_batch(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 10 );
        $prices        = [ 100.00, 50.00, 25.00 ];

        $results = $converter->convert_batch( $prices );

        // Each price: * 5.00 + 10%
        $this->assertEquals( [ 550.00, 275.00, 137.50 ], $results );
    }

    /**
     * Test format price for display.
     */
    public function test_format_price(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        $formatted = $converter->format_price( 1234.56 );

        $this->assertEquals( '1234.56', $formatted );
    }

    /**
     * Test get target currency.
     */
    public function test_get_target_currency(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        $this->assertEquals( 'RON', $converter->get_target_currency() );
    }

    /**
     * Test get markup percentage.
     */
    public function test_get_markup_percentage(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 25.5 );

        $this->assertEquals( 25.5, $converter->get_markup_percentage() );
    }

    /**
     * Test rounding mode: ceil.
     */
    public function test_rounding_ceil(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'ceil' );

        // 119.28 EUR * 5.00 = 596.40 → ceil → 597
        $this->assertEquals( 597.0, $converter->convert( 119.28 ) );
    }

    /**
     * Test rounding mode: ceil with exact integer stays the same.
     */
    public function test_rounding_ceil_exact_integer(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'ceil' );

        // 100.00 * 5.00 = 500.00 → ceil → 500
        $this->assertEquals( 500.0, $converter->convert( 100.00 ) );
    }

    /**
     * Test rounding mode: .99 ending.
     */
    public function test_rounding_99(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( '99' );

        // 119.28 * 5.00 = 596.40 → 596.99
        $this->assertEquals( 596.99, $converter->convert( 119.28 ) );
    }

    /**
     * Test rounding mode: .99 ending with exact integer.
     */
    public function test_rounding_99_exact_integer(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( '99' );

        // 100.00 * 5.00 = 500.00 → 500.99
        $this->assertEquals( 500.99, $converter->convert( 100.00 ) );
    }

    /**
     * Test rounding mode: nearest 5.
     */
    public function test_rounding_nearest5(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'nearest5' );

        // 119.28 * 5.00 = 596.40 → 600
        $this->assertEquals( 600.0, $converter->convert( 119.28 ) );
    }

    /**
     * Test rounding mode: nearest 5 with exact multiple.
     */
    public function test_rounding_nearest5_exact(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'nearest5' );

        // 100.00 * 5.00 = 500.00 → 500 (already a multiple of 5)
        $this->assertEquals( 500.0, $converter->convert( 100.00 ) );
    }

    /**
     * Test rounding mode: nearest 10.
     */
    public function test_rounding_nearest10(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'nearest10' );

        // 119.28 * 5.00 = 596.40 → 600
        $this->assertEquals( 600.0, $converter->convert( 119.28 ) );
    }

    /**
     * Test rounding mode: nearest 10 rounds up from 501.
     */
    public function test_rounding_nearest10_rounds_up(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'nearest10' );

        // 100.20 * 5.00 = 501.00 → 510
        $this->assertEquals( 510.0, $converter->convert( 100.20 ) );
    }

    /**
     * Test rounding mode: none leaves price unchanged.
     */
    public function test_rounding_none(): void {
        $rate_provider = $this->create_rate_provider_expecting( 5.00 );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( 'none' );

        $this->assertEquals( 596.40, $converter->convert( 119.28 ) );
    }

    /**
     * Test rounding with zero price.
     */
    public function test_rounding_zero_price(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $rate_provider->shouldNotReceive( 'get_rate' );

        $converter = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->set_rounding_mode( '99' );

        $this->assertEquals( 0.0, $converter->convert( 0.0 ) );
    }

    /**
     * Test get_rounding_mode returns set mode.
     */
    public function test_get_rounding_mode(): void {
        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $converter     = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );

        $this->assertEquals( 'none', $converter->get_rounding_mode() );

        $converter->set_rounding_mode( '99' );
        $this->assertEquals( '99', $converter->get_rounding_mode() );
    }

    /**
     * Test handles exchange rate provider error.
     */
    public function test_handles_exchange_rate_error(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Failed to get exchange rate' );

        $rate_provider = Mockery::mock( ExchangeRateProviderInterface::class );
        $rate_provider->shouldReceive( 'get_rate' )
            ->once()
            ->andThrow( new \RuntimeException( 'Failed to get exchange rate' ) );

        $converter = new PricingConverter( $rate_provider, 'EUR', 'RON', 0 );
        $converter->convert( 100.00 );
    }
}
