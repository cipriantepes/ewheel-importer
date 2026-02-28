<?php
/**
 * Fill missing pa_tip-componenta for Kukirin spare parts.
 *
 * The original extract-attributes.php only matched component types at title START.
 * This script uses broader keyword matching anywhere in the title to catch the
 * remaining 539 products (ewheel-imported Kukirin parts with different naming).
 *
 * Usage: wp eval-file fix-missing-tip-componenta.php
 */

define('FIX_APPLY', false);  // Set to true to write changes
define('FIX_BATCH_SIZE', 100);
define('KUKIRIN_BRAND_TERM_ID', 160);

/**
 * Keyword → pa_tip-componenta term mapping.
 * Checked in order — first match wins. Longer/more specific patterns first.
 *
 * Special values:
 *   '__SKIP__' = don't assign any term (non-component product)
 *   '__CHECK_SPATE__' = assign 'Stop' if title contains spate, else 'Far'
 */
function get_keyword_map() {
    return [
        // ── Specific multi-word patterns (check first) ──
        'port de încărcare'          => 'Încărcător',
        'port încărcare'             => 'Încărcător',
        'contact cu cheie'           => 'Comutator',
        'cheie de contact'           => 'Comutator',
        'cheie clemă de blocare'     => 'Comutator',
        'panou buton'                => 'Comutator',
        'întrerupător lumini'        => 'Comutator',
        'întrerupător alimentare'    => 'Comutator',
        'plăcuțe de frână'          => 'Frână',
        'plăcuță de frână'          => 'Frână',
        'placă de montare etrier'    => 'Frână',
        'etrier frână'              => 'Frână',
        'etrier de frână'           => 'Frână',
        'erieră de frână'           => 'Frână',
        'manete de frână'           => 'Manetă',
        'sistem frân'               => 'Frână',
        'cauciuc pentru plăcuță'    => 'Frână',
        'disc de frână'             => 'Disc de frână',
        'braț oscilant'             => 'Suspensie',
        'brat oscilant'             => 'Suspensie',
        'braț bascula'              => 'Suspensie',
        'braț suport furcă'        => 'Suspensie',
        'set rulmenți'              => 'Rulment',
        'set complet de rulmenți'   => 'Rulment',
        'rulmenți cu bile'          => 'Rulment',
        'cuvă rulment'              => 'Rulment',
        'ansamblu rulmenți'         => 'Rulment',
        'ansamblu cuvă rulment'     => 'Rulment',
        'kit ansamblu cuvă rulment' => 'Rulment',
        'modul placă de iluminare'  => 'Far',
        'modul placă de lumini'     => 'Far',
        'modul panou de lumini'     => 'Far',
        'modul lumină'              => 'Far',
        'modul pentru lumini'       => 'Far',
        'ansamblu lumini'           => '__CHECK_SPATE__',
        'ansamblu lumină'           => '__CHECK_SPATE__',
        'ansamblu de lumini'        => '__CHECK_SPATE__',
        'ansamblu modul placă'      => 'Far',
        'ansamblu comutator'        => 'Comutator',
        'ansamblu capac port'       => 'Încărcător',
        'ansamblu pliabil'          => 'Suport',
        'ansamblu blocare'          => 'Suport',
        'ansamblu scaun'            => 'Suport',
        'ansamblu suport'           => 'Suport',
        'ansamblu tub vertical'     => 'Tijă',
        'ansamblu tub de fixare'    => 'Suport',
        'ansamblu tablă'            => 'Apărătoare',
        'ansamblu cablaj'           => 'Cablu',
        'ansamblu de blocare'       => 'Suport',
        'adaptor cablu display'     => 'Cablu',
        'adaptor cablu'             => 'Cablu',
        'bază anti-alunecare'       => 'Apărătoare',
        'pernițe din silicon'       => 'Apărătoare',
        'capac de montare a barei'  => 'Apărătoare',
        'capac de montare a ghidon' => 'Ghidon',
        'capac de fixare ghidon'    => 'Ghidon',
        'capac de fixare a ghidon'  => 'Ghidon',
        'tub telescopic pentru ghidon' => 'Ghidon',
        'tub ghidon'                => 'Ghidon',
        'conector tub ghidon'       => 'Ghidon',
        'clemă ghidon'              => 'Ghidon',
        'clemă prindere ghidon'     => 'Ghidon',
        'clemă tub ghidon'          => 'Ghidon',
        'mânere ghidon'             => 'Ghidon',
        'mănuși ghidon'             => 'Manșon',
        'cap ghidon'                => 'Ghidon',
        'partea superioară a ghidon' => 'Ghidon',
        'îmbinarea verticală a tijei' => 'Ghidon',
        'tije ghidon'               => 'Ghidon',
        'dop silicon ghidon'        => 'Ghidon',
        'dop pentru ghidon'         => 'Ghidon',
        'dop ghidon'                => 'Ghidon',
        'bucșă t-bar'              => 'Ghidon',
        'suport picior'             => 'Suport',
        'suport picioare'           => 'Suport',
        'trusă scule'               => 'Suport',
        'trusă de scule'            => 'Suport',
        'cheie multifuncțional'     => 'Suport',
        'cheie multifunctional'     => 'Suport',
        'hexagon'                   => 'Suport',
        'pipă ghidon'               => 'Ghidon',
        'grindă'                    => 'Apărătoare',
        'pompă de aer'              => 'Suport',
        'lacăt'                     => 'Suport',
        'coș față'                  => 'Suport',
        'husă'                      => 'Suport',
        'bandă de lumină'           => 'Far',
        'lentă reflectorizantă'     => 'Apărătoare',
        'placă decorativă'          => 'Apărătoare',
        'piesă decorativă'          => 'Apărătoare',
        'ornamente laterale'        => 'Apărătoare',
        'ornament braț'             => 'Apărătoare',
        'placă de cauciuc'          => 'Apărătoare',
        'placă de bază'             => 'Apărătoare',
        'placă de protecție'        => 'Apărătoare',
        'placă de acoperire'        => 'Apărătoare',
        'placă de conectare'        => 'Suspensie',
        'placă de montare mâner'    => 'Apărătoare',
        'panou de protecție'        => 'Apărătoare',
        'panou far'                 => 'Far',
        'panou de lumini'           => 'Far',
        'spoturi'                   => 'Far',
        'protecție port de încărcare' => 'Încărcător',
        'protecție pedală'          => 'Apărătoare',
        'protectie cauciuc'         => 'Apărătoare',
        'protecție placă'           => 'Apărătoare',
        'protecție furcă'           => 'Suspensie',
        'capac protecție'           => 'Apărătoare',
        'capac port încărcare'      => 'Încărcător',
        'capac port de încărcare'   => 'Încărcător',
        'capac pentru port de încărcare' => 'Încărcător',
        'capac baterie'             => 'Baterie',
        'capac carcasă baterie'     => 'Baterie',
        'capac spate baterie'       => 'Baterie',
        'capac față baterie'        => 'Baterie',
        'carcasă baterie'           => 'Baterie',
        'cauciuc capac baterie'     => 'Baterie',
        'garnitură capac baterie'   => 'Baterie',
        'garnitura impermeabila baterie' => 'Baterie',
        'mâner din material textil pentru baterie' => 'Baterie',
        'cutie de conexiune'        => 'Cablu',
        'sferă'                     => 'Rulment',
        'schimbătoare de viteze'    => 'Comutator',
        'panou bluetooth'           => 'Comutator',
        'floarea soarelui'         => 'Rulment',
        'curea'                     => 'Suport',
        'ax '                       => 'Suport',
        'distanțier'                => 'Suport',
        'șurub'                     => 'Suport',
        'inel jantă'                => 'Jantă',
        'butuc'                     => 'Roată',
        'dop piuliță ax motor'      => 'Motor',
        'capac motor'               => 'Motor',
        'dop piuliță ax braț'       => 'Suspensie',

        // ── Single keyword patterns (broader, check last) ──
        'etrier'          => 'Frână',
        'plăcuț'          => 'Frână',
        'furcă'           => 'Suspensie',
        'semnalizat'      => 'Far',
        'semnalizare'     => 'Far',
        'reflector'       => 'Apărătoare',
        'reflectorizant'  => 'Apărătoare',
        'autocolant'      => 'Apărătoare',
        'aripă'           => 'Apărătoare',
        'aripa'           => 'Apărătoare',
        'ecran'           => 'Display',
        'afișaj'          => 'Display',
        'cric'            => 'Suport',
        'picior'          => 'Suport',
        'agățătoare'      => 'Suport',
        'cârlig'          => 'Suport',
        'blocare'         => 'Suport',
        'mecanism'        => 'Suport',
        'pliabil'         => 'Suport',
        'braț'            => 'Suspensie',
        'brat '           => 'Suspensie',
        'clemă'           => 'Suport',
        'tub vertical'    => 'Tijă',
        'tub filetat'     => 'Suport',
        'țeavă'           => 'Suport',
        'arc '            => 'Suspensie',
        'rulmenți'        => 'Rulment',
        'rulment'         => 'Rulment',
        'claxon'          => 'Sonerie',
        'lumină'          => '__CHECK_SPATE__',
        'lumini'          => '__CHECK_SPATE__',
        'led '            => 'Far',
        'proiector'       => 'Far',
        'fază'            => 'Far',
        'dop '            => 'Apărătoare',
        'capac'           => 'Apărătoare',
        'carcasă'         => 'Apărătoare',
        'garnitură'       => 'Apărătoare',
        'garnitura'       => 'Apărătoare',
        'ornament'        => 'Apărătoare',
        'cauciuc'         => 'Apărătoare',
        'placă'           => 'Apărătoare',
        'protecți'        => 'Apărătoare',
        'pernă'           => 'Suport',
        'pernițe'         => 'Apărătoare',
        'conector'        => 'Cablu',
        'conexiune'       => 'Cablu',
        'coloana'         => 'Suspensie',
        'punte'           => 'Suspensie',
        'banchetă'        => 'Suport',
        'baza'            => 'Suport',
        'bază'            => 'Suport',
        'șa'              => 'Suport',
        'scaun'           => 'Suport',
        'manual'          => '__SKIP__',
        'trotinetă'       => '__SKIP__',
    ];
}

