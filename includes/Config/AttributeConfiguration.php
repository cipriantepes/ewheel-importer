<?php
/**
 * Attribute Configuration class.
 *
 * @package Trotibike\EwheelImporter\Config
 */

namespace Trotibike\EwheelImporter\Config;

/**
 * Defines attribute handling configuration.
 *
 * Classifies API attributes into:
 * - Visible: Shown on product page
 * - Hidden: Filterable but not displayed
 * - Meta: Stored as product meta (not WooCommerce attributes)
 */
class AttributeConfiguration
{
    /**
     * Visible attributes (shown on product page).
     */
    public const VISIBLE_ATTRIBUTES = [
        'color',
        'talla',              // Size
        'medida',             // Measurement
        'conector',           // Connector type
        'o-exterior-in',      // Outer diameter inches
        'o-llanta-in',        // Rim diameter inches
        'o-exterior-mm',      // Outer diameter mm
        'ancho-neumatico-in', // Tire width inches
        'ancho-neumatico-mm', // Tire width mm
        'llanta',             // Rim size
        // 'modelos-compatibles' is now handled as product_model taxonomy
        'voltaje-nominal',    // Nominal voltage
        'voltaje-nominal-1',  // Nominal voltage (variant)
        'bisel',              // Bevel/chamfer
        'terreno',            // Terrain type
        'gel',                // Gel type (tires)
    ];

    /**
     * Hidden attributes (filterable but not displayed on product page).
     */
    public const HIDDEN_ATTRIBUTES = [
        'familia-sage',
        'subfamilia-sage',
        'subfamilia',
        'catalogos',
        'tipo',
        'agrupacion-productos',
    ];

    /**
     * Attributes to store as product meta (not as WooCommerce attributes).
     * Maps API attribute key => WordPress meta key.
     */
    public const META_ATTRIBUTES = [
        'update-date'            => '_ewheel_updated',
        'estatus'                => '_ewheel_status',
        'obsoleto'               => '_ewheel_obsolete',
        'novedad'                => '_ewheel_new',
        'orden-novedad'          => '_ewheel_new_order',
        'pack'                   => '_ewheel_pack',
        'unidades-por-pack'      => '_ewheel_units_per_pack',
        'descuento-por-pack'     => '_ewheel_pack_discount',
        'equivalente'            => '_ewheel_equivalent',
        'original'               => '_ewheel_original',
        'original-asignado'      => '_ewheel_original_assigned',
        'compatible-asignado'    => '_ewheel_compatible_assigned',
        'sustituye-a-referencia' => '_ewheel_replaces_reference',
        'especial-taller'        => '_ewheel_workshop_special',
        'descripcion-metacampo'  => '_ewheel_meta_description',
        'codigo-familia'         => '_ewheel_family_code',
        'ficha-tecnica'          => '_ewheel_product_sheet',
        'codigo-alternativo'     => '_ewheel_ean',
        'codigo-alternativo-2'   => '_ewheel_upc',
        'sku-anterior'           => '_ewheel_previous_sku',
    ];

    /**
     * Romanian labels for attributes with technical/abbreviated names
     * that the translation API might not handle well.
     * All other attribute names are translated automatically via the API.
     */
    public const ATTRIBUTE_LABELS = [
        'o-exterior-in'      => 'Diametru exterior (inch)',
        'o-llanta-in'        => 'Diametru jantă (inch)',
        'o-exterior-mm'      => 'Diametru exterior (mm)',
        'ancho-neumatico-in' => 'Lățime anvelopă (inch)',
        'ancho-neumatico-mm' => 'Lățime anvelopă (mm)',
        'voltaje-nominal-1'  => 'Tensiune nominală',
        'familia-sage'       => 'Familie',
        'subfamilia-sage'    => 'Subfamilie',
        'bisel'              => 'Teșitură',
    ];

    /**
     * Known value translations for short/problematic Spanish strings
     * that the translation API handles poorly.
     */
    public const VALUE_TRANSLATIONS = [
        'sin gel'   => 'Fără gel',
        'con gel'   => 'Cu gel',
        'sí'        => 'Da',
        'si'        => 'Da',
        'no'        => 'Nu',
    ];

    /**
     * Normalize an attribute key for consistent lookup.
     *
     * @param string $key The attribute key.
     * @return string Normalized key.
     */
    public static function normalize_key(string $key): string
    {
        return strtolower(str_replace(['_', ' '], '-', trim($key)));
    }

    /**
     * Check if an attribute should be visible on product page.
     *
     * @param string $attribute_key The attribute key (slug).
     * @return bool
     */
    public static function is_visible(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return in_array($normalized, self::VISIBLE_ATTRIBUTES, true);
    }

    /**
     * Check if an attribute should be hidden (filterable but not displayed).
     *
     * @param string $attribute_key The attribute key (slug).
     * @return bool
     */
    public static function is_hidden(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return in_array($normalized, self::HIDDEN_ATTRIBUTES, true);
    }

    /**
     * Check if an attribute should be stored as meta.
     *
     * @param string $attribute_key The attribute key (slug).
     * @return bool
     */
    public static function is_meta(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return isset(self::META_ATTRIBUTES[$normalized]);
    }

    /**
     * Get meta key for an API attribute.
     *
     * @param string $attribute_key The API attribute key.
     * @return string|null Meta key or null if not a meta attribute.
     */
    public static function get_meta_key(string $attribute_key): ?string
    {
        $normalized = self::normalize_key($attribute_key);
        return self::META_ATTRIBUTES[$normalized] ?? null;
    }

    /**
     * Get Romanian label for an attribute.
     *
     * @param string $attribute_key The attribute key.
     * @return string Label or formatted key if not found.
     */
    public static function get_label(string $attribute_key): string
    {
        $normalized = self::normalize_key($attribute_key);
        return self::ATTRIBUTE_LABELS[$normalized] ?? ucfirst(str_replace('-', ' ', $normalized));
    }

    /**
     * Determine visibility for an attribute.
     *
     * Returns true if visible, false if hidden.
     * Attributes not in either list default to visible.
     *
     * @param string $attribute_key The attribute key.
     * @return bool True if visible, false if hidden.
     */
    public static function get_visibility(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);

        // If explicitly hidden, return false
        if (in_array($normalized, self::HIDDEN_ATTRIBUTES, true)) {
            return false;
        }

        // Otherwise visible (including explicitly visible or unknown)
        return true;
    }

    /**
     * Check if attribute key is a brand field.
     *
     * @param string $attribute_key The attribute key.
     * @return bool
     */
    public static function is_brand(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return in_array($normalized, ['marca', 'brand'], true);
    }

    /**
     * Check if attribute key is a compatible models field.
     *
     * @param string $attribute_key The attribute key.
     * @return bool
     */
    public static function is_model(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return $normalized === 'modelos-compatibles';
    }

    /**
     * Check if attribute key is a dimension field.
     *
     * @param string $attribute_key The attribute key.
     * @return bool
     */
    public static function is_dimension(string $attribute_key): bool
    {
        $normalized = self::normalize_key($attribute_key);
        return in_array($normalized, ['peso', 'alto', 'ancho', 'largo', 'longitud'], true);
    }
}
