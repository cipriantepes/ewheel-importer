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
     * @return array The categories.
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

        return $this->http_client->get(
            $url,
            $this->get_headers()
        );
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
            $all_categories = array_merge($all_categories, $categories);
            $page++;
        } while (count($categories) >= $page_size);

        return $all_categories;
    }

    /**
     * Get products from the API.
     *
     * @param int   $page      The page number (0-indexed).
     * @param int   $page_size The number of items per page.
     * @param array $filters   Optional filters (active, hasImages, hasVariants, category, etc).
     * @return array The products.
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

        \Trotibike\EwheelImporter\Log\LiveLogger::log("API Request: POST $url (Page: $page)", 'info');

        try {
            $response = $this->http_client->post(
                $url,
                $body,
                $this->get_headers()
            );
            return $response;
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
            $all_products = array_merge($all_products, $products);
            $page++;
        } while (count($products) >= $page_size);

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
}
