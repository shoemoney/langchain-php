<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\DallEAPIWrapper;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/dalle.ts`; upstream's tests are live integration tests, so the images endpoint is scripted here. */
#[CoversClass(DallEAPIWrapper::class)]
final class DallEAPIWrapperTest extends TestCase
{
    private static function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }

    public function testGeneratesAnImageUrl(): void
    {
        $http = new FakeHttpClient([self::json(['data' => [['url' => 'https://img.example/cat.png']]])]);
        $dalle = new DallEAPIWrapper(['apiKey' => 'sk-test', 'httpClient' => $http, 'user' => 'u1']);

        self::assertSame('https://img.example/cat.png', $dalle->invoke('A painting of a cat'));

        self::assertSame('https://api.openai.com/v1/images/generations', $http->requests[0]['url']);
        self::assertSame('Bearer sk-test', $http->requests[0]['headers']['Authorization']);
        self::assertSame(
            [
                'model' => 'dall-e-3', 'prompt' => 'A painting of a cat', 'n' => 1, 'size' => '1024x1024',
                'response_format' => 'url', 'style' => 'vivid', 'quality' => 'standard', 'user' => 'u1',
            ],
            $http->lastRequestBody(),
        );
    }

    public function testBase64ResponseFormat(): void
    {
        $http = new FakeHttpClient([self::json(['data' => [['b64_json' => 'QUJD']]])]);
        $dalle = new DallEAPIWrapper(['apiKey' => 'k', 'httpClient' => $http, 'responseFormat' => 'b64_json']);

        self::assertSame('QUJD', $dalle->invoke('x'));
        self::assertSame('b64_json', $http->lastRequestBody()['response_format']);
    }

    public function testMultipleImagesAreImageUrlBlocks(): void
    {
        $http = new FakeHttpClient([
            self::json(['data' => [['url' => 'https://img.example/1.png']]]),
            self::json(['data' => [['url' => 'https://img.example/2.png']]]),
        ]);
        $dalle = new DallEAPIWrapper(['apiKey' => 'k', 'httpClient' => $http, 'n' => 2, 'baseUrl' => 'https://proxy.test/v1/']);

        $result = $dalle->invoke('x');

        self::assertCount(2, $http->requests);
        self::assertSame('https://proxy.test/v1/images/generations', $http->requests[0]['url']);
        self::assertSame(1, $http->lastRequestBody()['n']);
        self::assertEquals(
            [['type' => 'image_url', 'image_url' => 'https://img.example/1.png'], ['type' => 'image_url', 'image_url' => 'https://img.example/2.png']],
            $result,
        );
    }

    public function testMultipleBase64ImagesNestTheUrl(): void
    {
        $http = new FakeHttpClient([self::json(['data' => [['b64_json' => 'AAA']]]), self::json(['data' => [['b64_json' => 'BBB']]])]);
        $dalle = new DallEAPIWrapper(['apiKey' => 'k', 'httpClient' => $http, 'n' => 2, 'dallEResponseFormat' => 'b64_json']);

        self::assertEquals(
            [['type' => 'image_url', 'image_url' => ['url' => 'AAA']], ['type' => 'image_url', 'image_url' => ['url' => 'BBB']]],
            $dalle->invoke('x'),
        );
    }

    public function testApiErrorsSurfaceAsOpenAIException(): void
    {
        $http = new FakeHttpClient([self::json(['error' => ['message' => 'bad prompt', 'code' => 'content_policy']], 400)]);

        $this->expectException(OpenAIException::class);
        $this->expectExceptionMessage('bad prompt');

        (new DallEAPIWrapper(['apiKey' => 'k', 'httpClient' => $http]))->invoke('x');
    }
}
