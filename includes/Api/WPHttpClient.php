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
     * Request timeout in seconds.
     */
    private const TIMEOUT = 30;

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
        $args = [
            'method'  => 'POST',
            'timeout' => self::TIMEOUT,
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
        $args = [
            'method'  => 'GET',
            'timeout' => self::TIMEOUT,
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
            throw new \RuntimeException(
                sprintf( 'HTTP request failed with status %d', $status_code )
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
