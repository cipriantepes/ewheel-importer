<?php
/**
 * Tests for the Google Translate Service.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Translation\GoogleTranslateService;
use Mockery;

/**
 * Test case for GoogleTranslateService.
 */
class GoogleTranslateServiceTest extends TestCase {

    /**
     * Test single text translation.
     */
    public function test_translate_single_text(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                Mockery::on( fn( $url ) => strpos( $url, 'https://translation.googleapis.com/language/translate/v2' ) === 0 ),
                Mockery::type( 'array' ),
                Mockery::type( 'array' )
            )
            ->andReturn(
                [
                    'data' => [
                        'translations' => [
                            [ 'translatedText' => 'Trotinetă Electrică' ],
                        ],
                    ],
                ]
            );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $result  = $service->translate( 'Electric Scooter', 'en', 'ro' );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test batch translation.
     */
    public function test_translate_batch(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn(
                [
                    'data' => [
                        'translations' => [
                            [ 'translatedText' => 'Trotinetă' ],
                            [ 'translatedText' => 'Electrică' ],
                        ],
                    ],
                ]
            );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $results = $service->translate_batch( [ 'Scooter', 'Electric' ], 'en', 'ro' );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'Trotinetă', $results[0] );
        $this->assertEquals( 'Electrică', $results[1] );
    }

    /**
     * Test empty text returns empty via batch.
     */
    public function test_empty_text_returns_empty(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn(
                [
                    'data' => [
                        'translations' => [
                            [ 'translatedText' => '' ],
                        ],
                    ],
                ]
            );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $result  = $service->translate( '', 'en', 'ro' );

        // Empty text goes through translate_batch and returns '' if that's what API returns
        $this->assertEquals( '', $result );
    }

    /**
     * Test API error throws exception.
     */
    public function test_api_error_throws_exception(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Translation failed:' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andThrow( new \RuntimeException( 'API Error' ) );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }

    /**
     * Test empty batch returns empty array.
     */
    public function test_empty_batch_returns_empty_array(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldNotReceive( 'post' );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $results = $service->translate_batch( [], 'en', 'ro' );

        $this->assertEquals( [], $results );
    }

    /**
     * Test invalid response structure throws exception.
     */
    public function test_invalid_response_throws_exception(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid response from Google Translate API' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn( [ 'invalid' => 'response' ] );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }

    /**
     * Test API key is included in URL.
     */
    public function test_api_key_in_url(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                Mockery::on( fn( $url ) => strpos( $url, 'key=my-secret-key' ) !== false ),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(
                [
                    'data' => [
                        'translations' => [
                            [ 'translatedText' => 'Translated' ],
                        ],
                    ],
                ]
            );

        $service = new GoogleTranslateService( 'my-secret-key', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }

    /**
     * Test language codes are normalized.
     */
    public function test_language_codes_normalized(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(
                    function ( $body ) {
                        return $body['source'] === 'en' && $body['target'] === 'ro';
                    }
                ),
                Mockery::any()
            )
            ->andReturn(
                [
                    'data' => [
                        'translations' => [
                            [ 'translatedText' => 'Translated' ],
                        ],
                    ],
                ]
            );

        $service = new GoogleTranslateService( 'test-api-key', $http_client );
        $service->translate( 'Hello', 'EN', 'RO' ); // Uppercase should be normalized
    }
}
