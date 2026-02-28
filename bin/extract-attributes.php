<?php
/**
 * Extract structured product attributes from titles/descriptions for SEO.
 *
 * Scans all WooCommerce product titles and short descriptions, extracts
 * structured data (voltage, power, tire size, component type, etc.) via
 * regex, and adds them as WooCommerce global taxonomy attributes (pa_*).
 *
 * Usage: wp eval-file extract-attributes.php
 * Set $apply = true below to write changes.
 */

// ── Configuration ─────────────────────────────────────────────
define('ATTR_APPLY', false);   // Set to true to write changes to DB
define('ATTR_BATCH_SIZE', 100); // Products per batch (shared hosting safe)

// ── Attribute Definitions ─────────────────────────────────────

function get_attribute_definitions() {
    return [
        'dimensiune_anvelope' => [
            'label' => 'Dimensiune anvelopă',
            'slug'  => 'dimensiune-anvelope',
            'patterns' => [
                // x format with suffix: 10x2.75-6.5, 10x3.0-6.5, 9.2x2-6.1, 10x2.50-6
                '/\b(\d{1,2}(?:[.,]\d{1,2})?[xX×*]\d{1,2}(?:[.,]\d{1,3})?[-–]\d{1,2}(?:[.,]\d{1,2})?)\b/',
                // x format with slash: 10x2/2.125
                '/\b(\d{1,2}[xX×*]\d{1,2}(?:[.,]\d{1,3})?\/\d{1,2}(?:[.,]\d{1,3})?)\b/',
                // x format simple: 10x2.5, 8.5x2, 200x50, 200x70, 250x54
                '/\b(\d{1,3}(?:[.,]\d{1,2})?[xX×*]\d{1,3}(?:[.,]\d{1,3})?)\b/',
                // Slash format: 85/65-6.5, 90/65-6.5, 60/70-6.5, 100/65-6.5, 70/60-7.5
                '/\b(\d{2,3}\/\d{2,3}[-–]\d{1,2}(?:[.,]\d{1,2})?)\b/',
            ],
            'validator' => 'validate_tire_size',
            'formatter' => 'format_tire_size',
            'equiv_names' => ['Diametru exterior', 'Lățime anvelopă', 'Dimensiune jantă', 'Masura'],
        ],
        'tensiune' => [
            'label' => 'Tensiune (V)',
            'slug'  => 'tensiune-v',
            'patterns' => [
                // 48V, 60V, 36V — first match wins (nominal voltage before output)
                '/\b(\d{1,3})\s?V\b/u',
            ],
            'validator' => 'validate_voltage',
            'formatter' => 'format_voltage',
            'equiv_names' => ['Tensiune nominală', 'Tensiune', 'Voltaje'],
        ],
        'putere' => [
            'label' => 'Putere (W)',
            'slug'  => 'putere-w',
            'patterns' => [
                '/\b(\d{2,5})\s?W\b/u',
            ],
            'validator' => 'validate_power',
            'formatter' => 'format_power',
            'equiv_names' => ['Putere nominală', 'Putere', 'Potencia'],
        ],
        'capacitate_baterie' => [
            'label' => 'Capacitate baterie (Ah)',
            'slug'  => 'capacitate-baterie',
            'patterns' => [
                '/\b(\d{1,3}(?:[.,]\d{1,2})?)\s?Ah\b/iu',
            ],
            'validator' => 'validate_battery',
            'formatter' => 'format_battery',
            'equiv_names' => ['Capacitate', 'Baterie', 'Amperi'],
            // Only extract from battery/charger titles (avoid false positives from bad excerpts)
            'title_context_regex' => '/baterie|battery|acumulator|[iî]nc[aă]rc[aă]tor|charger/iu',
            'title_only' => true, // Extract from title only, not excerpt
        ],
        'dimensiune_mm' => [
            'label' => 'Dimensiune (mm)',
            'slug'  => 'dimensiune-mm',
            'patterns' => [
                '/\b(\d{2,4})\s?mm\b/iu',
            ],
            'validator' => 'validate_mm',
            'formatter' => 'format_mm',
            'equiv_names' => ['Dimensiune'],
            // Only extract if no tire size was found for this product
            'skip_if' => 'dimensiune_anvelope',
        ],
        'tip_anvelope' => [
            'label' => 'Tip anvelopă',
            'slug'  => 'tip-anvelope',
            'patterns' => [
                '/\b(tubeless)\b/iu',
                '/\b(solid[aă])\b/iu',
                '/\b(plin[aă])\b/iu',
                '/\b(cu\s+camer[aă])\b/iu',
                '/\b(pneumatic[aă])\b/iu',
            ],
            'validator' => null,
            'formatter' => 'format_tire_type',
            'equiv_names' => ['Teren', 'Cu gel'],
            // Only relevant for tire/tube products
            'context_regex' => '/anvelop|camer[aă]|cauciuc|tire|roat[aă]|plin[aă]|solid[aă]|tubeless/iu',
        ],
        'tip_componenta' => [
            'label' => 'Tip componentă',
            'slug'  => 'tip-componenta',
            'patterns' => [
                // Match component type keywords at or near start of title
                '/^(?:Cauciuc\/)?(?:Set\s+)?(Motor|Controler|Display|Afișaj|Fr[aâ]n[aă]|Anvelop[aă]|Camer[aă]\s+de\s+aer|Camer[aă]|[IÎ]nc[aă]rc[aă]tor|Baterie|Disc\s+de\s+fr[aâ]n[aă]|Amortizor|Suspensie|Manet[aă]|Far|Stop|Ghidon|Scaun|Roat[aă]|Jant[aă]|Pedal[aă]|Oglind[aă]|Sonerie|Ap[aă]r[aă]toare|Suport|Cablu|Accelerat|Comutator|Tijă|Rulment|Manșo[na])/iu',
            ],
            'validator' => null,
            'formatter' => 'format_component_type',
            'equiv_names' => [],
        ],
        'pozitie' => [
            'label' => 'Poziție',
            'slug'  => 'pozitie',
            'patterns' => [
                '/\b(fa[tț][aă])\b/iu',
                '/\b(spate)\b/iu',
            ],
            'validator' => null,
            'formatter' => 'format_position',
            'equiv_names' => [],
            // Only for parts, not tires (tires don't have front/rear designation)
            'context_exclude' => '/anvelop|cauciuc|camer[aă]\s+de\s+aer/iu',
        ],
        'culoare' => [
            'label' => 'Culoare',
            'slug'  => 'culoare',
            'patterns' => [
                '/\b(negr[ue]|neagr[aă])\b/iu',
                '/\b(ro[sș]u|ro[sș]i[ie])\b/iu',
                '/\b(albastr[ue]?|albastr[aă])\b/iu',
                '/\b(verde)\b/iu',
                '/\b(galben[aă]?)\b/iu',
                '/\b(portocali[ue]?)\b/iu',
                '/\b(roz)\b/iu',
                '/\b(auriu?)\b/iu',
                '/\b(arginti[ue]?)\b/iu',
                '/\b(gri)\b/iu',
            ],
            'validator' => null,
            'formatter' => 'format_color',
            'equiv_names' => ['Culoare', 'Color'],
        ],
    ];
}

