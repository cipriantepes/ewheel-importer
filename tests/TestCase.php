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
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}
