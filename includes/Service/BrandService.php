<?php
/**
 * Brand Service class.
 *
 * @package Trotibike\EwheelImporter\Service
 */

namespace Trotibike\EwheelImporter\Service;

/**
 * Handles product brand taxonomy operations.
 *
 * Manages the product_brand taxonomy for WooCommerce products,
 * providing methods to create brands and assign them to products.
 */
class BrandService
{
    /**
     * Taxonomy name for product brands.
     */
    public const TAXONOMY = 'product_brand';

    /**
     * Get or create a brand term.
     *
     * @param string $brand_name The brand name.
     * @return int|null Term ID or null on failure.
     */
    public function get_or_create_brand(string $brand_name): ?int
    {
        $brand_name = trim($brand_name);

        if (empty($brand_name)) {
            return null;
        }

        // Check if brand exists by name
        $term = get_term_by('name', $brand_name, self::TAXONOMY);

        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }

        // Check by slug (in case of different casing)
        $slug = sanitize_title($brand_name);
        $term = get_term_by('slug', $slug, self::TAXONOMY);

        if ($term instanceof \WP_Term) {
            return $term->term_id;
        }

        // Create new brand
        $result = wp_insert_term($brand_name, self::TAXONOMY, [
            'slug' => $slug,
        ]);

        if (is_wp_error($result)) {
            // Log error but don't fail
            error_log('[Ewheel Brand] Failed to create brand "' . $brand_name . '": ' . $result->get_error_message());
            return null;
        }

        return $result['term_id'];
    }

    /**
     * Assign brand to product.
     *
     * @param int    $product_id The product ID.
     * @param string $brand_name The brand name.
     * @return bool Success.
     */
    public function assign_brand_to_product(int $product_id, string $brand_name): bool
    {
        $term_id = $this->get_or_create_brand($brand_name);

        if ($term_id === null) {
            return false;
        }

        $result = wp_set_object_terms($product_id, [$term_id], self::TAXONOMY);

        if (is_wp_error($result)) {
            error_log('[Ewheel Brand] Failed to assign brand to product ' . $product_id . ': ' . $result->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * Get brand for a product.
     *
     * @param int $product_id The product ID.
     * @return string|null Brand name or null if not set.
     */
    public function get_product_brand(int $product_id): ?string
    {
        $terms = get_the_terms($product_id, self::TAXONOMY);

        if (is_wp_error($terms) || empty($terms)) {
            return null;
        }

        return $terms[0]->name;
    }

    /**
     * Get all brands.
     *
     * @param array $args Optional. Arguments for get_terms().
     * @return array Array of WP_Term objects.
     */
    public function get_all_brands(array $args = []): array
    {
        $defaults = [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ];

        $terms = get_terms(array_merge($defaults, $args));

        if (is_wp_error($terms)) {
            return [];
        }

        return $terms;
    }

    /**
     * Remove brand from product.
     *
     * @param int $product_id The product ID.
     * @return bool Success.
     */
    public function remove_brand_from_product(int $product_id): bool
    {
        $result = wp_set_object_terms($product_id, [], self::TAXONOMY);

        return !is_wp_error($result);
    }
}
