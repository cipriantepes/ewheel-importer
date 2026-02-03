<?php
/**
 * Product Transformer class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Pricing\PricingConverter;

/**
 * Transforms ewheel.es products to WooCommerce format.
 */
class ProductTransformer {

    /**
     * The translator.
     *
     * @var Translator
     */
    private Translator $translator;

    /**
     * The pricing converter.
     *
     * @var PricingConverter
     */
    private PricingConverter $pricing_converter;

    /**
     * Category mapping (ewheel reference => WooCommerce ID).
     *
     * @var array
     */
    private array $category_map;

    /**
     * Constructor.
     *
     * @param Translator       $translator        The translator instance.
     * @param PricingConverter $pricing_converter The pricing converter instance.
     * @param array            $category_map      Category mapping array.
     */
    public function __construct(
        Translator $translator,
        PricingConverter $pricing_converter,
        array $category_map = []
    ) {
        $this->translator        = $translator;
        $this->pricing_converter = $pricing_converter;
        $this->category_map      = $category_map;
    }

    /**
     * Transform an ewheel.es product to WooCommerce format.
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return array The WooCommerce product data.
     */
    public function transform( array $ewheel_product ): array {
        $has_variants = ! empty( $ewheel_product['Variants'] );
        $product_type = $has_variants ? 'variable' : 'simple';

        $woo_product = [
            'name'          => $this->translate_field( $ewheel_product['Name'] ?? [] ),
            'description'   => $this->translate_field( $ewheel_product['Description'] ?? [] ),
            'short_description' => $this->get_short_description( $ewheel_product ),
            'sku'           => $ewheel_product['Reference'] ?? '',
            'regular_price' => $this->convert_price( $ewheel_product['RRP'] ?? 0 ),
            'status'        => ( $ewheel_product['Active'] ?? false ) ? 'publish' : 'draft',
            'type'          => $product_type,
            'manage_stock'  => false,
            'images'        => $this->transform_images( $ewheel_product['Images'] ?? [] ),
            'categories'    => $this->transform_categories( $ewheel_product['Categories'] ?? [] ),
            'attributes'    => $this->transform_attributes( $ewheel_product['Attributes'] ?? [] ),
            'meta_data'     => $this->get_meta_data( $ewheel_product ),
        ];

        // Add variations for variable products
        if ( $has_variants ) {
            $woo_product['variations'] = $this->transform_variations( $ewheel_product['Variants'] );
            $woo_product['attributes'] = $this->get_variation_attributes( $ewheel_product );
        }

        return $woo_product;
    }

    /**
     * Transform multiple products at once.
     *
     * @param array $products Array of ewheel.es products.
     * @return array Array of WooCommerce products.
     */
    public function transform_batch( array $products ): array {
        return array_map( [ $this, 'transform' ], $products );
    }

    /**
     * Translate a multilingual field.
     *
     * @param array|string $field The field value (array with language keys or string).
     * @return string The translated text.
     */
    private function translate_field( $field ): string {
        if ( is_string( $field ) ) {
            return $field;
        }

        if ( is_array( $field ) && ! empty( $field ) ) {
            return $this->translator->translate_multilingual( $field );
        }

        return '';
    }

    /**
     * Convert price using the pricing converter.
     *
     * @param float|int $price The price in source currency.
     * @return string The converted price as string.
     */
    private function convert_price( $price ): string {
        $price = (float) $price;
        if ( $price <= 0 ) {
            return '0';
        }

        $converted = $this->pricing_converter->convert( $price );
        return $this->pricing_converter->format_price( $converted );
    }

    /**
     * Transform images to WooCommerce format.
     *
     * @param array $images Array of image URLs.
     * @return array WooCommerce images array.
     */
    private function transform_images( array $images ): array {
        $woo_images = [];

        foreach ( $images as $position => $url ) {
            if ( ! empty( $url ) ) {
                $woo_images[] = [
                    'src'      => $url,
                    'position' => $position,
                ];
            }
        }

        return $woo_images;
    }

    /**
     * Transform categories using the category map.
     *
     * @param array $category_refs Array of category references.
     * @return array WooCommerce categories array.
     */
    private function transform_categories( array $category_refs ): array {
        $woo_categories = [];

        foreach ( $category_refs as $ref ) {
            if ( isset( $this->category_map[ $ref ] ) ) {
                $woo_categories[] = [
                    'id' => $this->category_map[ $ref ],
                ];
            }
        }

        return $woo_categories;
    }

