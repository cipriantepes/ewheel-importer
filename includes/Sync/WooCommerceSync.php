<?php
/**
 * WooCommerce Sync class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Api\EwheelApiClient;

/**
 * Handles synchronization with WooCommerce.
 */
class WooCommerceSync
{

    /**
     * Batch size for WooCommerce API requests.
     */
    private const BATCH_SIZE = 50;

    /**
     * The ewheel API client.
     *
     * @var EwheelApiClient
     */
    private EwheelApiClient $ewheel_client;

    /**
     * The product transformer.
     *
     * @var ProductTransformer
     */
    private ProductTransformer $transformer;

    /**
     * Constructor.
     *
     * @param EwheelApiClient    $ewheel_client The ewheel API client.
     * @param ProductTransformer $transformer   The product transformer.
     */
    public function __construct(EwheelApiClient $ewheel_client, ProductTransformer $transformer)
    {
        $this->ewheel_client = $ewheel_client;
        $this->transformer = $transformer;
    }

    /**
     * Sync all categories from ewheel.es.
     *
     * @return array Array of created/updated category mappings.
     */
    public function sync_categories(): array
    {
        $ewheel_categories = $this->ewheel_client->get_all_categories();
        $category_map = [];

        foreach ($ewheel_categories as $category) {
            $woo_category_id = $this->sync_single_category($category);
            if ($woo_category_id) {
                $category_map[$category['reference']] = $woo_category_id;
            }
        }

        // Update transformer with new category map
        $this->transformer->set_category_map($category_map);

        return $category_map;
    }

    /**
     * Sync a single category.
     *
     * @param array $ewheel_category The ewheel category data.
     * @return int|null The WooCommerce category ID or null on failure.
     */
    private function sync_single_category(array $ewheel_category): ?int
    {
        $reference = $ewheel_category['reference'] ?? '';
        $name = $ewheel_category['name'] ?? $reference;

        // Check if category exists by meta
        $existing = $this->find_category_by_ewheel_ref($reference);

        if ($existing) {
            // Update existing
            wp_update_term(
                $existing,
                'product_cat',
                [
                    'name' => $name,
                ]
            );
            return $existing;
        }

        // Create new category
        $result = wp_insert_term(
            $name,
            'product_cat',
            [
                'slug' => sanitize_title($reference),
            ]
        );

        if (is_wp_error($result)) {
            error_log('Failed to create category: ' . $result->get_error_message());
            return null;
        }

        $term_id = $result['term_id'];

        // Store ewheel reference in term meta
        update_term_meta($term_id, '_ewheel_reference', $reference);

        return $term_id;
    }

    /**
     * Find a category by ewheel reference.
     *
     * @param string $reference The ewheel reference.
     * @return int|null The term ID or null if not found.
     */
    private function find_category_by_ewheel_ref(string $reference): ?int
    {
        $terms = get_terms(
            [
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'meta_query' => [
                    [
                        'key' => '_ewheel_reference',
                        'value' => $reference,
                    ],
                ],
            ]
        );

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->term_id;
        }

