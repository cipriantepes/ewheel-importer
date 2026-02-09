# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WordPress/WooCommerce plugin that imports products from the ewheel.es B2B API with automatic translation (Google Translate, DeepL, or OpenRouter) and EUR→RON price conversion.

## Commands

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Run tests with coverage report
composer test:coverage

# Run a single test file
vendor/bin/phpunit tests/Unit/Translation/TranslatorTest.php

# Run a single test method
vendor/bin/phpunit --filter testTranslateWithCache

# Build release ZIP (production-ready with vendor)
bin/build-release.sh
```

## Architecture

PSR-4 autoloading under `Trotibike\EwheelImporter` namespace. All classes in `includes/`.

### Core Components

| Directory | Purpose |
|-----------|---------|
| `Api/` | `EwheelApiClient` fetches products from ewheel.es API |
| `Sync/` | Orchestrates import: `SyncService` → `SyncBatchProcessor` → `ProductTransformer` → `WooCommerceSync` |
| `Translation/` | `Translator` facade with `GoogleTranslateService`, `DeepLTranslateService`, `OpenRouterTranslateService` |
| `Repository/` | Database layer: `ProductRepository`, `CategoryRepository`, `TranslationRepository`, `ProfileRepository` |
| `Config/` | `Configuration` (main settings), `ProfileConfiguration`, `AttributeConfiguration` |
| `Container/` | `ServiceContainer` for dependency injection |
| `Factory/` | `ServiceFactory` instantiates services |
| `Pricing/` | EUR to RON conversion with configurable markup |
| `Admin/` | WordPress admin page and settings UI |
| `Log/` | `PersistentLogger`, `LiveLogger` for sync logging |

### Data Flow

1. `SyncLauncher` triggers sync (manual or scheduled via WP Cron)
2. `EwheelApiClient` fetches products from API
3. `SyncBatchProcessor` processes in batches via Action Scheduler
4. `ProductTransformer` maps API data to WooCommerce format
5. `Translator` translates text fields (with database caching)
6. `WooCommerceSync` creates/updates WooCommerce products

### Key Patterns

- **Background Processing**: Uses Action Scheduler for non-blocking batch imports
- **Translation Caching**: `TranslationRepository` persists translations to avoid repeat API calls
- **Product Matching**: SKU-based (ewheel `Reference` field) for idempotent updates
- **Incremental Sync**: Only processes products modified since last sync

## Testing

Three test suites in `phpunit.xml`:
- `Unit` - Pure unit tests with mocked dependencies
- `Integration` - Tests with WordPress/WooCommerce stubs
- `Security` - Security-focused tests

Uses Brain Monkey for WordPress function mocking and Mockery for general mocking.

## Version Updates

Version is defined in the plugin header of `ewheel-importer.php`. The `bin/build-release.sh` script extracts this version automatically. GitHub releases trigger auto-updates via `yahnis-elsts/plugin-update-checker`.
