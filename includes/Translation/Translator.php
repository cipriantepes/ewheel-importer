<?php
/**
 * Translator class.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

use Trotibike\EwheelImporter\Repository\TranslationRepository;

/**
 * Handles text translation with persistent caching.
 */
class Translator
{

    /**
     * Preferred language priority for multilingual text.
     */
    private const LANGUAGE_PRIORITY = ['en', 'es', 'de', 'fr', 'it'];

    /**
     * The translation service.
     *
     * @var TranslationServiceInterface
     */
    private TranslationServiceInterface $translation_service;

    /**
     * The translation repository.
     *
     * @var TranslationRepository
     */
    private TranslationRepository $repository;

    /**
     * The target language code.
     *
     * @var string
     */
    private string $target_language;

    /**
     * Constructor.
     *
     * @param TranslationServiceInterface $translation_service The translation service.
     * @param TranslationRepository       $repository          The translation repository.
     * @param string                      $target_language     The target language code (e.g., 'ro').
     * @throws \InvalidArgumentException If target language is empty.
     */
    public function __construct(
        TranslationServiceInterface $translation_service,
        TranslationRepository $repository,
        string $target_language
    ) {
        if (empty(trim($target_language))) {
            throw new \InvalidArgumentException('Target language is required');
        }

        $this->translation_service = $translation_service;
        $this->repository = $repository;
        $this->target_language = $target_language;
    }

    /**
     * Translate text from a source language to the target language.
     *
     * @param string $text        The text to translate.
     * @param string $source_lang The source language code.
     * @return string The translated text.
     */
    public function translate(string $text, string $source_lang): string
    {
        $text = trim($text);

        if (empty($text)) {
            return '';
        }

        // Skip translation if EWHEEL_SKIP_TRANSLATION constant is defined and true
        if (defined('EWHEEL_SKIP_TRANSLATION') && EWHEEL_SKIP_TRANSLATION) {
            return $text;
        }

        // Normalize source language to lowercase
        $source_lang = strtolower(trim($source_lang));

        // Default to 'es' if source lang is invalid or 'auto'
        if (empty($source_lang) || $source_lang === 'auto' || strlen($source_lang) > 5) {
            $source_lang = 'es';
        }

        // Skip translation if source equals target
        if ($source_lang === $this->target_language) {
            return $text;
        }

        // Check persistent cache
        $cached = $this->repository->get($text, $source_lang, $this->target_language);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $translated = $this->translation_service->translate(
                $text,
                $source_lang,
                $this->target_language
            );

            // Save to persistent cache
            $this->repository->save(
                $text,
                $translated,
                $source_lang,
                $this->target_language,
                $this->get_service_name()
            );

            return $translated;
        } catch (\Exception $e) {
            error_log('Translation error: ' . $e->getMessage());
            return $text;
        }
    }

    /**
     * Translate multilingual text object.
     *
     * @param array $multilingual_text Array with language codes as keys.
     * @return string The translated text.
     */
    public function translate_multilingual(array $multilingual_text): string
    {
        if (empty($multilingual_text)) {
            return '';
        }

        // --- NEW LOGIC: Handle Complex API Object ---
        // { defaultLanguageCode: "es", translations: [ { reference: "es", value: "..." }, ... ] }
        if (isset($multilingual_text['translations']) && is_array($multilingual_text['translations'])) {
            $translations = $multilingual_text['translations'];

            // 1. Try to find target language directly in the translations array
            foreach ($translations as $t) {
                // 'reference' seems to hold the language code (e.g. 'es', 'en')
                // 'value' holds the text
                if (isset($t['reference']) && $t['reference'] === $this->target_language && !empty($t['value'])) {
                    return $t['value'];
                }
            }

            // 2. Fallback: Try preferred languages priority
            foreach (self::LANGUAGE_PRIORITY as $lang) {
                foreach ($translations as $t) {
                    if (isset($t['reference']) && $t['reference'] === $lang && !empty($t['value'])) {
                        // Translate from this source language
                        return $this->translate($t['value'], $lang);
                    }
                }
            }

            // 3. Last fallback: Use the first available translation
            $first = reset($translations);
            if ($first && isset($first['value']) && !empty($first['value'])) {
                $source = $first['reference'] ?? 'auto';
                return $this->translate($first['value'], $source);
            }

            return '';
        }

        // --- OLD LOGIC: Simple Key-Value Map ---
        // OPTIMIZATION: Check if target language is already present natively
        if (isset($multilingual_text[$this->target_language]) && !empty(trim($multilingual_text[$this->target_language]))) {
            return $multilingual_text[$this->target_language];
        }

        $source_lang = null;
        $text = null;

        foreach (self::LANGUAGE_PRIORITY as $lang) {
            if (isset($multilingual_text[$lang]) && !empty(trim($multilingual_text[$lang]))) {
                $source_lang = $lang;
                $text = $multilingual_text[$lang];
                break;
            }
        }

        if ($text === null) {
            $source_lang = array_key_first($multilingual_text);
            $text = $multilingual_text[$source_lang];
        }

        return $this->translate($text, $source_lang);
    }

    /**
     * Translate multiple texts at once.
     *
     * @param array  $texts       Array of texts to translate.
     * @param string $source_lang The source language code.
     * @return array Array of translated texts.
     */
    public function translate_batch(array $texts, string $source_lang): array
    {
        if (empty($texts)) {
            return [];
        }

        // Skip translation if EWHEEL_SKIP_TRANSLATION constant is defined and true
        if (defined('EWHEEL_SKIP_TRANSLATION') && EWHEEL_SKIP_TRANSLATION) {
            return array_values($texts);
        }

        // Check persistent cache first
        $cached_map = $this->repository->get_batch($texts, $source_lang, $this->target_language);

        $to_translate = [];
        $original_keys = [];
        $final_results = [];

        foreach ($texts as $key => $text) {
            $hash = $this->repository->generate_hash($text, $source_lang, $this->target_language);

            if (isset($cached_map[$hash])) {
                $final_results[$key] = $cached_map[$hash];
            } else {
                $text = trim($text);
                if (empty($text)) {
                    $final_results[$key] = '';
                } else {
                    $to_translate[] = $text;
                    $original_keys[] = $key;
                }
            }
        }

        if (!empty($to_translate)) {
            try {
                $translated_batch = $this->translation_service->translate_batch(
                    $to_translate,
                    $source_lang,
                    $this->target_language
                );

                foreach ($translated_batch as $index => $translated_text) {
                    $key = $original_keys[$index];
                    $source_text = $to_translate[$index];

                    $final_results[$key] = $translated_text;

                    // Save to persistent cache
                    $this->repository->save(
                        $source_text,
                        $translated_text,
                        $source_lang,
                        $this->target_language,
                        $this->get_service_name()
                    );
                }
            } catch (\Exception $e) {
                error_log('Batch translation error: ' . $e->getMessage());
                foreach ($original_keys as $index => $key) {
                    $final_results[$key] = $to_translate[$index];
                }
            }
        }

        ksort($final_results);
        return array_values($final_results);
    }

    /**
     * Get the service name for the database.
     *
     * @return string
     */
    private function get_service_name(): string
    {
        $class = get_class($this->translation_service);
        if (strpos($class, 'Google') !== false) {
            return 'google';
        } elseif (strpos($class, 'DeepL') !== false) {
            return 'deepl';
        }
        return 'unknown';
    }
}