        return null;
    }

    /**
     * Sync all products from ewheel.es.
     *
     * @param bool $dry_run If true, don't actually create/update products.
     * @return array Sync results.
     */
    public function sync_products(bool $dry_run = false): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'products' => [],
        ];

        // Get all products from ewheel
        $ewheel_products = $this->ewheel_client->get_all_products(['active' => true]);

        // Transform all products
        $woo_products = $this->transformer->transform_batch($ewheel_products);

        // Process in batches
        foreach (array_chunk($woo_products, self::BATCH_SIZE) as $batch) {
            if ($dry_run) {
                $results['products'] = array_merge($results['products'], $batch);
                $results['skipped'] += count($batch);
                continue;
            }

            $batch_results = $this->process_product_batch($batch);
            $results['created'] += $batch_results['created'];
            $results['updated'] += $batch_results['updated'];
            $results['errors'] += $batch_results['errors'];
        }

        return $results;
    }

    /**
     * Sync products modified since a specific date.
     *
     * @param string $since_date Date in format 'Y-m-d\TH:i:s'.
     * @param bool   $dry_run    If true, don't actually create/update products.
     * @return array Sync results.
     */
    public function sync_products_since(string $since_date, bool $dry_run = false): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'products' => [],
        ];

        // Get modified products
        $ewheel_products = [];
        $page = 0;

        do {
            $products = $this->ewheel_client->get_products_since($since_date, $page);
            $ewheel_products = array_merge($ewheel_products, $products);
            $page++;
        } while (count($products) >= 50);

        if (empty($ewheel_products)) {
            return $results;
        }

        // Transform and process
        $woo_products = $this->transformer->transform_batch($ewheel_products);

        foreach ($woo_products as $product) {
            if ($dry_run) {
                $results['products'][] = $product;
                $results['skipped']++;
                continue;
            }

            $result = $this->sync_single_product($product);
            if ($result === 'created') {
                $results['created']++;
            } elseif ($result === 'updated') {
                $results['updated']++;
            } else {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Process a batch of raw Ewheel products.
     *
     * @param array $ewheel_products Array of raw ewheel products.
     * @return array Batch results.
     */
    public function process_ewheel_products_batch(array $ewheel_products): array
    {
        // Transform products
        $woo_products = $this->transformer->transform_batch($ewheel_products);

        // Process them
        return $this->process_product_batch($woo_products);
    }

    /**
     * Process a batch of products.
     *
     * @param array $products Array of WooCommerce product data.
     * @return array Batch results.
     */
    private function process_product_batch(array $products): array
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        foreach ($products as $product_data) {
            $result = $this->sync_single_product($product_data);
            if ($result === 'created') {
                $results['created']++;
            } elseif ($result === 'updated') {
                $results['updated']++;
            } else {
                $results['errors']++;
            }
        }

        return $results;
    }

    /**
     * Sync a single product.
     *
     * @param array $product_data The WooCommerce product data.
     * @return string Result: 'created', 'updated', or 'error'.
     */
    private function sync_single_product(array $product_data): string
    {
        $sku = $product_data['sku'] ?? '';

        // Check if product exists by SKU
        $existing_id = wc_get_product_id_by_sku($sku);

        try {
            if ($existing_id) {
                $this->update_product($existing_id, $product_data);
                return 'updated';
            } else {
                $this->create_product($product_data);
                return 'created';
            }
        } catch (\Exception $e) {
            error_log('Failed to sync product ' . $sku . ': ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * Create a new WooCommerce product.
     *
     * @param array $data The product data.
     * @return int The new product ID.
     */
    private function create_product(array $data): int
    {
        $product_type = $data['type'] ?? 'simple';

        if ($product_type === 'variable') {
            $product = new \WC_Product_Variable();
        } else {
            $product = new \WC_Product_Simple();
        }

        $this->set_product_data($product, $data);
        $product_id = $product->save();

        // Handle variations for variable products
        if ($product_type === 'variable' && !empty($data['variations'])) {
            $this->create_variations($product_id, $data['variations'], $data['attributes'] ?? []);
        }

        return $product_id;
    }

    /**
     * Update an existing WooCommerce product.
     *
     * @param int   $product_id The product ID.
     * @param array $data       The product data.
     * @return void
     */
    private function update_product(int $product_id, array $data): void
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            throw new \RuntimeException('Product not found: ' . $product_id);
        }

        $this->set_product_data($product, $data);
        $product->save();

        // Update variations for variable products
        if ($product instanceof \WC_Product_Variable && !empty($data['variations'])) {
            $this->update_variations($product_id, $data['variations'], $data['attributes'] ?? []);
        }
    }

    /**
     * Set product data on a WooCommerce product object.
     *
     * @param \WC_Product $product The product object.
     * @param array       $data    The product data.
     * @return void
     */
    private function set_product_data(\WC_Product $product, array $data): void
    {
        if (isset($data['name'])) {
            $product->set_name($data['name']);
        }

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (isset($data['sku'])) {
            $product->set_sku($data['sku']);
        }

        if (isset($data['regular_price'])) {
            $product->set_regular_price($data['regular_price']);
        }

        if (isset($data['status'])) {
            $product->set_status($data['status']);
        }

        if (isset($data['manage_stock'])) {
            $product->set_manage_stock($data['manage_stock']);
        }

        // Set categories
        if (!empty($data['categories'])) {
            $category_ids = array_column($data['categories'], 'id');
            $product->set_category_ids($category_ids);
        }

        // Set images
        if (!empty($data['images'])) {
            $this->set_product_images($product, $data['images']);
        }

        // Set attributes
        if (!empty($data['attributes'])) {
            $this->set_product_attributes($product, $data['attributes']);
        }

        // Set meta data
        if (!empty($data['meta_data'])) {
            foreach ($data['meta_data'] as $meta) {
                $product->update_meta_data($meta['key'], $meta['value']);
            }
        }
    }

    /**
     * Set product images.
     *
     * @param \WC_Product $product The product.
     * @param array       $images  Array of image data.
     * @return void
     */
    private function set_product_images(\WC_Product $product, array $images): void
    {
        $image_ids = [];

        foreach ($images as $image) {
            $url = $image['src'] ?? '';
            if (empty($url)) {
                continue;
            }

            // Check if image already exists
            $attachment_id = $this->get_attachment_by_url($url);

            if (!$attachment_id) {
                // Download and create attachment
                $attachment_id = $this->upload_image_from_url($url);
            }

            if ($attachment_id) {
                $image_ids[] = $attachment_id;
            }
        }

        if (!empty($image_ids)) {
            $product->set_image_id($image_ids[0]);
            if (count($image_ids) > 1) {
                $product->set_gallery_image_ids(array_slice($image_ids, 1));
            }
        }
    }

    /**
     * Get attachment ID by URL.
     *
     * @param string $url The image URL.
     * @return int|null The attachment ID or null.
     */
    private function get_attachment_by_url(string $url): ?int
    {
        global $wpdb;

        $attachment_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ewheel_source_url' AND meta_value = %s",
                $url
            )
        );

        return $attachment_id ? (int) $attachment_id : null;
    }

    /**
     * Upload an image from URL.
     *
     * @param string $url The image URL.
     * @return int|null The attachment ID or null on failure.
     */
    private function upload_image_from_url(string $url): ?int
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download file to temp location
        $temp_file = download_url($url);

        if (is_wp_error($temp_file)) {
            return null;
        }

        $file_name = basename(wp_parse_url($url, PHP_URL_PATH));
        $file_array = [
            'name' => $file_name,
            'tmp_name' => $temp_file,
        ];

        // Upload to media library
        $attachment_id = media_handle_sideload($file_array, 0);

        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return null;
        }

        // Store source URL in meta
        update_post_meta($attachment_id, '_ewheel_source_url', $url);

        return $attachment_id;
    }

    /**
     * Set product attributes.
     *
     * @param \WC_Product $product    The product.
     * @param array       $attributes Array of attribute data.
     * @return void
     */
    private function set_product_attributes(\WC_Product $product, array $attributes): void
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

    /**
     * Create variations for a variable product.
     *
     * @param int   $product_id The parent product ID.
     * @param array $variations Array of variation data.
     * @param array $attributes The product attributes.
     * @return void
     */
    private function create_variations(int $product_id, array $variations, array $attributes): void
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
    private function update_variations(int $product_id, array $variations, array $attributes): void
    {
        // Get existing variations
        $product = wc_get_product($product_id);
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

    /**
     * Get the last sync timestamp.
     *
     * @return string|null The timestamp or null if never synced.
     */
    public function get_last_sync_time(): ?string
    {
        return get_option('ewheel_importer_last_sync', null);
    }

    /**
     * Update the last sync timestamp.
     *
     * @return void
     */
    public function update_last_sync_time(): void
    {
        update_option('ewheel_importer_last_sync', gmdate('Y-m-d\TH:i:s'));
    }
}
