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
use Trotibike\EwheelImporter\Repository\TranslationRepository;
use Trotibike\EwheelImporter\Pricing\ExchangeRateProviderInterface;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Trotibike\EwheelImporter\Config\Configuration;
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
     * Create a mock translation repository.
     *
     * @return \Mockery\MockInterface&TranslationRepository
     */
    public static function translation_repository(): TranslationRepository {
        $mock = Mockery::mock( TranslationRepository::class );
        // By default, return null for get (no cached translation)
        $mock->shouldReceive( 'get' )->andReturn( null );
        $mock->shouldReceive( 'get_batch' )->andReturn( [] );
        // Accept any save operations
        $mock->shouldReceive( 'save' )->andReturn( true );
        // Handle generate_hash for batch operations
        $mock->shouldReceive( 'generate_hash' )
            ->andReturnUsing( fn( $text, $source, $target ) => md5( $text . '|' . $source . '|' . $target ) );
        return $mock;
    }

    /**
     * Create a mock translation repository with cached translations.
     *
     * @param array $cache Map of "text|source|target" => translation.
     * @return \Mockery\MockInterface&TranslationRepository
     */
    public static function translation_repository_with_cache( array $cache ): TranslationRepository {
        $mock = Mockery::mock( TranslationRepository::class );
        $mock->shouldReceive( 'get' )
            ->andReturnUsing(
                function ( $text, $source, $target ) use ( $cache ) {
                    $key = "{$text}|{$source}|{$target}";
                    return $cache[ $key ] ?? null;
                }
            );
        $mock->shouldReceive( 'get_batch' )->andReturn( [] );
        $mock->shouldReceive( 'save' )->andReturn( true );
        return $mock;
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

    /**
     * Create a mock configuration.
     *
     * @param bool $variable_mode Whether to use variable product mode (default: true).
     * @return \Mockery\MockInterface&Configuration
     */
    public static function configuration( bool $variable_mode = true ): Configuration {
        $mock = Mockery::mock( Configuration::class );
        $mock->shouldReceive( 'get_api_key' )->andReturn( 'test-api-key' );
        $mock->shouldReceive( 'get_target_language' )->andReturn( 'ro' );
        $mock->shouldReceive( 'get_source_currency' )->andReturn( 'EUR' );
        $mock->shouldReceive( 'get_target_currency' )->andReturn( 'RON' );
        $mock->shouldReceive( 'get_markup_percentage' )->andReturn( 20.0 );
        $mock->shouldReceive( 'get_sync_frequency' )->andReturn( 'daily' );
        $mock->shouldReceive( 'get_last_sync' )->andReturn( null );
        $mock->shouldReceive( 'get_translation_service' )->andReturn( 'google' );
        $mock->shouldReceive( 'get_deepl_api_key' )->andReturn( '' );
        $mock->shouldReceive( 'get_google_api_key' )->andReturn( '' );
        $mock->shouldReceive( 'get_sync_fields' )->andReturn(
            [
                'name'              => true,
                'description'       => true,
                'short_description' => true,
                'price'             => true,
                'image'             => true,
                'categories'        => true,
                'attributes'        => true,
                'stock'             => true,
            ]
        );
        $mock->shouldReceive( 'is_variable_product_mode' )->andReturn( $variable_mode );
        $mock->shouldReceive( 'get_variation_mode' )->andReturn( $variable_mode ? 'variable' : 'simple' );
        return $mock;
    }
}
