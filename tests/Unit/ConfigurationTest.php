<?php
/**
 * Tests for the Configuration class.
 *
 * @package Trotibike\EwheelImporter\Tests\Unit
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Config\Configuration;
use Brain\Monkey\Functions;

/**
 * Test case for Configuration.
 */
class ConfigurationTest extends TestCase {

    /**
     * Test getting API key from options.
     */
    public function test_get_api_key(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_api_key', '' )
            ->andReturn( 'test-api-key' );

        $config = new Configuration();

        $this->assertEquals( 'test-api-key', $config->get_api_key() );
    }

    /**
     * Test getting target language.
     */
    public function test_get_target_language(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_target_language', 'ro' )
            ->andReturn( 'ro' );

        $config = new Configuration();

        $this->assertEquals( 'ro', $config->get_target_language() );
    }

    /**
     * Test getting exchange rate.
     */
    public function test_get_exchange_rate(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_exchange_rate', 4.97 )
            ->andReturn( 5.5 );

        $config = new Configuration();

        $this->assertEquals( 5.5, $config->get_exchange_rate() );
    }

    /**
     * Test getting markup percentage.
     */
    public function test_get_markup_percent(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_markup_percent', 20.0 )
            ->andReturn( 25.0 );

        $config = new Configuration();

        $this->assertEquals( 25.0, $config->get_markup_percent() );
    }

    /**
     * Test getting sync frequency.
     */
    public function test_get_sync_frequency(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_sync_frequency', 'daily' )
            ->andReturn( 'weekly' );

        $config = new Configuration();

        $this->assertEquals( 'weekly', $config->get_sync_frequency() );
    }

    /**
     * Test getting translation driver.
     */
    public function test_get_translation_driver(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_translation_driver', 'google' )
            ->andReturn( 'deepl' );

        $config = new Configuration();

        $this->assertEquals( 'deepl', $config->get_translation_driver() );
    }

    /**
     * Test getting translate API key.
     */
    public function test_get_translate_api_key(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_translate_api_key', '' )
            ->andReturn( 'google-key-123' );

        $config = new Configuration();

        $this->assertEquals( 'google-key-123', $config->get_translate_api_key() );
    }

    /**
     * Test getting DeepL API key.
     */
    public function test_get_deepl_api_key(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_deepl_api_key', '' )
            ->andReturn( 'deepl-key-456' );

        $config = new Configuration();

        $this->assertEquals( 'deepl-key-456', $config->get_deepl_api_key() );
    }

    /**
     * Test getting OpenRouter API key.
     */
    public function test_get_openrouter_api_key(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_openrouter_api_key', '' )
            ->andReturn( 'openrouter-key-789' );

        $config = new Configuration();

        $this->assertEquals( 'openrouter-key-789', $config->get_openrouter_api_key() );
    }

    /**
     * Test getting sync fields with defaults.
     */
    public function test_get_sync_fields_with_defaults(): void {
        $default_fields = [
            'name'              => true,
            'description'       => true,
            'short_description' => true,
            'price'             => true,
            'images'            => true,
            'categories'        => true,
            'attributes'        => true,
        ];

        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_sync_fields', $default_fields )
            ->andReturn( $default_fields );

        $config = new Configuration();
        $fields = $config->get_sync_fields();

        $this->assertArrayHasKey( 'name', $fields );
        $this->assertArrayHasKey( 'price', $fields );
        $this->assertTrue( $fields['name'] );
    }

    /**
     * Test getting sync fields merges with saved.
     */
    public function test_get_sync_fields_merges_saved(): void {
        $saved_fields = [
            'name'  => false, // Override
            'price' => false, // Override
        ];

        Functions\expect( 'get_option' )
            ->once()
            ->andReturn( $saved_fields );

        $config = new Configuration();
        $fields = $config->get_sync_fields();

        $this->assertFalse( $fields['name'] );
        $this->assertFalse( $fields['price'] );
        $this->assertTrue( $fields['description'] ); // Default value
    }

    /**
     * Test getting last sync timestamp.
     */
    public function test_get_last_sync(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_last_sync', null )
            ->andReturn( '2024-01-15T10:30:00' );

        $config = new Configuration();

        $this->assertEquals( '2024-01-15T10:30:00', $config->get_last_sync() );
    }

    /**
     * Test getting last sync returns null when empty.
     */
    public function test_get_last_sync_returns_null_when_empty(): void {
        Functions\expect( 'get_option' )
            ->once()
            ->with( 'ewheel_importer_last_sync', null )
            ->andReturn( '' );

        $config = new Configuration();

        $this->assertNull( $config->get_last_sync() );
    }

    /**
     * Test updating last sync.
     */
    public function test_update_last_sync(): void {
        Functions\expect( 'update_option' )
            ->once()
            ->with( 'ewheel_importer_last_sync', \Mockery::type( 'string' ) )
            ->andReturn( true );

        $config = new Configuration();
        $result = $config->update_last_sync();

        $this->assertTrue( $result );
    }

    /**
     * Test get all settings.
     */
    public function test_get_all(): void {
        Functions\expect( 'get_option' )
            ->andReturnUsing(
                function ( $key, $default ) {
                    return $default;
                }
            );

        $config   = new Configuration();
        $settings = $config->get_all();

        $this->assertArrayHasKey( 'api_key', $settings );
        $this->assertArrayHasKey( 'exchange_rate', $settings );
        $this->assertArrayHasKey( 'sync_frequency', $settings );
    }
}
