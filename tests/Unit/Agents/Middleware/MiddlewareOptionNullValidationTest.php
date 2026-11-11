<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangGraph\Agents\Middleware\Constants;
use LangGraph\Agents\Middleware\InvalidRetryConfigError;
use LangGraph\Agents\Middleware\ModelCallLimitMiddleware;
use LangGraph\Agents\Middleware\ModelRetryMiddleware;
use LangGraph\Agents\Middleware\ToolCallLimitMiddleware;
use LangGraph\Agents\Middleware\ToolRetryMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Source-derived boundaries from constants.ts, modelRetry.ts and toolRetry.ts. */
#[CoversClass(Constants::class)]
#[CoversClass(ModelRetryMiddleware::class)]
#[CoversClass(ToolRetryMiddleware::class)]
final class MiddlewareOptionNullValidationTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string, string}> */
    public static function explicitNulls(): iterable
    {
        foreach ([ModelRetryMiddleware::class, ToolRetryMiddleware::class] as $factory) {
            foreach (['maxRetries', 'backoffFactor', 'initialDelayMs', 'maxDelayMs'] as $field) {
                yield $factory . ':' . $field => [$factory, $field, 'invalid_type', 'Expected number, received null'];
            }
            yield $factory . ':jitter' => [$factory, 'jitter', 'invalid_type', 'Expected boolean, received null'];
            yield $factory . ':retryOn' => [$factory, 'retryOn', 'invalid_union', 'Invalid input'];
            yield $factory . ':onFailure' => [$factory, 'onFailure', 'invalid_union', 'Invalid input'];
        }
        yield 'tool:tools' => [ToolRetryMiddleware::class, 'tools', 'invalid_type', 'Expected array, received null'];
    }

    #[DataProvider('explicitNulls')]
    public function testExplicitNullDoesNotActivateAnOmittedFieldDefault(string $factory, string $field, string $code, string $message): void
    {
        try {
            $factory::create([$field => null]);
            self::fail('Explicit null must be rejected');
        } catch (InvalidRetryConfigError $error) {
            self::assertSame([['path' => [$field], 'code' => $code, 'message' => $message]], $error->issues());
        }
    }

    public function testOmittedRetryOptionsStillHaveTheirSourceDefaults(): void
    {
        $parsed = Constants::parseRetrySchema([]);

        self::assertTrue($parsed['success']);
        self::assertSame(2, $parsed['data']['maxRetries']);
        self::assertSame(2.0, $parsed['data']['backoffFactor']);
        self::assertSame(1000, $parsed['data']['initialDelayMs']);
        self::assertSame(60000, $parsed['data']['maxDelayMs']);
        self::assertTrue($parsed['data']['jitter']);
        self::assertTrue(($parsed['data']['retryOn'])(new \Exception()));
    }

    public function testZeroFalseAndEmptyRetryListRemainExplicitValues(): void
    {
        $config = ['maxRetries' => 0, 'backoffFactor' => 0, 'initialDelayMs' => 0, 'maxDelayMs' => 0, 'jitter' => false, 'retryOn' => []];
        $parsed = Constants::parseRetrySchema($config);

        self::assertTrue($parsed['success']);
        self::assertSame($config, $parsed['data']);
        self::assertSame('modelRetryMiddleware', ModelRetryMiddleware::create($config)['name']);
        self::assertSame('toolRetryMiddleware', ToolRetryMiddleware::create([...$config, 'tools' => []])['name']);
    }

    public function testModelCallLimitFactoryNullsRemainUnsetAsSourceNullishCoalescingRequires(): void
    {
        $middleware = ModelCallLimitMiddleware::create(['threadLimit' => null, 'runLimit' => null]);
        $hook = $middleware['beforeModel']['hook'];

        self::assertNull($hook(['threadModelCallCount' => 100, 'runModelCallCount' => 100], ['context' => []]));
    }

    public function testNullRuntimeModelLimitFallsBackToTheFactoryLimit(): void
    {
        $middleware = ModelCallLimitMiddleware::create(['runLimit' => 2]);
        $result = ($middleware['beforeModel']['hook'])(['runModelCallCount' => 2], ['context' => ['runLimit' => null]]);

        self::assertSame('end', $result['jumpTo']);
    }

    public function testToolCallLimitIntentionallyDivergesFromJsPresentNullComparison(): void
    {
        // Intentional PHP divergence: null remains unset when another limit exists.
        // Upstream rejects this combination because JavaScript compares 2 > null as 2 > 0.
        $middleware = ToolCallLimitMiddleware::create(['threadLimit' => null, 'runLimit' => 2]);

        self::assertSame('ToolCallLimitMiddleware', $middleware['name']);
    }
}
