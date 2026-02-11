<?php
/**
 * Product Repository.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Repository;

use Trotibike\EwheelImporter\Service\ImageService;

/**
 * Repository for WooCommerce products.
 *
 * Single Responsibility: Only handles product data access.
 * Dependency Inversion: Depends on ImageService abstraction.
 */
class ProductRepository implements RepositoryInterface {

    /**
     * Meta key for ewheel reference.
     */
    private const EWHEEL_REF_META = '_ewheel_reference';

    /**
     * Meta key for ewheel ID.
     */
    private const EWHEEL_ID_META = '_ewheel_id';

    /**
     * The image service.
     *
     * @var ImageService
     */
    private ImageService $image_service;

    /**
     * Constructor.
     *
     * @param ImageService $image_service Image handling service.
     */
    public function __construct( ImageService $image_service ) {
        $this->image_service = $image_service;
    }

    /**
     * Find product by ID.
     *
     * @param int $id Product ID.
     * @return \WC_Product|null
     */
    public function find( int $id ): ?\WC_Product {
        $product = wc_get_product( $id );
        return $product instanceof \WC_Product ? $product : null;
    }

    /**
     * Find product by SKU (reference).
     *
     * @param string $reference Product SKU.
     * @return \WC_Product|null
     */
    public function find_by_reference( string $reference ): ?\WC_Product {
        $product_id = wc_get_product_id_by_sku( $reference );
        return $product_id ? $this->find( $product_id ) : null;
    }

    /**
     * Find product by ewheel reference meta.
     *
     * @param string $ewheel_ref The ewheel reference.
     * @return \WC_Product|null
     */
    public function find_by_ewheel_reference( string $ewheel_ref ): ?\WC_Product {
        $products = wc_get_products(
            [
                'meta_key'   => self::EWHEEL_REF_META,
                'meta_value' => $ewheel_ref,
                'limit'      => 1,
            ]
        );

        return ! empty( $products ) ? $products[0] : null;
    }

    /**
     * Save a product.
     *
     * @param array $data Product data.
     * @return int Product ID.
     */
    public function save( array $data ): int {
        $sku             = $data['sku'] ?? '';
        $existing_product = $this->find_by_reference( $sku );

        if ( $existing_product ) {
            return $this->update( $existing_product, $data );
        }

        return $this->create( $data );
    }

    /**
     * Create a new product.
     *
     * @param array $data Product data.
     * @return int Product ID.
     */
    private function create( array $data ): int {
        $product = $this->create_product_instance( $data['type'] ?? 'simple' );
        $this->populate_product( $product, $data );

        $product_id = $product->save();

        if ( ( $data['type'] ?? 'simple' ) === 'variable' && ! empty( $data['variations'] ) ) {
            $this->save_variations( $product_id, $data['variations'] );
        }

        return $product_id;
    }

    /**
     * Update an existing product.
     *
     * @param \WC_Product $product Existing product.
     * @param array       $data    New data.
     * @return int Product ID.
     */
    private function update( \WC_Product $product, array $data ): int {
        $this->populate_product( $product, $data );
        $product_id = $product->save();

        if ( $product instanceof \WC_Product_Variable && ! empty( $data['variations'] ) ) {
            $this->update_variations( $product_id, $data['variations'] );
        }

        return $product_id;
    }

    /**
     * Create product instance based on type.
     *
     * @param string $type Product type.
     * @return \WC_Product
     */
    private function create_product_instance( string $type ): \WC_Product {
        return $type === 'variable'
            ? new \WC_Product_Variable()
            : new \WC_Product_Simple();
    }

    /**
     * Populate product with data.
     *
     * @param \WC_Product $product Product instance.
     * @param array       $data    Product data.
     * @return void
     */
    private function populate_product( \WC_Product $product, array $data ): void {
        $this->set_basic_data( $product, $data );
        $this->set_categories( $product, $data['categories'] ?? [] );
        $this->set_images( $product, $data['images'] ?? [] );
        $this->set_attributes( $product, $data['attributes'] ?? [] );
        $this->set_meta_data( $product, $data['meta_data'] ?? [] );
    }

    /**
     * Set basic product data.
     *
     * @param \WC_Product $product Product instance.
     * @param array       $data    Product data.
     * @return void
     */
    private function set_basic_data( \WC_Product $product, array $data ): void {
        $setters = [
            'name'              => 'set_name',
            'description'       => 'set_description',
            'short_description' => 'set_short_description',
            'sku'               => 'set_sku',
            'regular_price'     => 'set_regular_price',
            'status'            => 'set_status',
            'manage_stock'      => 'set_manage_stock',
        ];

        foreach ( $setters as $key => $method ) {
            if ( isset( $data[ $key ] ) ) {
                $product->$method( $data[ $key ] );
            }
        }
    }

    /**
     * Set product categories.
     *
     * @param \WC_Product $product    Product instance.
     * @param array       $categories Categories data.
     * @return void
     */
    private function set_categories( \WC_Product $product, array $categories ): void {
        if ( ! empty( $categories ) ) {
            $ids = array_column( $categories, 'id' );
            $product->set_category_ids( array_filter( $ids ) );
        }
    }

