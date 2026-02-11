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
     * Get a single-variant product with garbage attributes (mimics real API data).
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function single_variant_product_with_garbage_attrs( array $overrides = [] ): array {
        return array_merge(
            [
                'Id'          => 200,
                'Reference'   => 'VEST-PARENT',
                'Name'        => [ 'en' => 'Reflective Vest' ],
                'Description' => [ 'en' => 'Safety vest' ],
                'RRP'         => 19.99,
                'Currency'    => 'EUR',
                'Active'      => true,
                'Images'      => [],
                'Categories'  => [],
                'Attributes'  => [],
                'Variants'    => [
                    [
                        'Id'         => 'V001',
                        'Reference'  => 'VEST-001',
                        'net'        => 19.99,
                        'Attributes' => [
                            'estatus'            => 'M',
                            'update-date'        => '10/02/2026 20:45:50',
                            'ficha-tecnica'      => '{"STATUS":"M","ID":"abc123","FILE":"http://example.com/sheet.pdf"}',
                            'codigo-familia'     => '1003',
                            'peso'               => '0.178',
                            'codigo-alternativo' => '7427246040394',
                            'talla'              => 'L',
                            'color'              => 'Yellow',
                        ],
                    ],
                ],
            ],
            $overrides
        );
    }

    /**
     * Get a multi-variant product with mixed visible and meta attributes.
     *
     * @param array $overrides Optional field overrides.
     * @return array
     */
    public static function multi_variant_with_mixed_attrs( array $overrides = [] ): array {
        return array_merge(
            [
                'Id'          => 300,
                'Reference'   => 'TIRE-PARENT',
                'Name'        => [ 'en' => 'Scooter Tire' ],
                'Description' => [ 'en' => 'Replacement tire' ],
                'RRP'         => 29.99,
                'Currency'    => 'EUR',
                'Active'      => true,
                'Images'      => [],
                'Categories'  => [],
                'Attributes'  => [],
                'Variants'    => [
                    [
                        'Id'         => 'T001',
                        'Reference'  => 'TIRE-10INCH',
                        'net'        => 29.99,
                        'Attributes' => [
                            'medida'             => '10 inch',
                            'estatus'            => 'M',
                            'peso'               => '0.5',
                            'codigo-alternativo' => '1234567890123',
                        ],
                    ],
                    [
                        'Id'         => 'T002',
                        'Reference'  => 'TIRE-8INCH',
                        'net'        => 24.99,
                        'Attributes' => [
                            'medida'             => '8 inch',
                            'estatus'            => 'A',
                            'peso'               => '0.4',
                            'codigo-alternativo' => '9876543210987',
                        ],
                    ],
                ],
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
