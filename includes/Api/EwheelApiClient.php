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
     * Stock endpoint.
     */
    private const STOCK_ENDPOINT = '/api/v1/catalog/stock/filter';

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
        } while (true); // Stop only when empty page received (API may return < page_size)

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
        // Per Swagger: Page, PageSize, NewerThan are query params; body is the filter object
        $query_params = [
            'Page' => $page,
            'PageSize' => $page_size,
        ];

        // NewerThan goes in query params, not body
        if (isset($filters['NewerThan'])) {
            $query_params['NewerThan'] = $filters['NewerThan'];
            unset($filters['NewerThan']);
        }

        $url = self::BASE_URL . self::PRODUCTS_ENDPOINT . '?' . http_build_query($query_params);

        // Body contains filter fields: hasImages, active, category, productReference, etc.
        $body = !empty($filters) ? $filters : new \stdClass();

        // DEBUG: Log full request details
        \Trotibike\EwheelImporter\Log\PersistentLogger::info("[DEBUG] API Request: POST {$url}, body=" . wp_json_encode($body));
        \Trotibike\EwheelImporter\Log\LiveLogger::log("API Request: POST {$url} (Page: {$page})", 'info');

        try {
            $response = $this->http_client->post(
                $url,
                (array) $body,
                $this->get_headers()
            );

            // Extract data from wrapper
            $products = $this->extract_data($response);

            $count = count($products);
            // DEBUG: Log response details
            \Trotibike\EwheelImporter\Log\PersistentLogger::info("[DEBUG] API Response: HTTP success, products={$count}");
            \Trotibike\EwheelImporter\Log\LiveLogger::log("API Response: {$count} products on page {$page}", 'info');

            if ($count === 0) {
                \Trotibike\EwheelImporter\Log\LiveLogger::log("API WARNING: 0 products returned.", 'warning');
            }

            if (!empty($products)) {
                $first_item = $products[0];
                // Log the first item's ID for debugging (handle case sensitivity and nested variants)
                $first_id = 'Unknown';
                if (is_array($first_item)) {
                    // Check top-level keys
                    $first_id = $first_item['Reference'] ?? ($first_item['Id'] ?? ($first_item['reference'] ?? ($first_item['id'] ?? null)));

                    // If not found, check variants (common in Ewheel API v1)
                    if ($first_id === null && !empty($first_item['variants']) && is_array($first_item['variants'])) {
                        $first_variant = $first_item['variants'][0] ?? [];
                        $first_id = $first_variant['reference'] ?? ($first_variant['id'] ?? ($first_variant['Reference'] ?? ($first_variant['Id'] ?? 'Unknown')));
                        $first_id .= ' (Variant)';
                    }

                    if ($first_id === null) {
                        $first_id = 'Unknown';
                    }
                }

                \Trotibike\EwheelImporter\Log\LiveLogger::log("Found {$count} products. First Item ID: {$first_id}", 'info');
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
     * Get total product count from the API.
     *
     * Makes a minimal request to fetch the total count without loading all data.
     *
     * @param array $filters Optional filters.
     * @return int The total product count.
     */
    public function get_product_count(array $filters = []): int
    {
        // The API doesn't return a total count field and max PageSize is ~50.
        // Paginate through all pages, counting products without storing them.
        $total = 0;
        $page = 0;
        $page_size = 50;
        $max_pages = 200; // Safety limit (~10,000 products)

        do {
            $products = $this->get_products($page, $page_size, $filters);
            $count = count($products);
            $total += $count;
            $page++;
        } while ($count > 0 && $page < $max_pages);

        return $total;
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
        } while (true); // Stop only when empty page received (API may return < page_size)

        \Trotibike\EwheelImporter\Log\LiveLogger::log(
            "Fetched " . count($all_products) . " total products in {$page} pages",
            'success'
        );

        return $all_products;
    }

    /**
     * Get stock levels from the API.
     *
     * Returns all stock entries. The stock endpoint returns all data in one call.
     *
     * @return array Stock data indexed by variant reference.
     */
    public function get_stock(): array
    {
        $url = self::BASE_URL . self::STOCK_ENDPOINT;

        try {
            $response = $this->http_client->post(
                $url,
                [],
                $this->get_headers()
            );

            $data = $this->extract_data($response);

            // Index by variant reference for easy lookup
            $indexed = [];
            foreach ($data as $entry) {
                $ref = $entry['variantReference'] ?? '';
                if (!empty($ref)) {
                    $indexed[$ref] = (int) ($entry['stock'] ?? 0);
                }
            }

            \Trotibike\EwheelImporter\Log\LiveLogger::log(
                "Fetched stock for " . count($indexed) . " variants",
                'success'
            );

            return $indexed;
        } catch (\Exception $e) {
            \Trotibike\EwheelImporter\Log\LiveLogger::log(
                "Failed to fetch stock: " . $e->getMessage(),
                'error'
            );
            return [];
        }
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
        // Check if response has the wrapper structure (handle case sensitivity)
        $data = null;
        if (isset($response['Data']) && is_array($response['Data'])) {
            $data = $response['Data'];
        } elseif (isset($response['data']) && is_array($response['data'])) {
            $data = $response['data'];
        }

        if ($data !== null) {
            // Check for API errors in the wrapper
            // Note: API might return keys in different cases, checking standard PascalCase first
            if (
                (isset($response['Ok']) && $response['Ok'] === false) ||
                (isset($response['ok']) && $response['ok'] === false)
            ) {

                $message = $response['Message'] ?? ($response['message'] ?? 'Unknown API error');
                \Trotibike\EwheelImporter\Log\LiveLogger::log("API Error: {$message}", 'error');
                throw new \RuntimeException("API Error: {$message}");
            }

            return $data;
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
