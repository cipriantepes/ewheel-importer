<?php
/**
 * Google Translate Service implementation.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Translation;

use Trotibike\EwheelImporter\Api\HttpClientInterface;

/**
 * Translation service using Google Cloud Translation API.
 */
class GoogleTranslateService implements TranslationServiceInterface {

    /**
     * Google Translate API endpoint.
     */
    private const API_ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

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
     * @param string              $api_key     The Google Cloud API key.
     * @param HttpClientInterface $http_client The HTTP client.
     */
    public function __construct( string $api_key, HttpClientInterface $http_client ) {
        $this->api_key     = $api_key;
        $this->http_client = $http_client;
    }

    /**
     * Translate text from one language to another.
     *
     * @param string $text        The text to translate.
     * @param string $source_lang The source language code.
     * @param string $target_lang The target language code.
     * @return string The translated text.
     * @throws \RuntimeException If translation fails.
     */
    public function translate( string $text, string $source_lang, string $target_lang ): string {
        $results = $this->translate_batch( [ $text ], $source_lang, $target_lang );
        return $results[0] ?? $text;
    }

    /**
     * Translate multiple texts at once.
     *
     * @param array  $texts       Array of texts to translate.
     * @param string $source_lang The source language code.
     * @param string $target_lang The target language code.
     * @return array Array of translated texts.
     * @throws \RuntimeException If translation fails.
     */
    public function translate_batch( array $texts, string $source_lang, string $target_lang ): array {
        if ( empty( $texts ) ) {
            return [];
        }

        $url = self::API_ENDPOINT . '?key=' . urlencode( $this->api_key );

        $body = [
            'q'      => $texts,
            'source' => $this->normalize_language_code( $source_lang ),
            'target' => $this->normalize_language_code( $target_lang ),
            'format' => 'text',
        ];

        try {
            $response = $this->http_client->post( $url, $body, [] );

            if ( ! isset( $response['data']['translations'] ) ) {
                throw new \RuntimeException( 'Invalid response from Google Translate API' );
            }

            $translations = [];
            foreach ( $response['data']['translations'] as $translation ) {
                $translations[] = $translation['translatedText'] ?? '';
            }

            return $translations;
        } catch ( \Exception $e ) {
            throw new \RuntimeException(
                'Translation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Normalize language code for Google Translate API.
     *
     * @param string $code The language code.
     * @return string The normalized code.
     */
    private function normalize_language_code( string $code ): string {
        // Google uses ISO 639-1 codes
        $code_map = [
            'en' => 'en',
            'es' => 'es',
            'ro' => 'ro',
            'de' => 'de',
            'fr' => 'fr',
            'it' => 'it',
        ];

        $code = strtolower( trim( $code ) );
        return $code_map[ $code ] ?? $code;
    }
}
