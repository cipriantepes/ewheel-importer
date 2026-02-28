<?php
/**
 * Reorganize Kukirin spare parts into Brand > Model category hierarchy.
 *
 * Creates: Piese de schimb > Kukirin > Kukirin {Model}
 * Fills missing product_model assignments from product titles.
 * Reports pa_tip-componenta coverage gaps.
 *
 * Usage: wp eval-file reorganize-spare-parts.php
 */

define('REORG_APPLY', false); // Set to true to write changes
define('REORG_BATCH_SIZE', 100);

// Piese de schimb category ID
define('PIESE_DE_SCHIMB_ID', 926);

// Kukirin model category IDs (product_cat) — all currently parent=926
define('KUKIRIN_MODEL_CATS', [
    'Kukirin A1'          => 939,
    'Kukirin C1 Pro'      => 943,
    'Kukirin G2'          => 927,
    'Kukirin G2 Master'   => 929,
    'Kukirin G2 Max'      => 949,
    'Kukirin G2 Pro 2023' => 945,
    'Kukirin G2 Pro 2024' => 947,
    'Kukirin G2 Ultra'    => 971,
    'Kukirin G3'          => 951,
    'Kukirin G3 Pro'      => 931,
    'Kukirin G4'          => 933,
    'Kukirin G4 Max'      => 935,
    'Kukirin M4 Max'      => 941,
    'Kukirin S1 Max'      => 937,
    'Kukirin T3'          => 968,
]);

// Kukirin product_model term IDs
define('KUKIRIN_MODEL_TERMS', [
    'Kukirin A1'          => 958,
    'Kukirin C1 Pro'      => 956,
    'Kukirin G2'          => 963,
    'Kukirin G2 Master'   => 962,
    'Kukirin G2 Max'      => 456,
    'Kukirin G2 Pro 2023' => 955,
    'Kukirin G2 Pro 2024' => 954,
    'Kukirin G2 Ultra'    => 973,
    'Kukirin G3'          => 953,
    'Kukirin G3 Pro'      => 606,
    'Kukirin G4'          => 961,
    'Kukirin G4 Max'      => 960,
    'Kukirin M4 Max'      => 957,
    'Kukirin S1 Max'      => 959,
    'Kukirin T3'          => 970,
]);

// Kukirin product_brand term ID
define('KUKIRIN_BRAND_TERM_ID', 160);

/**
 * Extract Kukirin model name from product title.
 * Returns the model name (e.g., "Kukirin G3 Pro") or empty string.
 */
function extract_model_from_title($title) {
    // Match "Kukirin {Model}" patterns — order matters (longer names first)
    $models = [
        'Kukirin G2 Pro 2024',
        'Kukirin G2 Pro 2023',
        'Kukirin G2 Master',
        'Kukirin G2 Ultra',
        'Kukirin G2 Max',
        'Kukirin G3 Pro',
        'Kukirin G4 Max',
        'Kukirin M4 Max',
        'Kukirin S1 Max',
        'Kukirin C1 Pro',
        'Kukirin G2',
        'Kukirin G3',
        'Kukirin G4',
        'Kukirin A1',
        'Kukirin T3',
    ];

    foreach ($models as $model) {
        if (stripos($title, $model) !== false) {
            return $model;
        }
    }

    return '';
}

