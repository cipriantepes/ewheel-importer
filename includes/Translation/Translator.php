<?php
/**
 * Translator class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

/**
 * Handles text translation with caching.
 */
class Translator {

    /**
     * Preferred language priority for multilingual text.
     */
    private const LANGUAGE_PRIORITY = [ 'en', 'es', 'de', 'fr', 'it' ];

    /**
     * The translation service.
     *
     * @var TranslationServiceInterface
     */
    private TranslationServiceInterface $translation_service;

    /**
     * The target language code.
     *
     * @var string
     */
    private string $target_language;

    /**
     * Translation cache.
     *
     * @var array
     */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param TranslationServiceInterface $translation_service The translation service.
     * @param string                      $target_language     The target language code (e.g., 'ro').
     * @throws \InvalidArgumentException If target language is empty.
     */
    public function __construct( TranslationServiceInterface $translation_service, string $target_language ) {
        if ( empty( trim( $target_language ) ) ) {
            throw new \InvalidArgumentException( 'Target language is required' );
        }

        $this->translation_service = $translation_service;
        $this->target_language     = $target_language;
    }

    /**
     * Translate text from a source language to the target language.
     *
     * @param string $text        The text to translate.
     * @param string $source_lang The source language code.
     * @return string The translated text.
     */
    public function translate( string $text, string $source_lang ): string {
        $text = trim( $text );

        if ( empty( $text ) ) {
            return '';
        }

        $cache_key = $this->get_cache_key( $text, $source_lang );

        if ( isset( $this->cache[ $cache_key ] ) ) {
            return $this->cache[ $cache_key ];
        }

        try {
            $translated = $this->translation_service->translate(
                $text,
                $source_lang,
                $this->target_language
            );

            $this->cache[ $cache_key ] = $translated;

            return $translated;
        } catch ( \Exception $e ) {
            // Log error and return original text
            error_log( 'Translation error: ' . $e->getMessage() );
            return $text;
        }
    }

    /**
     * Translate multilingual text object.
     *
     * Chooses the best available language based on priority (English > Spanish > others).
     *
     * @param array $multilingual_text Array with language codes as keys (e.g., ['en' => 'text', 'es' => 'texto']).
     * @return string The translated text.
     */
    public function translate_multilingual( array $multilingual_text ): string {
        if ( empty( $multilingual_text ) ) {
            return '';
        }

        // Find the best available language
        $source_lang = null;
        $text        = null;

        // Check priority languages first
        foreach ( self::LANGUAGE_PRIORITY as $lang ) {
            if ( isset( $multilingual_text[ $lang ] ) && ! empty( trim( $multilingual_text[ $lang ] ) ) ) {
                $source_lang = $lang;
                $text        = $multilingual_text[ $lang ];
                break;
            }
        }

        // Fall back to first available language
        if ( $text === null ) {
            $source_lang = array_key_first( $multilingual_text );
            $text        = $multilingual_text[ $source_lang ];
        }

        return $this->translate( $text, $source_lang );
    }

    /**
     * Translate multiple texts at once.
     *
     * @param array  $texts       Array of texts to translate.
     * @param string $source_lang The source language code.
     * @return array Array of translated texts.
     */
    public function translate_batch( array $texts, string $source_lang ): array {
        if ( empty( $texts ) ) {
            return [];
        }

        // Filter out cached and empty texts
        $to_translate    = [];
        $cached_results  = [];
        $original_keys   = [];

        foreach ( $texts as $key => $text ) {
            $text      = trim( $text );
            $cache_key = $this->get_cache_key( $text, $source_lang );

            if ( empty( $text ) ) {
                $cached_results[ $key ] = '';
            } elseif ( isset( $this->cache[ $cache_key ] ) ) {
                $cached_results[ $key ] = $this->cache[ $cache_key ];
            } else {
                $to_translate[]        = $text;
                $original_keys[]       = $key;
            }
        }

        // Translate non-cached texts
        if ( ! empty( $to_translate ) ) {
            try {
                $translated = $this->translation_service->translate_batch(
                    $to_translate,
                    $source_lang,
                    $this->target_language
                );

                // Map results back and cache them
                foreach ( $translated as $index => $result ) {
                    $key                           = $original_keys[ $index ];
                    $cached_results[ $key ]        = $result;
                    $text                          = $to_translate[ $index ];
                    $cache_key                     = $this->get_cache_key( $text, $source_lang );
                    $this->cache[ $cache_key ]     = $result;
                }
            } catch ( \Exception $e ) {
                // Log error and return original texts
                error_log( 'Batch translation error: ' . $e->getMessage() );
                foreach ( $original_keys as $index => $key ) {
                    $cached_results[ $key ] = $to_translate[ $index ];
                }
            }
        }

        // Sort by original key order
        ksort( $cached_results );

        return array_values( $cached_results );
    }

    /**
     * Get cache key for a text.
     *
     * @param string $text        The text.
     * @param string $source_lang The source language.
     * @return string The cache key.
     */
    private function get_cache_key( string $text, string $source_lang ): string {
        return md5( $text . '|' . $source_lang . '|' . $this->target_language );
    }
}
