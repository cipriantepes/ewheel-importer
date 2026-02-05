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
        // Handle case sensitivity (API returns lowercase, code might expect PascalCase)
        $p = array_change_key_case($ewheel_product, CASE_LOWER);

        $has_variants = !empty($p['variants']);
        $use_variable_mode = $this->config->is_variable_product_mode();

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
        }

        $sync_fields = $this->config->get_sync_fields();

        $woo_product = [
            'sku' => $p['reference'] ?? ($ewheel_product['Reference'] ?? ''),
            'status' => ($p['active'] ?? ($ewheel_product['Active'] ?? false)) ? 'publish' : 'draft',
            'type' => $product_type,
            'manage_stock' => false,
            'meta_data' => $this->get_meta_data($ewheel_product),
        ];

        $name_val = $this->get_mapped_value($p, 'name', 'name');
        if ($name_val !== null) {
            $woo_product['name'] = $this->translate_field($name_val);
        }

        // Description: Use the translated NAME field as the product description
        // The API description field contains pipe-separated inventory data (EAN, UPC, dimensions)
        // which is NOT customer-facing content. The NAME field has the actual product description.
        $name_for_desc = $this->get_mapped_value($p, 'name', 'name');
        if ($name_for_desc !== null) {
            $woo_product['description'] = $this->translate_field($name_for_desc);
        }

        // Short Description: Build a clean specs table from the pipe-separated data
        // This extracts useful attributes (brand, color, dimensions) into a formatted table
        $pipe_attributes = $this->extract_pipe_attributes($ewheel_product);
        $specs_table = $this->build_specs_table($pipe_attributes);
        if (!empty($specs_table)) {
            $woo_product['short_description'] = $specs_table;
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
            $api_attributes = $this->transform_attributes(is_array($attrs_val) ? $attrs_val : []);
        }

        // Merge pipe-extracted attributes (from description field) with API attributes
        $pipe_woo_attributes = $this->convert_pipe_attributes_to_woo($pipe_attributes);
        $woo_product['attributes'] = array_merge($api_attributes, $pipe_woo_attributes);

        // Add variations for variable products
        if ($has_variants) {
            $woo_product['variations'] = $this->transform_variations($p['variants']);
            // For variable products, variation attributes take precedence
            $variation_attrs = $this->get_variation_attributes($ewheel_product);
            // Merge with pipe attributes but keep variation attrs as the base
            $woo_product['attributes'] = array_merge($pipe_woo_attributes, $variation_attrs);
        }

        return [$woo_product];
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

            // Short Description: Build specs table from pipe-separated data
            $pipe_attributes = $this->extract_pipe_attributes($ewheel_product);
            $specs_table = $this->build_specs_table($pipe_attributes);
            if (!empty($specs_table)) {
                $woo_product['short_description'] = $specs_table;
            }

            $price_val = $this->get_mapped_value($v, 'price', 'net'); // Variants usually use 'net' as default price source? Or 'rrp'?
            // Original code: $price = $v['net'] ?? ($variant['Net'] ?? 0);
            // So default source for variant IS 'net'.
            // But if user mapped 'price' to 'rrp', we should check $v['rrp']? 
            // Variants might not have RRP. 
            // If mapping is global 'price' -> 'rrp', and variant doesn't have it, we fallback to 0?
            // Let's use $v as source data.
            if ($price_val !== null) {
                $woo_product['regular_price'] = $this->convert_price($price_val);
            }

            $images_val = $this->get_mapped_value($p, 'image', 'images'); // Images usually from parent
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
                $parent_attrs = $this->transform_attributes(is_array($attrs_val) ? $attrs_val : []);
            }

            // Combine: parent attrs + variant attrs + pipe-extracted attrs
            $variant_attrs_woo = $this->transform_variant_attributes($v);
            $pipe_woo_attributes = $this->convert_pipe_attributes_to_woo($pipe_attributes);
            $woo_product['attributes'] = array_merge($parent_attrs, $variant_attrs_woo, $pipe_woo_attributes);

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

            $final_val = $this->clean_attribute_value($value);

            if (!empty($name) && $final_val !== null) {
                $woo_attributes[] = [
                    'name' => $this->translate_attribute_name((string) $name),
                    'options' => [$this->translate_attribute_value($final_val)],
                    'visible' => true,
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
            return $text;
        }

        if (is_array($text) && !empty($text)) {
            return $this->translator->translate_multilingual($text);
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

        // Debug: Log incoming category refs and map status
        if (empty($this->category_map)) {
            error_log('Ewheel Importer DEBUG (transform_categories): Category map is EMPTY');
        }

        foreach ($category_refs as $item) {
            // Handle object structure
            $ref = is_array($item) ? ($item['reference'] ?? ($item['Reference'] ?? '')) : $item;

            // Debug: Log each category reference
            error_log('Ewheel Importer DEBUG (transform_categories): Processing category ref: "' . $ref . '"');

            if ($ref && isset($this->category_map[$ref])) {
                $woo_categories[] = [
                    'id' => $this->category_map[$ref],
                ];
                error_log('Ewheel Importer DEBUG (transform_categories): MATCHED ref "' . $ref . '" to WooCommerce ID ' . $this->category_map[$ref]);
            } else {
                error_log('Ewheel Importer DEBUG (transform_categories): NO MATCH for ref "' . $ref . '" in category_map');

                // Debug: Show available keys if there's a mismatch
                if (!empty($this->category_map) && !isset($this->category_map[$ref])) {
                    error_log('Ewheel Importer DEBUG (transform_categories): Available keys: ' . implode(', ', array_keys($this->category_map)));
                }
            }
        }

        return $woo_categories;
    }


    /**
     * Helper to clean up attribute values (handle JSON, arrays, etc).
     * 
     * @param mixed $value The attribute value.
     * @return string|null Cleaned string or null if should be skipped.
     */
    private function clean_attribute_value($value)
    {
        if (is_array($value)) {
            // If it's a multilingual array or selection
            if (isset($value['value'])) {
                $value = $value['value'];
            } else {
                $value = $this->translate_field($value);
            }
        }

        if (is_array($value)) {
            $value = implode(' | ', $value);
        }

        $str_val = (string) $value;
        $str_val = trim($str_val);

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
     * Transform attributes to WooCommerce format.
     *
     * @param array $attributes Array of attributes.
     * @return array WooCommerce attributes array.
     */
    private function transform_attributes(array $attributes): array
    {
        $woo_attributes = [];

        foreach ($attributes as $key => $value) {
            // Support both Key => Value map and list of { alias: '...', value: '...' }
            $attr_name = $key;
            $attr_val = $value;

            if (is_array($value)) {
                $attr_name = $value['alias'] ?? ($value['Alias'] ?? '');
                $attr_val = $value['value'] ?? ($value['Value'] ?? '');
            }

            if (empty($attr_name)) {
                continue;
            }

            // CLEANUP VALUE
            $final_val = $this->clean_attribute_value($attr_val);

            if ($final_val === null) {
                continue;
            }

            // Translate attribute name
            $translated_name = $this->translate_attribute_name((string) $attr_name);

            // Skip translation for brand names (marca, brand) - keep original value
            $is_brand = in_array(strtolower($attr_name), ['marca', 'brand'], true);
            $translated_val = $is_brand ? $final_val : $this->translate_attribute_value($final_val);

            $woo_attributes[] = [
                'name' => $translated_name,
                'options' => [$translated_val],
                'visible' => true,
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

                $final_val = $this->clean_attribute_value($value);

                if (!empty($name) && $final_val !== null) {
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
            // Translate each value
            $translated_values = array_map(function ($val) {
                return $this->translate_attribute_value($val);
            }, $values);

            $woo_attributes[] = [
                'name' => $this->translate_attribute_name((string) $name),
                'options' => $translated_values,
                'visible' => true,
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

            $variation = [
                'sku' => $v['reference'] ?? ($variant['Reference'] ?? ''),
                'regular_price' => $this->convert_price($v['net'] ?? ($variant['Net'] ?? 0)),
                'attributes' => [],
                'manage_stock' => true, // Default to managed
                'stock_quantity' => isset($v['stock']) ? (int) $v['stock'] : 100, // Default to 100 if missing, matching user script logic
            ];

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
     * @return string The translated and formatted name.
     */
    private function translate_attribute_name(string $name): string
    {
        // First format the name
        $formatted = $this->format_attribute_name($name);

        // Translate the formatted name to target language
        $translated = $this->translator->translate($formatted);

        return !empty($translated) ? $translated : $formatted;
    }

    /**
     * Translate attribute value to target language.
     *
     * @param string $value The attribute value.
     * @return string The translated value.
     */
    private function translate_attribute_value(string $value): string
    {
        // Skip translation for numeric values, URLs, file paths, and codes
        if (is_numeric($value)
            || preg_match('/^https?:\/\//', $value)
            || preg_match('/\.(pdf|jpg|png|gif)$/i', $value)
            || preg_match('/^\d+(\.\d+)?\s*(kg|cm|mm|m|g|l|ml)$/i', $value)
        ) {
            return $value;
        }

        // Translate text values
        $translated = $this->translator->translate($value);

        return !empty($translated) ? $translated : $value;
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
     * Parses known positions from the pipe-separated format:
     * - Position 7: Brand
     * - Position 16: Color
     * - Position 32: Weight (kg)
     * - Position 33: Height (cm)
     * - Position 34: Width (cm)
     * - Position 35: Length (cm)
     * - Position 41: Family
     * - Position 42: Subfamily
     *
     * @param array $ewheel_product The ewheel.es product data.
     * @return array Associative array of extracted attributes.
     */
    public function extract_pipe_attributes(array $ewheel_product): array
    {
        $text = $this->get_description_text($ewheel_product);

        if (empty($text) || strpos($text, '|') === false) {
            return [];
        }

        $parts = array_map('trim', explode('|', $text));
        $attributes = [];

        // Position 7: Brand
        if (!empty($parts[7]) && $parts[7] !== '/' && $parts[7] !== '0') {
            $attributes['brand'] = $parts[7];
        }

        // Position 16: Color
        if (!empty($parts[16]) && $parts[16] !== '/' && $parts[16] !== '0') {
            $attributes['color'] = $parts[16];
        }

        // Position 32: Weight (kg)
        if (!empty($parts[32]) && is_numeric($parts[32]) && (float) $parts[32] > 0) {
            $attributes['weight'] = $parts[32] . ' kg';
        }

        // Position 33: Height (cm)
        if (!empty($parts[33]) && is_numeric($parts[33]) && (float) $parts[33] > 0) {
            $attributes['height'] = $parts[33] . ' cm';
        }

        // Position 34: Width (cm)
        if (!empty($parts[34]) && is_numeric($parts[34]) && (float) $parts[34] > 0) {
            $attributes['width'] = $parts[34] . ' cm';
        }

        // Position 35: Length (cm)
        if (!empty($parts[35]) && is_numeric($parts[35]) && (float) $parts[35] > 0) {
            $attributes['length'] = $parts[35] . ' cm';
        }

        // Position 41: Family
        if (!empty($parts[41]) && $parts[41] !== '/' && $parts[41] !== '0') {
            $attributes['family'] = $parts[41];
        }

        // Position 42: Subfamily
        if (!empty($parts[42]) && $parts[42] !== '/' && $parts[42] !== '0') {
            $attributes['subfamily'] = $parts[42];
        }

        return $attributes;
    }

    /**
     * Convert pipe attributes to WooCommerce attribute format.
     *
     * Takes the associative array from extract_pipe_attributes() and converts
     * it to the WooCommerce attributes array format with Romanian labels.
     *
     * @param array $pipe_attributes Associative array from extract_pipe_attributes().
     * @return array WooCommerce attributes array.
     */
    public function convert_pipe_attributes_to_woo(array $pipe_attributes): array
    {
        if (empty($pipe_attributes)) {
            return [];
        }

        // Romanian labels for display
        $labels = [
            'brand' => 'Marcă',
            'color' => 'Culoare',
            'weight' => 'Greutate',
            'height' => 'Înălțime',
            'width' => 'Lățime',
            'length' => 'Lungime',
            'family' => 'Familie',
            'subfamily' => 'Subfamilie',
        ];

        $woo_attributes = [];

        foreach ($pipe_attributes as $key => $value) {
            $label = $labels[$key] ?? ucfirst($key);

            $woo_attributes[] = [
                'name' => $label,
                'options' => [$value],
                'visible' => true,
                'variation' => false,
            ];
        }

        return $woo_attributes;
    }

    /**
     * Build a clean HTML specs table from extracted attributes.
     *
     * Creates a formatted table with Romanian labels for use as short description.
     *
     * @param array $attributes Associative array of attributes from extract_pipe_attributes().
     * @return string HTML table string, or empty string if no attributes.
     */
    public function build_specs_table(array $attributes): string
    {
        if (empty($attributes)) {
            return '';
        }

        // Romanian labels for each attribute key
        $labels = [
            'brand' => 'Marcă',
            'color' => 'Culoare',
            'weight' => 'Greutate',
            'height' => 'Înălțime',
            'width' => 'Lățime',
            'length' => 'Lungime',
            'family' => 'Familie',
            'subfamily' => 'Subfamilie',
        ];

        $html = '<table class="product-specs woocommerce-product-attributes shop_attributes">';
        $html .= '<tbody>';

        foreach ($attributes as $key => $value) {
            $label = $labels[$key] ?? ucfirst($key);
            $escaped_label = function_exists('esc_html') ? esc_html($label) : htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $escaped_value = function_exists('esc_html') ? esc_html($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

            $html .= '<tr class="woocommerce-product-attributes-item woocommerce-product-attributes-item--' . esc_attr($key) . '">';
            $html .= '<th class="woocommerce-product-attributes-item__label">' . $escaped_label . '</th>';
            $html .= '<td class="woocommerce-product-attributes-item__value">' . $escaped_value . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }
}