function run() {
    global $wpdb;

    $stats = [
        'brand_cat_created'    => false,
        'cats_reparented'      => 0,
        'brand_assigned'       => 0,
        'brand_already'        => 0,
        'model_assigned'       => 0,
        'model_already'        => 0,
        'model_not_found'      => 0,
        'tip_componenta_has'   => 0,
        'tip_componenta_missing' => 0,
        'products_scanned'     => 0,
        'products_in_piese'    => 0,
    ];

    $missing_models = [];
    $missing_tip = [];

    // ─── Step 1: Create "Kukirin" brand category under Piese de schimb ───

    WP_CLI::log('');
    WP_CLI::log('=== Step 1: Create Kukirin brand category ===');

    // Check if "Kukirin" category already exists under Piese de schimb
    $kukirin_brand_cat = get_term_by('name', 'Kukirin', 'product_cat');
    $kukirin_brand_cat_id = null;

    if ($kukirin_brand_cat && $kukirin_brand_cat->parent == PIESE_DE_SCHIMB_ID) {
        $kukirin_brand_cat_id = $kukirin_brand_cat->term_id;
        WP_CLI::log("  Kukirin brand category already exists (ID {$kukirin_brand_cat_id})");
    } else {
        WP_CLI::log("  Creating 'Kukirin' category under Piese de schimb (ID " . PIESE_DE_SCHIMB_ID . ")");
        if (REORG_APPLY) {
            $result = wp_insert_term('Kukirin', 'product_cat', [
                'parent' => PIESE_DE_SCHIMB_ID,
                'slug'   => 'piese-kukirin',
            ]);
            if (is_wp_error($result)) {
                // Maybe exists with different parent — try to find by slug
                $existing = get_term_by('slug', 'piese-kukirin', 'product_cat');
                if ($existing) {
                    $kukirin_brand_cat_id = $existing->term_id;
                    // Update parent
                    wp_update_term($kukirin_brand_cat_id, 'product_cat', ['parent' => PIESE_DE_SCHIMB_ID]);
                    WP_CLI::log("  Found existing term, reparented (ID {$kukirin_brand_cat_id})");
                } else {
                    WP_CLI::error("  Failed to create Kukirin brand category: " . $result->get_error_message());
                    return;
                }
            } else {
                $kukirin_brand_cat_id = $result['term_id'];
                WP_CLI::log("  Created Kukirin brand category (ID {$kukirin_brand_cat_id})");
            }
            $stats['brand_cat_created'] = true;
        } else {
            WP_CLI::log("  [DRY RUN] Would create 'Kukirin' category");
            $kukirin_brand_cat_id = 'NEW';
        }
    }

    // ─── Step 2: Reparent model categories under Kukirin brand ───

    WP_CLI::log('');
    WP_CLI::log('=== Step 2: Reparent model categories ===');

    foreach (KUKIRIN_MODEL_CATS as $model_name => $cat_id) {
        $term = get_term($cat_id, 'product_cat');
        if (!$term || is_wp_error($term)) {
            WP_CLI::warning("  Category '{$model_name}' (ID {$cat_id}) not found — skipping");
            continue;
        }

        $current_parent = $term->parent;

        if ($kukirin_brand_cat_id !== 'NEW' && $current_parent == $kukirin_brand_cat_id) {
            WP_CLI::log("  {$model_name} (ID {$cat_id}) — already under Kukirin brand");
            continue;
        }

        if ($current_parent != PIESE_DE_SCHIMB_ID) {
            WP_CLI::warning("  {$model_name} (ID {$cat_id}) — unexpected parent {$current_parent}, expected " . PIESE_DE_SCHIMB_ID . " — skipping");
            continue;
        }

        if (REORG_APPLY && $kukirin_brand_cat_id !== 'NEW') {
            wp_update_term($cat_id, 'product_cat', ['parent' => $kukirin_brand_cat_id]);
            WP_CLI::log("  {$model_name} (ID {$cat_id}) — reparented under Kukirin (ID {$kukirin_brand_cat_id})");
        } else {
            WP_CLI::log("  [DRY RUN] Would reparent {$model_name} (ID {$cat_id}) under Kukirin");
        }
        $stats['cats_reparented']++;
    }

    // ─── Step 3: Scan all Kukirin spare parts ───

    WP_CLI::log('');
    WP_CLI::log('=== Step 3: Scan Kukirin spare parts ===');

    // Get all product IDs that are in any Kukirin model category OR in Piese de schimb
    $cat_ids = array_values(KUKIRIN_MODEL_CATS);
    $cat_ids[] = PIESE_DE_SCHIMB_ID;
    $placeholders = implode(',', array_fill(0, count($cat_ids), '%d'));

    $product_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE p.post_type = 'product'
           AND p.post_status IN ('publish', 'draft', 'private')
           AND tt.taxonomy = 'product_cat'
           AND tt.term_id IN ({$placeholders})",
        ...$cat_ids
    ));

    // Also get products with product_brand = Kukirin that might not be in categories
    $kukirin_brand_products = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE p.post_type = 'product'
           AND p.post_status IN ('publish', 'draft', 'private')
           AND tt.taxonomy = 'product_brand'
           AND tt.term_id = %d",
        KUKIRIN_BRAND_TERM_ID
    ));

    $all_ids = array_unique(array_merge($product_ids, $kukirin_brand_products));
    sort($all_ids);

    $stats['products_in_piese'] = count($product_ids);
    WP_CLI::log("  Found " . count($product_ids) . " products in Piese de schimb/Kukirin categories");
    WP_CLI::log("  Found " . count($kukirin_brand_products) . " products with Kukirin brand");
    WP_CLI::log("  Total unique Kukirin products: " . count($all_ids));

    // Process in batches
    $batches = array_chunk($all_ids, REORG_BATCH_SIZE);

    foreach ($batches as $batch_num => $batch_ids) {
        WP_CLI::log("  Processing batch " . ($batch_num + 1) . "/" . count($batches) . " (" . count($batch_ids) . " products)");

        foreach ($batch_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_type() === 'variation') continue;

            $title = $product->get_name();
            $stats['products_scanned']++;

            // ── Check/assign product_brand = Kukirin ──
            $brands = wp_get_object_terms($product_id, 'product_brand', ['fields' => 'ids']);
            if (in_array(KUKIRIN_BRAND_TERM_ID, $brands)) {
                $stats['brand_already']++;
            } else {
                if (REORG_APPLY) {
                    wp_set_object_terms($product_id, KUKIRIN_BRAND_TERM_ID, 'product_brand', true);
                }
                $stats['brand_assigned']++;
            }

            // ── Check/assign product_model ──
            $models = wp_get_object_terms($product_id, 'product_model', ['fields' => 'names']);
            $has_kukirin_model = false;
            foreach ($models as $m) {
                if (stripos($m, 'Kukirin') !== false) {
                    $has_kukirin_model = true;
                    break;
                }
            }

            if ($has_kukirin_model) {
                $stats['model_already']++;
            } else {
                $extracted_model = extract_model_from_title($title);
                if ($extracted_model && isset(KUKIRIN_MODEL_TERMS[$extracted_model])) {
                    $model_term_id = KUKIRIN_MODEL_TERMS[$extracted_model];
                    if (REORG_APPLY) {
                        wp_set_object_terms($product_id, $model_term_id, 'product_model', true);
                    }
                    $stats['model_assigned']++;
                } else {
                    $stats['model_not_found']++;
                    $missing_models[$product_id] = $title;
                }
            }

            // ── Check pa_tip-componenta ──
            $tip_terms = wp_get_object_terms($product_id, 'pa_tip-componenta', ['fields' => 'names']);
            if (!empty($tip_terms)) {
                $stats['tip_componenta_has']++;
            } else {
                $stats['tip_componenta_missing']++;
                $missing_tip[$product_id] = $title;
            }

            // ── Ensure product is in correct model category ──
            $extracted = extract_model_from_title($title);
            if ($extracted && isset(KUKIRIN_MODEL_CATS[$extracted])) {
                $model_cat_id = KUKIRIN_MODEL_CATS[$extracted];
                $current_cats = wp_get_object_terms($product_id, 'product_cat', ['fields' => 'ids']);
                if (!in_array($model_cat_id, $current_cats)) {
                    if (REORG_APPLY) {
                        wp_set_object_terms($product_id, $model_cat_id, 'product_cat', true);
                    }
                }
            }
        }

        wp_cache_flush();
    }

    // ─── Report ───

    WP_CLI::log('');
    WP_CLI::log('=== Results ===');
    WP_CLI::log("Products scanned: {$stats['products_scanned']}");
    WP_CLI::log('');
    WP_CLI::log("Brand category created: " . ($stats['brand_cat_created'] ? 'Yes' : 'No (dry run or existed)'));
    WP_CLI::log("Model categories reparented: {$stats['cats_reparented']}");
    WP_CLI::log('');
    WP_CLI::log("product_brand Kukirin:");
    WP_CLI::log("  Already assigned: {$stats['brand_already']}");
    WP_CLI::log("  Newly assigned: {$stats['brand_assigned']}");
    WP_CLI::log('');
    WP_CLI::log("product_model:");
    WP_CLI::log("  Already assigned: {$stats['model_already']}");
    WP_CLI::log("  Newly assigned: {$stats['model_assigned']}");
    WP_CLI::log("  Could not extract from title: {$stats['model_not_found']}");
    WP_CLI::log('');
    WP_CLI::log("pa_tip-componenta:");
    WP_CLI::log("  Has component type: {$stats['tip_componenta_has']}");
    WP_CLI::log("  Missing component type: {$stats['tip_componenta_missing']}");

    if (!empty($missing_models)) {
        WP_CLI::log('');
        WP_CLI::log("=== Products without model (sample, max 20) ===");
        $i = 0;
        foreach ($missing_models as $pid => $ptitle) {
            WP_CLI::log("  #{$pid}: {$ptitle}");
            if (++$i >= 20) break;
        }
    }

    if (!empty($missing_tip)) {
        WP_CLI::log('');
        WP_CLI::log("=== Products without pa_tip-componenta (sample, max 20) ===");
        $i = 0;
        foreach ($missing_tip as $pid => $ptitle) {
            WP_CLI::log("  #{$pid}: {$ptitle}");
            if (++$i >= 20) break;
        }
    }

    WP_CLI::log('');
    if (REORG_APPLY) {
        WP_CLI::success("Done! Changes applied.");
    } else {
        WP_CLI::success("Dry run complete. Set REORG_APPLY to true to apply changes.");
    }
}

run();