function run() {
    global $wpdb;

    $keyword_map = get_keyword_map();

    // Get products with Kukirin brand but missing pa_tip-componenta
    $product_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
         JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
         WHERE p.post_type = 'product'
           AND p.post_status IN ('publish','draft','private')
           AND tt.taxonomy = 'product_brand'
           AND tt.term_id = %d
           AND p.ID NOT IN (
               SELECT tr2.object_id
               FROM {$wpdb->term_relationships} tr2
               JOIN {$wpdb->term_taxonomy} tt2 ON tr2.term_taxonomy_id = tt2.term_taxonomy_id
               WHERE tt2.taxonomy = 'pa_tip-componenta'
           )
         ORDER BY p.ID",
        KUKIRIN_BRAND_TERM_ID
    ));

    WP_CLI::log("Found " . count($product_ids) . " Kukirin products without pa_tip-componenta");
    WP_CLI::log('');

    // Get the WC attribute taxonomy ID for pa_tip-componenta
    $attr_tax_id = $wpdb->get_var(
        "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = 'tip-componenta'"
    );

    if (!$attr_tax_id) {
        WP_CLI::error("pa_tip-componenta attribute taxonomy not found!");
        return;
    }

    $stats = [
        'assigned'  => 0,
        'skipped'   => 0,
        'no_match'  => 0,
    ];
    $assignments = [];  // term_name => count
    $no_match = [];     // product_id => title

    $batches = array_chunk($product_ids, FIX_BATCH_SIZE);

    foreach ($batches as $batch_num => $batch_ids) {
        WP_CLI::log("Batch " . ($batch_num + 1) . "/" . count($batches));

        foreach ($batch_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->get_type() === 'variation') continue;

            $title = $product->get_name();
            $title_lower = mb_strtolower($title);
            $matched_term = null;

            foreach ($keyword_map as $keyword => $term_name) {
                if (mb_strpos($title_lower, mb_strtolower($keyword)) !== false) {
                    if ($term_name === '__SKIP__') {
                        $stats['skipped']++;
                        $matched_term = '__SKIP__';
                        break;
                    }
                    if ($term_name === '__CHECK_SPATE__') {
                        // Light position: spate → Stop, else → Far
                        if (mb_strpos($title_lower, 'spate') !== false &&
                            mb_strpos($title_lower, 'față') === false) {
                            $term_name = 'Stop';
                        } else {
                            $term_name = 'Far';
                        }
                    }
                    $matched_term = $term_name;
                    break;
                }
            }

            if ($matched_term === '__SKIP__') continue;

            if (!$matched_term) {
                $stats['no_match']++;
                $no_match[$product_id] = $title;
                continue;
            }

            // Assign the term
            if (!isset($assignments[$matched_term])) {
                $assignments[$matched_term] = 0;
            }
            $assignments[$matched_term]++;
            $stats['assigned']++;

            if (FIX_APPLY) {
                $taxonomy = 'pa_tip-componenta';

                // Get or create term
                $term = get_term_by('name', $matched_term, $taxonomy);
                if (!$term) {
                    $result = wp_insert_term($matched_term, $taxonomy);
                    if (is_wp_error($result)) {
                        WP_CLI::warning("  Failed to create term '{$matched_term}': " . $result->get_error_message());
                        continue;
                    }
                    $term = get_term($result['term_id'], $taxonomy);
                }

                // Assign term to product
                wp_set_object_terms($product_id, $term->term_id, $taxonomy, true);

                // Update _product_attributes meta to include this taxonomy
                $existing_attrs = $product->get_attributes();
                if (!isset($existing_attrs[$taxonomy])) {
                    $wc_attr = new WC_Product_Attribute();
                    $wc_attr->set_id($attr_tax_id);
                    $wc_attr->set_name($taxonomy);
                    $wc_attr->set_options([$term->term_id]);
                    $wc_attr->set_visible(true);
                    $wc_attr->set_variation(false);

                    $existing_attrs[$taxonomy] = $wc_attr;
                    $product->set_attributes($existing_attrs);
                    $product->save();
                }
            }
        }

        wp_cache_flush();
    }

    // Report
    WP_CLI::log('');
    WP_CLI::log('=== Results ===');
    WP_CLI::log("Assigned: {$stats['assigned']}");
    WP_CLI::log("Skipped (non-component): {$stats['skipped']}");
    WP_CLI::log("No match: {$stats['no_match']}");

    if (!empty($assignments)) {
        WP_CLI::log('');
        WP_CLI::log('=== Assignments by component type ===');
        arsort($assignments);
        foreach ($assignments as $term => $count) {
            WP_CLI::log("  {$term}: {$count}");
        }
    }

    if (!empty($no_match)) {
        WP_CLI::log('');
        WP_CLI::log('=== No match (all ' . count($no_match) . ') ===');
        foreach ($no_match as $pid => $ptitle) {
            WP_CLI::log("  #{$pid}: {$ptitle}");
        }
    }

    WP_CLI::log('');
    if (FIX_APPLY) {
        WP_CLI::success("Done! Changes applied.");
    } else {
        WP_CLI::success("Dry run complete. Set FIX_APPLY to true to apply.");
    }
}

run();
