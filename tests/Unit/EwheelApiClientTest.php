<?php
/**
 * Tests for the Ewheel API Client.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Tests\Helpers\ProductFixtures;
use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Mockery;

/**
 * Test case for EwheelApiClient.
 */
class EwheelApiClientTest extends TestCase {

    /**
     * Helper to wrap data in API response format.
     *
     * @param array $data The data to wrap.
     * @return array The wrapped response.
     */
    private function wrap_response( array $data ): array {
        return [
            'Data'       => $data,
            'Ok'         => true,
            'Type'       => 'Success',
            'Code'       => 0,
            'Message'    => '',
            'StackTrace' => null,
        ];
    }

    /**
     * Test that client requires API key.
     */
    public function test_constructor_requires_api_key(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'API key is required' );

        $http_client = MockFactory::http_client();
        new EwheelApiClient( '', $http_client );
    }

    /**
     * Test fetching categories successfully (uses POST, extracts Data).
     */
    public function test_get_categories_returns_array(): void {
        $category_data   = ProductFixtures::category_list();
        $mock_response   = $this->wrap_response( $category_data );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/categories',
                Mockery::on(
                    function ( $body ) {
                        return isset( $body['Page'] ) && $body['Page'] === 0
                            && isset( $body['PageSize'] ) && $body['PageSize'] === 50;
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( $mock_response );

        $client     = new EwheelApiClient( 'test-api-key', $http_client );
        $categories = $client->get_categories();

        $this->assertIsArray( $categories );
        $this->assertCount( 2, $categories );
        $this->assertEquals( 'CAT001', $categories[0]['reference'] );
        $this->assertEquals( 'Electric Scooters', $categories[0]['name'] );
    }

    /**
     * Test fetching categories with pagination (uses POST body).
     */
    public function test_get_categories_with_pagination(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/categories',
                Mockery::on(
                    function ( $body ) {
                        return isset( $body['Page'] ) && $body['Page'] === 2
                            && isset( $body['PageSize'] ) && $body['PageSize'] === 25;
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( $this->wrap_response( [] ) );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_categories( 2, 25 );
    }

    /**
     * Test fetching products successfully (extracts Data from wrapper).
     */
    public function test_get_products_returns_array(): void {
        $product_data  = [ ProductFixtures::simple_ewheel_product( [ 'Reference' => 'PROD001' ] ) ];
        $mock_response = $this->wrap_response( $product_data );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/products/filter',
                Mockery::type( 'array' ),
                Mockery::type( 'array' )
            )
            ->andReturn( $mock_response );

        $client   = new EwheelApiClient( 'test-api-key', $http_client );
        $products = $client->get_products();

        $this->assertIsArray( $products );
        $this->assertCount( 1, $products );
        $this->assertEquals( 'PROD001', $products[0]['Reference'] );
        $this->assertEquals( 599.99, $products[0]['RRP'] );
    }

    /**
     * Test fetching products with filters.
     */
    public function test_get_products_with_filters(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/products/filter',
                Mockery::on(
                    function ( $body ) {
                        return isset( $body['active'] ) && $body['active'] === true
                            && isset( $body['hasImages'] ) && $body['hasImages'] === true
                            && isset( $body['category'] ) && $body['category'] === [ 'CAT001' ];
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( $this->wrap_response( [] ) );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_products(
            0,
            10,
            [
                'active'    => true,
                'hasImages' => true,
                'category'  => [ 'CAT001' ],
            ]
        );
    }

    /**
     * Test fetching products newer than a date (incremental sync).
     */
    public function test_get_products_newer_than(): void {
        $since_date = '2024-01-15T10:30:00';

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/products/filter',
                Mockery::on(
                    function ( $body ) use ( $since_date ) {
                        return isset( $body['NewerThan'] ) && $body['NewerThan'] === $since_date;
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( $this->wrap_response( [] ) );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_products_since( $since_date );
    }

    /**
     * Test API key is included in request headers (for categories POST).
     */
    public function test_api_key_included_in_headers(): void {
        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::on(
                    function ( $headers ) {
                        return isset( $headers['X-API-KEY'] ) && $headers['X-API-KEY'] === 'my-secret-key';
                    }
                )
            )
            ->andReturn( $this->wrap_response( [] ) );

        $client = new EwheelApiClient( 'my-secret-key', $http_client );
        $client->get_categories();
    }

    /**
     * Test handling API errors.
     */
    public function test_handles_api_error(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'API request failed' );

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andThrow( new \RuntimeException( 'API request failed' ) );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_categories();
    }

    /**
     * Test handling API error response in wrapper.
     */
    public function test_handles_api_error_in_wrapper(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'API Error: Invalid API key' );

        $error_response = [
            'Data'       => [],
            'Ok'         => false,
            'Type'       => 'Error',
            'Code'       => 401,
            'Message'    => 'Invalid API key',
            'StackTrace' => null,
        ];

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn( $error_response );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_categories();
    }

    /**
     * Test fetching all products with pagination (handles wrapper).
     */
    public function test_get_all_products_handles_pagination(): void {
        $page1 = $this->wrap_response( ProductFixtures::product_list( 50 ) );
        $page2 = $this->wrap_response( ProductFixtures::product_list( 50 ) );
        $page3 = $this->wrap_response( ProductFixtures::product_list( 25 ) ); // Less than page size = last page

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->times( 3 )
            ->andReturn( $page1, $page2, $page3 );

        $client   = new EwheelApiClient( 'test-api-key', $http_client );
        $products = $client->get_all_products();

        $this->assertCount( 125, $products );
    }

    /**
     * Test pagination stops on empty response.
     */
    public function test_pagination_stops_on_empty_response(): void {
        $page1 = $this->wrap_response( ProductFixtures::product_list( 50 ) );
        $page2 = $this->wrap_response( [] ); // Empty = stop

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->times( 2 )
            ->andReturn( $page1, $page2 );

        $client   = new EwheelApiClient( 'test-api-key', $http_client );
        $products = $client->get_all_products();

        $this->assertCount( 50, $products );
    }

    /**
     * Test backwards compatibility with direct array response (no wrapper).
     */
    public function test_handles_direct_array_response(): void {
        // Some APIs might return direct array without wrapper
        $direct_response = [ ProductFixtures::simple_ewheel_product( [ 'Reference' => 'PROD001' ] ) ];

        $http_client = MockFactory::http_client();
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andReturn( $direct_response );

        $client   = new EwheelApiClient( 'test-api-key', $http_client );
        $products = $client->get_products();

        $this->assertIsArray( $products );
        $this->assertCount( 1, $products );
        $this->assertEquals( 'PROD001', $products[0]['Reference'] );
    }
}
