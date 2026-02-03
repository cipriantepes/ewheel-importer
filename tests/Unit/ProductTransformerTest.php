<?php
/**
 * Tests for the Product Transformer module.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Tests\Helpers\ProductFixtures;
use Trotibike\EwheelImporter\Sync\ProductTransformer;
use Mockery;

/**
 * Test case for ProductTransformer.
 */
class ProductTransformerTest extends TestCase {

    /**
     * Test transforming a simple product.
     */
    public function test_transform_simple_product(): void {
        $translations = [
            'Electric Scooter X1'               => 'Trotinetă Electrică X1',
            'High performance electric scooter' => 'Trotinetă electrică de înaltă performanță',
        ];

        $translator        = MockFactory::translator_with_map( $translations );
        $pricing_converter = MockFactory::pricing_converter( 5.0 );
        $config            = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::simple_ewheel_product(
            [
                'Images' => [ 'https://example.com/img1.jpg', 'https://example.com/img2.jpg' ],
            ]
        );

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'Trotinetă Electrică X1', $woo_product['name'] );
        $this->assertEquals( 'Trotinetă electrică de înaltă performanță', $woo_product['description'] );
        $this->assertEquals( 'SCOOTER-X1', $woo_product['sku'] );
        $this->assertEquals( 'publish', $woo_product['status'] );
        $this->assertEquals( 'simple', $woo_product['type'] );
        $this->assertCount( 2, $woo_product['images'] );
        $this->assertEquals( 'https://example.com/img1.jpg', $woo_product['images'][0]['src'] );
    }

    /**
     * Test transforming inactive product sets status to draft.
     */
    public function test_transform_inactive_product_status_draft(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::inactive_ewheel_product();

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'draft', $woo_product['status'] );
    }

    /**
     * Test transforming product with variants creates variable product.
     */
    public function test_transform_product_with_variants(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::variable_ewheel_product();

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
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::simple_ewheel_product(
            [
                'Attributes' => [
                    'weight'       => '15kg',
                    'max_speed'    => '25km/h',
                    'battery_life' => '40km',
                ],
            ]
        );

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertArrayHasKey( 'attributes', $woo_product );
        $this->assertCount( 3, $woo_product['attributes'] );
    }

    /**
     * Test category mapping.
     */
    public function test_maps_categories(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $category_map = [
            'CAT001' => 10,
            'CAT002' => 20,
        ];

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config, $category_map );
        $ewheel_product = ProductFixtures::simple_ewheel_product(
            [
                'Categories' => [ 'CAT001', 'CAT002' ],
            ]
        );

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
        $translator = Mockery::mock( \Trotibike\EwheelImporter\Translation\Translator::class );
        $translator->shouldReceive( 'translate_multilingual' )->andReturn( '' );

        $pricing_converter = Mockery::mock( \Trotibike\EwheelImporter\Pricing\PricingConverter::class );
        $pricing_converter->shouldReceive( 'convert' )->andReturn( 0.00 );

        $config = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::minimal_ewheel_product();

        $woo_product = $transformer->transform( $ewheel_product );

        $this->assertEquals( 'MINIMAL-001', $woo_product['sku'] );
        $this->assertEquals( '', $woo_product['name'] );
        $this->assertEquals( '0', $woo_product['regular_price'] );
    }

    /**
     * Test storing ewheel ID in meta.
     */
    public function test_stores_ewheel_id_in_meta(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::simple_ewheel_product(
            [
                'Id'        => 999,
                'Reference' => 'META-TEST',
            ]
        );

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
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration();

        $transformer = new ProductTransformer( $translator, $pricing_converter, $config );
        $products    = ProductFixtures::product_list( 2 );

        $results = $transformer->transform_batch( $products );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'PROD-1', $results[0]['sku'] );
        $this->assertEquals( 'PROD-2', $results[1]['sku'] );
    }
}