    /**
     * Transform attributes to WooCommerce format.
     *
     * @param array $attributes Array of attributes.
     * @return array WooCommerce attributes array.
     */
    private function transform_attributes( array $attributes ): array {
        $woo_attributes = [];

        foreach ( $attributes as $name => $value ) {
            if ( empty( $value ) ) {
                continue;
            }

            $woo_attributes[] = [
                'name'      => $this->format_attribute_name( $name ),
                'options'   => [ $value ],
                'visible'   => true,
                'variation' => false,
            ];
        }

        return $woo_attributes;
    }

    /**
     * Get variation attributes from a product with variants.
     *
     * @param array $ewheel_product The ewheel.es product.
     * @return array WooCommerce attributes for variations.
     */
    private function get_variation_attributes( array $ewheel_product ): array {
        $variants = $ewheel_product['Variants'] ?? [];
        $attribute_values = [];

        // Collect all unique attribute values from variants
        foreach ( $variants as $variant ) {
            $attrs = $variant['Attributes'] ?? [];
            foreach ( $attrs as $name => $value ) {
                if ( ! isset( $attribute_values[ $name ] ) ) {
                    $attribute_values[ $name ] = [];
                }
                if ( ! in_array( $value, $attribute_values[ $name ], true ) ) {
                    $attribute_values[ $name ][] = $value;
                }
            }
        }

        // Convert to WooCommerce format
        $woo_attributes = [];
        foreach ( $attribute_values as $name => $values ) {
            $woo_attributes[] = [
                'name'      => $this->format_attribute_name( $name ),
                'options'   => $values,
                'visible'   => true,
                'variation' => true,
            ];
        }

        return $woo_attributes;
    }

    /**
     * Transform variations to WooCommerce format.
     *
     * @param array $variants Array of ewheel.es variants.
     * @return array WooCommerce variations array.
     */
    private function transform_variations( array $variants ): array {
        $woo_variations = [];

        foreach ( $variants as $variant ) {
            $variation = [
                'sku'           => $variant['Reference'] ?? '',
                'regular_price' => $this->convert_price( $variant['net'] ?? 0 ),
                'attributes'    => [],
            ];

            // Add variant attributes
            $attrs = $variant['Attributes'] ?? [];
            foreach ( $attrs as $name => $value ) {
                $variation['attributes'][] = [
                    'name'   => $this->format_attribute_name( $name ),
                    'option' => $value,
                ];
            }

            $woo_variations[] = $variation;
        }

        return $woo_variations;
    }

    /**
     * Format attribute name for display.
     *
     * @param string $name The raw attribute name.
     * @return string The formatted name.
     */
    private function format_attribute_name( string $name ): string {
        // Convert snake_case to Title Case
        $name = str_replace( [ '_', '-' ], ' ', $name );
        return ucwords( $name );
    }

    /**
     * Get short description from product.
     *
     * @param array $ewheel_product The ewheel.es product.
     * @return string The short description.
     */
    private function get_short_description( array $ewheel_product ): string {
        // If there's a specific short description field, use it
        if ( isset( $ewheel_product['ShortDescription'] ) ) {
            return $this->translate_field( $ewheel_product['ShortDescription'] );
        }

        // Otherwise, truncate the main description
        $description = $this->translate_field( $ewheel_product['Description'] ?? [] );
        if ( strlen( $description ) > 200 ) {
            return substr( $description, 0, 197 ) . '...';
        }

        return $description;
    }

    /**
     * Get meta data for the product.
     *
     * @param array $ewheel_product The ewheel.es product.
     * @return array Meta data array.
     */
    private function get_meta_data( array $ewheel_product ): array {
        return [
            [
                'key'   => '_ewheel_id',
                'value' => (string) ( $ewheel_product['Id'] ?? '' ),
            ],
            [
                'key'   => '_ewheel_reference',
                'value' => $ewheel_product['Reference'] ?? '',
            ],
            [
                'key'   => '_ewheel_last_sync',
                'value' => gmdate( 'Y-m-d\TH:i:s' ),
            ],
        ];
    }

    /**
     * Set the category map.
     *
     * @param array $category_map The category mapping.
     * @return void
     */
    public function set_category_map( array $category_map ): void {
        $this->category_map = $category_map;
    }
}
