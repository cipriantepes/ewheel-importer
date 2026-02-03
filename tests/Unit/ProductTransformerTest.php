<?php
/**
 * Tests for the Product Transformer module.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Sync\ProductTransformer;
use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Mockery;

/**
 * Test case for ProductTransformer.
 */
class ProductTransformerTest extends TestCase {

    /**
     * Test transforming a simple product.
     */
    public function test_transform_simple_product(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )
            ->with( [ 'en' => 'Electric Scooter X1' ] )
            ->andReturn( 'Trotinetă Electrică X1' );
        $translator->shouldReceive( 'translate_multilingual' )
            ->with( [ 'en' => 'High performance electric scooter' ] )
            ->andReturn( 'Trotinetă electrică de înaltă performanță' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )
            ->with( 599.99 )
            ->andReturn( 2999.95 );
        $pricing_converter->shouldReceive( 'format_price' )
            ->andReturnUsing( function( $price ) { return number_format( $price, 2, '.', '' ); } );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'          => 123,
            'Reference'   => 'SCOOTER-X1',
            'Name'        => [ 'en' => 'Electric Scooter X1' ],
            'Description' => [ 'en' => 'High performance electric scooter' ],
            'RRP'         => 599.99,
            'Currency'    => 'EUR',
            'Active'      => true,
            'Images'      => [ 'https://example.com/img1.jpg', 'https://example.com/img2.jpg' ],
            'Categories'  => [ 'CAT001' ],
            'Attributes'  => [
                'weight' => '15kg',
                'range'  => '40km',
            ],
            'Variants'    => [],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'Trotinetă Electrică X1', $woo_product['name'] );
        $this->assertEquals( 'Trotinetă electrică de înaltă performanță', $woo_product['description'] );
        $this->assertEquals( 'SCOOTER-X1', $woo_product['sku'] );
        $this->assertEquals( '2999.95', $woo_product['regular_price'] );
        $this->assertEquals( 'publish', $woo_product['status'] );
        $this->assertEquals( 'simple', $woo_product['type'] );
        $this->assertCount( 2, $woo_product['images'] );
        $this->assertEquals( 'https://example.com/img1.jpg', $woo_product['images'][0]['src'] );
    }

    /**
     * Test transforming inactive product sets status to draft.
     */
    public function test_transform_inactive_product_status_draft(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Test Product' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 100.00 );
        $pricing_converter->shouldReceive( 'format_price' )->andReturn( '100.00' );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'          => 124,
            'Reference'   => 'TEST-001',
            'Name'        => [ 'en' => 'Test' ],
            'Description' => [ 'en' => 'Test' ],
            'RRP'         => 50.00,
            'Currency'    => 'EUR',
            'Active'      => false,
            'Images'      => [],
            'Categories'  => [],
            'Attributes'  => [],
            'Variants'    => [],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'draft', $woo_product['status'] );
    }

    /**
     * Test transforming product with variants creates variable product.
     */
    public function test_transform_product_with_variants(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Scooter' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )
            ->with( 499.99 )
            ->andReturn( 2499.95 );
        $pricing_converter->shouldReceive( 'convert' )
            ->with( 599.99 )
            ->andReturn( 2999.95 );
        $pricing_converter->shouldReceive( 'format_price' )
            ->andReturnUsing( function( $price ) { return number_format( $price, 2, '.', '' ); } );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'          => 125,
            'Reference'   => 'SCOOTER-V',
            'Name'        => [ 'en' => 'Scooter' ],
            'Description' => [ 'en' => 'Scooter' ],
            'RRP'         => 499.99,
            'Currency'    => 'EUR',
            'Active'      => true,
            'Images'      => [],
            'Categories'  => [],
            'Attributes'  => [ 'color' => 'Black' ],
            'Variants'    => [
                [
                    'Id'         => 'VAR001',
                    'Reference'  => 'SCOOTER-V-BLACK',
                    'net'        => 499.99,
                    'Attributes' => [ 'color' => 'Black' ],
                ],
                [
                    'Id'         => 'VAR002',
                    'Reference'  => 'SCOOTER-V-WHITE',
                    'net'        => 599.99,
                    'Attributes' => [ 'color' => 'White' ],
                ],
            ],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'variable', $woo_product['type'] );
        $this->assertArrayHasKey( 'attributes', $woo_product );
        $this->assertArrayHasKey( 'variations', $woo_product );
        $this->assertCount( 2, $woo_product['variations'] );
    }

    /**
     * Test transforming attributes to WooCommerce format.
     */
    public function test_transform_attributes(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Test' );
        $translator->shouldReceive( 'translate' )
            ->andReturnUsing( function( $text ) { return $text; } );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 100.00 );
        $pricing_converter->shouldReceive( 'format_price' )->andReturn( '100.00' );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'          => 126,
            'Reference'   => 'TEST-002',
            'Name'        => [ 'en' => 'Test' ],
            'Description' => [ 'en' => 'Test' ],
            'RRP'         => 50.00,
            'Currency'    => 'EUR',
            'Active'      => true,
            'Images'      => [],
            'Categories'  => [],
            'Attributes'  => [
                'weight'       => '15kg',
                'max_speed'    => '25km/h',
                'battery_life' => '40km',
            ],
            'Variants'    => [],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertArrayHasKey( 'attributes', $woo_product );
        $this->assertCount( 3, $woo_product['attributes'] );
    }

    /**
     * Test category mapping.
     */
    public function test_maps_categories(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Test' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 100.00 );

        $pricing_converter->shouldReceive( 'format_price' )->andReturn( '100.00' );

        $category_map = [
            'CAT001' => 10,
            'CAT002' => 20,
        ];

        $transformer = new ProductTransformer( $translator, $pricing_converter, $category_map );

        $ewheel_product = [
            'Id'          => 127,
            'Reference'   => 'TEST-003',
            'Name'        => [ 'en' => 'Test' ],
            'Description' => [ 'en' => 'Test' ],
            'RRP'         => 50.00,
            'Currency'    => 'EUR',
            'Active'      => true,
            'Images'      => [],
            'Categories'  => [ 'CAT001', 'CAT002' ],
            'Attributes'  => [],
            'Variants'    => [],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertArrayHasKey( 'categories', $woo_product );
        $this->assertCount( 2, $woo_product['categories'] );
        $this->assertEquals( 10, $woo_product['categories'][0]['id'] );
        $this->assertEquals( 20, $woo_product['categories'][1]['id'] );
    }

    /**
     * Test product with missing optional fields.
     */
    public function test_handles_missing_optional_fields(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( '' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 0.00 );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'        => 128,
            'Reference' => 'MINIMAL-001',
            'Name'      => [],
            'RRP'       => 0,
            'Active'    => true,
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'MINIMAL-001', $woo_product['sku'] );
        $this->assertEquals( '', $woo_product['name'] );
        $this->assertEquals( '0', $woo_product['regular_price'] );
    }

    /**
     * Test storing ewheel ID in meta.
     */
    public function test_stores_ewheel_id_in_meta(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Test' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 100.00 );
        $pricing_converter->shouldReceive( 'format_price' )->andReturn( '100.00' );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $ewheel_product = [
            'Id'          => 999,
            'Reference'   => 'META-TEST',
            'Name'        => [ 'en' => 'Test' ],
            'Description' => [ 'en' => 'Test' ],
            'RRP'         => 50.00,
            'Currency'    => 'EUR',
            'Active'      => true,
            'Images'      => [],
            'Categories'  => [],
            'Attributes'  => [],
            'Variants'    => [],
        ];

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertArrayHasKey( 'meta_data', $woo_product );
        $meta_keys = array_column( $woo_product['meta_data'], 'key' );
        $this->assertContains( '_ewheel_id', $meta_keys );
        $this->assertContains( '_ewheel_reference', $meta_keys );
    }

    /**
     * Test batch transform.
     */
    public function test_batch_transform(): void {
        $translator = Mockery::mock( Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( 'Test' );

        $pricing_converter = Mockery::mock( PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 100.00 );
        $pricing_converter->shouldReceive( 'format_price' )->andReturn( '100.00' );

        $transformer = new ProductTransformer( $translator, $pricing_converter );

        $products = [
            [
                'Id'        => 1,
                'Reference' => 'PROD-1',
                'Name'      => [ 'en' => 'Product 1' ],
                'RRP'       => 100,
                'Active'    => true,
            ],
            [
                'Id'        => 2,
                'Reference' => 'PROD-2',
                'Name'      => [ 'en' => 'Product 2' ],
                'RRP'       => 200,
                'Active'    => true,
            ],
        ];

        $results = $transformer->transform_batch( $products );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'PROD-1', $results[0]['sku'] );
        $this->assertEquals( 'PROD-2', $results[1]['sku'] );
    }
}
