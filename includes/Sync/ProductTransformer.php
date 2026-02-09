<?php
/**
 * Product Transformer class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Config\AttributeConfiguration;
use Trotibike\EwheelImporter\Log\PersistentLogger;

/**
 * Transforms ewheel.es products to WooCommerce format.
 */
class ProductTransformer
{

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
    /**
     * Category mapping (ewheel reference => WooCommerce ID).
     *
     * @var array
     */
    private array $category_map;

    /**
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

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
        Configuration $config,
        array $category_map = []
    ) {
        $this->translator = $translator;
        $this->pricing_converter = $pricing_converter;
        $this->config = $config;
        $this->category_map = $category_map;
    }

    /**
     * Transform an ewheel.es product to WooCommerce format.
     *
     * Returns an array of products. For variable mode with variants, returns one variable product.
     * For simple mode with variants, returns multiple simple products (one per variant).
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return array Array of WooCommerce product data arrays.
     */
    public function transform(array $ewheel_product): array
    {
        try {
            // Handle case sensitivity (API returns lowercase, code might expect PascalCase)
            $p = array_change_key_case($ewheel_product, CASE_LOWER);

            $ref = $p['reference'] ?? ($ewheel_product['Reference'] ?? 'unknown');
            PersistentLogger::info("ProductTransformer::transform - reference: {$ref}");

            $has_variants = !empty($p['variants']);
            $use_variable_mode = $this->config->is_variable_product_mode();

            $mode = $has_variants ? ($use_variable_mode ? 'variable' : 'simple-expanded') : 'simple';
            PersistentLogger::info("Transform mode: {$mode}");

            // If simple mode and has variants, expand to multiple simple products
            if ($has_variants && !$use_variable_mode) {
                return $this->transform_to_simple_products($ewheel_product);
            }

            // Standard transformation (single product)
            $product_type = $has_variants ? 'variable' : 'simple';

            // Detect if top-level data is missing but variants exist (container structure)
            if (empty($p['reference']) && empty($p['name']) && !empty($p['variants'])) {
                $first_variant = array_change_key_case($p['variants'][0], CASE_LOWER);

                // Fallback for Name
                if (empty($p['name'])) {
                    $p['name'] = $first_variant['name'] ?? [];
                }

                // Fallback for Description
                if (empty($p['description'])) {
                    $p['description'] = $first_variant['description'] ?? [];
                }

                // Fallback for Images
                if (empty($p['images'])) {
                    $p['images'] = $first_variant['images'] ?? [];
                }

                // Fallback for Attributes
                if (empty($p['attributes'])) {
                    $p['attributes'] = $first_variant['attributes'] ?? [];
                }

                // Fallback for Reference (SKU) to ensure parent has an ID
                if (empty($p['reference'])) {
                    // Use first variant's reference but maybe prefix or use as base
                    $p['reference'] = $first_variant['reference'] ?? '';
                }

                // Fallback for RRP (price) from first variant's net price
                if (empty($p['rrp'])) {
                    $p['rrp'] = $first_variant['net'] ?? ($first_variant['rrp'] ?? 0);
                }
            }

            $sync_fields = $this->config->get_sync_fields();

            // Variable product parents don't need a SKU — variations carry the real SKUs.
            // Simple products use the clean reference (without -parent suffix).
            $raw_ref = $p['reference'] ?? ($ewheel_product['Reference'] ?? '');
            $sku = ($product_type === 'variable') ? '' : $this->clean_sku($raw_ref);

            $woo_product = [
                'sku' => $sku,
                'status' => ($p['active'] ?? ($ewheel_product['Active'] ?? false)) ? 'publish' : 'draft',
                'type' => $product_type,
                'manage_stock' => false,
                'stock_status' => 'instock', // Required when manage_stock is false
                'meta_data' => $this->get_meta_data($ewheel_product),
            ];

            $name_val = $this->get_mapped_value($p, 'name', 'name');
            PersistentLogger::info("name_val type: " . gettype($name_val) . ", value: " . (is_array($name_val) ? json_encode($name_val) : substr((string) $name_val, 0, 100)));

            if ($name_val !== null) {
                $woo_product['name'] = $this->translate_field($name_val);
                PersistentLogger::info("Translated name: " . substr($woo_product['name'], 0, 100));
            }

            // Description: Use the translated NAME field as the product description
            // The API description field contains pipe-separated inventory data (EAN, UPC, dimensions)
            // which is NOT customer-facing content. The NAME field has the actual product description.
            $name_for_desc = $this->get_mapped_value($p, 'name', 'name');
            if ($name_for_desc !== null) {
                $woo_product['description'] = $this->translate_field($name_for_desc);
            }

            // Extract structured data from pipe-separated description
            // Returns: gtin, brand, dimensions, attributes, meta
            $pipe_data = $this->extract_pipe_attributes($ewheel_product);

            // Short Description: Build a clean specs table from visible attributes
            $specs_table = $this->build_specs_table($pipe_data);
            if (!empty($specs_table)) {
                $woo_product['short_description'] = $specs_table;
            }

            // Store internal data for WooCommerceSync to process
            // These keys are prefixed with _ to indicate internal use
            if (!empty($pipe_data['brand'])) {
                $woo_product['_brand'] = $pipe_data['brand'];
            }

            if (!empty($pipe_data['dimensions'])) {
                $woo_product['_dimensions'] = $pipe_data['dimensions'];
            }

            // GTIN and barcodes: only set on simple products, not variable parents
            // (variable product variations get their own GTIN via transform_variations)
            if ($product_type !== 'variable') {
                if (!empty($pipe_data['gtin']['ean'])) {
                    $woo_product['_gtin'] = $pipe_data['gtin']['ean'];
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_ean',
                        'value' => $pipe_data['gtin']['ean'],
                    ];
                }

                if (!empty($pipe_data['gtin']['upc'])) {
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_upc',
                        'value' => $pipe_data['gtin']['upc'],
                    ];
                }
            }

            $price_val = $this->get_mapped_value($p, 'price', 'rrp');
            if ($price_val !== null) {
                $woo_product['regular_price'] = $this->convert_price($price_val);
            }

            $images_val = $this->get_mapped_value($p, 'images', 'images');
            if ($images_val !== null) {
                $woo_product['images'] = $this->transform_images(is_array($images_val) ? $images_val : []);
            }

            $cats_val = $this->get_mapped_value($p, 'categories', 'categories');
            if ($cats_val !== null) {
                $woo_product['categories'] = $this->transform_categories(is_array($cats_val) ? $cats_val : []);
            }

            $attrs_val = $this->get_mapped_value($p, 'attributes', 'attributes');
            $api_attributes = [];
            if ($attrs_val !== null) {
                // Extract compatible model IDs before attribute filtering removes them
                $model_ids = $this->extract_model_ids(is_array($attrs_val) ? $attrs_val : []);
                if (!empty($model_ids)) {
                    $woo_product['_models'] = $model_ids;
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_compatible_models',
                        'value' => implode(',', $model_ids),
                    ];
                }

