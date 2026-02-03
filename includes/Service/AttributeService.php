<?php
/**
 * Attribute Service class.
 *
 * @package Trotibike\EwheelImporter\Service
 */

namespace Trotibike\EwheelImporter\Service;

/**
 * Handles WooCommerce product attributes.
 */
class AttributeService
{
    /**
     * Set product attributes.
     *
     * @param \WC_Product $product    The product.
     * @param array       $attributes Array of attribute data.
     * @return void
     */
    public function set_product_attributes(\WC_Product $product, array $attributes): void
    {
        $wc_attributes = [];

        foreach ($attributes as $attr) {
            $name = $attr['name'] ?? '';
            $options = $attr['options'] ?? [];

            if (empty($name) || empty($options)) {
                continue;
            }

            $attribute = new \WC_Product_Attribute();
            $attribute->set_name($name);
            $attribute->set_options($options);
            $attribute->set_visible($attr['visible'] ?? true);
            $attribute->set_variation($attr['variation'] ?? false);

            $wc_attributes[] = $attribute;
        }

        $product->set_attributes($wc_attributes);
    }
}
