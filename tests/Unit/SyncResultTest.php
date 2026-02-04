<?php
/**
 * Tests for the SyncResult class.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Sync\SyncResult;

/**
 * Test case for SyncResult.
 */
class SyncResultTest extends TestCase {

    /**
     * Test empty result has zero counts.
     */
    public function test_empty_result(): void {
        $result = new SyncResult();

        $this->assertEquals( 0, $result->get_created() );
        $this->assertEquals( 0, $result->get_updated() );
        $this->assertEquals( 0, $result->get_skipped() );
        $this->assertEquals( 0, $result->get_errors() );
        $this->assertTrue( $result->is_successful() );
    }

    /**
     * Test increment created.
     */
    public function test_increment_created(): void {
        $result = new SyncResult();
        $result->increment_created();
        $result->increment_created();

        $this->assertEquals( 2, $result->get_created() );
    }

    /**
     * Test increment updated.
     */
    public function test_increment_updated(): void {
        $result = new SyncResult();
        $result->increment_updated();

        $this->assertEquals( 1, $result->get_updated() );
    }

    /**
     * Test increment errors.
     */
    public function test_increment_errors(): void {
        $result = new SyncResult();
        $result->increment_errors();

        $this->assertEquals( 1, $result->get_errors() );
        $this->assertFalse( $result->is_successful() );
    }

    /**
     * Test increment skipped.
     */
    public function test_increment_skipped(): void {
        $result = new SyncResult();
        $result->increment_skipped();
        $result->increment_skipped();

        $this->assertEquals( 2, $result->get_skipped() );
    }

    /**
     * Test is_successful returns false when errors.
     */
    public function test_not_successful_when_errors(): void {
        $result = new SyncResult();
        $result->increment_errors();

        $this->assertFalse( $result->is_successful() );
    }

    /**
     * Test add_error adds message and increments error count.
     */
    public function test_add_error(): void {
        $result = new SyncResult();
        $result->add_error( 'Something went wrong' );
        $result->add_error( 'Another error' );

        $this->assertEquals( 2, $result->get_errors() );
        $messages = $result->get_error_messages();
        $this->assertCount( 2, $messages );
        $this->assertEquals( 'Something went wrong', $messages[0] );
        $this->assertEquals( 'Another error', $messages[1] );
    }

    /**
     * Test get_total calculates correctly.
     */
    public function test_get_total(): void {
        $result = new SyncResult();
        $result->increment_created();
        $result->increment_updated();
        $result->increment_updated();
        $result->increment_skipped();
        $result->increment_errors();

        $this->assertEquals( 5, $result->get_total() );
    }

    /**
     * Test merge combines results.
     */
    public function test_merge(): void {
        $result1 = new SyncResult();
        $result1->increment_created();
        $result1->add_error( 'Error 1' );

        $result2 = new SyncResult();
        $result2->increment_updated();
        $result2->increment_updated();
        $result2->add_error( 'Error 2' );

        $result1->merge( $result2 );

        $this->assertEquals( 1, $result1->get_created() );
        $this->assertEquals( 2, $result1->get_updated() );
        $this->assertEquals( 2, $result1->get_errors() ); // Each add_error increments errors
        $this->assertCount( 2, $result1->get_error_messages() );
    }

    /**
     * Test to_array returns expected structure.
     */
    public function test_to_array(): void {
        $result = new SyncResult();
        $result->increment_created();
        $result->increment_updated();
        $result->increment_skipped();
        $result->add_error( 'Test error' );

        $array = $result->to_array();

        $this->assertArrayHasKey( 'created', $array );
        $this->assertArrayHasKey( 'updated', $array );
        $this->assertArrayHasKey( 'skipped', $array );
        $this->assertArrayHasKey( 'errors', $array );
        $this->assertArrayHasKey( 'error_messages', $array );
        $this->assertArrayHasKey( 'total', $array );
        $this->assertArrayHasKey( 'successful', $array );

        $this->assertEquals( 1, $array['created'] );
        $this->assertEquals( 1, $array['updated'] );
        $this->assertEquals( 1, $array['skipped'] );
        $this->assertEquals( 1, $array['errors'] );
        $this->assertEquals( 4, $array['total'] );
        $this->assertFalse( $array['successful'] );
    }

    /**
     * Test record method with created.
     */
    public function test_record_created(): void {
        $result = new SyncResult();
        $result->record( 'created' );

        $this->assertEquals( 1, $result->get_created() );
    }

    /**
     * Test record method with updated.
     */
    public function test_record_updated(): void {
        $result = new SyncResult();
        $result->record( 'updated' );

        $this->assertEquals( 1, $result->get_updated() );
    }

    /**
     * Test record method with error.
     */
    public function test_record_error(): void {
        $result = new SyncResult();
        $result->record( 'error' );

        $this->assertEquals( 1, $result->get_errors() );
    }

    /**
     * Test record method with unknown outcome goes to skipped.
     */
    public function test_record_unknown_goes_to_skipped(): void {
        $result = new SyncResult();
        $result->record( 'unknown' );

        $this->assertEquals( 1, $result->get_skipped() );
    }

    /**
     * Test get_summary returns formatted string.
     */
    public function test_get_summary(): void {
        $result = new SyncResult();
        $result->increment_created();
        $result->increment_updated();
        $result->increment_updated();
        $result->increment_skipped();
        $result->increment_errors();

        $summary = $result->get_summary();

        $this->assertStringContainsString( 'Created: 1', $summary );
        $this->assertStringContainsString( 'Updated: 2', $summary );
        $this->assertStringContainsString( 'Skipped: 1', $summary );
        $this->assertStringContainsString( 'Errors: 1', $summary );
    }

    /**
     * Test add_preview adds to preview and increments skipped.
     */
    public function test_add_preview(): void {
        $result = new SyncResult();
        $result->add_preview( [ 'sku' => 'TEST-001', 'name' => 'Test Product' ] );

        $preview = $result->get_preview();
        $this->assertCount( 1, $preview );
        $this->assertEquals( 'TEST-001', $preview[0]['sku'] );
        $this->assertEquals( 1, $result->get_skipped() );
    }

    /**
     * Test increment methods return self for chaining.
     */
    public function test_method_chaining(): void {
        $result = new SyncResult();

        $chained = $result
            ->increment_created()
            ->increment_updated()
            ->increment_skipped()
            ->increment_errors();

        $this->assertSame( $result, $chained );
        $this->assertEquals( 4, $result->get_total() );
    }
}
