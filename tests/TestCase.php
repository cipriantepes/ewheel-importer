<?php
/**
 * Base test case class for ewheel-importer tests.
 */

namespace Trotibike\EwheelImporter\Tests;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Abstract base test case.
 */
abstract class TestCase extends PHPUnitTestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Set up test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Set up minimal $wpdb mock so PersistentLogger doesn't error
        if (!isset($GLOBALS['wpdb']) || !is_object($GLOBALS['wpdb'])) {
            $wpdb = \Mockery::mock('wpdb');
            $wpdb->prefix = 'wp_';
            $wpdb->shouldReceive('prepare')->andReturn('');
            $wpdb->shouldReceive('get_var')->andReturn(null);
            $wpdb->shouldReceive('insert')->andReturn(true);
            $GLOBALS['wpdb'] = $wpdb;
        }
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
