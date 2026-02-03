<?php
/**
 * PHPUnit bootstrap file for ewheel-importer tests.
 */

// Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

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
