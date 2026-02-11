<?php
/**
 * Product Lookup Cache class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

/**
 * In-memory lookup cache for product existence checks during sync.
 *
 * Bulk-loads SKU, _ewheel_reference, and _ewheel_reference_base mappings
 * into hash maps at initialization, providing O(1) lookups thereafter.
 *
 * Replaces ~7,000-10,000 individual DB queries with 3 bulk queries.
 */
class ProductLookupCache
{

    /** @var array<string, int> SKU => product_id */
    private array $sku_map = [];

    /** @var array<string, int> ewheel_reference => product_id */
    private array $reference_map = [];

    /** @var array<string, int> ewheel_reference_base => product_id (first match) */
    private array $reference_base_map = [];

    /** @var bool Whether the cache has been populated */
    private bool $loaded = false;

    /**
     * Warm the cache by bulk-loading all mappings.
     *
     * Executes exactly 3 SQL queries total (regardless of product count).
     */
    public function warm(): void
    {
        global $wpdb;

        // Query 1: All SKU => product_id mappings
        $sku_rows = $wpdb->get_results(
            "SELECT pm.meta_value AS sku, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_sku'
               AND pm.meta_value != ''
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status != 'trash'",
            ARRAY_A
        );

        foreach ($sku_rows as $row) {
            $this->sku_map[$row['sku']] = (int) $row['post_id'];
        }
        unset($sku_rows);

        // Query 2: All _ewheel_reference => product_id mappings
        $ref_rows = $wpdb->get_results(
            "SELECT pm.meta_value AS ref, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_ewheel_reference'
               AND pm.meta_value != ''
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status != 'trash'",
            ARRAY_A
        );

        foreach ($ref_rows as $row) {
            $this->reference_map[$row['ref']] = (int) $row['post_id'];
        }
        unset($ref_rows);

        // Query 3: All _ewheel_reference_base => product_id mappings
        $base_rows = $wpdb->get_results(
            "SELECT pm.meta_value AS base_ref, pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_ewheel_reference_base'
               AND pm.meta_value != ''
               AND p.post_type IN ('product', 'product_variation')
               AND p.post_status != 'trash'",
            ARRAY_A
        );

        foreach ($base_rows as $row) {
            // Only keep first match (matches original LIMIT 1 behavior)
            if (!isset($this->reference_base_map[$row['base_ref']])) {
                $this->reference_base_map[$row['base_ref']] = (int) $row['post_id'];
            }
        }
        unset($base_rows);

        $this->loaded = true;
    }

    /**
     * Find product ID by SKU.
     *
     * @param string $sku The product SKU.
     * @return int Product ID or 0 if not found.
     */
    public function find_by_sku(string $sku): int
    {
        if (empty($sku)) {
            return 0;
        }
        return $this->sku_map[$sku] ?? 0;
    }

    /**
     * Find product ID by _ewheel_reference meta.
     *
     * @param string $reference The ewheel reference.
     * @return int Product ID or 0 if not found.
     */
    public function find_by_reference(string $reference): int
    {
        if (empty($reference)) {
            return 0;
        }
        return $this->reference_map[$reference] ?? 0;
    }

    /**
     * Find product ID by _ewheel_reference_base meta.
     *
     * @param string $base The reference base string.
     * @return int Product ID or 0 if not found.
     */
    public function find_by_reference_base(string $base): int
    {
        if (empty($base)) {
            return 0;
        }
        return $this->reference_base_map[$base] ?? 0;
    }

    /**
     * Update cache after a product is created or updated.
     *
     * @param int    $product_id The product ID.
     * @param string $sku        The product SKU (may be empty).
     * @param string $reference  The _ewheel_reference value.
     * @param string $base       The _ewheel_reference_base value.
     */
    public function record(int $product_id, string $sku = '', string $reference = '', string $base = ''): void
    {
        if (!empty($sku)) {
            $this->sku_map[$sku] = $product_id;
        }
        if (!empty($reference)) {
            $this->reference_map[$reference] = $product_id;
        }
        if (!empty($base) && !isset($this->reference_base_map[$base])) {
            $this->reference_base_map[$base] = $product_id;
        }
    }

    /**
     * Remove a SKU from the cache.
     *
     * Used for migration cleanup of old -parent SKUs.
     *
     * @param string $sku The SKU to remove.
     */
    public function remove_sku(string $sku): void
    {
        unset($this->sku_map[$sku]);
    }

    /**
     * Check if cache has been warmed.
     *
     * @return bool
     */
    public function is_loaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Get cache statistics for logging.
     *
     * @return array{sku_count: int, reference_count: int, base_count: int}
     */
    public function get_stats(): array
    {
        return [
            'sku_count'       => count($this->sku_map),
            'reference_count' => count($this->reference_map),
            'base_count'      => count($this->reference_base_map),
        ];
    }
}
