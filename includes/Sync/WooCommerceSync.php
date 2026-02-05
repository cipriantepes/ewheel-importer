<?php
/**
 * WooCommerce Sync class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Log\PersistentLogger;

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
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

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
     * Attribute Service.
     *
     * @var \Trotibike\EwheelImporter\Service\AttributeService
     */
    private $attribute_service;

    /**
     * Variation Service.
     *
     * @var \Trotibike\EwheelImporter\Service\VariationService
     */
    private $variation_service;

    /**
     * Image Service.
     *
     * @var \Trotibike\EwheelImporter\Service\ImageService
     */
    private $image_service;

    /**
     * Category Repository.
     *
     * @var \Trotibike\EwheelImporter\Repository\CategoryRepository
     */
    private $category_repository;

    /**
     * Constructor.
     *
     * @param EwheelApiClient                                     $ewheel_client       The ewheel API client.
     * @param ProductTransformer                                  $transformer         The product transformer.
     * @param \Trotibike\EwheelImporter\Repository\CategoryRepository $category_repository Category repository.
     * @param \Trotibike\EwheelImporter\Service\AttributeService  $attribute_service   Attribute service.
     * @param \Trotibike\EwheelImporter\Service\VariationService  $variation_service   Variation service.
     * @param \Trotibike\EwheelImporter\Service\ImageService      $image_service       Image service.
     * @param Configuration                                       $config              Configuration.
     */
    public function __construct(
        EwheelApiClient $ewheel_client,
        ProductTransformer $transformer,
        \Trotibike\EwheelImporter\Repository\CategoryRepository $category_repository,
        \Trotibike\EwheelImporter\Service\AttributeService $attribute_service,
        \Trotibike\EwheelImporter\Service\VariationService $variation_service,
        \Trotibike\EwheelImporter\Service\ImageService $image_service,
        Configuration $config
    ) {
        $this->ewheel_client = $ewheel_client;
        $this->transformer = $transformer;
        $this->category_repository = $category_repository;
        $this->attribute_service = $attribute_service;
        $this->variation_service = $variation_service;
        $this->image_service = $image_service;
        $this->config = $config;
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

        // Use cache-first approach to avoid overwhelming translation API
        $translation_repo = new \Trotibike\EwheelImporter\Repository\TranslationRepository();
        $target_lang = $this->config->get_target_language();

        // First pass: extract source texts and prepare cache lookup
        $category_data = [];
        $texts_by_lang = [];
        foreach ($ewheel_categories as $category) {
            $reference = $category['reference'] ?? ($category['Reference'] ?? '');
            $raw_name = $category['name'] ?? ($category['Name'] ?? $reference);

            // Extract source text (prefer English over Spanish)
            $source_text = '';
            $source_lang = 'en';
            if (is_array($raw_name)) {
                if (isset($raw_name['translations']) && is_array($raw_name['translations'])) {
                    foreach (['en', 'es'] as $preferred_lang) {
                        foreach ($raw_name['translations'] as $t) {
                            if (isset($t['reference']) && $t['reference'] === $preferred_lang && !empty($t['value'])) {
                                $source_text = $t['value'];
                                $source_lang = $preferred_lang;
                                break 2;
                            }
                        }
                    }
                    if (empty($source_text) && !empty($raw_name['translations'][0]['value'])) {
                        $source_text = $raw_name['translations'][0]['value'];
                        $source_lang = $raw_name['translations'][0]['reference'] ?? 'en';
                    }
                } elseif (!empty($raw_name['en'])) {
                    $source_text = $raw_name['en'];
                    $source_lang = 'en';
                } elseif (!empty($raw_name['es'])) {
                    $source_text = $raw_name['es'];
                    $source_lang = 'es';
                }
            } else {
                $source_text = (string) $raw_name;
            }

            if (empty($source_text)) {
                $source_text = $reference;
            }

            $category_data[$reference] = [
                'category' => $category,
                'source_text' => $source_text,
                'source_lang' => $source_lang,
            ];
            $texts_by_lang[$source_lang][] = $source_text;
        }

        // Batch query cache (1-2 DB queries total)
        $cache_map = [];
        foreach ($texts_by_lang as $src_lang => $texts) {
            $batch_result = $translation_repo->get_batch($texts, $src_lang, $target_lang);
            foreach ($texts as $text) {
                $hash = $translation_repo->generate_hash($text, $src_lang, $target_lang);
                if (isset($batch_result[$hash])) {
                    $cache_map[$hash] = $batch_result[$hash];
                }
            }
        }

        // Second pass: create/update categories using cached translations or original text
        foreach ($category_data as $reference => $data) {
            $hash = $translation_repo->generate_hash($data['source_text'], $data['source_lang'], $target_lang);
            $name = $cache_map[$hash] ?? $data['source_text'];

            $woo_category_id = $this->sync_single_category_with_name($data['category'], $name);
            if ($woo_category_id) {
                $category_map[$reference] = $woo_category_id;
            }
        }

        // Update transformer with new category map
        $this->transformer->set_category_map($category_map);

        return $category_map;
    }

    /**
     * Sync a single category with a pre-computed name.
     *
     * @param array  $ewheel_category The ewheel category data.
     * @param string $name            The category name (already translated or original).
     * @return int|null The WooCommerce category ID or null on failure.
     */
    private function sync_single_category_with_name(array $ewheel_category, string $name): ?int
    {
        $reference = $ewheel_category['reference'] ?? ($ewheel_category['Reference'] ?? '');

        // Fallback to reference if name is empty
        if (empty($name)) {
            $name = $reference;
        }

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
                // Let WP generate slug from name for SEO
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
            $term = $terms[0];
            return ($term instanceof \WP_Term) ? $term->term_id : (int) $term;
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
        $max_pages = 100; // Safety limit

        do {
            $products = $this->ewheel_client->get_products_since($since_date, $page);

            if (empty($products)) {
                break;
            }

            $ewheel_products = array_merge($ewheel_products, $products);
            $page++;

            // Safety limit to prevent infinite loops
            if ($page >= $max_pages) {
                break;
            }
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
     * @param array      $ewheel_products Array of raw ewheel products.
     * @param mixed|null $profile_config  Optional profile configuration (for future use).
     * @return array Batch results.
     */
    public function process_ewheel_products_batch(array $ewheel_products, $profile_config = null): array
    {
        // DEBUG: Log batch start
        PersistentLogger::info("[DEBUG] process_ewheel_products_batch called with " . count($ewheel_products) . " products");

        // Load category map for this batch
        if ($this->category_repository) {
            $category_map = $this->category_repository->get_combined_mapping();
            $this->transformer->set_category_map($category_map);
        }

        $results = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];

        // Process products ONE AT A TIME to prevent memory exhaustion
        // (Following WP All Import patterns)
        foreach ($ewheel_products as $raw_product) {
            // DEBUG: Log each product being processed
            $raw_ref = $raw_product['reference'] ?? ($raw_product['Reference'] ?? 'no-ref');
            $raw_name = $raw_product['productName']['es'] ?? ($raw_product['productName'] ?? ($raw_product['name']['es'] ?? ($raw_product['name'] ?? 'no-name')));
            if (is_array($raw_name)) {
                $raw_name = $raw_name['es'] ?? reset($raw_name) ?: 'no-name';
            }
            PersistentLogger::info("[DEBUG] Processing ewheel product: reference={$raw_ref}, name=" . substr($raw_name, 0, 50));

            // Transform single product (may return multiple if simple mode)
            $transformed_products = $this->transformer->transform($raw_product);

            // DEBUG: Log transformation result
            PersistentLogger::info("[DEBUG] Transformer returned " . count($transformed_products) . " products for reference " . $raw_ref);

            // Process each transformed product
            foreach ($transformed_products as $product_data) {
                $result = $this->sync_single_product($product_data);

                if ($result === 'created') {
                    $results['created']++;
                } elseif ($result === 'updated') {
                    $results['updated']++;
                } else {
                    $results['errors']++;
                }

                // Memory cleanup after each product
                unset($product_data);
            }

            // Memory cleanup after each raw product
            unset($raw_product, $transformed_products);

            // Flush WP object cache periodically to prevent memory bloat
            wp_cache_flush();
        }

        return $results;
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

        // DEBUG: Log SKU lookup result
        PersistentLogger::info("[DEBUG] sync_single_product - SKU: {$sku}, existing_id: " . ($existing_id ?: 'none'));

        try {
            if ($existing_id) {
                $this->update_product($existing_id, $product_data);
                PersistentLogger::info("[DEBUG] Product updated - ID: {$existing_id}, SKU: {$sku}");
                return 'updated';
            } else {
                // Before creating, check for parent relationship
                $parent_id = $this->detect_parent_relationship($product_data);
                if ($parent_id) {
                    // Add parent reference to meta data
                    $product_data['meta_data'][] = [
                        'key' => '_ewheel_parent_product_id',
                        'value' => $parent_id,
                    ];
                }

                $new_id = $this->create_product($product_data);
                PersistentLogger::info("[DEBUG] Product created - ID: {$new_id}, SKU: {$sku}");
                return 'created';
            }
        } catch (\Exception $e) {
            PersistentLogger::error("[DEBUG] Exception in sync_single_product for SKU {$sku}: " . $e->getMessage());
            // Log to persistent logger if available
            if (class_exists(\Trotibike\EwheelImporter\Log\PersistentLogger::class)) {
                \Trotibike\EwheelImporter\Log\PersistentLogger::error(
                    'Failed to sync product: ' . $e->getMessage(),
                    $sku
                );
            }
            error_log('Failed to sync product ' . $sku . ': ' . $e->getMessage());
            return 'error';
        }
    }

    /**
     * Detect if a product has a parent relationship with an existing product.
     *
     * Checks if there's an existing product with the same base reference.
     * For example, if importing "MP-010" and "MP-010-parent" already exists,
     * they are considered related.
     *
     * @param array $product_data The product data being imported.
     * @return int|null The parent product ID if found, null otherwise.
     */
    private function detect_parent_relationship(array $product_data): ?int
    {
        // Get the reference base from meta data
        $reference_base = null;
        if (!empty($product_data['meta_data'])) {
            foreach ($product_data['meta_data'] as $meta) {
                if ($meta['key'] === '_ewheel_reference_base') {
                    $reference_base = $meta['value'];
                    break;
                }
            }
        }

        if (empty($reference_base)) {
            return null;
        }

        // Look for existing products with the same base reference
        global $wpdb;

        $parent_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_ewheel_reference_base'
                AND meta_value = %s
                LIMIT 1",
                $reference_base
            )
        );

        if ($parent_id) {
            return (int) $parent_id;
        }

        // Also check for parent SKU pattern (e.g., base-parent)
        $parent_sku = $reference_base . '-parent';
        $parent_by_sku = wc_get_product_id_by_sku($parent_sku);

        if ($parent_by_sku) {
            return $parent_by_sku;
        }

        return null;
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
            $this->variation_service->create_variations($product_id, $data['variations'], $data['attributes'] ?? []);
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
            $this->variation_service->update_variations($product_id, $data['variations'], $data['attributes'] ?? []);
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
        $is_update = $product->get_id() > 0;
        $sync_protection = $this->config->get('sync_protection') ?: [];

        $is_protected = function ($field) use ($is_update, $sync_protection) {
            return $is_update && !empty($sync_protection[$field]);
        };

        if (isset($data['name']) && !$is_protected('name')) {
            $product->set_name($data['name']);
        }

        if (isset($data['description']) && !$is_protected('description')) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description']) && !$is_protected('short_description')) {
            $product->set_short_description($data['short_description']);
        }

        if (isset($data['sku'])) {
            $product->set_sku($data['sku']);
        }

        if (isset($data['regular_price']) && !$is_protected('price')) {
            $product->set_regular_price($data['regular_price']);
        }

        if (isset($data['status'])) {
            $product->set_status($data['status']);
        }

        if (isset($data['manage_stock'])) {
            $product->set_manage_stock($data['manage_stock']);
        }

        // Set categories
        if (!empty($data['categories']) && !$is_protected('categories')) {
            $category_ids = array_column($data['categories'], 'id');
            $product->set_category_ids($category_ids);
        }

        // Set images
        if (!empty($data['images']) && !$is_protected('image')) {
            $this->set_product_images($product, $data['images']);
        }

        // Set attributes
        if (!empty($data['attributes']) && !$is_protected('attributes')) {
            $this->attribute_service->set_product_attributes($product, $data['attributes']);
        }

        // Set meta data
        if (!empty($data['meta_data'])) {
            foreach ($data['meta_data'] as $meta) {
                $product->update_meta_data($meta['key'], $meta['value']);

                // Set WooCommerce native GTIN field if EAN is found
                // Available in WooCommerce 8.3+ via set_global_unique_id()
                if ($meta['key'] === '_ewheel_ean' && !empty($meta['value'])) {
                    if (method_exists($product, 'set_global_unique_id')) {
                        $product->set_global_unique_id($meta['value']);
                    }
                }
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

            // Use ImageService to import
            $attachment_id = $this->image_service->import_from_url($url);

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
