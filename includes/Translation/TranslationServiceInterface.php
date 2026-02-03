<?php
/**
 * Translation Service Interface.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

/**
 * Interface for translation services (Google Translate, DeepL, etc).
 */
interface TranslationServiceInterface {

    /**
     * Translate text from one language to another.
     *
     * @param string $text        The text to translate.
     * @param string $source_lang The source language code (e.g., 'en', 'es').
     * @param string $target_lang The target language code (e.g., 'ro').
     * @return string The translated text.
     * @throws \RuntimeException If translation fails.
     */
    public function translate( string $text, string $source_lang, string $target_lang ): string;

    /**
     * Translate multiple texts at once (batch translation).
     *
     * @param array  $texts       Array of texts to translate.
     * @param string $source_lang The source language code.
     * @param string $target_lang The target language code.
     * @return array Array of translated texts.
     * @throws \RuntimeException If translation fails.
     */
    public function translate_batch( array $texts, string $source_lang, string $target_lang ): array;
}
