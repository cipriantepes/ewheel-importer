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
        // OpenRouter doesn't have a native batch endpoint like Google/DeepL.
        // For now, we loop. In future, we could use parallel requests or a bulk prompt.
        // Given we cache, looping is acceptable for the first run.
        $translated = [];
        foreach ($texts as $text) {
            $translated[] = $this->translate($text, $source_lang, $target_lang);
        }
        return $translated;
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
