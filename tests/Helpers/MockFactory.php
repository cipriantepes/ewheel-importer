<?php
/**
 * Mock Factory for Tests.
 *
 * @package Trotibike\EwheelImporter\Tests
 */

namespace Trotibike\EwheelImporter\Tests\Helpers;

use Trotibike\EwheelImporter\Api\HttpClientInterface;
use Trotibike\EwheelImporter\Translation\TranslationServiceInterface;
use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Pricing\ExchangeRateProviderInterface;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Mockery;

/**
 * Factory for creating commonly used mocks.
 *
 * DRY: Centralizes mock creation to avoid repetition in tests.
 */
class MockFactory {

    /**
     * Create a mock HTTP client.
     *
     * @return \Mockery\MockInterface&HttpClientInterface
     */
    public static function http_client(): HttpClientInterface {
        return Mockery::mock( HttpClientInterface::class );
    }

    /**
     * Create a mock HTTP client with a preset response.
     *
     * @param array $response Response to return.
     * @return \Mockery\MockInterface&HttpClientInterface
     */
    public static function http_client_with_response( array $response ): HttpClientInterface {
        $mock = self::http_client();
        $mock->shouldReceive( 'post' )->andReturn( $response );
        $mock->shouldReceive( 'get' )->andReturn( $response );
        return $mock;
    }

    /**
     * Create a mock translation service.
     *
     * @return \Mockery\MockInterface&TranslationServiceInterface
     */
    public static function translation_service(): TranslationServiceInterface {
        return Mockery::mock( TranslationServiceInterface::class );
    }

    /**
     * Create a mock translation service that returns input unchanged.
     *
     * @return \Mockery\MockInterface&TranslationServiceInterface
     */
    public static function passthrough_translation_service(): TranslationServiceInterface {
        $mock = self::translation_service();
        $mock->shouldReceive( 'translate' )
            ->andReturnUsing( fn( $text ) => $text );
        $mock->shouldReceive( 'translate_batch' )
            ->andReturnUsing( fn( $texts ) => $texts );
        return $mock;
    }

    /**
     * Create a mock translator.
     *
     * @return \Mockery\MockInterface&Translator
     */
    public static function translator(): Translator {
        $mock = Mockery::mock( Translator::class );
        $mock->shouldReceive( 'translate_multilingual' )->andReturn( 'Translated' );
        $mock->shouldReceive( 'translate' )->andReturnUsing( fn( $text ) => $text );
        return $mock;
    }

    /**
     * Create a mock translator with specific translations.
     *
     * @param array $translations Map of original => translated.
     * @return \Mockery\MockInterface&Translator
     */
    public static function translator_with_map( array $translations ): Translator {
        $mock = Mockery::mock( Translator::class );
        $mock->shouldReceive( 'translate_multilingual' )
            ->andReturnUsing(
                function ( $input ) use ( $translations ) {
                    $text = is_array( $input ) ? ( $input['en'] ?? reset( $input ) ) : $input;
                    return $translations[ $text ] ?? $text;
                }
            );
        $mock->shouldReceive( 'translate' )
            ->andReturnUsing( fn( $text ) => $translations[ $text ] ?? $text );
        return $mock;
    }

    /**
     * Create a mock exchange rate provider.
     *
     * @param float $rate Exchange rate to return.
     * @return \Mockery\MockInterface&ExchangeRateProviderInterface
     */
    public static function exchange_rate_provider( float $rate = 5.0 ): ExchangeRateProviderInterface {
        $mock = Mockery::mock( ExchangeRateProviderInterface::class );
        $mock->shouldReceive( 'get_rate' )->andReturn( $rate );
        return $mock;
    }

    /**
     * Create a mock pricing converter.
     *
     * @param float $multiplier Price multiplier (conversion + markup).
     * @return \Mockery\MockInterface&PricingConverter
     */
    public static function pricing_converter( float $multiplier = 5.0 ): PricingConverter {
        $mock = Mockery::mock( PricingConverter::class );
        $mock->shouldReceive( 'convert' )
            ->andReturnUsing( fn( $price ) => round( $price * $multiplier, 2 ) );
        $mock->shouldReceive( 'format_price' )
            ->andReturnUsing( fn( $price ) => number_format( $price, 2, '.', '' ) );
        return $mock;
    }
}
