<?php
/**
 * OpenRouter Translate Service.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

use Trotibike\EwheelImporter\Api\HttpClientInterface;

/**
 * Service to translate text using OpenRouter (LLMs).
 */
class OpenRouterTranslateService implements TranslationServiceInterface
{

    /**
     * OpenRouter API URL.
     */
    private const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /**
     * Reasoning models that are too slow for batch translation.
     * These models "think" through problems and can take minutes per request.
     */
    private const REASONING_MODELS = [
        'deepseek/deepseek-r1',
        'deepseek/deepseek-reasoner',
        'openai/o1',
        'openai/o1-mini',
        'openai/o1-preview',
        'anthropic/claude-3-opus', // Not reasoning but very slow
    ];

    /**
     * Fast models recommended for translation.
     */
    private const RECOMMENDED_MODELS = [
        'google/gemini-2.0-flash:free',
        'google/gemini-flash-1.5',
        'google/gemini-flash-1.5-8b',
        'meta-llama/llama-3.1-8b-instruct:free',
    ];

    /**
     * API Key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * LLM Model (e.g., google/gemini-flash-1.5).
     *
     * @var string
     */
    private string $model;

    /**
     * HTTP Client.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $http_client;

    /**
     * Constructor.
     *
     * @param string              $api_key     API Key.
     * @param string              $model       Model ID.
     * @param HttpClientInterface $http_client HTTP Client.
     */
    public function __construct(string $api_key, string $model, HttpClientInterface $http_client)
    {
        $this->api_key = $api_key;
        $this->model = $model;
        $this->http_client = $http_client;

        // Warn if using a reasoning model
        if ($this->is_reasoning_model($model)) {
            error_log("[Ewheel Translation] WARNING: Model '{$model}' is a reasoning model - these are VERY SLOW for translation!");
            error_log("[Ewheel Translation] Recommended models: " . implode(', ', self::RECOMMENDED_MODELS));
        }
    }

    /**
     * Check if the model is a slow reasoning model.
     *
     * @param string $model Model ID.
     * @return bool True if reasoning model.
     */
    private function is_reasoning_model(string $model): bool
    {
        foreach (self::REASONING_MODELS as $reasoning_model) {
            if (strpos($model, $reasoning_model) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Translate text.
     *
     * @param string $text        Text to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return string Translated text.
     * @throws \RuntimeException If translation fails.
     */
    public function translate(string $text, string $source_lang, string $target_lang): string
    {
        if (empty(trim($text))) {
            return $text;
        }

        // Skip translation if using a reasoning model - they are too slow
        if ($this->is_reasoning_model($this->model)) {
            error_log("[Ewheel Translation] SKIPPING translation - model '{$this->model}' is too slow");
            return $text;
        }

        $system_prompt = $this->build_translation_prompt($source_lang, $target_lang);

        $body = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'HTTP-Referer' => get_site_url(), // OpenRouter requirement
            'X-Title' => 'Ewheel Importer', // OpenRouter requirement
        ];

        try {
            $response = $this->http_client->post(self::API_URL, $body, $headers);

            if (isset($response['choices'][0]['message']['content'])) {
                return trim($response['choices'][0]['message']['content']);
            }

            throw new \RuntimeException('Invalid response structure from OpenRouter');
        } catch (\Exception $e) {
            // Log error but allow flow to continue (throw up)
            throw new \RuntimeException('OpenRouter Error: ' . $e->getMessage());
        }
    }

    /**
     * Translate batch.
     *
     * @param array  $texts       Texts to translate.
     * @param string $source_lang Source language.
     * @param string $target_lang Target language.
     * @return array Translated texts.
     */
    public function translate_batch(array $texts, string $source_lang, string $target_lang): array
    {
        if (empty($texts)) {
            return [];
        }

        $count = count($texts);

        // Skip translation entirely if using a reasoning model - they are too slow
        if ($this->is_reasoning_model($this->model)) {
            error_log("[Ewheel Translation] SKIPPING batch translation - model '{$this->model}' is a reasoning model (too slow)");
            error_log("[Ewheel Translation] Please change to a fast model in Settings: " . implode(', ', self::RECOMMENDED_MODELS));
            error_log("[Ewheel Translation] Returning {$count} original texts without translation");
            return array_values($texts);
        }

        // For small batches, use single prompt with numbered list
        // This is much faster than 64 separate API calls
        error_log("[Ewheel Translation] Batch translating {$count} texts via OpenRouter (model: {$this->model})");

        // Build numbered input
        $numbered_input = [];
        foreach ($texts as $index => $text) {
            $num = $index + 1;
            $numbered_input[] = "{$num}. {$text}";
        }
        $input_text = implode("\n", $numbered_input);

        $system_prompt = $this->build_batch_translation_prompt($source_lang, $target_lang, $count);

        $body = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $system_prompt,
                ],
                [
                    'role' => 'user',
                    'content' => $input_text,
                ],
            ],
            'max_tokens' => 4096,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'HTTP-Referer' => get_site_url(),
            'X-Title' => 'Ewheel Importer',
        ];

