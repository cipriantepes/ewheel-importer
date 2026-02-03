# Ewheel Importer for WooCommerce

A WordPress plugin to import products from the ewheel.es API into WooCommerce with automatic translation and price conversion.

## Features

- **Automatic Product Import**: Fetches products from ewheel.es API and creates/updates them in WooCommerce
- **Translation**: Automatically translates product names and descriptions to Romanian using Google Translate API
- **Price Conversion**: Converts EUR prices to RON with configurable exchange rate and markup percentage
- **Category Sync**: Imports categories and maintains hierarchy
- **Variations Support**: Handles products with variants/variations
- **Image Import**: Downloads and imports product images
- **Incremental Sync**: Only syncs products modified since last sync
- **Scheduled Sync**: Automatic daily or weekly synchronization via WP Cron
- **Manual Sync**: One-click sync from admin panel

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+
- ewheel.es API key
- Google Cloud Translate API key (for translation)

## Installation

### From ZIP file

1. Download the plugin ZIP file
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the ZIP file and click "Install Now"
4. Activate the plugin

### Manual Installation

1. Upload the `ewheel-importer` folder to `/wp-content/plugins/`
2. Run `composer install` in the plugin directory (for development)
3. Activate the plugin in WordPress Admin → Plugins

## Configuration

1. Go to WooCommerce → Ewheel Import
2. Enter your **ewheel.es API Key**
3. Enter your **Google Translate API Key** (for automatic translation)
4. Set your **EUR to RON exchange rate**
5. Set your **markup percentage** (e.g., 20 for 20% profit margin)
6. Choose **sync frequency** (daily, weekly, or manual)
7. Click "Save Changes"

## Usage

### Manual Sync

1. Go to WooCommerce → Ewheel Import
2. Click "Run Sync Now"
3. Wait for the sync to complete

### Automatic Sync

The plugin uses WP Cron to run automatic syncs based on your configured frequency.

**Note**: WP Cron only runs when someone visits your site. For reliable scheduling on low-traffic sites, set up a real cron job:

```bash
# Add to your server's crontab
*/15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

## API Keys Setup

### ewheel.es API Key

Contact ewheel.es to obtain your API key for the B2B catalog API.

### Google Cloud Translate API Key

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable the "Cloud Translation API"
4. Create an API key in "Credentials"
5. (Optional) Restrict the key to the Translation API for security

## Product Matching

Products are matched by **SKU** (using the ewheel `Reference` field). This allows:
- Creating new products if SKU doesn't exist
- Updating existing products if SKU matches
- No duplicate products

## Data Mapping

| ewheel.es Field | WooCommerce Field | Notes |
|-----------------|-------------------|-------|
| Reference | SKU | Unique identifier |
| Name | Product Name | Translated to target language |
| Description | Description | Translated to target language |
| RRP | Regular Price | Converted to RON + markup |
| Images | Product Images | Downloaded to Media Library |
| Categories | Product Categories | Mapped via reference |
| Attributes | Product Attributes | Displayed on product page |
| Variants | Product Variations | For variable products |
| Active | Status | true=publish, false=draft |

## Development

### Running Tests

```bash
composer install
composer test
```

### Code Structure

```
ewheel-importer/
├── ewheel-importer.php      # Main plugin file
├── composer.json            # Dependencies
├── phpunit.xml             # Test configuration
├── includes/               # PHP classes
│   ├── Api/               # API clients
│   ├── Pricing/           # Price conversion
│   ├── Sync/              # WooCommerce sync
│   └── Translation/       # Translation services
├── assets/                 # CSS/JS
└── tests/                  # PHPUnit tests
```

## Troubleshooting

### "API key is required" error
Make sure you've entered the ewheel.es API key in the plugin settings.

### Products not translating
Check that your Google Translate API key is correct and has the Translation API enabled.

### Images not importing
Ensure your server allows outbound HTTP requests and has enough memory for image processing.

### Sync takes too long
The first sync imports all products. Subsequent syncs use incremental updates which are faster.

## Support

For issues and feature requests, please create an issue on the GitHub repository.

## License

GPL-2.0-or-later
