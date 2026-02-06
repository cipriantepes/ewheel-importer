<?php
/**
 * WordPress HTTP Client implementation.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Api;

/**
 * HTTP client using WordPress HTTP API.
 */
class WPHttpClient implements HttpClientInterface {

    /**
     * Default request timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 10;

    /**
     * Extended timeout for slow APIs (LLMs, etc) in seconds.
     * Reduced from 60 to 30 to prevent blocking sync for too long.
     */
    private const EXTENDED_TIMEOUT = 30;

    /**
     * Make a POST request.
     *
     * @param string $url     The URL to request.
     * @param array  $body    The request body.
     * @param array  $headers The request headers.
     * @return array The response data.
     * @throws \RuntimeException If the request fails.
     */
    public function post( string $url, array $body = [], array $headers = [] ): array {
        // Use extended timeout for OpenRouter/LLM APIs
        $timeout = $this->get_timeout_for_url( $url );

        $args = [
            'method'  => 'POST',
            'timeout' => $timeout,
            'headers' => array_merge(
                [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                $headers
            ),
            'body'    => wp_json_encode( $body ),
        ];

        $response = wp_remote_post( $url, $args );

        return $this->handle_response( $response );
    }

    /**
     * Make a GET request.
     *
     * @param string $url     The URL to request.
     * @param array  $headers The request headers.
     * @return array The response data.
     * @throws \RuntimeException If the request fails.
     */
    public function get( string $url, array $headers = [] ): array {
        $timeout = $this->get_timeout_for_url( $url );

        $args = [
            'method'  => 'GET',
            'timeout' => $timeout,
            'headers' => array_merge(
                [
                    'Accept' => 'application/json',
                ],
                $headers
            ),
        ];

        $response = wp_remote_get( $url, $args );

        return $this->handle_response( $response );
    }

    /**
     * Get appropriate timeout based on URL.
     *
     * @param string $url The URL being requested.
     * @return int Timeout in seconds.
     */
    private function get_timeout_for_url( string $url ): int {
        // Extended timeout for LLM/AI APIs that can be slow
        $slow_apis = [
            'openrouter.ai',
            'api.openai.com',
            'api.anthropic.com',
            'api.deepl.com',
        ];

        foreach ( $slow_apis as $api ) {
            if ( strpos( $url, $api ) !== false ) {
                return self::EXTENDED_TIMEOUT;
            }
        }

        return self::DEFAULT_TIMEOUT;
    }

    /**
     * Handle the response from WordPress HTTP API.
     *
     * @param array|\WP_Error $response The response.
     * @return array The decoded response body.
     * @throws \RuntimeException If the request fails.
     */
    private function handle_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                'HTTP request failed: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            $error_detail = '';

            // Try to extract error message from response body
            if ( ! empty( $body ) ) {
                $decoded = json_decode( $body, true );
                if ( isset( $decoded['error']['message'] ) ) {
                    $error_detail = ': ' . $decoded['error']['message'];
                } elseif ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
                    $error_detail = ': ' . $decoded['error'];
                } elseif ( isset( $decoded['message'] ) ) {
                    $error_detail = ': ' . $decoded['message'];
                }
            }

            throw new \RuntimeException(
                sprintf( 'HTTP request failed with status %d%s', $status_code, $error_detail )
            );
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            return [];
        }

        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            throw new \RuntimeException(
                'Failed to decode JSON response: ' . json_last_error_msg()
            );
        }

        return $decoded ?? [];
    }
}