        try {
            error_log("[Ewheel Translation] Sending request to OpenRouter (model: {$this->model})...");
            $start_time = microtime(true);

            $response = $this->http_client->post(self::API_URL, $body, $headers);

            $elapsed = round(microtime(true) - $start_time, 2);
            error_log("[Ewheel Translation] OpenRouter responded in {$elapsed}s");

            if (!isset($response['choices'][0]['message']['content'])) {
                error_log("[Ewheel Translation] Invalid response: " . wp_json_encode($response));
                throw new \RuntimeException('Invalid response structure from OpenRouter');
            }

            $output = trim($response['choices'][0]['message']['content']);
            error_log("[Ewheel Translation] Batch response received, parsing...");

            // Parse numbered output
            $translated = $this->parse_numbered_response($output, $count, $texts);

            error_log("[Ewheel Translation] Batch complete: {$count} texts translated");
            return $translated;

        } catch (\Exception $e) {
            error_log("[Ewheel Translation] Batch translation failed: " . $e->getMessage());
            error_log("[Ewheel Translation] Returning original texts as fallback");
            // Fallback: return original texts
            return array_values($texts);
        }
    }

    /**
     * Parse numbered response from batch translation.
     *
     * @param string $output Raw output from LLM.
     * @param int    $count  Expected number of items.
     * @param array  $originals Original texts for fallback.
     * @return array Parsed translations.
     */
    private function parse_numbered_response(string $output, int $count, array $originals): array
    {
        $lines = preg_split('/\r?\n/', $output);
        $results = [];
        $current_num = 0;
        $current_text = '';

        foreach ($lines as $line) {
            // Match lines starting with number followed by period/colon
            if (preg_match('/^(\d+)[.:\)]\s*(.*)$/', trim($line), $matches)) {
                // Save previous if exists
                if ($current_num > 0 && $current_num <= $count) {
                    $results[$current_num - 1] = trim($current_text);
                }
                $current_num = (int) $matches[1];
                $current_text = $matches[2];
            } else {
                // Continuation of previous line
                $current_text .= ' ' . trim($line);
            }
        }

        // Don't forget the last one
        if ($current_num > 0 && $current_num <= $count) {
            $results[$current_num - 1] = trim($current_text);
        }

        // Fill missing with originals
        $final = [];
        $originals_indexed = array_values($originals);
        for ($i = 0; $i < $count; $i++) {
            $final[] = isset($results[$i]) && !empty($results[$i])
                ? $results[$i]
                : $originals_indexed[$i];
        }

        return $final;
    }

    /**
     * Build batch translation prompt.
     *
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @param int    $count       Number of items.
     * @return string The system prompt.
     */
    private function build_batch_translation_prompt(string $source_lang, string $target_lang, int $count): string
    {
        $base = "You are a professional translator for e-commerce products.";

        if ($target_lang === 'ro') {
            return "{$base}

TASK: Translate {$count} items from {$source_lang} to Romanian.

RULES:
- Use proper Romanian diacritics: ă, â, î, ș, ț
- Apply correct noun-adjective agreement
- Keep technical terms/brand names untranslated
- Use formal e-commerce register

FORMAT:
- Input: numbered list (1. text, 2. text, ...)
- Output: same numbered format with translations
- Return ONLY the numbered translations, nothing else

Example:
Input: 1. Electric Scooter
Output: 1. Trotinetă Electrică";
        }

        return "{$base}
Translate {$count} items from {$source_lang} to {$target_lang}.
Input is a numbered list. Return the same numbered format with translations only.";
    }

    /**
     * Build translation prompt with language-specific grammar rules.
     *
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @return string The system prompt.
     */
    private function build_translation_prompt(string $source_lang, string $target_lang): string
    {
        $base_prompt = "You are a professional translator specializing in e-commerce product content.";

        // Romanian-specific grammar rules
        if ($target_lang === 'ro') {
            return $base_prompt . " Translate from {$source_lang} to Romanian following these rules:

ROMANIAN GRAMMAR RULES:
- Use proper Romanian diacritics: ă, â, î, ș, ț (never substitute with a, i, s, t)
- Apply correct noun-adjective agreement (adjectives follow nouns, agree in gender/number/case)
- Use Romanian definite articles as suffixes (-ul, -a, -le, -lui, -lor)
- Use formal register appropriate for e-commerce
- Preserve technical product terms commonly used untranslated in Romanian (e.g., brand names, model numbers)
- For measurements, use Romanian conventions (km/h, kg, cm)
- Translate product categories naturally (e.g., 'Electric Scooters' → 'Trotinete Electrice')

Return ONLY the translation, no explanations, no quotes.";
        }

        // Default prompt for other languages
        return "{$base_prompt} Translate the following e-commerce product text from {$source_lang} to {$target_lang}. Return ONLY the translation, no extra text, no quotes.";
    }
}
