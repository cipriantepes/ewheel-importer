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
        // Preserve existing global taxonomy attributes (pa_*) that were added
        // outside the sync (e.g., pa_tip-componenta, pa_tensiune-v from SEO
        // extraction). The ewheel API only sends product-level custom attributes,
        // so taxonomy attributes would be wiped without this merge.
        $existing = $product->get_attributes();
        $preserved = [];
        foreach ($existing as $key => $attr) {
            if ($attr->is_taxonomy()) {
                $preserved[$key] = $attr;
            }
        }

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

            $wc_attributes[$name] = $attribute;
        }

        // Merge: preserved taxonomy attrs + new API attrs (API wins on conflict)
        $product->set_attributes($preserved + $wc_attributes);
    }
}
