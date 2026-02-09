<?php
/**
 * Model Service class.
 *
 * @package Trotibike\EwheelImporter\Service
 */

namespace Trotibike\EwheelImporter\Service;

/**
 * Handles scooter model taxonomy operations.
 *
 * Manages the scooter_model taxonomy for WooCommerce products,
 * providing methods to create model terms and assign them to products.
 * Model terms are looked up by ewheel model ID stored as term meta.
 */
class ModelService
{
    /**
     * Taxonomy name for scooter models.
     */
    public const TAXONOMY = 'scooter_model';

    /**
     * Get or create a model term by ewheel model ID.
     *
     * @param string $model_id The ewheel model ID (e.g., "110").
     * @return int|null Term ID or null on failure.
     */
    public function get_or_create_model(string $model_id): ?int
    {
        $model_id = trim($model_id);

        if (empty($model_id)) {
            return null;
        }

        // Look up by ewheel model ID in term meta
        $terms = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key'   => '_ewheel_model_id',
                    'value' => $model_id,
                ],
            ],
        ]);

        if (!empty($terms) && !is_wp_error($terms)) {
            return $terms[0]->term_id;
        }

        // Also check by slug (in case term was renamed but slug matches)
        $term = get_term_by('slug', $model_id, self::TAXONOMY);
        if ($term instanceof \WP_Term) {
            update_term_meta($term->term_id, '_ewheel_model_id', $model_id);
            return $term->term_id;
        }

        // Create new model term with numeric ID as name
        $result = wp_insert_term($model_id, self::TAXONOMY, [
            'slug' => sanitize_title($model_id),
        ]);

        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'term_exists') {
                $existing_id = (int) $result->get_error_data();
                update_term_meta($existing_id, '_ewheel_model_id', $model_id);
                return $existing_id;
            }
            error_log('[Ewheel Model] Failed to create model "' . $model_id . '": ' . $result->get_error_message());
            return null;
        }

        $term_id = $result['term_id'];
        update_term_meta($term_id, '_ewheel_model_id', $model_id);

        return $term_id;
    }

    /**
     * Assign multiple model IDs to a product.
     *
     * @param int   $product_id The product ID.
     * @param array $model_ids  Array of ewheel model IDs.
     * @return bool Success.
     */
    public function assign_models_to_product(int $product_id, array $model_ids): bool
    {
        if (empty($model_ids)) {
            return false;
        }

        $term_ids = [];
        foreach ($model_ids as $model_id) {
            $term_id = $this->get_or_create_model((string) $model_id);
            if ($term_id !== null) {
                $term_ids[] = $term_id;
            }
        }

        if (empty($term_ids)) {
            return false;
        }

        $result = wp_set_object_terms($product_id, $term_ids, self::TAXONOMY);

        if (is_wp_error($result)) {
            error_log('[Ewheel Model] Failed to assign models to product ' . $product_id . ': ' . $result->get_error_message());
            return false;
        }

        return true;
    }

    /**
     * Get models assigned to a product.
     *
     * @param int $product_id The product ID.
     * @return array Array of WP_Term objects.
     */
    public function get_product_models(int $product_id): array
    {
        $terms = get_the_terms($product_id, self::TAXONOMY);

        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        return $terms;
    }

    /**
     * Get all model terms.
     *
     * @param array $args Optional. Arguments for get_terms().
     * @return array Array of WP_Term objects.
     */
    public function get_all_models(array $args = []): array
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
}
