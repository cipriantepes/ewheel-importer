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

            if (isset($variation_data['sku'])) {
                $variation->set_sku($variation_data['sku']);
            }

            if (isset($variation_data['regular_price'])) {
                $variation->set_regular_price($variation_data['regular_price']);
            }

            // Set variation attributes
            if (!empty($variation_data['attributes'])) {
                $attrs = [];
                foreach ($variation_data['attributes'] as $attr) {
                    $slug = sanitize_title($attr['name'] ?? '');
                    $attrs[$slug] = $attr['option'] ?? '';
                }
                $variation->set_attributes($attrs);
            }

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
                    if (isset($variation_data['regular_price'])) {
                        $variation->set_regular_price($variation_data['regular_price']);
                    }
                    $variation->save();
                }
                unset($existing_by_sku[$sku]);
            } else {
                // Create new variation
                $variation = new \WC_Product_Variation();
                $variation->set_parent_id($product_id);

                if (isset($variation_data['sku'])) {
                    $variation->set_sku($variation_data['sku']);
                }

                if (isset($variation_data['regular_price'])) {
                    $variation->set_regular_price($variation_data['regular_price']);
                }

                if (!empty($variation_data['attributes'])) {
                    $attrs = [];
                    foreach ($variation_data['attributes'] as $attr) {
                        $slug = sanitize_title($attr['name'] ?? '');
                        $attrs[$slug] = $attr['option'] ?? '';
                    }
                    $variation->set_attributes($attrs);
                }

                $variation->save();
            }
        }

        // Note: We don't delete variations that are no longer in the feed
        // to avoid data loss. They can be manually removed.
    }
}