// ── Validators ────────────────────────────────────────────────

function validate_tire_size($v) {
    return preg_match('/\d.*[xX×*\/\-].*\d/', $v);
}

function validate_voltage($v) {
    $n = (int) $v;
    return in_array($n, [5, 12, 24, 36, 42, 48, 52, 54, 60, 72, 84, 96]);
}

function validate_power($v) {
    $n = (int) $v;
    return $n >= 36 && $n <= 20000;
}

function validate_battery($v) {
    $n = (float) str_replace(',', '.', $v);
    return $n >= 1 && $n <= 100;
}

function validate_mm($v) {
    $n = (int) $v;
    return $n >= 10 && $n <= 2000;
}

// ── Formatters ────────────────────────────────────────────────

function format_tire_size($v) {
    // Normalize: × → x, – → -, * → x
    return str_replace(['×', '–', '*'], ['x', '-', 'x'], $v);
}

function format_voltage($v) {
    return ((int) $v) . 'V';
}

function format_power($v) {
    return ((int) $v) . 'W';
}

function format_battery($v) {
    $n = (float) str_replace(',', '.', $v);
    return (floor($n) == $n) ? ((int) $n . 'Ah') : ($n . 'Ah');
}

function format_mm($v) {
    return ((int) $v) . 'mm';
}

function format_tire_type($v) {
    $map = [
        'tubeless'    => 'Tubeless',
        'solida'      => 'Solidă', 'solidă' => 'Solidă',
        'plina'       => 'Plină',  'plină'  => 'Plină',
        'cu camera'   => 'Cu cameră', 'cu cameră' => 'Cu cameră',
        'pneumatica'  => 'Pneumatică', 'pneumatică' => 'Pneumatică',
    ];
    $lower = mb_strtolower(trim($v));
    return $map[$lower] ?? ucfirst($lower);
}

