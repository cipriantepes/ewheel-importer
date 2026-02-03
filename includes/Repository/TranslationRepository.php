<?php
/**
 * Translation Repository.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Repository;

/**
 * Handles persistence of translations.
 */
class TranslationRepository
{

    /**
     * Table name without prefix.
     */
    private const TABLE_NAME = 'ewheel_translations';

    /**
     * Get a translation from the database.
     *
     * @param string $text        The source text.
     * @param string $source_lang The source language.
     * @param string $target_lang The target language.
     * @return string|null The translated text or null if not found.
     */
    public function get(string $text, string $source_lang, string $target_lang): ?string
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $hash = $this->generate_hash($text, $source_lang, $target_lang);

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT translated_text FROM $table_name WHERE source_hash = %s",
                $hash
            )
        );

        return $result;
    }

    /**
     * Get multiple translations at once.
     *
     * @param array  $texts       Array of source texts.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array Map of hash => translated_text.
     */
    public function get_batch(array $texts, string $source_lang, string $target_lang): array
    {
        global $wpdb;

        if (empty($texts)) {
            return [];
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $hashes = [];
        $hash_map = []; // Map hash to original text if needed, or just rely on hash

        foreach ($texts as $text) {
            $hash = $this->generate_hash($text, $source_lang, $target_lang);
            $hashes[] = $hash;
        }

        // Escape hashes for IN clause
        $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

        $query = "SELECT source_hash, translated_text FROM $table_name WHERE source_hash IN ($placeholders)";

        $results = $wpdb->get_results(
            $wpdb->prepare($query, $hashes),
            ARRAY_A
        );

        $map = [];
        foreach ($results as $row) {
            $map[$row['source_hash']] = $row['translated_text'];
        }

        return $map;
    }

    /**
     * Save a translation.
     *
     * @param string $text        Source text.
     * @param string $translated  Translated text.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @param string $service     Service used (google/deepl).
     * @return bool Success.
     */
    public function save(string $text, string $translated, string $source_lang, string $target_lang, string $service): bool
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $hash = $this->generate_hash($text, $source_lang, $target_lang);

        $result = $wpdb->replace(
            $table_name,
            [
                'source_hash' => $hash,
                'source_text' => $text,
                'translated_text' => $translated,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'service' => $service,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Generate a unique hash for the translation key.
     *
     * @param string $text        Source text.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return string MD5 hash.
     */
    public function generate_hash(string $text, string $source_lang, string $target_lang): string
    {
        return md5($text . '|' . $source_lang . '|' . $target_lang);
    }
}
