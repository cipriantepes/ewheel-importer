<?php
/**
 * Tests for the LiveLogger class.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Log\LiveLogger;

/**
 * Test case for LiveLogger.
 */
class LiveLoggerTest extends TestCase {

    /**
     * Set up test - clear logs before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        LiveLogger::clear();
    }

    /**
     * Test logging a message.
     */
    public function test_log_message(): void {
        LiveLogger::log( 'Test message', 'info' );

        $logs = LiveLogger::get_logs();

        $this->assertCount( 1, $logs );
        $this->assertEquals( 'Test message', $logs[0]['message'] );
        $this->assertEquals( 'info', $logs[0]['type'] );
    }

    /**
     * Test logging multiple messages.
     */
    public function test_log_multiple_messages(): void {
        LiveLogger::log( 'Message 1', 'info' );
        LiveLogger::log( 'Message 2', 'error' );
        LiveLogger::log( 'Message 3', 'success' );

        $logs = LiveLogger::get_logs();

        $this->assertCount( 3, $logs );
        // Most recent first
        $this->assertEquals( 'Message 3', $logs[0]['message'] );
        $this->assertEquals( 'Message 2', $logs[1]['message'] );
        $this->assertEquals( 'Message 1', $logs[2]['message'] );
    }

    /**
     * Test clearing logs.
     */
    public function test_clear_logs(): void {
        LiveLogger::log( 'Test message' );
        LiveLogger::clear();

        $logs = LiveLogger::get_logs();

        $this->assertCount( 0, $logs );
    }

    /**
     * Test empty logs returns empty array.
     */
    public function test_empty_logs_returns_array(): void {
        $logs = LiveLogger::get_logs();

        $this->assertIsArray( $logs );
        $this->assertCount( 0, $logs );
    }

    /**
     * Test log entry has time.
     */
    public function test_log_has_time(): void {
        LiveLogger::log( 'Test message' );

        $logs = LiveLogger::get_logs();

        $this->assertArrayHasKey( 'time', $logs[0] );
        $this->assertNotEmpty( $logs[0]['time'] );
    }

    /**
     * Test default log type is info.
     */
    public function test_default_type_is_info(): void {
        LiveLogger::log( 'Test message' );

        $logs = LiveLogger::get_logs();

        $this->assertEquals( 'info', $logs[0]['type'] );
    }

    /**
     * Test error type logging.
     */
    public function test_error_type(): void {
        LiveLogger::log( 'Error occurred', 'error' );

        $logs = LiveLogger::get_logs();

        $this->assertEquals( 'error', $logs[0]['type'] );
    }

    /**
     * Test success type logging.
     */
    public function test_success_type(): void {
        LiveLogger::log( 'Operation completed', 'success' );

        $logs = LiveLogger::get_logs();

        $this->assertEquals( 'success', $logs[0]['type'] );
    }
}