function format_component_type($v) {
    $map = [
        'motor' => 'Motor', 'controler' => 'Controler',
        'display' => 'Display', 'afișaj' => 'Display',
        'frana' => 'Frână', 'frână' => 'Frână', 'frâna' => 'Frână', 'frânã' => 'Frână',
        'anvelopa' => 'Anvelopă', 'anvelopă' => 'Anvelopă',
        'camera de aer' => 'Cameră de aer', 'cameră de aer' => 'Cameră de aer',
        'camera' => 'Cameră', 'cameră' => 'Cameră',
        'incarcator' => 'Încărcător', 'încărcător' => 'Încărcător', 'încãrcãtor' => 'Încărcător',
        'baterie' => 'Baterie',
        'disc de frana' => 'Disc de frână', 'disc de frână' => 'Disc de frână', 'disc de frâna' => 'Disc de frână',
        'amortizor' => 'Amortizor', 'suspensie' => 'Suspensie',
        'maneta' => 'Manetă', 'manetă' => 'Manetă',
        'far' => 'Far', 'stop' => 'Stop',
        'ghidon' => 'Ghidon', 'scaun' => 'Scaun',
        'roata' => 'Roată', 'roată' => 'Roată',
        'janta' => 'Jantă', 'jantă' => 'Jantă',
        'pedala' => 'Pedală', 'pedală' => 'Pedală',
        'oglinda' => 'Oglindă', 'oglindă' => 'Oglindă',
        'sonerie' => 'Sonerie',
        'aparatoare' => 'Apărătoare', 'apărătoare' => 'Apărătoare',
        'suport' => 'Suport', 'cablu' => 'Cablu',
        'accelerat' => 'Accelerator', 'comutator' => 'Comutator',
        'tija' => 'Tijă', 'tijă' => 'Tijă',
        'rulment' => 'Rulment',
        'manson' => 'Manșon', 'manșon' => 'Manșon', 'mansona' => 'Manșon',
    ];
    $lower = mb_strtolower(trim($v));
    return $map[$lower] ?? ucfirst($lower);
}

function format_position($v) {
    $map = [
        'fata' => 'Față', 'față' => 'Față', 'fața' => 'Față', 'fatã' => 'Față',
        'spate' => 'Spate',
    ];
    $lower = mb_strtolower(trim($v));
    return $map[$lower] ?? ucfirst($lower);
}

function format_color($v) {
    $map = [
        'negru' => 'Negru', 'negre' => 'Negru', 'neagra' => 'Negru', 'neagră' => 'Negru',
        'rosu' => 'Roșu', 'roșu' => 'Roșu', 'rosii' => 'Roșu', 'roșii' => 'Roșu', 'rosie' => 'Roșu', 'roșie' => 'Roșu',
        'albastru' => 'Albastru', 'albastre' => 'Albastru', 'albastră' => 'Albastru', 'albastra' => 'Albastru',
        'verde' => 'Verde',
        'galben' => 'Galben', 'galbenă' => 'Galben', 'galbena' => 'Galben',
        'portocaliu' => 'Portocaliu', 'portocalie' => 'Portocaliu',
        'roz' => 'Roz',
        'auriu' => 'Auriu', 'aur' => 'Auriu',
        'argintiu' => 'Argintiu', 'argintie' => 'Argintiu',
        'gri' => 'Gri',
    ];
    $lower = mb_strtolower(trim($v));
    return $map[$lower] ?? ucfirst($lower);
}

// ── Core Functions ────────────────────────────────────────────

function ensure_global_attribute($slug, $label) {
    $existing_id = wc_attribute_taxonomy_id_by_name($slug);
    if ($existing_id > 0) {
        return $existing_id;
    }

    $id = wc_create_attribute([
        'name'         => $label,
        'slug'         => $slug,
        'type'         => 'select',
        'order_by'     => 'menu_order',
        'has_archives' => true,
    ]);

    if (is_wp_error($id)) {
        WP_CLI::warning("Failed to create attribute '{$slug}': " . $id->get_error_message());
        return 0;
    }

    // Register taxonomy so wp_set_object_terms works in same request
    $taxonomy_name = wc_attribute_taxonomy_name($slug);
    if (!taxonomy_exists($taxonomy_name)) {
        register_taxonomy($taxonomy_name, 'product', [
            'labels'       => ['name' => $label],
            'hierarchical' => false,
            'show_ui'      => false,
        ]);
    }

    delete_transient('wc_attribute_taxonomies');
    return $id;
}