                $api_attributes = $this->transform_attributes_with_visibility(is_array($attrs_val) ? $attrs_val : []);
            }

            // Extract EAN/UPC from API attributes (codigo-alternativo, codigo-alternativo-2)
            // Only for simple products — variable parents skip barcodes (variations carry them)
            if ($product_type !== 'variable' && is_array($attrs_val)) {
                $barcode_data = $this->extract_barcode_from_attributes($attrs_val);
                if (!empty($barcode_data['ean']) && empty($woo_product['_gtin'])) {
                    $woo_product['_gtin'] = $barcode_data['ean'];
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_ean',
                        'value' => $barcode_data['ean'],
                    ];
                }
                if (!empty($barcode_data['upc']) && !$this->has_meta_key($woo_product, '_ewheel_upc')) {
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_upc',
                        'value' => $barcode_data['upc'],
                    ];
                }
            }

            // Merge pipe-extracted attributes (from description field) with API attributes
            $pipe_woo_attributes = $this->convert_pipe_attributes_to_woo($pipe_data);
            $woo_product['attributes'] = $this->merge_attributes($api_attributes, $pipe_woo_attributes);

            // Add variations for variable products
            if ($has_variants) {
                PersistentLogger::info("Processing " . count($p['variants']) . " variants");
                $woo_product['variations'] = $this->transform_variations($p['variants']);
                // For variable products, variation attributes take precedence
                $variation_attrs = $this->get_variation_attributes($ewheel_product);
                // Merge: variation attrs take precedence, pipe attrs fill gaps
                $woo_product['attributes'] = $this->merge_attributes($variation_attrs, $pipe_woo_attributes);
            }

            PersistentLogger::info("Transform output: 1 product, SKU: " . ($woo_product['sku'] ?? 'none') . ", name: " . substr($woo_product['name'] ?? 'no-name', 0, 50));

            return [$woo_product];
        } catch (\Throwable $e) {
            $ref = $ewheel_product['reference'] ?? ($ewheel_product['Reference'] ?? 'unknown');
            PersistentLogger::error("Transform EXCEPTION for {$ref}: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            return [];
        }
    }

    /**
     * Get mapped value from product data.
     *
     * @param array  $data              Product data (lowercase keys).
     * @param string $config_key        Configuration key (e.g. 'name').
     * @param string $default_source_key Default source key (e.g. 'name').
     * @return mixed|null Value or null if disabled.
     */
    private function get_mapped_value(array $data, string $config_key, string $default_source_key)
    {
        $map = $this->config->get_sync_fields()[$config_key] ?? $default_source_key;

        // Handle legacy/toggle values
        if ($map === true || $map === '1' || $map === 1 || $map === 'enabled') {
            $map = $default_source_key;
        }

        // Handle disabled/none
        if ($map === false || $map === '0' || $map === 0 || $map === '' || $map === 'none' || $map === 'disabled') {
            return null;
        }

        // Handle Custom Pattern
        if ($map === 'custom') {
            return $this->resolve_custom_pattern($data, $config_key);
        }

        // Special case for 'image' config key mapping to 'images' source
        if ($config_key === 'image' && $map === 'image') {
            $map = 'images';
        }

        return $data[$map] ?? ($data[ucfirst($map)] ?? null);
    }

    /**
     * Resolve custom pattern for a field.
     *
     * @param array  $data       Product data.
     * @param string $config_key Field key.
     * @return string The resolved string.
     */
    private function resolve_custom_pattern(array $data, string $config_key): string
    {
        $patterns = $this->config->get('custom_patterns') ?: [];
        $pattern = $patterns[$config_key] ?? '';

        if (empty($pattern)) {
            return '';
        }

        // supported tags: {name}, {reference}, {price}, {description}
        // we can add more dynamically based on keys in $data
        return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($matches) use ($data) {
            $key = strtolower($matches[1]);
            $val = $data[$key] ?? '';

            // If value is an array (like multilingual name), translate it first
            if (is_array($val)) {
                return $this->translate_field($val);
            }

            return (string) $val;
        }, $pattern);
    }

    /**
     * Transform a product with variants into multiple simple products.
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return array Array of simple WooCommerce product data arrays.
     */
    private function transform_to_simple_products(array $ewheel_product): array
    {
        $p = array_change_key_case($ewheel_product, CASE_LOWER);
        $variants = $p['variants'] ?? [];
        $sync_fields = $this->config->get_sync_fields();

        // Parent reference for linking related products
        $parent_reference = $p['reference'] ?? ($ewheel_product['Reference'] ?? '');
        $parent_name = $this->translate_field($p['name'] ?? ($ewheel_product['Name'] ?? []));

        $products = [];

        foreach ($variants as $variant) {
            $v = array_change_key_case($variant, CASE_LOWER);

            // Build variant-specific name (e.g., "Scooter X500 - Red")
            $variant_attrs = $this->extract_variant_attribute_values($v);
            $variant_suffix = !empty($variant_attrs) ? ' - ' . implode(', ', $variant_attrs) : '';

            $woo_product = [
                'sku' => $v['reference'] ?? ($variant['Reference'] ?? ''),
                'status' => ($p['active'] ?? ($ewheel_product['Active'] ?? false)) ? 'publish' : 'draft',
                'type' => 'simple',
                'manage_stock' => false,
                'stock_status' => 'instock', // Required when manage_stock is false
                'meta_data' => array_merge(
                    $this->get_meta_data($ewheel_product),
                    [
                        [
                            'key' => '_ewheel_product_group',
                            'value' => $parent_reference,
                        ],
                        [
                            'key' => '_ewheel_variant_id',
                            'value' => (string) ($v['id'] ?? ($variant['Id'] ?? '')),
                        ],
                    ]
                ),
            ];

            $name_val = $this->get_mapped_value($p, 'name', 'name');
            if ($name_val !== null) {
                // Determine source: if we are using default 'name', append suffix.
                // If mapping is 'reference', we probably still want suffix?
                // Let's append suffix regardless to ensure uniqueness?
                // Or maybe the user wants exact mapping.
                // Assuming standard behavior: base value + suffix.
                $base_name = $this->translate_field($name_val);
                $woo_product['name'] = $base_name . $variant_suffix;
            }

            // Description: Use the translated NAME field as the product description
            // (same logic as main transform() - API description is pipe-separated inventory data)
            $name_for_desc = $this->get_mapped_value($p, 'name', 'name');
            if ($name_for_desc !== null) {
                $woo_product['description'] = $this->translate_field($name_for_desc);
            }

            // Extract structured data from pipe-separated description
            $pipe_data = $this->extract_pipe_attributes($ewheel_product);

            // Short Description: Build specs table from pipe-separated data
            $specs_table = $this->build_specs_table($pipe_data);
            if (!empty($specs_table)) {
                $woo_product['short_description'] = $specs_table;
            }

            // Store internal data for WooCommerceSync to process
            if (!empty($pipe_data['brand'])) {
                $woo_product['_brand'] = $pipe_data['brand'];
            }

            if (!empty($pipe_data['dimensions'])) {
                $woo_product['_dimensions'] = $pipe_data['dimensions'];
            }

            if (!empty($pipe_data['gtin']['ean'])) {
                $woo_product['_gtin'] = $pipe_data['gtin']['ean'];
                $woo_product['meta_data'][] = [
                    'key' => '_ewheel_ean',
                    'value' => $pipe_data['gtin']['ean'],
                ];
            }

            if (!empty($pipe_data['gtin']['upc'])) {
                $woo_product['meta_data'][] = [
                    'key' => '_ewheel_upc',
                    'value' => $pipe_data['gtin']['upc'],
                ];
            }

            $net_price = $this->get_mapped_value($v, 'price', 'net');
            if ($net_price !== null) {
                $woo_product['regular_price'] = $this->convert_price($net_price);

                // Check for sale price (comparePrice)
                $compare_price = $v['compareprice'] ?? ($variant['ComparePrice'] ?? ($v['comparePrice'] ?? null));
                if ($compare_price !== null && (float) $compare_price > 0 && (float) $compare_price > (float) $net_price) {
                    $woo_product['regular_price'] = $this->convert_price($compare_price);
                    $woo_product['sale_price'] = $this->convert_price($net_price);
                }
            }

            $images_val = $this->get_mapped_value($p, 'image', 'images');
            if ($images_val !== null) {
                $woo_product['images'] = $this->transform_images(is_array($images_val) ? $images_val : []);
            }

            $cats_val = $this->get_mapped_value($p, 'categories', 'categories');
            if ($cats_val !== null) {
                $woo_product['categories'] = $this->transform_categories(is_array($cats_val) ? $cats_val : []);
            }

            $attrs_val = $this->get_mapped_value($p, 'attributes', 'attributes');
            $parent_attrs = [];
            if ($attrs_val !== null) {
                // Extract compatible model IDs before attribute filtering removes them
                $model_ids = $this->extract_model_ids(is_array($attrs_val) ? $attrs_val : []);
                if (!empty($model_ids)) {
                    $woo_product['_models'] = $model_ids;
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_compatible_models',
                        'value' => implode(',', $model_ids),
                    ];
                }

                $parent_attrs = $this->transform_attributes_with_visibility(is_array($attrs_val) ? $attrs_val : []);
            }

            // Extract EAN/UPC from API attributes (supplement pipe data)
            if (is_array($attrs_val)) {
                $barcode_data = $this->extract_barcode_from_attributes($attrs_val);
                if (!empty($barcode_data['ean']) && empty($woo_product['_gtin'])) {
                    $woo_product['_gtin'] = $barcode_data['ean'];
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_ean',
                        'value' => $barcode_data['ean'],
                    ];
                }
                if (!empty($barcode_data['upc']) && !$this->has_meta_key($woo_product, '_ewheel_upc')) {
                    $woo_product['meta_data'][] = [
                        'key' => '_ewheel_upc',
                        'value' => $barcode_data['upc'],
                    ];
                }
            }

            // Combine: parent attrs + variant attrs + pipe-extracted attrs (deduplicated)
            $variant_attrs_woo = $this->transform_variant_attributes($v);
            $pipe_woo_attributes = $this->convert_pipe_attributes_to_woo($pipe_data);
            $combined = $this->merge_attributes($parent_attrs, $variant_attrs_woo);
            $woo_product['attributes'] = $this->merge_attributes($combined, $pipe_woo_attributes);

            $products[] = $woo_product;
        }

        return $products;
    }

    /**
     * Extract attribute values from a variant for display in product name.
     *
     * @param array $variant The variant data (lowercase keys).
     * @return array Array of attribute values.
     */
    private function extract_variant_attribute_values(array $variant): array
    {
        $attrs = $variant['attributes'] ?? [];
        $values = [];

        foreach ($attrs as $key => $val) {
            $value = $val;

            if (is_array($val)) {
                $value = $val['value'] ?? ($val['Value'] ?? '');
            }

            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            if (!empty($value)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Transform variant attributes to WooCommerce format.
     *
     * @param array $variant The variant data (lowercase keys).
     * @return array WooCommerce attributes array.
     */
    private function transform_variant_attributes(array $variant): array
    {
        $attrs = $variant['attributes'] ?? [];
        $woo_attributes = [];

        foreach ($attrs as $key => $val) {
            $name = $key;
            $value = $val;

            if (is_array($val)) {
                $name = $val['alias'] ?? ($val['Alias'] ?? '');
                $value = $val['value'] ?? ($val['Value'] ?? '');
            }

            if (empty($name)) {
                continue;
            }

            // Apply same filtering as transform_attributes_with_visibility
            $normalized_key = AttributeConfiguration::normalize_key($name);
            if (AttributeConfiguration::is_brand($normalized_key)) {
                continue;
            }
            if (AttributeConfiguration::is_model($normalized_key)) {
                continue;
            }
            if (AttributeConfiguration::is_dimension($normalized_key)) {
                continue;
            }
            if (AttributeConfiguration::is_meta($normalized_key)) {
                continue;
            }

            $final_val = $this->clean_attribute_value($value);

            if ($final_val !== null) {
                $woo_attributes[] = [
                    'name' => $this->translate_attribute_name((string) $name),
                    'options' => [$this->translate_attribute_value($final_val)],
                    'visible' => AttributeConfiguration::get_visibility($normalized_key),
                    'variation' => false,
                ];
            }
        }

        return $woo_attributes;
    }

    /**
     * Transform multiple products at once.
     *
     * @param array $products Array of ewheel.es products.
     * @return array Array of WooCommerce products.
     */
    public function transform_batch(array $products): array
    {
        $result = [];
        foreach ($products as $product) {
            $transformed = $this->transform($product);
            // transform() returns an array of products (one for variable, multiple for simple mode)
            $result = array_merge($result, $transformed);
        }
        return $result;
    }

    /**
     * Translate a multilingual field.
     *
     * @param array|string $field The field value (array with language keys or string).
     * @return string The translated text.
     */
    private function translate_field($field): string
    {
        return $this->translate_text($field);
    }

    /**
     * Public method to translate multilingual text.
     *
     * Handles both simple strings and complex multilingual arrays like:
     * - Simple: {"es": "texto", "en": "text"}
     * - Complex: {"defaultLanguageCode": "es", "translations": [{"reference": "es", "value": "texto"}]}
     *
     * @param array|string $text The text to translate.
     * @return string The translated text in target language.
     */
    public function translate_text($text): string
    {
        if (is_string($text)) {
            // String inputs should be translated from Spanish
            if (!empty($text)) {
                return $this->translator->translate($text, 'es');
            }
            return $text;
        }

        if (is_array($text) && !empty($text)) {
            try {
                // Normalize keys to lowercase for consistent lookup
                $normalized = [];
                foreach ($text as $key => $value) {
                    $normalized[strtolower($key)] = $value;
                }

                // If it has 'translations' key, pass as-is (complex format)
                if (isset($normalized['translations'])) {
                    return $this->translator->translate_multilingual($text);
                }

                // Simple format - use normalized keys
                return $this->translator->translate_multilingual($normalized);
            } catch (\Throwable $e) {
                PersistentLogger::error("translate_text exception: " . $e->getMessage());
                // Fallback: try to extract any string value
                foreach ($text as $value) {
                    if (is_string($value) && !empty($value)) {
                        return $value;
                    }
                }
                return '';
            }
        }

        return '';
    }

    /**
     * Convert price using the pricing converter.
     *
     * @param float|int $price The price in source currency.
     * @return string The converted price as string.
     */
    private function convert_price($price): string
    {
        $price = (float) $price;
        if ($price <= 0) {
            return '0';
        }

        $converted = $this->pricing_converter->convert($price);
        return $this->pricing_converter->format_price($converted);
    }

    /**
     * Transform images to WooCommerce format.
     *
     * @param array $images Array of image URLs.
     * @return array WooCommerce images array.
     */
    private function transform_images(array $images): array
    {
        $woo_images = [];

        foreach ($images as $position => $item) {
            // Handle both string URLs and object structures
            $url = is_array($item) ? ($item['url'] ?? ($item['Url'] ?? '')) : $item;

            if (!empty($url)) {
                $woo_images[] = [
                    'src' => $url,
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
    private function transform_categories(array $category_refs): array
    {
        $woo_categories = [];

        foreach ($category_refs as $item) {
            // Handle object structure
            $ref = is_array($item) ? ($item['reference'] ?? ($item['Reference'] ?? '')) : $item;

            if ($ref && isset($this->category_map[$ref])) {
                $woo_categories[] = [
                    'id' => $this->category_map[$ref],
                ];
            }
        }

        return $woo_categories;
    }


    /**
     * Extract compatible model IDs from raw attributes.
     *
     * The 'modelos-compatibles' attribute contains a JSON map like
     * {"114":"113","118":"117"} where the VALUES are the ewheel model IDs.
     *
     * @param array $attributes Raw API attributes.
     * @return array Array of model ID strings.
     */
    private function extract_model_ids(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            $attr_name = $key;
            $attr_val = $value;

            if (is_array($value)) {
                $attr_name = $value['alias'] ?? ($value['Alias'] ?? '');
                $attr_val = $value['value'] ?? ($value['Value'] ?? '');
            }

            $normalized = AttributeConfiguration::normalize_key($attr_name);
            if ($normalized !== 'modelos-compatibles') {
                continue;
            }

            // Value is a JSON map {"114":"113","118":"117"} — model IDs are the values
            if (is_string($attr_val)) {
                $decoded = json_decode($attr_val, true);
                if (is_array($decoded)) {
                    return array_unique(array_values($decoded));
                }
                // Fallback: pipe-separated or comma-separated
                $ids = preg_split('/[|,]/', $attr_val);
                return array_filter(array_map('trim', $ids));
            }

            if (is_array($attr_val)) {
                return array_unique(array_values($attr_val));
            }

            return [];
        }

        return [];
    }

    /**
     * Helper to clean up attribute values (handle JSON, arrays, etc).
     *
     * @param mixed $value The attribute value.
     * @return string|null Cleaned string or null if should be skipped.
     */
    private function clean_attribute_value($value)
    {
        // Handle LocalizedString type: numeric reference IDs are not useful values
        if (is_numeric($value) && !is_string($value)) {
            return null;
        }

        if (is_array($value)) {
            // If it's a selection with explicit 'value' key
            if (isset($value['value'])) {
                $value = $value['value'];
            } elseif (isset($value['id']) && isset($value['translations']) && empty($value['translations'])) {
                // LocalizedString with empty translations (e.g., tipo, descripcion-metacampo at product level)
                return null;
            } elseif (isset($value['translations']) || isset($value['Translations'])) {
                // Complex multilingual format — extract Spanish source text
                // Translation happens later in translate_attribute_value()
                $translations = $value['translations'] ?? $value['Translations'] ?? [];
                $extracted = '';
                foreach ($translations as $t) {
                    $ref = strtolower($t['reference'] ?? ($t['Reference'] ?? ''));
                    if ($ref === 'es') {
                        $extracted = $t['value'] ?? ($t['Value'] ?? '');
                        break;
                    }
                }
                if (empty($extracted) && !empty($translations)) {
                    $first = reset($translations);
                    $extracted = $first['value'] ?? ($first['Value'] ?? '');
                }
                $value = (string) $extracted;
            } else {
                // Simple multilingual format: {"es": "texto", "en": "text"}
                // Extract Spanish source text without translating
                $lower = array_change_key_case($value, CASE_LOWER);
                $value = $lower['es'] ?? $lower['en'] ?? '';
                if (empty($value)) {
                    foreach ($lower as $v) {
                        if (is_string($v) && !empty($v)) {
                            $value = $v;
                            break;
                        }
                    }
                }
                $value = (string) $value;
            }
        }

        if (is_array($value)) {
            $value = implode(' | ', $value);
        }

        $str_val = (string) $value;
        $str_val = trim($str_val);

        // Filter out garbage/sentinel values from API
        $garbage_values = ['[]', 'none', 'null', 'false', 'n/a', '0', '{}'];
        if (in_array(strtolower($str_val), $garbage_values, true)) {
            return null;
        }

        // Detect JSON
        if (strpos($str_val, '{') === 0 || strpos($str_val, '[') === 0) {
            $json = json_decode($str_val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                // Case 1: File Object (e.g. Ficha Tecnica)
                if (isset($json['FILE'])) {
                    return $json['FILE'];
                }
                // Case 2: ID/Value map (Modelos Compatibles)
                // If it's a simple map, maybe implode values?
                // Or if keys are numeric/IDS, take values.
                $parts = [];
                foreach ($json as $k => $v) {
                    if (is_string($v) || is_numeric($v)) {
                        $parts[] = $v;
                    }
                }
                if (!empty($parts)) {
                    return implode(' | ', $parts);
                }
                // Fallback: if we can't extract useful info, return null or empty to skip
                return null;
            }
        }

        return $str_val === '' ? null : $str_val;
    }

    /**
     * Transform attributes to WooCommerce format with visibility support.
     *
     * Uses AttributeConfiguration to determine:
     * - Whether attribute should be visible on product page
     * - Whether attribute should be stored as meta instead
     * - Whether attribute is brand (skip - handled separately)
     * - Whether attribute is dimension (skip - handled by WC native fields)
     *
     * @param array $attributes Array of attributes from API.
     * @return array WooCommerce attributes array with visibility flags.
     */
    private function transform_attributes_with_visibility(array $attributes): array
    {
        $woo_attributes = [];

        foreach ($attributes as $key => $value) {
            $attr_name = $key;
            $attr_val = $value;

            if (is_array($value)) {
                $attr_name = $value['alias'] ?? ($value['Alias'] ?? '');
                $attr_val = $value['value'] ?? ($value['Value'] ?? '');
            }

            if (empty($attr_name)) {
                continue;
            }

            $normalized_key = AttributeConfiguration::normalize_key($attr_name);

            // Skip brand - handled as taxonomy
            if (AttributeConfiguration::is_brand($normalized_key)) {
                continue;
            }

            // Skip models - handled as product_model taxonomy
            if (AttributeConfiguration::is_model($normalized_key)) {
                continue;
            }

            // Skip dimensions - handled by WC native fields
            if (AttributeConfiguration::is_dimension($normalized_key)) {
                continue;
            }

            // Skip meta attributes - stored separately
            if (AttributeConfiguration::is_meta($normalized_key)) {
                continue;
            }

            // Clean the attribute value
            $final_val = $this->clean_attribute_value($attr_val);

            // Fix Selection arrays that split decimal values (e.g., o-llanta-in ["6","5"] → "6.5")
            if ($final_val !== null && strpos($final_val, ' | ') !== false) {
                $inch_attrs = ['o-llanta-in', 'ancho-neumatico-in', 'o-exterior-in'];
                if (in_array($normalized_key, $inch_attrs, true)) {
                    $parts = array_map('trim', explode(' | ', $final_val));
                    if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
                        $final_val = $parts[0] . '.' . $parts[1];
                    }
                }
            }

            if ($final_val === null) {
                continue;
            }

            // Translate attribute name
            $translated_name = $this->translate_attribute_name((string) $attr_name);

            // Translate value
            $translated_val = $this->translate_attribute_value($final_val);

            // Determine visibility from configuration
            $is_visible = AttributeConfiguration::get_visibility($normalized_key);

            $woo_attributes[] = [
                'name' => $translated_name,
                'options' => [$translated_val],
                'visible' => $is_visible,
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
    private function get_variation_attributes(array $ewheel_product): array
    {
        $p = array_change_key_case($ewheel_product, CASE_LOWER);
        $variants = $p['variants'] ?? [];
        $attribute_values = [];

        // Collect all unique attribute values from variants
        foreach ($variants as $variant) {
            $v = array_change_key_case($variant, CASE_LOWER);
            $attrs = $v['attributes'] ?? [];

            // Normalize attributes from list to map if needed
            foreach ($attrs as $key => $val) {
                $name = $key;
                $value = $val;

                if (is_array($val)) {
                    $name = $val['alias'] ?? ($val['Alias'] ?? '');
                    $value = $val['value'] ?? ($val['Value'] ?? '');
                }

                if (empty($name)) {
                    continue;
                }

                // Apply same filtering as transform_attributes_with_visibility
                $normalized_key = AttributeConfiguration::normalize_key($name);
                if (AttributeConfiguration::is_brand($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_model($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_dimension($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_meta($normalized_key)) {
                    continue;
                }

                $final_val = $this->clean_attribute_value($value);

                if ($final_val !== null) {
                    if (!isset($attribute_values[$name])) {
                        $attribute_values[$name] = [];
                    }
                    if (!in_array($final_val, $attribute_values[$name], true)) {
                        $attribute_values[$name][] = $final_val;
                    }
                }
            }
        }

        // Convert to WooCommerce format with translation
        $woo_attributes = [];
        foreach ($attribute_values as $name => $values) {
            $normalized_key = AttributeConfiguration::normalize_key($name);

            // Translate each value
            $translated_values = array_map(function ($val) {
                return $this->translate_attribute_value($val);
            }, $values);

            $woo_attributes[] = [
                'name' => $this->translate_attribute_name((string) $name),
                'options' => $translated_values,
                'visible' => AttributeConfiguration::get_visibility($normalized_key),
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
    private function transform_variations(array $variants): array
    {
        $woo_variations = [];

        foreach ($variants as $variant) {
            $v = array_change_key_case($variant, CASE_LOWER);

            $net_price = $v['net'] ?? ($variant['Net'] ?? 0);
            $compare_price = $v['compareprice'] ?? ($variant['ComparePrice'] ?? ($v['comparePrice'] ?? null));

            $variation = [
                'sku' => $v['reference'] ?? ($variant['Reference'] ?? ''),
                'regular_price' => $this->convert_price($net_price),
                'attributes' => [],
                'manage_stock' => true, // Default to managed
                'stock_quantity' => isset($v['stock']) ? (int) $v['stock'] : 100, // Default to 100 if missing, matching user script logic
            ];

            // If comparePrice exists and is greater than net, product is on sale
            if ($compare_price !== null && (float) $compare_price > 0 && (float) $compare_price > (float) $net_price) {
                $variation['regular_price'] = $this->convert_price($compare_price);
                $variation['sale_price'] = $this->convert_price($net_price);
            }

            // Image
            if (!empty($v['images'])) {
                $images = $this->transform_images($v['images']);
                if (!empty($images)) {
                    $variation['image'] = $images[0]['src']; // Variations only support one main image
                }
            }

            // Variant Attributes & Dimensions
            $attrs = $v['attributes'] ?? ($variant['Attributes'] ?? []);
            foreach ($attrs as $key => $val) {
                $normal_attr = $this->normalize_attribute($key, $val);
                $name = $normal_attr['name'];
                $value = $normal_attr['value'];

                if (is_array($value)) {
                    $value = implode(' | ', $value);
                }

                // Check for dimensions
                $alias = strtolower($normal_attr['alias'] ?? $name);
                if ($alias === 'peso') {
                    $variation['weight'] = $value;
                } elseif ($alias === 'ancho') {
                    $variation['width'] = $value;
                } elseif ($alias === 'alto') {
                    $variation['height'] = $value;
                } elseif ($alias === 'largo') {
                    $variation['length'] = $value;
                }

                // Filter out brand, model, dimension, and meta attributes
                $normalized_key = AttributeConfiguration::normalize_key($alias);
                if (AttributeConfiguration::is_brand($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_model($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_dimension($normalized_key)) {
                    continue;
                }
                if (AttributeConfiguration::is_meta($normalized_key)) {
                    continue;
                }

                if (!empty($name) && !empty($value)) {
                    $variation['attributes'][] = [
                        'name' => $this->translate_attribute_name((string) $name),
                        'option' => $this->translate_attribute_value((string) $value),
                    ];
                }
            }

            $woo_variations[] = $variation;
        }

        return $woo_variations;
    }

    /**
     * Normalize attribute data.
     *
     * @param string|int $key   Array key.
     * @param mixed      $val   Array value.
     * @return array Normalized attribute ['name' => ..., 'value' => ..., 'alias' => ...]
     */
    private function normalize_attribute($key, $val): array
    {
        $name = $key;
        $value = $val;
        $alias = $key;

        if (is_array($val)) {
            $name = $val['alias'] ?? ($val['Alias'] ?? '');
            $value = $val['value'] ?? ($val['Value'] ?? '');
            $alias = $name;
        }

        return [
            'name' => $name,
            'value' => $value,
            'alias' => $alias,
        ];
    }

    /**
     * Format attribute name for display.
     *
     * @param string $name The raw attribute name.
     * @return string The formatted name.
     */
    private function format_attribute_name(string $name): string
    {
        // Convert snake_case to Title Case
        $name = str_replace(['_', '-'], ' ', $name);
        return ucwords($name);
    }

    /**
     * Translate attribute name to target language.
     *
     * @param string $name The attribute name (e.g., "marca", "color").
     * @param string $source_lang Source language code (default: 'es').
     * @return string The translated and formatted name.
     */
    private function translate_attribute_name(string $name, string $source_lang = 'es'): string
    {
        // Check for a hardcoded Romanian label first (more reliable for short words)
        $normalized = AttributeConfiguration::normalize_key($name);
        $label = AttributeConfiguration::get_label($normalized);
        // get_label returns ucfirst(slug) as fallback — only use it if it's a real label
        if (isset(AttributeConfiguration::ATTRIBUTE_LABELS[$normalized])) {
            return $label;
        }

        // Fall back to API translation
        $formatted = $this->format_attribute_name($name);
        $translated = $this->translator->translate($formatted, $source_lang);

        return !empty($translated) ? $translated : $formatted;
    }

    /**
     * Translate attribute value to target language.
     *
     * @param string $value The attribute value.
     * @param string $source_lang Source language code (default: 'es').
     * @return string The translated value.
     */
    private function translate_attribute_value(string $value, string $source_lang = 'es'): string
    {
        $trimmed = trim($value);

        // Skip translation for numeric values, URLs, file paths, codes, and units
        if (
            $trimmed === ''
            || is_numeric($trimmed)
            || preg_match('/^[\d.,]+$/', $trimmed)
            || preg_match('/^https?:\/\//', $trimmed)
            || preg_match('/\.(pdf|jpg|png|gif)$/i', $trimmed)
            || preg_match('/^\d+[\.,]?\d*\s*(kg|cm|mm|m|g|l|ml|v|w|a|ah|wh|mah)$/i', $trimmed)
            || preg_match('/^\d+\s*[xX×]\s*\d+/', $trimmed)
        ) {
            return $trimmed;
        }

        // Check known value translations for short/problematic strings
        $lower = strtolower($trimmed);
        if (isset(AttributeConfiguration::VALUE_TRANSLATIONS[$lower])) {
            return AttributeConfiguration::VALUE_TRANSLATIONS[$lower];
        }

        // Translate text values
        $translated = $this->translator->translate($trimmed, $source_lang);

        return !empty($translated) ? $translated : $trimmed;
    }

    /**
     * Get short description from product.
     *
     * @param array $ewheel_product The ewheel.es product.
     * @return string The short description.
     */
    private function get_short_description(array $ewheel_product): string
    {
        // Handle case sensitivity
        $p = array_change_key_case($ewheel_product, CASE_LOWER);
        $short = $p['shortdescription'] ?? ($ewheel_product['ShortDescription'] ?? null);

        // If there's a specific short description field, use it
        if ($short) {
            return $this->translate_field($short);
        }

        // Otherwise, truncate the main description
        $description = $this->translate_field($p['description'] ?? ($ewheel_product['Description'] ?? []));
        if (strlen($description) > 200) {
            return substr($description, 0, 197) . '...';
        }

        return $description;
    }

    /**
     * Get meta data for the product.
     *
     * @param array $ewheel_product The ewheel.es product.
     * @return array Meta data array.
     */
    private function get_meta_data(array $ewheel_product): array
    {
        $p = array_change_key_case($ewheel_product, CASE_LOWER);

        $reference = $p['reference'] ?? ($ewheel_product['Reference'] ?? '');

        $meta = [
            [
                'key' => '_ewheel_id',
                'value' => (string) ($p['id'] ?? ($ewheel_product['Id'] ?? '')),
            ],
            [
                'key' => '_ewheel_reference',
                'value' => $reference,
            ],
            [
                'key' => '_ewheel_reference_base',
                'value' => $this->extract_reference_base($reference),
            ],
            [
                'key' => '_ewheel_last_sync',
                'value' => gmdate('Y-m-d\TH:i:s'),
            ],
        ];

        // Extract and add EAN/GTIN if available
        $ean = $this->extract_ean_from_description($ewheel_product);
        if ($ean) {
            $meta[] = [
                'key' => '_ewheel_ean',
                'value' => $ean,
            ];
        }

        return $meta;
    }

    /**
     * Set the category map.
     *
     * @param array $category_map The category mapping.
     * @return void
     */
    public function set_category_map(array $category_map): void
    {
        $this->category_map = $category_map;
    }

    /**
     * Helper: Format the pipe-separated description into a clean HTML table.
     *
     * @param string $raw_desc The raw description string.
     * @return string Formatted HTML.
     */
    private function format_pipe_description(string $raw_desc): string
    {
        // 1. Sanity check
        if (empty($raw_desc) || strpos($raw_desc, '|') === false) {
            return function_exists('wpautop') ? wpautop($raw_desc) : $raw_desc;
        }

        // 2. Explode the string by the pipe character
        $parts = explode('|', $raw_desc);

        // 3. Start building HTML
        $html = '<h3>Code Description</h3>';
        $html .= '<table class="shop_attributes table-specs" style="width:100%; border-collapse: collapse;">';
        $html .= '<tbody>';

        $has_content = false;

        // 4. Labels mapping (Based on user analysis/dump)
        $labels = [
            0 => 'EAN',
            1 => 'UPC',
            7 => 'Brand',
            8 => 'SKU',
            9 => 'Reference',
        ];

        foreach ($parts as $index => $part) {
            $part = trim($part);

            // Filter out empty strings, slash placeholders, or purely whitespace
            if (empty($part) || $part === '/' || $part === '0') {
                continue;
            }

            $has_content = true;

            // Determine label
            $label = isset($labels[$index]) ? '<strong>' . esc_html($labels[$index]) . '</strong>' : 'Spec';

            $html .= '<tr style="border-bottom: 1px solid #eee;">';
            // If we have a mapped label, show it in the left column
            if (isset($labels[$index])) {
                $html .= '<th style="padding: 10px; text-align: left; background-color: #f9f9f9; width: 30%;">' . $label . '</th>';
                $html .= '<td style="padding: 10px;">' . esc_html($part) . '</td>';
            } else {
                // If we don't know the label, just display the value cleanly
                $html .= '<td colspan="2" style="padding: 10px;">' . esc_html($part) . '</td>';
            }
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        // 5. If the description string was all empty pipes, return nothing (or empty string)
        if (!$has_content) {
            return '';
        }

        return $html;
    }

    /**
     * Clean a SKU by stripping the '-parent' suffix.
     *
     * The ewheel API uses '-parent' suffix on container product references
     * (e.g., "MP-010-parent") while variant references are clean ("MP-010").
     * WooCommerce SKUs should use the clean reference.
     *
     * @param string $reference The raw reference string.
     * @return string The cleaned SKU.
     */
    private function clean_sku(string $reference): string
    {
        if (preg_match('/^(.+)-parent$/i', $reference, $matches)) {
            return $matches[1];
        }

        return $reference;
    }

    /**
     * Extract EAN and UPC barcodes from API attributes.
     *
     * Scans for 'codigo-alternativo' (EAN) and 'codigo-alternativo-2' (UPC).
     *
     * @param array $attributes Raw API attributes array.
     * @return array ['ean' => string|null, 'upc' => string|null]
     */
    private function extract_barcode_from_attributes(array $attributes): array
    {
        $result = ['ean' => null, 'upc' => null];

        foreach ($attributes as $key => $value) {
            $attr_name = $key;
            $attr_val = $value;

            if (is_array($value)) {
                $attr_name = $value['alias'] ?? ($value['Alias'] ?? '');
                $attr_val = $value['value'] ?? ($value['Value'] ?? '');
            }

            $normalized = AttributeConfiguration::normalize_key($attr_name);

            if ($normalized === 'codigo-alternativo') {
                $val = $this->clean_attribute_value($attr_val);
                if ($val !== null && preg_match('/^\d{8,14}$/', $val)) {
                    $result['ean'] = $val;
                }
            } elseif ($normalized === 'codigo-alternativo-2') {
                $val = $this->clean_attribute_value($attr_val);
                if ($val !== null && preg_match('/^\d{8,14}$/', $val)) {
                    $result['upc'] = $val;
                }
            }
        }

        return $result;
    }

    /**
     * Check if a product data array already has a specific meta key.
     *
     * @param array  $product_data The product data.
     * @param string $meta_key     The meta key to check.
     * @return bool True if the meta key exists with a non-empty value.
     */
    private function has_meta_key(array $product_data, string $meta_key): bool
    {
        if (empty($product_data['meta_data'])) {
            return false;
        }

        foreach ($product_data['meta_data'] as $meta) {
            if ($meta['key'] === $meta_key && !empty($meta['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract the base reference from a SKU/reference string.
     *
     * Removes common suffixes like '-parent', color codes, sizes, etc.
     * Examples:
     * - "MP-010-parent" → "MP-010"
     * - "MP-010-BLACK" → "MP-010"
     * - "SCOOTER-V-RED" → "SCOOTER-V"
     *
     * @param string $reference The full reference string.
     * @return string The base reference without suffix.
     */
    public function extract_reference_base(string $reference): string
    {
        if (empty($reference)) {
            return '';
        }

        // Remove '-parent' suffix first (case-insensitive)
        if (preg_match('/^(.+)-parent$/i', $reference, $matches)) {
            return $matches[1];
        }

        // Split by dash to analyze parts
        $parts = explode('-', $reference);

        // If only one part, return as-is
        if (count($parts) <= 1) {
            return $reference;
        }

        // Check if last part looks like a variant suffix
        $last = strtolower(end($parts));

        // Common color suffixes
        $color_suffixes = ['black', 'white', 'red', 'blue', 'green', 'yellow', 'orange', 'purple', 'pink', 'grey', 'gray', 'negro', 'blanco', 'rojo', 'azul', 'verde', 'amarillo'];

        // Common size suffixes
        $size_suffixes = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl', '2xl', '3xl'];

        // Check if it's a known suffix
        if (in_array($last, $color_suffixes, true) || in_array($last, $size_suffixes, true)) {
            array_pop($parts);
            return implode('-', $parts);
        }

        // Check if last part is purely numeric (could be variant number)
        if (preg_match('/^\d{1,3}$/', $last)) {
            array_pop($parts);
            return implode('-', $parts);
        }

        // No suffix detected, return original
        return $reference;
    }

    /**
     * Extract EAN/GTIN from pipe-separated description.
     *
     * The description format is: "EAN | UPC | ... | Brand | SKU | Reference"
     * Position 0 contains the EAN barcode.
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return string|null The EAN code or null if not found.
     */
    public function extract_ean_from_description(array $ewheel_product): ?string
    {
        $p = array_change_key_case($ewheel_product, CASE_LOWER);
        $desc = $p['description'] ?? ($ewheel_product['Description'] ?? []);

        // Get the raw description text
        $text = $this->get_translation_value($desc);

        if (empty($text) || strpos($text, '|') === false) {
            return null;
        }

        // Parse pipe-separated format
        $parts = array_map('trim', explode('|', $text));

        // Position 0 should be EAN
        if (!empty($parts[0])) {
            // Validate EAN format (8-14 digits)
            if (preg_match('/^\d{8,14}$/', $parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    /**
     * Get translation value from multilingual field.
     *
     * Handles both simple {"es": "...", "en": "..."} and complex
     * {"defaultLanguageCode": "es", "translations": [...]} formats.
     *
     * @param array|string $field The multilingual field.
     * @return string The text value.
     */
    private function get_translation_value($field): string
    {
        if (is_string($field)) {
            return $field;
        }

        if (!is_array($field)) {
            return '';
        }

        // Complex format with translations array
        if (isset($field['translations']) && is_array($field['translations'])) {
            foreach ($field['translations'] as $translation) {
                if (!empty($translation['value'])) {
                    return (string) $translation['value'];
                }
            }
        }

        // Simple format {"es": "...", "en": "..."}
        $priority = ['en', 'es', 'de', 'fr', 'it'];
        foreach ($priority as $lang) {
            if (!empty($field[$lang])) {
                return (string) $field[$lang];
            }
        }

        // Fallback to first non-empty value
        foreach ($field as $value) {
            if (!empty($value) && is_string($value)) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Get the raw description text from a product.
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return string The raw description text.
     */
    private function get_description_text(array $ewheel_product): string
    {
        $p = array_change_key_case($ewheel_product, CASE_LOWER);
        $desc = $p['description'] ?? ($ewheel_product['Description'] ?? []);

        return $this->get_translation_value($desc);
    }

    /**
     * Extract attributes from pipe-separated description data.
     *
     * Returns a structured array with categories:
     * - gtin: EAN and UPC barcodes
     * - brand: Brand name (for taxonomy)
     * - dimensions: Weight and physical dimensions (for WC native fields)
     * - attributes: Display attributes (color, etc.)
     * - meta: Internal metadata
     *
     * Pipe positions:
     * - 0: EAN, 1: UPC, 7: Brand, 16: Color
     * - 32: Weight (kg), 33: Height (cm), 34: Width (cm), 35: Length (cm)
     * - 41: Family, 42: Subfamily
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return array Structured array with gtin, brand, dimensions, attributes, meta.
     */
    public function extract_pipe_attributes(array $ewheel_product): array
    {
        $text = $this->get_description_text($ewheel_product);

        $result = [
            'gtin' => ['ean' => null, 'upc' => null],
            'brand' => null,
            'dimensions' => [
                'weight' => null,
                'height' => null,
                'width' => null,
                'length' => null,
            ],
            'attributes' => [],
            'meta' => [],
        ];

        if (empty($text) || strpos($text, '|') === false) {
            return $result;
        }

        $parts = array_map('trim', explode('|', $text));

        // Position 0: EAN (8-14 digits)
        if (!empty($parts[0]) && preg_match('/^\d{8,14}$/', $parts[0])) {
            $result['gtin']['ean'] = $parts[0];
        }

        // Position 1: UPC (12 digits typically)
        if (!empty($parts[1]) && preg_match('/^\d{10,14}$/', $parts[1])) {
            $result['gtin']['upc'] = $parts[1];
        }

        // Position 7: Brand (for taxonomy, not attribute)
        if (!empty($parts[7]) && $parts[7] !== '/' && $parts[7] !== '0') {
            $result['brand'] = $parts[7];
        }

        // Position 16: Color (visible attribute)
        if (!empty($parts[16]) && $parts[16] !== '/' && $parts[16] !== '0') {
            $result['attributes']['color'] = [
                'value' => $parts[16],
                'visible' => AttributeConfiguration::get_visibility('color'),
            ];
        }

        // Dimensions (for WooCommerce native fields - raw numeric values)
        // Position 32: Weight (kg)
        if (!empty($parts[32]) && is_numeric($parts[32]) && (float) $parts[32] > 0) {
            $result['dimensions']['weight'] = (float) $parts[32];
        }

        // Position 33: Height (cm)
        if (!empty($parts[33]) && is_numeric($parts[33]) && (float) $parts[33] > 0) {
            $result['dimensions']['height'] = (float) $parts[33];
        }

        // Position 34: Width (cm)
        if (!empty($parts[34]) && is_numeric($parts[34]) && (float) $parts[34] > 0) {
            $result['dimensions']['width'] = (float) $parts[34];
        }

        // Position 35: Length (cm)
        if (!empty($parts[35]) && is_numeric($parts[35]) && (float) $parts[35] > 0) {
            $result['dimensions']['length'] = (float) $parts[35];
        }

        // Position 41: Family (hidden attribute)
        if (!empty($parts[41]) && $parts[41] !== '/' && $parts[41] !== '0') {
            $result['attributes']['familia-sage'] = [
                'value' => $parts[41],
                'visible' => AttributeConfiguration::get_visibility('familia-sage'),
            ];
        }

        // Position 42: Subfamily (hidden attribute)
        if (!empty($parts[42]) && $parts[42] !== '/' && $parts[42] !== '0') {
            $result['attributes']['subfamilia-sage'] = [
                'value' => $parts[42],
                'visible' => AttributeConfiguration::get_visibility('subfamilia-sage'),
            ];
        }

        return $result;
    }

    /**
     * Convert pipe attributes to WooCommerce attribute format.
     *
     * Takes the structured array from extract_pipe_attributes() and converts
     * the 'attributes' section to WooCommerce format with visibility support.
     *
     * @param array $pipe_data Structured array from extract_pipe_attributes().
     * @return array WooCommerce attributes array.
     */
    public function convert_pipe_attributes_to_woo(array $pipe_data): array
    {
        // Handle both old format (flat array) and new format (structured with 'attributes' key)
        $attributes = isset($pipe_data['attributes']) ? $pipe_data['attributes'] : $pipe_data;

        if (empty($attributes)) {
            return [];
        }

        $woo_attributes = [];

        foreach ($attributes as $key => $data) {
            // Handle both old format (direct value) and new format (array with 'value' and 'visible')
            if (is_array($data) && isset($data['value'])) {
                $value = $data['value'];
                $visible = $data['visible'] ?? true;
            } else {
                $value = $data;
                $visible = true;
            }

            // Translate name and value
            $translated_name = $this->translate_attribute_name($key);
            $translated_value = $this->translate_attribute_value((string) $value);

            $woo_attributes[] = [
                'name' => $translated_name,
                'options' => [$translated_value],
                'visible' => $visible,
                'variation' => false,
            ];
        }

        return $woo_attributes;
    }

    /**
     * Merge two attribute arrays, deduplicating by slug.
     *
     * Primary attributes take precedence over secondary when names collide.
     *
     * @param array $primary   Primary attributes (take precedence).
     * @param array $secondary Secondary attributes (fill gaps only).
     * @return array Merged attributes.
     */
    private function merge_attributes(array $primary, array $secondary): array
    {
        // Build a set of slugified names from primary
        $existing_slugs = [];
        foreach ($primary as $attr) {
            $slug = sanitize_title($attr['name'] ?? '');
            $existing_slugs[$slug] = true;
        }

        // Only add secondary attrs whose slug doesn't collide
        foreach ($secondary as $attr) {
            $slug = sanitize_title($attr['name'] ?? '');
            if (!isset($existing_slugs[$slug])) {
                $primary[] = $attr;
                $existing_slugs[$slug] = true;
            }
        }

        return $primary;
    }

    /**
     * Build a clean HTML specs table from extracted attributes.
     *
     * Creates a formatted table with Romanian labels for use as short description.
     * Only includes visible attributes in the specs table.
     *
     * @param array $pipe_data Structured array from extract_pipe_attributes().
     * @return string HTML table string, or empty string if no visible attributes.
     */
    public function build_specs_table(array $pipe_data): string
    {
        // Collect visible specs from multiple sources
        $specs = [];

        // Brand (if available)
        if (!empty($pipe_data['brand'])) {
            $specs['brand'] = $pipe_data['brand'];
        }

        // Dimensions with units (only if visible and non-zero)
        if (!empty($pipe_data['dimensions'])) {
            $dims = $pipe_data['dimensions'];
            if (!empty($dims['weight'])) {
                $specs['weight'] = $dims['weight'] . ' kg';
            }
            if (!empty($dims['height'])) {
                $specs['height'] = $dims['height'] . ' cm';
            }
            if (!empty($dims['width'])) {
                $specs['width'] = $dims['width'] . ' cm';
            }
            if (!empty($dims['length'])) {
                $specs['length'] = $dims['length'] . ' cm';
            }
        }

        // Visible attributes only
        if (!empty($pipe_data['attributes'])) {
            foreach ($pipe_data['attributes'] as $key => $data) {
                $visible = is_array($data) ? ($data['visible'] ?? true) : true;
                $value = is_array($data) ? ($data['value'] ?? $data) : $data;

                if ($visible && !empty($value)) {
                    $specs[$key] = $value;
                }
            }
        }

        if (empty($specs)) {
            return '';
        }

        $html = '<table class="product-specs woocommerce-product-attributes shop_attributes">';
        $html .= '<tbody>';

        foreach ($specs as $key => $value) {
            $label = AttributeConfiguration::get_label($key);
            $escaped_label = function_exists('esc_html') ? esc_html($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $escaped_value = function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $safe_key = function_exists('esc_attr') ? esc_attr($key) : htmlspecialchars($key, ENT_QUOTES, 'UTF-8');

            $html .= '<tr class="woocommerce-product-attributes-item woocommerce-product-attributes-item--' . $safe_key . '">';
            $html .= '<th class="woocommerce-product-attributes-item__label">' . $escaped_label . '</th>';
            $html .= '<td class="woocommerce-product-attributes-item__value">' . $escaped_value . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    /**
     * Prefetch translations for a batch of products.
     *
     * Extracts all translatable strings (names, attributes, pipe-descriptions)
     * and processes them in a single batch to avoid multiple API calls.
     *
     * @param array $ewheel_products Array of raw ewheel products.
     * @return void
     */
    public function prefetch_translations(array $ewheel_products): void
    {
        $texts_to_translate = [];

        foreach ($ewheel_products as $product) {
            $p = array_change_key_case($product, CASE_LOWER);

            // 1. Name
            $name_val = $this->get_mapped_value($p, 'name', 'name');
            if ($name_val) {
                $text = $this->extract_es_text($name_val);
                if ($text)
                    $texts_to_translate[$text] = true;
            }

            // 2. Attributes (names and values)
            $attrs_val = $this->get_mapped_value($p, 'attributes', 'attributes');
            if (is_array($attrs_val)) {
                $this->collect_attribute_texts($attrs_val, $texts_to_translate);
            }

            // 3. Variants Attributes
            if (!empty($p['variants']) && is_array($p['variants'])) {
                foreach ($p['variants'] as $variant) {
                    $v = array_change_key_case($variant, CASE_LOWER);
                    if (!empty($v['attributes'])) {
                        $this->collect_attribute_texts($v['attributes'], $texts_to_translate);
                    }
                }
            }

            // 4. Pipe Description Attributes (Brand, Family, Subfamily, Color)
            // Note: Pipe description itself usually contains values that need translation?
            // Actually, extract_pipe_attributes returns raw values.
            // convert_pipe_attributes_to_woo translates specific keys (color, etc.)?
            // convert_pipe_attributes_to_woo uses $labels (hardcoded Romanian). 
            // It puts values directly into options.
        }

        if (!empty($texts_to_translate)) {
            $unique_texts = array_keys($texts_to_translate);
            $count = count($unique_texts);

            PersistentLogger::info("[Performance] Prefetching translations for $count strings...");

            // Process in chunks of 25 to stay within token limits and reduce failure blast radius
            $chunks = array_chunk($unique_texts, 25);
            $chunk_count = count($chunks);
            foreach ($chunks as $index => $chunk) {
                $this->translator->translate_batch($chunk, 'es');

                // Add 1 second delay between chunks to prevent API rate limiting
                if ($index < $chunk_count - 1) {
                    sleep(1);
                }
            }
        }
    }

    /**
     * Helper to collect attribute texts (names and values) for translation.
     *
     * @param array $attributes RAW attributes array.
     * @param array &$collection Reference to collection array.
     */
    private function collect_attribute_texts(array $attributes, array &$collection): void
    {
        foreach ($attributes as $key => $val) {
            $name = $key;
            $value = $val;

            if (is_array($val)) {
                $name = $val['alias'] ?? ($val['Alias'] ?? '');
                $value = $val['value'] ?? ($val['Value'] ?? '');
            }

            // Attribute Name
            $fmt_name = $this->format_attribute_name((string) $name);
            if ($fmt_name)
                $collection[$fmt_name] = true;

            // Attribute Value
            if (is_array($value)) {
                $value = isset($value['value']) ? $value['value'] : $this->extract_es_text($value);
            }
            // For value arrays (multiselect), we might need to implode or loop?
            // clean_attribute_value handles imploding.
            // But translate_attribute_value translates the raw string properly if it's text.

            if (is_string($value) && !is_numeric($value)) {
                // Heuristic: only translate if it looks like text
                if (strlen($value) > 2 && !preg_match('/^https?:/', $value)) {
                    $collection[$value] = true;
                }
            }
        }
    }

    /**
     * Helper to extract Spanish text from a mixed/multilingual field.
     *
     * @param mixed $field
     * @return string
     */
    private function extract_es_text($field): string
    {
        if (is_string($field))
            return $field;
        if (is_array($field)) {
            // Try 'es'
            if (isset($field['es']))
                return $field['es'];

            // Try 'translations' array
            if (isset($field['translations'])) {
                foreach ($field['translations'] as $t) {
                    if (($t['reference'] ?? '') === 'es')
                        return $t['value'] ?? '';
                }
            }

            // Fallback to first string
            foreach ($field as $v) {
                if (is_string($v))
                    return $v;
            }
        }
        return '';
    }
}
