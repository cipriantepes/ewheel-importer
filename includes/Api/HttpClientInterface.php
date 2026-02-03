<?php
/**
 * HTTP Client Interface.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Api;

/**
 * Interface for HTTP clients.
 */
interface HttpClientInterface {

    /**
     * Make a POST request.
     *
     * @param string $url     The URL to request.
     * @param array  $body    The request body.
     * @param array  $headers The request headers.
     * @return array The response data.
     * @throws \RuntimeException If the request fails.
     */
    public function post( string $url, array $body = [], array $headers = [] ): array;

    /**
     * Make a GET request.
     *
     * @param string $url     The URL to request.
     * @param array  $headers The request headers.
     * @return array The response data.
     * @throws \RuntimeException If the request fails.
     */
    public function get( string $url, array $headers = [] ): array;
}
