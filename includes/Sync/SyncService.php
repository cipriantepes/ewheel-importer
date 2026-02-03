<?php
/**
 * Sync Service.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Sync;

use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Repository\ProductRepository;
use Trotibike\EwheelImporter\Repository\CategoryRepository;

/**
 * Orchestrates the sync process.
 *
 * Single Responsibility: Coordinates sync operations, delegates to repositories.
 * Dependency Inversion: Depends on abstractions (repositories), not concretions.
 */
class SyncService {

    /**
     * Batch size for processing.
     */
    private const BATCH_SIZE = 50;

    /**
     * Ewheel API client.
     *
     * @var EwheelApiClient
     */
    private EwheelApiClient $api_client;

    /**
     * Product transformer.
     *
     * @var ProductTransformer
     */
    private ProductTransformer $transformer;

    /**
     * Product repository.
     *
     * @var ProductRepository
     */
    private ProductRepository $product_repo;

    /**
     * Category repository.
     *
     * @var CategoryRepository
     */
    private CategoryRepository $category_repo;

    /**
     * Configuration.
     *
     * @var Configuration
     */
    private Configuration $config;

    /**
     * Constructor.
     *
     * @param EwheelApiClient    $api_client    API client.
     * @param ProductTransformer $transformer   Product transformer.
     * @param ProductRepository  $product_repo  Product repository.
     * @param CategoryRepository $category_repo Category repository.
     * @param Configuration      $config        Configuration.
     */
    public function __construct(
        EwheelApiClient $api_client,
        ProductTransformer $transformer,
        ProductRepository $product_repo,
        CategoryRepository $category_repo,
        Configuration $config
    ) {
        $this->api_client    = $api_client;
        $this->transformer   = $transformer;
        $this->product_repo  = $product_repo;
        $this->category_repo = $category_repo;
        $this->config        = $config;
    }

    /**
     * Run full sync.
     *
     * @param bool $dry_run If true, don't persist changes.
     * @return SyncResult
     */
    public function sync_all( bool $dry_run = false ): SyncResult {
        $result = new SyncResult();

        try {
            // Sync categories first
            $category_result = $this->sync_categories();
            $result->add_categories( $category_result );

            // Update transformer with category mapping
            $this->transformer->set_category_map( $this->category_repo->get_mapping() );

            // Sync products
            $product_result = $this->sync_products( $dry_run );
            $result->merge( $product_result );

            // Update last sync time
            if ( ! $dry_run ) {
                $this->config->update_last_sync();
            }
        } catch ( \Exception $e ) {
            $result->add_error( $e->getMessage() );
        }

        return $result;
    }

    /**
     * Run incremental sync.
     *
     * @param string|null $since Date to sync from (null uses last sync time).
     * @param bool        $dry_run If true, don't persist changes.
     * @return SyncResult
     */
    public function sync_incremental( ?string $since = null, bool $dry_run = false ): SyncResult {
        $since = $since ?? $this->config->get_last_sync();

        if ( ! $since ) {
            return $this->sync_all( $dry_run );
        }

        $result = new SyncResult();

        try {
            $products = $this->fetch_products_since( $since );
            $result   = $this->process_products( $products, $dry_run );

            if ( ! $dry_run ) {
                $this->config->update_last_sync();
            }
        } catch ( \Exception $e ) {
            $result->add_error( $e->getMessage() );
        }

        return $result;
    }

    /**
     * Sync categories.
     *
     * @return SyncResult
     */
    public function sync_categories(): SyncResult {
        $result     = new SyncResult();
        $categories = $this->api_client->get_all_categories();

        foreach ( $categories as $category ) {
            try {
                $this->category_repo->save(
                    [
                        'reference'        => $category['reference'] ?? '',
                        'name'             => $category['name'] ?? '',
                        'parent_reference' => $category['parentReference'] ?? null,
                    ]
                );
                $result->increment_updated();
            } catch ( \Exception $e ) {
                $result->add_error( "Category {$category['reference']}: " . $e->getMessage() );
            }
        }

        return $result;
    }

    /**
     * Sync all products.
     *
     * @param bool $dry_run If true, don't persist changes.
     * @return SyncResult
     */
    public function sync_products( bool $dry_run = false ): SyncResult {
        $products = $this->api_client->get_all_products( [ 'active' => true ] );
        return $this->process_products( $products, $dry_run );
    }

    /**
     * Fetch products modified since date.
     *
     * @param string $since Date string.
     * @return array
     */
    private function fetch_products_since( string $since ): array {
        $all_products = [];
        $page         = 0;

        do {
            $products     = $this->api_client->get_products_since( $since, $page );
            $all_products = array_merge( $all_products, $products );
            $page++;
        } while ( count( $products ) >= self::BATCH_SIZE );

        return $all_products;
    }

    /**
     * Process products in batches.
     *
     * @param array $products Ewheel products.
     * @param bool  $dry_run  If true, don't persist changes.
     * @return SyncResult
     */
    private function process_products( array $products, bool $dry_run ): SyncResult {
        $result = new SyncResult();

        // Transform all products
        $woo_products = $this->transformer->transform_batch( $products );

        // Process in batches
        foreach ( array_chunk( $woo_products, self::BATCH_SIZE ) as $batch ) {
            $batch_result = $this->process_batch( $batch, $dry_run );
            $result->merge( $batch_result );
        }

        return $result;
    }

    /**
     * Process a batch of products.
     *
     * @param array $products WooCommerce-formatted products.
     * @param bool  $dry_run  If true, don't persist changes.
     * @return SyncResult
     */
    private function process_batch( array $products, bool $dry_run ): SyncResult {
        $result = new SyncResult();

        foreach ( $products as $product ) {
            if ( $dry_run ) {
                $result->add_preview( $product );
                continue;
            }

            $outcome = $this->save_product( $product );
            $result->record( $outcome );
        }

        return $result;
    }

    /**
     * Save a single product.
     *
     * @param array $product Product data.
     * @return string 'created', 'updated', or 'error'.
     */
    private function save_product( array $product ): string {
        $sku = $product['sku'] ?? '';

        try {
            $existing = $this->product_repo->find_by_reference( $sku );
            $this->product_repo->save( $product );

            return $existing ? 'updated' : 'created';
        } catch ( \Exception $e ) {
            error_log( "Ewheel Importer - Failed to save product {$sku}: " . $e->getMessage() );
            return 'error';
        }
    }
}
