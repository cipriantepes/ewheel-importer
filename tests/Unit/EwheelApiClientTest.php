<?php
/**
 * Tests for the Ewheel API Client.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Api\HttpClientInterface;
use Mockery;

/**
 * Test case for EwheelApiClient.
 */
class EwheelApiClientTest extends TestCase {

    /**
     * Test that client requires API key.
     */
    public function test_constructor_requires_api_key(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'API key is required' );

        $http_client = Mockery::mock( HttpClientInterface::class );
        new EwheelApiClient( '', $http_client );
    }

    /**
     * Test fetching categories successfully.
     */
    public function test_get_categories_returns_array(): void {
        $mock_response = [
            [
                'reference'       => 'CAT001',
                'name'            => 'Electric Scooters',
                'parentReference' => null,
            ],
            [
                'reference'       => 'CAT002',
                'name'            => 'Accessories',
                'parentReference' => 'CAT001',
            ],
        ];

        $http_client = Mockery::mock( HttpClientInterface::class );
        $http_client->shouldReceive( 'post' )
            ->once()
            ->with(
                'https://api.ewheel.es/api/v1/catalog/categories',
                Mockery::type( 'array' ),
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
     * Test fetching categories with pagination.
     */
    public function test_get_categories_with_pagination(): void {
        $http_client = Mockery::mock( HttpClientInterface::class );
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
            ->andReturn( [] );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_categories( 2, 25 );
    }

    /**
     * Test fetching products successfully.
     */
    public function test_get_products_returns_array(): void {
        $mock_response = [
            [
                'Id'          => 123,
                'Reference'   => 'PROD001',
                'Name'        => [ 'en' => 'Electric Scooter X1' ],
                'Description' => [ 'en' => 'High performance scooter' ],
                'RRP'         => 599.99,
                'Currency'    => 'EUR',
                'Active'      => true,
                'Images'      => [ 'https://example.com/img1.jpg' ],
                'Categories'  => [ 'CAT001' ],
                'Attributes'  => [
                    'weight' => '15kg',
                    'range'  => '40km',
                ],
                'Variants'    => [],
            ],
        ];

        $http_client = Mockery::mock( HttpClientInterface::class );
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
        $http_client = Mockery::mock( HttpClientInterface::class );
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
            ->andReturn( [] );

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

        $http_client = Mockery::mock( HttpClientInterface::class );
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
            ->andReturn( [] );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_products_since( $since_date );
    }

    /**
     * Test API key is included in request headers.
     */
    public function test_api_key_included_in_headers(): void {
        $http_client = Mockery::mock( HttpClientInterface::class );
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
            ->andReturn( [] );

        $client = new EwheelApiClient( 'my-secret-key', $http_client );
        $client->get_categories();
    }

    /**
     * Test handling API errors.
     */
    public function test_handles_api_error(): void {
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'API request failed' );

        $http_client = Mockery::mock( HttpClientInterface::class );
        $http_client->shouldReceive( 'post' )
            ->once()
            ->andThrow( new \RuntimeException( 'API request failed' ) );

        $client = new EwheelApiClient( 'test-api-key', $http_client );
        $client->get_categories();
    }

    /**
     * Test fetching all products with pagination.
     */
    public function test_get_all_products_handles_pagination(): void {
        $page1 = array_fill( 0, 50, [ 'Id' => 1, 'Reference' => 'PROD' ] );
        $page2 = array_fill( 0, 50, [ 'Id' => 2, 'Reference' => 'PROD' ] );
        $page3 = array_fill( 0, 25, [ 'Id' => 3, 'Reference' => 'PROD' ] ); // Less than page size = last page

        $http_client = Mockery::mock( HttpClientInterface::class );
        $http_client->shouldReceive( 'post' )
            ->times( 3 )
            ->andReturn( $page1, $page2, $page3 );

        $client   = new EwheelApiClient( 'test-api-key', $http_client );
        $products = $client->get_all_products();

        $this->assertCount( 125, $products );
    }
}