    /**
     * Set product images.
     *
     * @param \WC_Product $product Product instance.
     * @param array       $images  Images data.
     * @return void
     */
    private function set_images( \WC_Product $product, array $images ): void {
        if ( empty( $images ) ) {
            return;
        }

        $image_ids = [];
        foreach ( $images as $image ) {
            $url = $image['src'] ?? '';
            if ( empty( $url ) ) {
                continue;
            }

            $attachment_id = $this->image_service->import_from_url( $url, [
                'alt_text' => $product->get_name(),
                'title'    => $product->get_name(),
            ] );
            if ( $attachment_id ) {
                $image_ids[] = $attachment_id;
            }
        }

        if ( ! empty( $image_ids ) ) {
            $product->set_image_id( $image_ids[0] );
            if ( count( $image_ids ) > 1 ) {
                $product->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
            }
        }
    }

    /**
     * Set product attributes.
     *
     * @param \WC_Product $product    Product instance.
     * @param array       $attributes Attributes data.
     * @return void
     */
    private function set_attributes( \WC_Product $product, array $attributes ): void {
        if ( empty( $attributes ) ) {
            return;
        }

        $wc_attributes = [];
        foreach ( $attributes as $attr ) {
            $name    = $attr['name'] ?? '';
            $options = $attr['options'] ?? [];

            if ( empty( $name ) || empty( $options ) ) {
                continue;
            }

            $attribute = new \WC_Product_Attribute();
            $attribute->set_name( $name );
            $attribute->set_options( $options );
            $attribute->set_visible( $attr['visible'] ?? true );
            $attribute->set_variation( $attr['variation'] ?? false );

            $wc_attributes[] = $attribute;
        }

        $product->set_attributes( $wc_attributes );
    }

    /**
     * Set product meta data.
     *
     * @param \WC_Product $product Product instance.
     * @param array       $meta    Meta data.
     * @return void
     */
    private function set_meta_data( \WC_Product $product, array $meta ): void {
        foreach ( $meta as $item ) {
            $product->update_meta_data( $item['key'], $item['value'] );
        }
    }

    /**
     * Save product variations.
     *
     * @param int   $product_id Product ID.
     * @param array $variations Variations data.
     * @return void
     */
    private function save_variations( int $product_id, array $variations ): void {
        foreach ( $variations as $var_data ) {
            $this->create_variation( $product_id, $var_data );
        }
    }

    /**
     * Update product variations.
     *
     * @param int   $product_id Product ID.
     * @param array $variations Variations data.
     * @return void
     */
    private function update_variations( int $product_id, array $variations ): void {
        $existing = $this->get_variations_by_sku( $product_id );

        foreach ( $variations as $var_data ) {
            $sku = $var_data['sku'] ?? '';
            if ( isset( $existing[ $sku ] ) ) {
                $this->update_variation( $existing[ $sku ], $var_data );
                unset( $existing[ $sku ] );
            } else {
                $this->create_variation( $product_id, $var_data );
            }
        }
    }

    /**
     * Get existing variations indexed by SKU.
     *
     * @param int $product_id Product ID.
     * @return array<string, int>
     */
    private function get_variations_by_sku( int $product_id ): array {
        $product  = wc_get_product( $product_id );
        $existing = [];

        if ( $product instanceof \WC_Product_Variable ) {
            foreach ( $product->get_children() as $var_id ) {
                $variation = wc_get_product( $var_id );
                if ( $variation ) {
                    $existing[ $variation->get_sku() ] = $var_id;
                }
            }
        }

        return $existing;
    }

    /**
     * Create a product variation.
     *
     * @param int   $product_id Product ID.
     * @param array $data       Variation data.
     * @return int Variation ID.
     */
    private function create_variation( int $product_id, array $data ): int {
        $variation = new \WC_Product_Variation();
        $variation->set_parent_id( $product_id );
        $this->populate_variation( $variation, $data );
        return $variation->save();
    }

    /**
     * Update a product variation.
     *
     * @param int   $variation_id Variation ID.
     * @param array $data         Variation data.
     * @return void
     */
    private function update_variation( int $variation_id, array $data ): void {
        $variation = wc_get_product( $variation_id );
        if ( $variation instanceof \WC_Product_Variation ) {
            $this->populate_variation( $variation, $data );
            $variation->save();
        }
    }

    /**
     * Populate variation with data.
     *
     * @param \WC_Product_Variation $variation Variation instance.
     * @param array                 $data      Variation data.
     * @return void
     */
    private function populate_variation( \WC_Product_Variation $variation, array $data ): void {
        if ( isset( $data['sku'] ) ) {
            $variation->set_sku( $data['sku'] );
        }

        if ( isset( $data['regular_price'] ) ) {
            $variation->set_regular_price( $data['regular_price'] );
        }

        if ( ! empty( $data['attributes'] ) ) {
            $attrs = [];
            foreach ( $data['attributes'] as $attr ) {
                $slug           = sanitize_title( $attr['name'] ?? '' );
                $attrs[ $slug ] = $attr['option'] ?? '';
            }
            $variation->set_attributes( $attrs );
        }
    }

    /**
     * Delete a product.
     *
     * @param int $id Product ID.
     * @return bool
     */
    public function delete( int $id ): bool {
        $product = $this->find( $id );
        if ( $product ) {
            return $product->delete( true );
        }
        return false;
    }
}
