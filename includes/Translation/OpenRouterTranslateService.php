<?php
/**
 * OpenRouter Translate Service.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

use Trotibike\EwheelImporter\Api\HttpClientInterface;
use Trotibike\EwheelImporter\Log\PersistentLogger;

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
        'google/gemini-2.5-flash',
        'google/gemini-2.0-flash-001',
        'google/gemini-2.0-flash-lite-001',
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
            $msg = "Translation SKIPPED: model '{$this->model}' is a reasoning model (too slow). Change to a fast model: " . implode(', ', self::RECOMMENDED_MODELS);
            error_log("[Ewheel Translation] " . $msg);
            PersistentLogger::warning("[Translation] " . $msg);
            return array_values($texts);
        }

        // For small batches, use single prompt with numbered list
        // This is much faster than N separate API calls
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
            'max_tokens' => 8192,
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'HTTP-Referer' => get_site_url(),
            'X-Title' => 'Ewheel Importer',
        ];

        $max_attempts = 2;
        $last_exception = null;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                error_log("[Ewheel Translation] Sending request to OpenRouter (model: {$this->model}, attempt {$attempt}/{$max_attempts})...");
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
                $last_exception = $e;
                error_log("[Ewheel Translation] Batch attempt {$attempt} failed: " . $e->getMessage());

                if ($attempt < $max_attempts) {
                    error_log("[Ewheel Translation] Retrying in 2 seconds...");
                    sleep(2);
                }
            }
        }

        error_log("[Ewheel Translation] All {$max_attempts} attempts failed. Returning original texts as fallback.");
        PersistentLogger::warning("[Translation] Batch translation failed after {$max_attempts} attempts: " . ($last_exception ? $last_exception->getMessage() : 'unknown'));
        return array_values($texts);
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

ROMANIAN GRAMMAR RULES:
- Use proper Romanian diacritics: ă, â, î, ș, ț (NEVER use ş or ţ with cedilla)
- Apply correct noun-adjective agreement (adjective follows noun, agrees in gender/number)
- Use definite article as suffix where natural: -ul, -a, -le, -lui, -lor
- Use formal e-commerce register
- Keep technical terms, brand names, model numbers, and measurements untranslated
- Do NOT translate pure numbers — return them as-is
- Capitalize only the first word and proper nouns (Romanian does not use Title Case)
- Use correct prepositions: 'pentru' (for), 'cu' (with), 'fara' (without), 'din' (from/of)
- Product names: use natural Romanian word order (noun + adjective, e.g. 'Trotineta Electrica')
- Short attribute values: translate concisely (e.g. 'Sin gel' = 'Fara gel', 'Si' = 'Da', 'No' = 'Nu')

FORMAT:
- Input: numbered list (1. text, 2. text, ...)
- Output: same numbered format with translations
- Return ONLY the numbered translations, nothing else

Example:
Input: 1. Patinete Electrico  2. Cubierta sin camara
Output: 1. Trotineta electrica  2. Anvelopa fara camera";
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
- Use proper Romanian diacritics: ă, â, î, ș, ț (NEVER use ş or ţ with cedilla, NEVER substitute with a, i, s, t)
- Apply correct noun-adjective agreement (adjective follows noun, agrees in gender/number/case)
- Use definite article as suffix where natural: -ul, -a, -le, -lui, -lor
- Use formal e-commerce register
- Keep technical terms, brand names, model numbers, and measurements untranslated
- Do NOT translate pure numbers — return them as-is
- Capitalize only the first word and proper nouns (Romanian does not use Title Case for common nouns)
- Use correct prepositions: 'pentru' (for), 'cu' (with), 'fara' (without), 'din' (from/of)
- Product names: use natural Romanian word order (noun + adjective)
- For measurements, use Romanian conventions (km/h, kg, cm)

Return ONLY the translation, no explanations, no quotes.";
        }

        // Default prompt for other languages
        return "{$base_prompt} Translate the following e-commerce product text from {$source_lang} to {$target_lang}. Return ONLY the translation, no extra text, no quotes.";
    }
}
