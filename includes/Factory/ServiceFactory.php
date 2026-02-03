<?php
/**
 * Service Factory.
 *
 * @package Trotibike\EwheelImporter
 */

namespace Trotibike\EwheelImporter\Factory;

use Trotibike\EwheelImporter\Config\Configuration;
use Trotibike\EwheelImporter\Container\ServiceContainer;
use Trotibike\EwheelImporter\Api\EwheelApiClient;
use Trotibike\EwheelImporter\Api\WPHttpClient;
use Trotibike\EwheelImporter\Api\HttpClientInterface;
use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Translation\GoogleTranslateService;
use Trotibike\EwheelImporter\Translation\DeepLTranslateService;
use Trotibike\EwheelImporter\Translation\TranslationServiceInterface;
use Trotibike\EwheelImporter\Repository\TranslationRepository;
use Trotibike\EwheelImporter\Pricing\PricingConverter;
use Trotibike\EwheelImporter\Pricing\FixedExchangeRateProvider;
use Trotibike\EwheelImporter\Pricing\ExchangeRateProviderInterface;
use Trotibike\EwheelImporter\Sync\ProductTransformer;
use Trotibike\EwheelImporter\Sync\SyncService;
use Trotibike\EwheelImporter\Repository\ProductRepository;
use Trotibike\EwheelImporter\Repository\CategoryRepository;
use Trotibike\EwheelImporter\Service\ImageService;

/**
 * Factory for creating and configuring services.
 *
 * Open/Closed Principle: Easy to extend with new services without modifying existing code.
 * Dependency Inversion: Creates abstractions, not concretions.
 */
class ServiceFactory
{

    /**
     * Build and configure the service container.
     *
     * @return ServiceContainer Configured container.
     */
    public static function build_container(): ServiceContainer
    {
        $container = new ServiceContainer();

        // Configuration
        $container->singleton(
            Configuration::class,
            fn() => new Configuration()
        );

        // HTTP Client
        $container->singleton(
            HttpClientInterface::class,
            fn() => new WPHttpClient()
        );

        // Ewheel API Client
        $container->singleton(
            EwheelApiClient::class,
            function (ServiceContainer $c) {
                $config = $c->get(Configuration::class);
                return new EwheelApiClient(
                    $config->get_api_key(),
                    $c->get(HttpClientInterface::class)
                );
            }
        );

        // Translation Service
        $container->singleton(
            TranslationServiceInterface::class,
            function (ServiceContainer $c) {
                $config = $c->get(Configuration::class);
                $driver = $config->get_translation_driver();

                if ($driver === 'deepl') {
                    return new DeepLTranslateService(
                        $config->get_deepl_api_key(),
                        $c->get(HttpClientInterface::class)
                    );
                }

                return new GoogleTranslateService(
                    $config->get_translate_api_key(),
                    $c->get(HttpClientInterface::class)
                );
            }
        );

        // Translation Repository
        $container->singleton(
            \Trotibike\EwheelImporter\Repository\TranslationRepository::class,
            fn() => new \Trotibike\EwheelImporter\Repository\TranslationRepository()
        );

        // Translator
        $container->singleton(
            Translator::class,
            function (ServiceContainer $c) {
                $config = $c->get(Configuration::class);
                return new Translator(
                    $c->get(TranslationServiceInterface::class),
                    $c->get(\Trotibike\EwheelImporter\Repository\TranslationRepository::class),
                    $config->get_target_language()
                );
            }
        );

        // Exchange Rate Provider
        $container->singleton(
            ExchangeRateProviderInterface::class,
            function (ServiceContainer $c) {
                $config = $c->get(Configuration::class);
                return new FixedExchangeRateProvider(
                    ['EUR_RON' => $config->get_exchange_rate()]
                );
            }
        );

        // Pricing Converter
        $container->singleton(
            PricingConverter::class,
            function (ServiceContainer $c) {
                $config = $c->get(Configuration::class);
                return new PricingConverter(
                    $c->get(ExchangeRateProviderInterface::class),
                    'EUR',
                    'RON',
                    $config->get_markup_percent()
                );
            }
        );

        // Attribute Service
        $container->singleton(
            \Trotibike\EwheelImporter\Service\AttributeService::class,
            fn() => new \Trotibike\EwheelImporter\Service\AttributeService()
        );

        // Variation Service
        $container->singleton(
            \Trotibike\EwheelImporter\Service\VariationService::class,
            fn() => new \Trotibike\EwheelImporter\Service\VariationService()
        );

        // Image Service
        $container->singleton(
            ImageService::class,
            fn() => new ImageService()
        );

        // Repositories
        $container->singleton(
            CategoryRepository::class,
            fn() => new CategoryRepository()
        );

        $container->singleton(
            ProductRepository::class,
            fn(ServiceContainer $c) => new ProductRepository(
                $c->get(ImageService::class)
            )
        );

        // Product Transformer
        $container->singleton(
            ProductTransformer::class,
            function (ServiceContainer $c) {
                return new ProductTransformer(
                    $c->get(Translator::class),
                    $c->get(PricingConverter::class),
                    $c->get(Configuration::class)
                );
            }
        );

        // Sync Service
        $container->singleton(
            SyncService::class,
            function (ServiceContainer $c) {
                return new SyncService(
                    $c->get(EwheelApiClient::class),
                    $c->get(ProductTransformer::class),
                    $c->get(ProductRepository::class),
                    $c->get(CategoryRepository::class),
                    $c->get(Configuration::class)
                );
            }
        );

        // Sync Launcher
        $container->singleton(
            \Trotibike\EwheelImporter\Sync\SyncLauncher::class,
            fn(ServiceContainer $c) => new \Trotibike\EwheelImporter\Sync\SyncLauncher(
                $c->get(Configuration::class)
            )
        );

        // WooCommerce Sync
        $container->singleton(
            \Trotibike\EwheelImporter\Sync\WooCommerceSync::class,
            fn(ServiceContainer $c) => new \Trotibike\EwheelImporter\Sync\WooCommerceSync(
                $c->get(EwheelApiClient::class),
                $c->get(ProductTransformer::class),
                $c->get(\Trotibike\EwheelImporter\Service\AttributeService::class),
                $c->get(\Trotibike\EwheelImporter\Service\VariationService::class),
                $c->get(ImageService::class)
            )
        );

        // Sync Batch Processor
        $container->singleton(
            \Trotibike\EwheelImporter\Sync\SyncBatchProcessor::class,
            fn(ServiceContainer $c) => new \Trotibike\EwheelImporter\Sync\SyncBatchProcessor(
                $c->get(EwheelApiClient::class),
                $c->get(\Trotibike\EwheelImporter\Sync\WooCommerceSync::class),
                $c->get(Configuration::class)
            )
        );

        return $container;
    }

    /**
     * Create a sync service with all dependencies.
     *
     * Convenience method for quick access.
     *
     * @return SyncService
     */
    public static function create_sync_service(): SyncService
    {
        $container = self::build_container();
        return $container->get(SyncService::class);
    }

    /**
     * Create an API client for testing connection.
     *
     * @param string $api_key The API key to test.
     * @return EwheelApiClient
     */
    public static function create_api_client(string $api_key): EwheelApiClient
    {
        return new EwheelApiClient($api_key, new WPHttpClient());
    }
}
