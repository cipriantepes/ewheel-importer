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

        $result = $transformer->transform( $ewheel_product );

        $this->assertCount( 1, $result );
        $woo_product = $result[0];

        $this->assertEquals( 'Trotinetă Electrică X1', $woo_product['name'] );
        // Description also comes from the Name field (API Description has pipe-separated data)
        $this->assertEquals( 'Trotinetă Electrică X1', $woo_product['description'] );
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

        $result = $transformer->transform( $ewheel_product );
        $woo_product = $result[0];

        $this->assertEquals( 'draft', $woo_product['status'] );
    }

    /**
     * Test transforming product with variants creates variable product.
     */
    public function test_transform_product_with_variants(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration( true ); // Variable mode

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::variable_ewheel_product();

        $result = $transformer->transform( $ewheel_product );

        $this->assertCount( 1, $result ); // One variable product
        $woo_product = $result[0];

        $this->assertEquals( 'variable', $woo_product['type'] );
        $this->assertArrayHasKey( 'attributes', $woo_product );
        $this->assertArrayHasKey( 'variations', $woo_product );
        $this->assertCount( 2, $woo_product['variations'] );
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

        $result = $transformer->transform( $ewheel_product );
        $woo_product = $result[0];

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

        $result = $transformer->transform( $ewheel_product );
        $woo_product = $result[0];

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

        $result = $transformer->transform( $ewheel_product );
        $woo_product = $result[0];

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

    /**
     * Test simple mode creates multiple simple products from variants.
     */
    public function test_simple_mode_creates_multiple_products_from_variants(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration( false ); // Simple mode

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::variable_ewheel_product();

        $result = $transformer->transform( $ewheel_product );

        // Should create 2 simple products (one per variant)
        $this->assertCount( 2, $result );

        // First variant
        $this->assertEquals( 'simple', $result[0]['type'] );
        $this->assertEquals( 'SCOOTER-V-BLACK', $result[0]['sku'] );
        $this->assertStringContains( 'Black', $result[0]['name'] );

        // Second variant
        $this->assertEquals( 'simple', $result[1]['type'] );
        $this->assertEquals( 'SCOOTER-V-WHITE', $result[1]['sku'] );
        $this->assertStringContains( 'White', $result[1]['name'] );

        // Both should have product group meta
        $meta_keys_1 = array_column( $result[0]['meta_data'], 'key' );
        $meta_keys_2 = array_column( $result[1]['meta_data'], 'key' );
        $this->assertContains( '_ewheel_product_group', $meta_keys_1 );
        $this->assertContains( '_ewheel_product_group', $meta_keys_2 );
    }

    /**
     * Test simple mode products share the same product group.
     */
    public function test_simple_mode_products_share_product_group(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration( false ); // Simple mode

        $transformer    = new ProductTransformer( $translator, $pricing_converter, $config );
        $ewheel_product = ProductFixtures::variable_ewheel_product();

        $result = $transformer->transform( $ewheel_product );

        // Get product group values
        $get_group = function ( $product ) {
            foreach ( $product['meta_data'] as $meta ) {
                if ( $meta['key'] === '_ewheel_product_group' ) {
                    return $meta['value'];
                }
            }
            return null;
        };

        $group_1 = $get_group( $result[0] );
        $group_2 = $get_group( $result[1] );

        $this->assertEquals( 'SCOOTER-V', $group_1 );
        $this->assertEquals( $group_1, $group_2 );
    }

    /**
     * Test batch transform with mixed simple and variable products in simple mode.
     */
    public function test_batch_transform_expands_variants_in_simple_mode(): void {
        $translator        = MockFactory::translator();
        $pricing_converter = MockFactory::pricing_converter();
        $config            = MockFactory::configuration( false ); // Simple mode

        $transformer = new ProductTransformer( $translator, $pricing_converter, $config );
        $products    = [
            ProductFixtures::simple_ewheel_product( [ 'Reference' => 'SIMPLE-1' ] ),
            ProductFixtures::variable_ewheel_product(), // Has 2 variants
        ];

        $results = $transformer->transform_batch( $products );

        // 1 simple + 2 expanded variants = 3 products
        $this->assertCount( 3, $results );
        $this->assertEquals( 'SIMPLE-1', $results[0]['sku'] );
        $this->assertEquals( 'SCOOTER-V-BLACK', $results[1]['sku'] );
        $this->assertEquals( 'SCOOTER-V-WHITE', $results[2]['sku'] );
    }

    /**
     * Helper to check if string contains substring.
     *
     * @param string $needle   The substring to search for.
     * @param string $haystack The string to search in.
     * @return void
     */
    private function assertStringContains( string $needle, string $haystack ): void {
        $this->assertTrue(
            strpos( $haystack, $needle ) !== false,
            "Failed asserting that '$haystack' contains '$needle'"
        );
    }
}