function extract_from_text($text, $def) {
    foreach ($def['patterns'] as $pattern) {
        if (preg_match($pattern, $text, $matches)) {
            $raw = $matches[1];

            // Validate
            if (!empty($def['validator'])) {
                $fn = $def['validator'];
                if (!$fn($raw)) {
                    continue;
                }
            }

            // Format
            if (!empty($def['formatter'])) {
                $fn = $def['formatter'];
                return $fn($raw);
            }

            return $raw;
        }
    }
    return null;
}

function product_has_attribute($product, $taxonomy, $equiv_names) {
    // Check 1: Global taxonomy terms already assigned
    $terms = wp_get_object_terms($product->get_id(), $taxonomy, ['fields' => 'names']);
    if (!empty($terms) && !is_wp_error($terms)) {
        return true;
    }

    // Check 2: Product-level custom attributes with equivalent name
    if (empty($equiv_names)) {
        return false;
    }

    $existing_attrs = $product->get_attributes();
    foreach ($existing_attrs as $attr) {
        $name = $attr->get_name();
        foreach ($equiv_names as $check) {
            if (mb_stripos($name, $check) !== false) {
                $options = $attr->get_options();
                if (!empty($options)) {
                    // Check options actually have content (not just empty strings)
                    $has_value = false;
                    foreach ($options as $opt) {
                        if (is_string($opt) && trim($opt) !== '') {
                            $has_value = true;
                            break;
                        }
                        if (is_int($opt) && $opt > 0) {
                            $has_value = true;
                            break;
                        }
                    }
                    if ($has_value) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

function merge_attribute_into_product($product_id, $attr_tax_id, $taxonomy, $term_value) {
    $product = wc_get_product($product_id);
    if (!$product) return false;

    // Get or create the term
    $term = get_term_by('name', $term_value, $taxonomy);
    if (!$term) {
        $result = wp_insert_term($term_value, $taxonomy);
        if (is_wp_error($result)) {
            WP_CLI::warning("Failed to create term '{$term_value}' in {$taxonomy}: " . $result->get_error_message());
            return false;
        }
        $term_id = $result['term_id'];
    } else {
        $term_id = $term->term_id;
    }

    // Assign term to product
    wp_set_object_terms($product_id, [$term_id], $taxonomy, true);

    // Update _product_attributes meta to include the taxonomy attribute
    $existing_attrs = $product->get_attributes();
    $already_has = false;
    foreach ($existing_attrs as $attr) {
        if ($attr->is_taxonomy() && $attr->get_name() === $taxonomy) {
            $already_has = true;
            break;
        }
    }

    if (!$already_has) {
        $new_attr = new WC_Product_Attribute();
        $new_attr->set_id($attr_tax_id);
        $new_attr->set_name($taxonomy);
        $new_attr->set_options([$term_id]);
        $new_attr->set_visible(true);
        $new_attr->set_variation(false);

        $attrs = $existing_attrs;
        $attrs[] = $new_attr;
        $product->set_attributes($attrs);
        $product->save();
    }

    return true;
}

// ── Main ──────────────────────────────────────────────────────

function run() {
    $apply = ATTR_APPLY;
    $batch_size = ATTR_BATCH_SIZE;

    WP_CLI::log("=== Product Attribute Extraction for SEO ===");
    WP_CLI::log("Mode: " . ($apply ? "APPLY (writing to DB)" : "DRY RUN (report only)"));
    WP_CLI::log("");

    $defs = get_attribute_definitions();

    // Phase 1: Ensure global attribute taxonomies exist
    $registry = [];
    foreach ($defs as $key => $def) {
        if ($apply) {
            $attr_id = ensure_global_attribute($def['slug'], $def['label']);
        } else {
            $attr_id = wc_attribute_taxonomy_id_by_name($def['slug']);
        }
        $taxonomy = 'pa_' . $def['slug'];
        $registry[$key] = [
            'id'       => $attr_id ?: 0,
            'taxonomy' => $taxonomy,
            'def'      => $def,
        ];
        $status = $attr_id ? "ID {$attr_id}" : "(new — will create on apply)";
        WP_CLI::log("  {$def['label']} ({$taxonomy}) → {$status}");
    }
    WP_CLI::log("");

    // Phase 2: Scan products in batches
    $stats = [
        'scanned'   => 0,
        'enriched'  => 0,
        'extracted' => array_fill_keys(array_keys($defs), 0),
        'skipped'   => array_fill_keys(array_keys($defs), 0),
    ];
    $samples = []; // First 5 extractions per attribute for review

    $page = 1;
    $progress = null;

    // Count total first
    $total = (int) wp_count_posts('product')->publish + (int) wp_count_posts('product')->draft;
    WP_CLI::log("Scanning {$total} products...\n");

    do {
        $raw_products = wc_get_products([
            'limit'  => $batch_size,
            'page'   => $page,
            'status' => 'any',
            'return' => 'objects',
        ]);
        $raw_count = count($raw_products);
        // Filter out variations (child products of variable products)
        $products = array_filter($raw_products, function($p) {
            return $p->get_type() !== 'variation';
        });

        foreach ($products as $product) {
            $stats['scanned']++;
            $pid   = $product->get_id();
            $title = $product->get_name();
            $excerpt = wp_strip_all_tags($product->get_short_description());
            $search_text = $title . ' ' . $excerpt;

            $product_extracted = [];

            foreach ($registry as $attr_key => $reg) {
                $def = $reg['def'];
                $taxonomy = $reg['taxonomy'];

                // Context check — only extract if product is relevant
                if (!empty($def['context_regex']) && !preg_match($def['context_regex'], $search_text)) {
                    continue;
                }
                if (!empty($def['title_context_regex']) && !preg_match($def['title_context_regex'], $title)) {
                    continue;
                }
                if (!empty($def['context_exclude']) && preg_match($def['context_exclude'], $title)) {
                    continue;
                }

                // Skip-if dependency (e.g., skip mm if tire size was extracted)
                if (!empty($def['skip_if']) && isset($product_extracted[$def['skip_if']])) {
                    continue;
                }

                // Check existing attributes
                if (product_has_attribute($product, $taxonomy, $def['equiv_names'] ?? [])) {
                    $stats['skipped'][$attr_key]++;
                    continue;
                }

                // Extract — use title only if flagged (avoids bad excerpt data)
                $extract_text = !empty($def['title_only']) ? $title : $search_text;
                $value = extract_from_text($extract_text, $def);
                if ($value === null) {
                    continue;
                }

                $product_extracted[$attr_key] = $value;

                // Collect samples
                if (!isset($samples[$attr_key])) $samples[$attr_key] = [];
                if (count($samples[$attr_key]) < 5) {
                    $samples[$attr_key][] = "#{$pid} \"{$title}\" → {$value}";
                }

                $stats['extracted'][$attr_key]++;

                if ($apply && $reg['id'] > 0) {
                    merge_attribute_into_product($pid, $reg['id'], $taxonomy, $value);
                }
            }

            if (!empty($product_extracted)) {
                $stats['enriched']++;
            }

            // Progress every 500 products
            if ($stats['scanned'] % 500 === 0) {
                WP_CLI::log("  ...scanned {$stats['scanned']}/{$total}");
            }
        }

        $page++;
        wp_cache_flush();

    } while ($raw_count === $batch_size);

    // Phase 3: Report
    WP_CLI::log("\n=== RESULTS ===");
    WP_CLI::log("Total products scanned: {$stats['scanned']}");
    WP_CLI::log("Products enriched: {$stats['enriched']}");
    WP_CLI::log("");

    WP_CLI::log("Extractions by attribute:");
    foreach ($defs as $key => $def) {
        $ext = $stats['extracted'][$key];
        $skip = $stats['skipped'][$key];
        WP_CLI::log("  {$def['label']}: {$ext} extracted, {$skip} skipped (existing)");
    }

    WP_CLI::log("\n=== SAMPLES ===");
    foreach ($samples as $attr_key => $s) {
        $label = $defs[$attr_key]['label'];
        WP_CLI::log("\n{$label}:");
        foreach ($s as $line) {
            WP_CLI::log("  {$line}");
        }
    }

    if (!$apply) {
        WP_CLI::log("\n--- DRY RUN complete. Set \$apply = true to write changes. ---");
    } else {
        // Flush WC transients
        delete_transient('wc_attribute_taxonomies');
        WP_CLI::success("Done! {$stats['enriched']} products enriched with attributes.");
    }
}

run();
