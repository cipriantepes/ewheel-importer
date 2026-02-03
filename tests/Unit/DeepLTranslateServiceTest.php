<?php
/**
 * DeepL Translate Service Unit Test.
 *
 * @package Trotibike\EwheelImporter
 */

use PHPUnit\Framework\TestCase;
use Trotibike\EwheelImporter\Translation\DeepLTranslateService;
use Trotibike\EwheelImporter\Api\HttpClientInterface;

class DeepLTranslateServiceTest extends TestCase
{
    public function test_translate_batch_calls_api_correctly()
    {
        $apiKey = 'test-api-key:fx'; // Free tier
        $texts = ['Hello', 'World'];
        $sourceLang = 'en';
        $targetLang = 'de';

        $expectedBody = [
            'text' => $texts,
            'source_lang' => 'EN',
            'target_lang' => 'DE',
        ];

        $expectedHeaders = [
            'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
            'Content-Type' => 'application/json',
        ];

        $mockResponse = [
            'translations' => [
                ['text' => 'Hallo'],
                ['text' => 'Welt'],
            ]
        ];

        // Mock HTTP Client
        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api-free.deepl.com/v2/translate',
                $expectedBody,
                $expectedHeaders
            )
            ->willReturn($mockResponse);

        $service = new DeepLTranslateService($apiKey, $httpClient);
        $results = $service->translate_batch($texts, $sourceLang, $targetLang);

        $this->assertEquals(['Hallo', 'Welt'], $results);
    }

    public function test_translate_batch_throws_exception_on_api_error()
    {
        $apiKey = 'test-api-key';
        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient->method('post')
            ->willThrowException(new \Exception('API Error'));

        $this->expectException(\RuntimeException::class);

        $service = new DeepLTranslateService($apiKey, $httpClient);
        $service->translate_batch(['test'], 'en', 'de');
    }
}
