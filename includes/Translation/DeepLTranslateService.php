<?php
/**
 * DeepL Translate Service implementation.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

use Trotibike\EwheelImporter\Api\HttpClientInterface;

/**
 * Translation service using DeepL API.
 */
class DeepLTranslateService implements TranslationServiceInterface
{

    /**
     * DeepL API endpoints.
     */
    private const API_FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';
    private const API_PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

    /**
     * The API key.
     *
     * @var string
     */
    private string $api_key;

    /**
     * The HTTP client.
     *
     * @var HttpClientInterface
     */
    private HttpClientInterface $http_client;

    /**
     * Constructor.
     *
     * @param string              $api_key     The DeepL API key.
     * @param HttpClientInterface $http_client The HTTP client.
     */
    public function __construct(string $api_key, HttpClientInterface $http_client)
    {
        $this->api_key = $api_key;
        $this->http_client = $http_client;
    }

    /**
     * Translate text.
     *
     * @param string $text        The text to translate.
     * @param string $source_lang The source language code.
     * @param string $target_lang The target language code.
     * @return string The translated text.
     */
    public function translate(string $text, string $source_lang, string $target_lang): string
    {
        $results = $this->translate_batch([$text], $source_lang, $target_lang);
        return $results[0] ?? $text;
    }

    /**
     * Translate batch.
     *
     * @param array  $texts       Array of texts to translate.
     * @param string $source_lang The source language code.
     * @param string $target_lang The target language code.
     * @return array Array of translated texts.
     */
    public function translate_batch(array $texts, string $source_lang, string $target_lang): array
    {
        if (empty($texts)) {
            return [];
        }

        // Determine if using Free or Pro API
        $endpoint = strpos($this->api_key, ':fx') !== false
            ? self::API_FREE_ENDPOINT
            : self::API_PRO_ENDPOINT;

        $body = [
            'text' => $texts,
            'source_lang' => strtoupper($source_lang),
            'target_lang' => strtoupper($target_lang),
        ];

        try {
            // DeepL expects form-url-encoded or JSON. Using request params directly via HttpClient if possible,
            // but our HttpClient sends JSON body by default in post(). DeepL accepts JSON.
            // We need to pass Authorization header.
            $headers = [
                'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
                'Content-Type' => 'application/json',
            ];

            $response = $this->http_client->post($endpoint, $body, $headers);

            if (!isset($response['translations'])) {
                throw new \RuntimeException('Invalid response from DeepL API');
            }

            $translations = [];
            foreach ($response['translations'] as $translation) {
                $translations[] = $translation['text'] ?? '';
            }

            return $translations;
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'DeepL Translation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
