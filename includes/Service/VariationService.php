<?php
/**
 * Variation Service class.
 *
 * @package Trotibike\EwheelImporter\Service
 */

namespace Trotibike\EwheelImporter\Service;

/**
 * Handles WooCommerce product variations.
 */
class VariationService
{
    /**
     * Image service instance.
     *
     * @var ImageService
     */
    private ImageService $image_service;

    /**
     * Constructor.
     *
     * @param ImageService $image_service Image service.
     */
    public function __construct(ImageService $image_service)
    {
        $this->image_service = $image_service;
    }

    /**
     * Create variations for a variable product.
     *
     * @param int   $product_id The parent product ID.
     * @param array $variations Array of variation data.
     * @param array $attributes The product attributes.
     * @return void
     */
    public function create_variations(int $product_id, array $variations, array $attributes): void
    {
        foreach ($variations as $variation_data) {
            $variation = new \WC_Product_Variation();
            $variation->set_parent_id($product_id);

            $this->set_variation_data($variation, $variation_data);

            $variation->save();
        }
    }

    /**
     * Update variations for a variable product.
     *
     * @param int   $product_id The parent product ID.
     * @param array $variations Array of variation data.
     * @param array $attributes The product attributes.
     * @return void
     */
    public function update_variations(int $product_id, array $variations, array $attributes): void
    {
        // Get existing variations
        $product = wc_get_product($product_id);

        if (!$product) {
            return;
        }

        $existing_variation_ids = $product->get_children();

        // Map existing variations by SKU
        $existing_by_sku = [];
        foreach ($existing_variation_ids as $var_id) {
            $var = wc_get_product($var_id);
            if ($var) {
                $existing_by_sku[$var->get_sku()] = $var_id;
            }
        }

        // Update or create variations
        foreach ($variations as $variation_data) {
            $sku = $variation_data['sku'] ?? '';

            if (isset($existing_by_sku[$sku])) {
                // Update existing
                $variation = wc_get_product($existing_by_sku[$sku]);
                if ($variation) {
                    $this->set_variation_data($variation, $variation_data);
                    $variation->save();
                }
                unset($existing_by_sku[$sku]);
            } else {
                // Create new variation
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product_id);
                $this->set_variation_data($variation, $variation_data);
                $variation->save();
            }
        }

        // Note: We don't delete variations that are no longer in the feed
        // to avoid data loss. They can be manually removed.
    }

    /**
     * Set data on a variation object.
     *
     * @param \WC_Product_Variation $variation The variation object.
     * @param array                 $data      The variation data.
     * @return void
     */
    private function set_variation_data(\WC_Product_Variation $variation, array $data): void
    {
        if (isset($data['sku'])) {
            $variation->set_sku($data['sku']);
        }

        if (isset($data['regular_price'])) {
            $variation->set_regular_price($data['regular_price']);
            $variation->set_price($data['regular_price']);
        }

        if (isset($data['sale_price'])) {
            $variation->set_sale_price($data['sale_price']);
            $variation->set_price($data['sale_price']); // WC display price = sale price when on sale
        } else {
            $variation->set_sale_price(''); // Clear sale price if not on sale
        }

        // Stock management
        if (isset($data['manage_stock'])) {
            $variation->set_manage_stock($data['manage_stock']);
        } else {
            $variation->set_manage_stock(true); // Default to managed
        }

        if (isset($data['stock_quantity'])) {
            $variation->set_stock_quantity($data['stock_quantity']);
            $variation->set_stock_status($data['stock_quantity'] > 0 ? 'instock' : 'outofstock');
        } elseif (isset($data['stock_status'])) {
            $variation->set_stock_status($data['stock_status']);
        }

        // Dimensions and Weight
        if (isset($data['weight'])) {
            $variation->set_weight($data['weight']);
        }
        if (isset($data['length'])) {
            $variation->set_length($data['length']);
        }
        if (isset($data['width'])) {
            $variation->set_width($data['width']);
        }
        if (isset($data['height'])) {
            $variation->set_height($data['height']);
        }

        // Image (with SEO metadata)
        if (!empty($data['image'])) {
            $image_id = $this->image_service->import_from_url($data['image'], [
                'alt_text' => $variation->get_name(),
                'title'    => $variation->get_name(),
            ]);
            if ($image_id) {
                $variation->set_image_id($image_id);
            }
        }

        // Attributes
        if (!empty($data['attributes'])) {
            $attrs = [];
            foreach ($data['attributes'] as $attr) {
                $slug = sanitize_title($attr['name'] ?? '');
                $attrs[$slug] = $attr['option'] ?? '';
            }
            $variation->set_attributes($attrs);
        }
    }
}
