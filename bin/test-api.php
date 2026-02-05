#!/usr/bin/env php
<?php
/**
 * Test script for ewheel.es API
 *
 * Usage: php bin/test-api.php [--products|--categories] [--page=N] [--size=N]
 *
 * @package Trotibike\EwheelImporter
 */

// Parse CLI arguments
$options = getopt('', ['products', 'categories', 'page:', 'size:', 'help', 'raw']);

if (isset($options['help'])) {
    echo <<<HELP
Ewheel.es API Test Script

Usage:
  php bin/test-api.php [options]

Options:
  --products     Fetch products (default)
  --categories   Fetch categories
  --page=N       Page number (default: 0)
  --size=N       Page size (default: 3)
  --raw          Output raw JSON only
  --help         Show this help

Examples:
  php bin/test-api.php --products --page=0 --size=5
  php bin/test-api.php --categories
  php bin/test-api.php --raw > response.json

HELP;
    exit(0);
}

// Configuration
$endpoint_type = isset($options['categories']) ? 'categories' : 'products';
$page = isset($options['page']) ? (int) $options['page'] : 0;
$page_size = isset($options['size']) ? (int) $options['size'] : 3;
$raw_output = isset($options['raw']);

// Check for API key from environment first (preferred for CLI testing)
$env_api_key = getenv('EWHEEL_API_KEY');

// Only load WordPress if no env API key provided
$wp_loaded = false;
if (empty($env_api_key)) {
    $wp_load_paths = [
        dirname(__DIR__, 2) . '/wp-load.php',
        dirname(__DIR__, 3) . '/wp-load.php',
        dirname(__DIR__, 4) . '/wp-load.php',
    ];

    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            // Suppress WordPress CLI warnings
            @require_once $path;
            $wp_loaded = true;
            break;
        }
    }
}

if (!$wp_loaded) {
    // Fallback: use cURL directly
    if (!$raw_output) {
        echo "WordPress not found. Using standalone cURL mode.\n";
        echo "Note: API key must be set via EWHEEL_API_KEY environment variable.\n\n";
    }

    $api_key = getenv('EWHEEL_API_KEY');
    if (empty($api_key)) {
        echo "Error: EWHEEL_API_KEY environment variable not set.\n";
        echo "Usage: EWHEEL_API_KEY=your-key php bin/test-api.php\n";
        exit(1);
    }

    $base_url = 'https://api.ewheel.es';

    if ($endpoint_type === 'categories') {
        $url = $base_url . '/api/v1/catalog/categories?' . http_build_query([
            'Page' => $page,
            'PageSize' => $page_size,
        ]);
        $method = 'GET';
        $body = null;
    } else {
        $url = $base_url . '/api/v1/catalog/products/filter';
        $method = 'POST';
        $body = json_encode([
            'Page' => $page,
            'PageSize' => $page_size,
        ]);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'X-API-KEY: ' . $api_key,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        echo "cURL Error: $error\n";
        exit(1);
    }

} else {
    // WordPress mode - use wp_remote_* functions
    if (!$raw_output) {
        echo "WordPress loaded successfully.\n";
    }

    $api_key = get_option('ewheel_importer_api_key', '');

    if (empty($api_key)) {
        echo "Error: No API key found in WordPress settings.\n";
        echo "Please configure the API key in the Ewheel Importer admin panel.\n";
        exit(1);
    }

    if (!$raw_output) {
        echo "API Key: " . substr($api_key, 0, 8) . "..." . substr($api_key, -4) . "\n\n";
    }

    $base_url = 'https://api.ewheel.es';
    $headers = [
        'X-API-KEY' => $api_key,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    if ($endpoint_type === 'categories') {
        $url = add_query_arg([
            'Page' => $page,
            'PageSize' => $page_size,
        ], $base_url . '/api/v1/catalog/categories');

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 30,
        ]);
    } else {
        $url = $base_url . '/api/v1/catalog/products/filter';

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode([
                'Page' => $page,
                'PageSize' => $page_size,
            ]),
            'timeout' => 30,
        ]);
    }

    if (is_wp_error($response)) {
        echo "WordPress HTTP Error: " . $response->get_error_message() . "\n";
        exit(1);
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
}

// Parse and display response
$data = json_decode($response_body, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON Parse Error: " . json_last_error_msg() . "\n";
    echo "Raw response:\n" . substr($response_body, 0, 500) . "\n";
    exit(1);
}

if ($raw_output) {
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

// Pretty output
echo "=== API Response ===\n";
echo "Endpoint: " . ($endpoint_type === 'categories' ? 'Categories' : 'Products') . "\n";
echo "HTTP Status: $http_code\n";
echo "Page: $page, Size: $page_size\n";
echo "\n";

// Check wrapper structure
$ok = $data['Ok'] ?? ($data['ok'] ?? null);
$items = $data['Data'] ?? ($data['data'] ?? []);
$message = $data['Message'] ?? ($data['message'] ?? '');

echo "Response Status: " . ($ok ? 'OK' : 'ERROR') . "\n";
if ($message) {
    echo "Message: $message\n";
}
echo "Items returned: " . count($items) . "\n";
echo "\n";

if (empty($items)) {
    echo "No items in response.\n";
    echo "\nFull response:\n";
    print_r($data);
    exit(0);
}

// Analyze first item
echo "=== First Item Structure ===\n";
$first = $items[0];
analyze_structure($first, '');

echo "\n=== First Item Data ===\n";
echo json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n";

if (count($items) > 1) {
    echo "\n=== All Items (Summary) ===\n";
    foreach ($items as $i => $item) {
        $ref = $item['Reference'] ?? ($item['reference'] ?? 'N/A');
        $name = $item['Name'] ?? ($item['name'] ?? []);
        if (is_array($name)) {
            $name = $name['es'] ?? ($name['en'] ?? array_values($name)[0] ?? 'N/A');
        }
        echo ($i + 1) . ". [$ref] $name\n";
    }
}

/**
 * Recursively analyze and print data structure.
 */
function analyze_structure($data, $prefix = '', $depth = 0) {
    if ($depth > 3) {
        echo $prefix . "...(truncated)\n";
        return;
    }

    if (is_array($data)) {
        // Check if it's a list (sequential keys) or map (named keys)
        $is_list = array_keys($data) === range(0, count($data) - 1);

        if ($is_list && count($data) > 0) {
            echo $prefix . "Array[" . count($data) . "] of:\n";
            analyze_structure($data[0], $prefix . "  ", $depth + 1);
        } else {
            foreach ($data as $key => $value) {
                $type = gettype($value);
                if (is_array($value)) {
                    $is_sublist = array_keys($value) === range(0, count($value) - 1);
                    if ($is_sublist) {
                        echo $prefix . "$key: Array[" . count($value) . "]\n";
                        if (count($value) > 0) {
                            analyze_structure($value[0], $prefix . "  ", $depth + 1);
                        }
                    } else {
                        echo $prefix . "$key: Object\n";
                        analyze_structure($value, $prefix . "  ", $depth + 1);
                    }
                } else {
                    $preview = is_string($value) ? '"' . substr($value, 0, 50) . (strlen($value) > 50 ? '...' : '') . '"' : var_export($value, true);
                    echo $prefix . "$key: ($type) $preview\n";
                }
            }
        }
    } else {
        $type = gettype($data);
        echo $prefix . "($type) " . var_export($data, true) . "\n";
    }
}
