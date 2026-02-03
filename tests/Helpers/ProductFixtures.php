<?php
/**
 * Product Test Fixtures.
 *
 * @package Trotibike\EwheelImporter\Tests
 */

namespace Trotibike\EwheelImporter\Tests\Helpers;

/**
 * Provides sample product data for tests.
 *
 * DRY: Centralizes test data to avoid repetition.
 */
class ProductFixtures {

    /**
     * Get a simple ewheel product.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function simple_ewheel_product( array $overrides = [] ): array {
        return array_merge(
            [
                'Id'          => 123,
                'Reference'   => 'SCOOTER-X1',
                'Name'        => [ 'en' => 'Electric Scooter X1' ],
                'Description' => [ 'en' => 'High performance electric scooter' ],
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
            $overrides
        );
    }

    /**
     * Get a variable ewheel product with variants.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function variable_ewheel_product( array $overrides = [] ): array {
        return array_merge(
            [
                'Id'          => 125,
                'Reference'   => 'SCOOTER-V',
                'Name'        => [ 'en' => 'Variable Scooter' ],
                'Description' => [ 'en' => 'Scooter with color options' ],
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
            ],
            $overrides
        );
    }

    /**
     * Get a minimal ewheel product.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function minimal_ewheel_product( array $overrides = [] ): array {
        return array_merge(
            [
                'Id'        => 999,
                'Reference' => 'MINIMAL-001',
                'Name'      => [],
                'RRP'       => 0,
                'Active'    => true,
            ],
            $overrides
        );
    }

    /**
     * Get an inactive ewheel product.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function inactive_ewheel_product( array $overrides = [] ): array {
        return self::simple_ewheel_product(
            array_merge(
                [
                    'Id'        => 124,
                    'Reference' => 'INACTIVE-001',
                    'Active'    => false,
                ],
                $overrides
            )
        );
    }

    /**
     * Get a list of ewheel products.
     *
     * @param int $count Number of products.
     * @return array
     */
    public static function product_list( int $count = 5 ): array {
        $products = [];

        for ( $i = 1; $i <= $count; $i++ ) {
            $products[] = self::simple_ewheel_product(
                [
                    'Id'        => $i,
                    'Reference' => "PROD-{$i}",
                    'Name'      => [ 'en' => "Product {$i}" ],
                    'RRP'       => 100 * $i,
                ]
            );
        }

        return $products;
    }

    /**
     * Get a simple WooCommerce-formatted product.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function simple_woo_product( array $overrides = [] ): array {
        return array_merge(
            [
                'name'              => 'Translated Product',
                'description'       => 'Translated description',
                'short_description' => 'Short desc',
                'sku'               => 'SCOOTER-X1',
                'regular_price'     => '2999.95',
                'status'            => 'publish',
                'type'              => 'simple',
                'manage_stock'      => false,
                'images'            => [
                    [ 'src' => 'https://example.com/img1.jpg', 'position' => 0 ],
                ],
                'categories'        => [],
                'attributes'        => [
                    [
                        'name'      => 'Weight',
                        'options'   => [ '15kg' ],
                        'visible'   => true,
                        'variation' => false,
                    ],
                ],
                'meta_data'         => [
                    [ 'key' => '_ewheel_id', 'value' => '123' ],
                    [ 'key' => '_ewheel_reference', 'value' => 'SCOOTER-X1' ],
                ],
            ],
            $overrides
        );
    }

    /**
     * Get ewheel category data.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function ewheel_category( array $overrides = [] ): array {
        return array_merge(
            [
                'reference'       => 'CAT001',
                'name'            => 'Electric Scooters',
                'parentReference' => null,
            ],
            $overrides
        );
    }

    /**
     * Get a list of ewheel categories.
     *
     * @return array
     */
    public static function category_list(): array {
        return [
            self::ewheel_category(),
            self::ewheel_category(
                [
                    'reference'       => 'CAT002',
                    'name'            => 'Accessories',
                    'parentReference' => 'CAT001',
                ]
            ),
        ];
    }
}
