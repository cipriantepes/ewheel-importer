<?php
/**
 * Tests for the OpenRouter Translate Service.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Translation\OpenRouterTranslateService;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test case for OpenRouterTranslateService.
 */
class OpenRouterTranslateServiceTest extends TestCase {

    /**
     * Test single text translation.
     */
    public function test_translate_single_text(): void {
        Functions\expect( 'get_site_url' )
            ->once()
            ->andReturn( 'https://example.com' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://openrouter.ai/api/v1/chat/completions',
                Mockery::type( 'array' ),
                Mockery::type( 'array' )
            )
            ->andReturn(
                [
                    'choices' => [
                        [
                            'message' => [
                                'content' => 'Trotinetă Electrică',
                            ],
                        ],
                    ],
                ]
            );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $result  = $service->translate( 'Electric Scooter', 'en', 'ro' );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test batch translation sends a single numbered-list request.
     */
    public function test_translate_batch(): void {
        Functions\expect( 'get_site_url' )
            ->once()
            ->andReturn( 'https://example.com' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn(
                [
                    'choices' => [
                        [
                            'message' => [ 'content' => "1. Trotinetă\n2. Electrică" ],
                        ],
                    ],
                ]
            );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $results = $service->translate_batch( [ 'Scooter', 'Electric' ], 'en', 'ro' );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'Trotinetă', $results[0] );
        $this->assertEquals( 'Electrică', $results[1] );
    }

    /**
     * Test empty text returns empty string.
     */
    public function test_empty_text_returns_empty(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldNotReceive( 'post' );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $result  = $service->translate( '', 'en', 'ro' );

        $this->assertEquals( '', $result );
    }

    /**
     * Test whitespace-only text returns original text.
     */
    public function test_whitespace_text_returns_original(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldNotReceive( 'post' );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $result  = $service->translate( '   ', 'en', 'ro' );

        $this->assertEquals( '   ', $result );
    }

    /**
     * Test API error throws exception.
     */
    public function test_api_error_throws_exception(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'OpenRouter Error:' );

        Functions\expect( 'get_site_url' )
            ->once()
            ->andReturn( 'https://example.com' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andThrow( new \RuntimeException( 'API Error' ) );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }

    /**
     * Test custom model can be specified.
     */
    public function test_custom_model(): void {
        Functions\expect( 'get_site_url' )
            ->once()
            ->andReturn( 'https://example.com' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                Mockery::any(),
                Mockery::on(
                    function ( $body ) {
                        return isset( $body['model'] ) && $body['model'] === 'anthropic/claude-3-haiku';
                    }
                ),
                Mockery::any()
            )
            ->andReturn(
                [
                    'choices' => [
                        [ 'message' => [ 'content' => 'Translated' ] ],
                    ],
                ]
            );

        $service = new OpenRouterTranslateService( 'test-api-key', 'anthropic/claude-3-haiku', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }

    /**
     * Test empty batch returns empty array.
     */
    public function test_empty_batch_returns_empty_array(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldNotReceive( 'post' );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $results = $service->translate_batch( [], 'en', 'ro' );

        $this->assertEquals( [], $results );
    }

    /**
     * Test invalid response structure throws exception.
     */
    public function test_invalid_response_throws_exception(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Invalid response structure from OpenRouter' );

        Functions\expect( 'get_site_url' )
            ->once()
            ->andReturn( 'https://example.com' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn( [ 'invalid' => 'response' ] );

        $service = new OpenRouterTranslateService( 'test-api-key', 'google/gemini-flash-1.5', $http_client );
        $service->translate( 'Hello', 'en', 'ro' );
    }
}
