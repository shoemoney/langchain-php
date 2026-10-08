<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\Utils\Errors;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterAuthError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterRateLimitError;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/errors.test.ts` (8 cases). `isInstance` brand checks are
 * plain `instanceof` in PHP.
 */
#[CoversClass(Errors::class)]
#[CoversClass(OpenRouterError::class)]
#[CoversClass(OpenRouterAuthError::class)]
#[CoversClass(OpenRouterRateLimitError::class)]
final class ErrorsTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private static function jsonResponse(int $status, array $body, array $headers = ['Content-Type' => 'application/json']): HttpResponse
    {
        return new HttpResponse($status, $headers, (string) json_encode($body));
    }

    public function testReturnsAuthErrorFor401(): void
    {
        $err = OpenRouterError::fromResponse(self::jsonResponse(401, [
            'error' => ['message' => 'Invalid API key', 'code' => 401, 'metadata' => ['a' => 1]],
        ]));

        self::assertInstanceOf(OpenRouterAuthError::class, $err);
        self::assertStringContainsString('Invalid API key', $err->getMessage());
        self::assertStringContainsString('"a":1', $err->getMessage());
        self::assertSame(401, $err->getCode());
        self::assertSame(['a' => 1], $err->metadata);
    }

    public function testReturnsAuthErrorFor403(): void
    {
        $err = OpenRouterError::fromResponse(self::jsonResponse(403, ['error' => ['message' => 'Forbidden']]));

        self::assertInstanceOf(OpenRouterAuthError::class, $err);
        self::assertSame('Forbidden', $err->getMessage());
    }

    public function testReturnsRateLimitErrorFor429(): void
    {
        $err = OpenRouterError::fromResponse(self::jsonResponse(429, ['error' => ['message' => 'Rate limit exceeded', 'code' => 429]]));

        self::assertInstanceOf(OpenRouterRateLimitError::class, $err);
        self::assertSame('Rate limit exceeded', $err->getMessage());
        self::assertSame(429, $err->getCode());
    }

    public function testPreservesResponseHeadersOnRateLimitErrors(): void
    {
        $err = OpenRouterError::fromResponse(self::jsonResponse(
            429,
            ['error' => ['message' => 'Rate limit exceeded', 'code' => 429]],
            ['Content-Type' => 'application/json', 'Retry-After' => '5'],
        ));

        self::assertInstanceOf(OpenRouterRateLimitError::class, $err);
        self::assertSame('application/json', $err->headers['content-type']);
        self::assertSame('5', $err->headers['retry-after']);
    }

    public function testReturnsBaseErrorFor500(): void
    {
        $err = OpenRouterError::fromResponse(self::jsonResponse(500, ['error' => ['message' => 'Internal error', 'code' => 500]]));

        self::assertInstanceOf(OpenRouterError::class, $err);
        self::assertNotInstanceOf(OpenRouterAuthError::class, $err);
        self::assertNotInstanceOf(OpenRouterRateLimitError::class, $err);
        self::assertSame('Internal error', $err->getMessage());
    }

    public function testFallsBackToStatusTextWhenBodyIsNotJson(): void
    {
        $err = OpenRouterError::fromResponse(new HttpResponse(502, [], 'plain text'), 'Bad Gateway');

        self::assertSame('HTTP 502: Bad Gateway', $err->getMessage());
        self::assertSame(502, $err->getCode());
    }

    public function testIsInstanceIsFalseForAPlainError(): void
    {
        $plain = new \Exception('nope');

        self::assertNotInstanceOf(OpenRouterError::class, $plain);
        self::assertNotInstanceOf(OpenRouterAuthError::class, $plain);
        self::assertNotInstanceOf(OpenRouterRateLimitError::class, $plain);
    }

    public function testIsInstanceOfBaseIsTrueForSubclasses(): void
    {
        self::assertInstanceOf(OpenRouterError::class, new OpenRouterAuthError('auth fail'));
        self::assertInstanceOf(OpenRouterError::class, new OpenRouterRateLimitError('rate limit'));
    }

    public function testErrorsCarryTheirJsStyleName(): void
    {
        self::assertSame('OpenRouterError', (new OpenRouterError('x'))->name);
        self::assertSame('OpenRouterAuthError', (new OpenRouterAuthError('x'))->name);
        self::assertSame('OpenRouterRateLimitError', (new OpenRouterRateLimitError('x'))->name);
    }

    public function testBuildsATypedErrorFromAStreamingTransportFailure(): void
    {
        $err = Errors::fromHttpException(new HttpException('boom', 429, '{"error":{"message":"slow down"}}'));

        self::assertInstanceOf(OpenRouterRateLimitError::class, $err);
        self::assertSame('slow down', $err->getMessage());
        self::assertSame(429, $err->statusCode);
    }

    public function testUsesTheStandardReasonPhraseWhenNoStatusTextIsGiven(): void
    {
        $err = Errors::fromResponse(new HttpResponse(503, [], '<html>'));

        self::assertSame('HTTP 503: Service Unavailable', $err->getMessage());
    }
}
