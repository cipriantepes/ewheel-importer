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
     * Known model ID → scooter name mapping.
     * Scraped from ewheel.es product catalog (single-model products only).
     */
    public const MODEL_NAMES = [
        '1'   => 'Ninebot ES2',
        '2'   => 'Ninebot ES4',
        '3'   => 'Ninebot ES1',
        '7'   => 'Xiaomi Pro 2',
        '8'   => 'Xiaomi Mi 3',
        '13'  => 'Xiaomi Pro',
        '14'  => 'Ninebot MAX G30',
        '25'  => 'Dualtron Speedway 4',
        '26'  => 'SmartGyro Rockway Pro V2.0',
        '29'  => 'Ninebot KickScooter MAX G2',
        '52'  => 'Xiaomi Mi 4 Pro (1st Gen)',
        '57'  => 'Ninebot F2',
        '59'  => 'SmartGyro SpeedWay PRO',
        '62'  => 'Wispeed T850',
        '67'  => 'Ninebot KickScooter E22',
        '68'  => 'Ninebot KickScooter E25',
        '69'  => 'Ninebot KickScooter E45',
        '71'  => 'Dualtron Mini',
        '79'  => 'Dualtron Thunder',
        '80'  => 'Dualtron Raptor',
        '81'  => 'Dualtron Thunder 2',
        '82'  => 'Dualtron Compact',
        '83'  => 'Dualtron Storm',
        '85'  => 'Dualtron Spider',
        '87'  => 'Dualtron Speedway',
        '88'  => 'Dualtron Achilleus',
        '90'  => 'Dualtron Eagle',
        '91'  => 'Dualtron Eagle',
        '93'  => 'Dualtron 3',
        '95'  => 'Dualtron Storm',
        '96'  => 'Dualtron Victor Luxury',
        '102' => 'Xiaomi Mi 3 Lite',
        '110' => 'Xiaomi Mi 4 Lite',
        '112' => 'Dualtron Thunder',
        '113' => 'Dualtron Victor',
        '116' => 'Dualtron Raptor',
        '117' => 'Dualtron Victor Luxury Plus',
        '118' => 'NIU KQi3',
        '119' => 'NIU KQi2 Pro',
        '120' => 'NIU KQi1',
        '122' => 'Xiaomi Mi 4',
        '124' => 'Kukirin G2 Max',
        '125' => 'Kukirin G3 Pro',
        '127' => 'Xiaomi Mi 4 Ultra',
        '129' => 'NIU KQi3 Max',
        '130' => 'NIU KQi3 Pro',
        '140' => 'Dualtron Mini',
        '146' => 'Dualtron Speedway 5 Dual Motor',
        '147' => 'Xiaomi Mi 4 GO',
        '152' => 'Dualtron Mini Special Long Body Single Motor',
        '153' => 'Dualtron Mini Special Long Body Dual Motor',
        '158' => 'Dualtron Popular Dual Motor',
        '159' => 'Dualtron Popular Single Motor',
        '204' => 'Ninebot KickScooter MAX G2',
        '205' => 'Ninebot KickScooter MAX G2 D',
        '243' => 'Wispeed T850',
        '327' => 'Kugoo G2 Pro',
        '444' => 'Navee N40',
        '452' => 'Navee N65',
        '459' => 'Xiaomi Mi 4 Lite (2nd Generation)',
        '485' => 'Xiaomi Mi 4 Pro (2nd Generation)',
        '486' => 'Xiaomi Mi 4 Pro Max',
        '899' => 'Xiaomi Mi 4 - Versión FR',
        '920' => 'Niu KQi300X',
        '921' => 'Niu KQi4 Sport',
        '923' => 'Niu KQi AIR',
        '924' => 'Niu KQi300P',
        '925' => 'Niu KQi100',
        '926' => 'Niu KQi3 Sport',
        '932' => 'Wispeed T865',
        '934' => 'Ninebot F3 E',
        '937' => 'Ninebot F3 E',
        '947' => 'Ninebot ZT3 Pro EU',
        '956' => 'Niu KQi1 Pro',
        '961' => 'Smartgyro Crossover Dual Max 2',
        '973' => 'Xiaomi Mi4 Lite Gen2 (IT/DE)',
        '974' => 'Xiaomi Mi4 Pro Gen1 (IT)',
        '975' => 'Xiaomi Mi4 Pro Gen1 (IT)',
        '976' => 'Xiaomi Mi4 Pro Plus',
        '978' => 'Xiaomi Mi 5 Max',
        '979' => 'Xiaomi Mi 5 Pro',
        '980' => 'Xiaomi Mi 5',
        '981' => 'Navee N20',
        '990' => 'Niu KQi2',
    ];

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

        // Create new model term — use known name if available, otherwise numeric ID
        $term_name = self::MODEL_NAMES[$model_id] ?? $model_id;
        $result = wp_insert_term($term_name, self::TAXONOMY, [
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
