<?php
/**
 * Tests for ImageService.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Service\ImageService;
use Trotibike\EwheelImporter\Tests\TestCase;
use Brain\Monkey\Functions;

/**
 * ImageService test cases.
 */
class ImageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/');
        }
    }

    /**
     * Helper to set up wpdb mock for find_by_source_url.
     *
     * @param int|null $return_id Attachment ID to return (null = not found).
     */
    private function mock_wpdb_lookup(?int $return_id): void
    {
        $wpdb = \Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturn('query');
        $wpdb->shouldReceive('get_var')->andReturn($return_id);
        $wpdb->shouldReceive('insert')->andReturn(true);
        $GLOBALS['wpdb'] = $wpdb;
    }

    /**
     * Helper to set up mocks for the download_and_attach flow.
     *
     * @param int $attachment_id The attachment ID to return from media_handle_sideload.
     */
    private function mock_download_flow(int $attachment_id): void
    {
        Functions\expect('download_url')->once()->andReturn('/tmp/test-image.jpg');
        Functions\expect('is_wp_error')->andReturn(false);
        Functions\expect('wp_parse_url')->andReturn('/image.jpg');
        Functions\expect('sanitize_file_name')->andReturn('image.jpg');
        Functions\expect('media_handle_sideload')->once()->andReturn($attachment_id);
        Functions\expect('wp_generate_attachment_metadata')->andReturn([]);
    }

    /**
     * Test that import sets alt text and title on new images.
     */
    public function test_import_sets_alt_text_and_title(): void
    {
        $service = new ImageService();
        $this->mock_wpdb_lookup(null);
        $this->mock_download_flow(42);

        // Expect source URL meta
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_ewheel_source_url', 'https://example.com/image.jpg');

        // Expect alt text meta
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_wp_attachment_image_alt', \Mockery::type('string'));

        // Expect title update
        Functions\expect('wp_update_post')
            ->once()
            ->with(\Mockery::on(function ($args) {
                return $args['ID'] === 42 && !empty($args['post_title']);
            }));

        $result = $service->import_from_url('https://example.com/image.jpg', [
            'alt_text' => 'Trotinetă electrică roșie',
            'title'    => 'Trotinetă electrică roșie',
        ]);

        $this->assertSame(42, $result);
    }

    /**
     * Test that import without meta parameter still works (backward compat).
     */
    public function test_import_without_meta_still_works(): void
    {
        $service = new ImageService();
        $this->mock_wpdb_lookup(null);
        $this->mock_download_flow(42);

        // Only source URL meta should be set (no alt text, no title)
        Functions\expect('update_post_meta')
            ->once()
            ->with(42, '_ewheel_source_url', 'https://example.com/image.jpg');

        // wp_update_post should NOT be called (no title to set)
        Functions\expect('wp_update_post')->never();

        $result = $service->import_from_url('https://example.com/image.jpg');

        $this->assertSame(42, $result);
    }

    /**
     * Test that existing image gets alt text backfilled when missing.
     */
    public function test_existing_image_backfills_alt_text(): void
    {
        $service = new ImageService();
        $this->mock_wpdb_lookup(99);

        // No existing alt text
        Functions\expect('get_post_meta')
            ->once()
            ->with(99, '_wp_attachment_image_alt', true)
            ->andReturn('');

        // Should backfill alt text
        Functions\expect('update_post_meta')
            ->once()
            ->with(99, '_wp_attachment_image_alt', \Mockery::type('string'));

        $result = $service->import_from_url('https://example.com/image.jpg', [
            'alt_text' => 'Product Name',
        ]);

        $this->assertSame(99, $result);
    }

    /**
     * Test that existing image does not overwrite manually set alt text.
     */
    public function test_existing_image_does_not_overwrite_alt_text(): void
    {
        $service = new ImageService();
        $this->mock_wpdb_lookup(99);

        // Existing alt text already set
        Functions\expect('get_post_meta')
            ->once()
            ->with(99, '_wp_attachment_image_alt', true)
            ->andReturn('Manually set alt text');

        // Should NOT update
        Functions\expect('update_post_meta')->never();

        $result = $service->import_from_url('https://example.com/image.jpg', [
            'alt_text' => 'New Alt Text',
        ]);

        $this->assertSame(99, $result);
    }

    /**
     * Test that empty URL returns null.
     */
    public function test_empty_url_returns_null(): void
    {
        $service = new ImageService();

        $this->assertNull($service->import_from_url(''));
        $this->assertNull($service->import_from_url('', ['alt_text' => 'Test']));
    }
}
