<?php
/**
 * Tests for the Translator module.
 */

namespace Trotibike\EwheelImporter\Tests\Unit;

use Trotibike\EwheelImporter\Tests\TestCase;
use Trotibike\EwheelImporter\Tests\Helpers\MockFactory;
use Trotibike\EwheelImporter\Translation\Translator;
use Mockery;

/**
 * Test case for Translator.
 */
class TranslatorTest extends TestCase {

    /**
     * Test translating text from English to Romanian.
     */
    public function test_translate_from_english_to_romanian(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->with( 'Electric Scooter', 'en', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate( 'Electric Scooter', 'en' );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test translating text from Spanish to Romanian.
     */
    public function test_translate_from_spanish_to_romanian(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->with( 'Patinete Eléctrico', 'es', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate( 'Patinete Eléctrico', 'es' );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test that empty text returns empty string without calling API.
     */
    public function test_empty_text_returns_empty_string(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldNotReceive( 'translate' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate( '', 'en' );

        $this->assertEquals( '', $result );
    }

    /**
     * Test that whitespace-only text returns empty string.
     */
    public function test_whitespace_text_returns_empty_string(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldNotReceive( 'translate' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate( '   ', 'en' );

        $this->assertEquals( '', $result );
    }

    /**
     * Test translating multilingual text object - prefers English.
     */
    public function test_translate_multilingual_object_prefers_english(): void {
        $multilingual_text = [
            'es' => 'Patinete Eléctrico',
            'en' => 'Electric Scooter',
        ];

        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->with( 'Electric Scooter', 'en', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate_multilingual( $multilingual_text );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test translating multilingual text object - falls back to Spanish.
     */
    public function test_translate_multilingual_object_fallback_to_spanish(): void {
        $multilingual_text = [
            'es' => 'Patinete Eléctrico',
        ];

        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->with( 'Patinete Eléctrico', 'es', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate_multilingual( $multilingual_text );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test translating multilingual text object - falls back to first available.
     */
    public function test_translate_multilingual_object_fallback_to_first(): void {
        $multilingual_text = [
            'de' => 'Elektroroller',
        ];

        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->with( 'Elektroroller', 'de', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate_multilingual( $multilingual_text );

        $this->assertEquals( 'Trotinetă Electrică', $result );
    }

    /**
     * Test empty multilingual object returns empty string.
     */
    public function test_empty_multilingual_object_returns_empty(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldNotReceive( 'translate' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate_multilingual( [] );

        $this->assertEquals( '', $result );
    }

    /**
     * Test caching - same text should be retrieved from repository.
     */
    public function test_caches_translations(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once() // Only called once despite two translate calls
            ->with( 'Electric Scooter', 'en', 'ro' )
            ->andReturn( 'Trotinetă Electrică' );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        // Repository returns null first (not cached), then we save it
        $repository = Mockery::mock( \Trotibike\EwheelImporter\Repository\TranslationRepository::class );
        $repository->shouldReceive( 'get' )
            ->with( 'Electric Scooter', 'en', 'ro' )
            ->once()
            ->andReturn( null );
        $repository->shouldReceive( 'get' )
            ->with( 'Electric Scooter', 'en', 'ro' )
            ->once()
            ->andReturn( 'Trotinetă Electrică' );
        $repository->shouldReceive( 'save' )->andReturn( true );

        $translator = new Translator( $translation_service, $repository, 'ro' );

        $result1 = $translator->translate( 'Electric Scooter', 'en' );
        $result2 = $translator->translate( 'Electric Scooter', 'en' );

        $this->assertEquals( 'Trotinetă Electrică', $result1 );
        $this->assertEquals( 'Trotinetă Electrică', $result2 );
    }

    /**
     * Test handling translation service errors.
     */
    public function test_handles_translation_error_gracefully(): void {
        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate' )
            ->once()
            ->andThrow( new \RuntimeException( 'Translation API error' ) );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $result     = $translator->translate( 'Electric Scooter', 'en' );

        // Should return original text on error
        $this->assertEquals( 'Electric Scooter', $result );
    }

    /**
     * Test batch translation.
     */
    public function test_batch_translate(): void {
        $texts = [
            'Electric Scooter',
            'High performance',
            'Battery life',
        ];

        $translation_service = MockFactory::translation_service();
        $translation_service->shouldReceive( 'translate_batch' )
            ->once()
            ->with( $texts, 'en', 'ro' )
            ->andReturn(
                [
                    'Trotinetă Electrică',
                    'Performanță ridicată',
                    'Durata bateriei',
                ]
            );
        $translation_service->shouldReceive( 'get_service_name' )->andReturn( 'mock' );

        $repository = MockFactory::translation_repository();

        $translator = new Translator( $translation_service, $repository, 'ro' );
        $results    = $translator->translate_batch( $texts, 'en' );

        $this->assertCount( 3, $results );
        $this->assertEquals( 'Trotinetă Electrică', $results[0] );
        $this->assertEquals( 'Performanță ridicată', $results[1] );
        $this->assertEquals( 'Durata bateriei', $results[2] );
    }

    /**
     * Test that target language must be set.
     */
    public function test_requires_target_language(): void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'Target language is required' );

        $translation_service = MockFactory::translation_service();
        $repository          = MockFactory::translation_repository();
        new Translator( $translation_service, $repository, '' );
    }
}
