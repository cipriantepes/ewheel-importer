<?php
/**
 * PHPUnit bootstrap file for ewheel-importer tests.
 */

// Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants needed by plugin code
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Brain Monkey setup
require_once __DIR__ . '/Helpers/WPFunctions.php';

use Brain\Monkey;

// PHPUnit lifecycle hooks
Monkey\setUp();

// Register shutdown function
register_shutdown_function(
    function () {
        Monkey\tearDown();
    }
);
