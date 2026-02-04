<?php
/**
 * Ewheel API Client.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Api;

/**
 * Client for interacting with the ewheel.es API.
 */
class EwheelApiClient
{

    /**
     * Base URL for the ewheel.es API.
     */
    private const BASE_URL = 'https://api.ewheel.es';

    /**
     * Categories endpoint.
     */
    private const CATEGORIES_ENDPOINT = '/api/v1/catalog/categories';

    /**
     * Products filter endpoint.
     */
    private const PRODUCTS_ENDPOINT = '/api/v1/catalog/products/filter';

    /**
     * Default page size for API requests.
     */
    private const DEFAULT_PAGE_SIZE = 50;

    /**
     * Maximum pages to fetch (safety limit to prevent infinite loops).
     */
    private const MAX_PAGES = 100;

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
     * @param string              $api_key     The ewheel.es API key.
     * @param HttpClientInterface $http_client The HTTP client to use for requests.
     * @throws \InvalidArgumentException If API key is empty.
     */
    public function __construct(string $api_key, HttpClientInterface $http_client)
    {
        if (empty(trim($api_key))) {
            throw new \InvalidArgumentException('API key is required');
        }

        $this->api_key = $api_key;
        $this->http_client = $http_client;
    }

    /**
     * Get categories from the API.
     *
     * @param int $page      The page number (0-indexed).
     * @param int $page_size The number of items per page.
     * @return array The categories (extracted from Data wrapper).
     */
    public function get_categories(int $page = 0, int $page_size = self::DEFAULT_PAGE_SIZE): array
    {
        $url = add_query_arg(
            [
                'Page' => $page,
                'PageSize' => $page_size,
            ],
            self::BASE_URL . self::CATEGORIES_ENDPOINT
        );

        $response = $this->http_client->get(
            $url,
            $this->get_headers()
        );

        return $this->extract_data($response);
    }

    /**
     * Get all categories (handles pagination automatically).
     *
     * @return array All categories.
     */
    public function get_all_categories(): array
    {
        $all_categories = [];
        $page = 0;
        $page_size = self::DEFAULT_PAGE_SIZE;

        do {
            $categories = $this->get_categories($page, $page_size);

            if (empty($categories)) {
                break;
            }

            $all_categories = array_merge($all_categories, $categories);
            $page++;

            // Safety limit to prevent infinite loops
            if ($page >= self::MAX_PAGES) {
                \Trotibike\EwheelImporter\Log\LiveLogger::log(
                    "Warning: Reached max page limit ({$page}) for categories. Stopping pagination.",
                    'warning'
                );
                break;
            }
        } while (count($categories) >= $page_size);

        return $all_categories;
    }

    /**
     * Get products from the API.
     *
     * @param int   $page      The page number (0-indexed).
     * @param int   $page_size The number of items per page.
     * @param array $filters   Optional filters (active, hasImages, hasVariants, category, etc).
     * @return array The products (extracted from Data wrapper).
     */
    public function get_products(int $page = 0, int $page_size = self::DEFAULT_PAGE_SIZE, array $filters = []): array
    {
        $body = array_merge(
            [
                'Page' => $page,
                'PageSize' => $page_size,
            ],
            $filters
        );

        $url = self::BASE_URL . self::PRODUCTS_ENDPOINT;

        // DEBUG LOG
        error_log("Ewheel API Request (POST): $url " . wp_json_encode($body));
        \Trotibike\EwheelImporter\Log\LiveLogger::log("API Request: POST $url (Page: $page)", 'info');

        try {
            $response = $this->http_client->post(
                $url,
                $body,
                $this->get_headers()
            );

            // DEBUG LOG
            error_log("Ewheel API Response Raw: " . print_r($response, true));

            // Extract data from wrapper
            $products = $this->extract_data($response);

            $count = count($products);
            \Trotibike\EwheelImporter\Log\LiveLogger::log("API Response: {$count} products on page {$page}", 'info');

            if ($count === 0) {
                error_log("Ewheel API WARNING: 0 products returned. Raw Data Dump: " . print_r($response, true));
            }

            if (!empty($products)) {
                $first_item = $products[0];
                $first_id = is_array($first_item) ? ($first_item['Reference'] ?? ($first_item['Id'] ?? 'Unknown')) : 'Unknown';
                \Trotibike\EwheelImporter\Log\LiveLogger::log("First Item ID: $first_id", 'info');
            }

            return $products;
        } catch (\Exception $e) {
            \Trotibike\EwheelImporter\Log\LiveLogger::log("API Error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Get products modified since a specific date.
     *
     * @param string $since_date The date in format 'yyyy-MM-ddTHH:mm:ss'.
     * @param int    $page       The page number (0-indexed).
     * @param int    $page_size  The number of items per page.
     * @return array The products.
     */
    public function get_products_since(string $since_date, int $page = 0, int $page_size = self::DEFAULT_PAGE_SIZE): array
    {
        return $this->get_products(
            $page,
            $page_size,
            ['NewerThan' => $since_date]
        );
    }

    /**
     * Get all products (handles pagination automatically).
     *
     * @param array $filters Optional filters.
     * @return array All products.
     */
    public function get_all_products(array $filters = []): array
    {
        $all_products = [];
        $page = 0;
        $page_size = self::DEFAULT_PAGE_SIZE;

        do {
            $products = $this->get_products($page, $page_size, $filters);

            if (empty($products)) {
                break;
            }

            $all_products = array_merge($all_products, $products);
            $page++;

            // Safety limit to prevent infinite loops
            if ($page >= self::MAX_PAGES) {
                \Trotibike\EwheelImporter\Log\LiveLogger::log(
                    "Warning: Reached max page limit ({$page}) for products. Stopping pagination.",
                    'warning'
                );
                break;
            }
        } while (count($products) >= $page_size);

        \Trotibike\EwheelImporter\Log\LiveLogger::log(
            "Fetched " . count($all_products) . " total products in {$page} pages",
            'success'
        );

        return $all_products;
    }

    /**
     * Get the request headers.
     *
     * @return array The headers.
     */
    private function get_headers(): array
    {
        return [
            'X-API-KEY' => $this->api_key,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Extract data from API response wrapper.
     *
     * The ewheel.es API returns responses in a wrapper format:
     * {
     *     "Data": [...],      // Actual items
     *     "Ok": true,
     *     "Type": "Success",
     *     "Code": 0,
     *     "Message": "",
     *     "StackTrace": null
     * }
     *
     * @param array $response The raw API response.
     * @return array The extracted data array.
     */
    private function extract_data(array $response): array
    {
        // Check if response has the wrapper structure
        if (isset($response['Data']) && is_array($response['Data'])) {
            // Check for API errors in the wrapper
            if (isset($response['Ok']) && $response['Ok'] === false) {
                $message = $response['Message'] ?? 'Unknown API error';
                \Trotibike\EwheelImporter\Log\LiveLogger::log("API Error: {$message}", 'error');
                throw new \RuntimeException("API Error: {$message}");
            }

            return $response['Data'];
        }

        // Fallback: if response is already a direct array of items (indexed array)
        // This handles cases where the response might not have the wrapper
        if (!empty($response) && isset($response[0])) {
            return $response;
        }

        // Empty or unexpected response
        return [];
    }
}
