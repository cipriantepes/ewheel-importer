<?php
/**
 * Translation Caching Integration Test.
 *
 * @package Trotibike\EwheelImporter
 */

use PHPUnit\Framework\TestCase;
use Trotibike\EwheelImporter\Translation\Translator;
use Trotibike\EwheelImporter\Translation\TranslationServiceInterface;
use Trotibike\EwheelImporter\Repository\TranslationRepository;

class TranslationCachingTest extends TestCase
{
    public function test_translate_uses_cache_and_skips_api()
    {
        $text = 'Hello World';
        $source_lang = 'en';
        $target_lang = 'ro';
        $translated = 'Salut Lume';

        // Mock Service
        $service = $this->createMock(TranslationServiceInterface::class);

        // Expect translate to act only ONCE
        $service->expects($this->once())
            ->method('translate')
            ->with($text, $source_lang, $target_lang)
            ->willReturn($translated);

        // Mock Repository
        $repository = $this->createMock(TranslationRepository::class);

        // First call: Cache miss, then save
        $repository->expects($this->exactly(2))
            ->method('get')
            ->with($text, $source_lang, $target_lang)
            ->willReturnOnConsecutiveCalls(null, $translated); // First miss, second hit

        $repository->expects($this->once())
            ->method('save')
            ->with($text, $translated, $source_lang, $target_lang, $this->anything());

        // Instantiate Translator
        $translator = new Translator($service, $repository, $target_lang);

        // 1. First Call: Should hit API and Save
        $result1 = $translator->translate($text, $source_lang);
        $this->assertEquals($translated, $result1);

        // 2. Second Call: Should hit Cache and SKIP API
        $result2 = $translator->translate($text, $source_lang);
        $this->assertEquals($translated, $result2);
    }
}
