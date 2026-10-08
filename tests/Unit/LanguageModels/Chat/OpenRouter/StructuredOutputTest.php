<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\Utils\StructuredOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/structured_output.test.ts` (10 cases).
 */
#[CoversClass(StructuredOutput::class)]
final class StructuredOutputTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private static function resolve(array $overrides = []): string
    {
        return StructuredOutput::resolveOpenRouterStructuredOutputMethod($overrides + ['model' => 'test/model', 'method' => null, 'profile' => []]);
    }

    // ─── explicit method validation ──────────────────────────────────

    public function testThrowsOnAnUnsupportedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid structured output method.*banana/');

        self::resolve(['method' => 'banana']);
    }

    public function testThrowsWhenJsonSchemaIsRequestedButProfileLacksStructuredOutput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not supported for model/');

        self::resolve(['method' => 'jsonSchema', 'profile' => ['structuredOutput' => false]]);
    }

    public function testReturnsJsonSchemaWhenProfileSupportsIt(): void
    {
        self::assertSame('jsonSchema', self::resolve(['method' => 'jsonSchema', 'profile' => ['structuredOutput' => true]]));
    }

    public function testReturnsFunctionCallingWhenExplicitlyRequested(): void
    {
        self::assertSame('functionCalling', self::resolve(['method' => 'functionCalling']));
    }

    public function testReturnsJsonModeWhenExplicitlyRequested(): void
    {
        self::assertSame('jsonMode', self::resolve(['method' => 'jsonMode']));
    }

    // ─── auto-detect with routing ────────────────────────────────────

    public function testFallsBackToFunctionCallingWhenModelsListIsProvided(): void
    {
        self::assertSame('functionCalling', self::resolve(['models' => ['model-a', 'model-b'], 'profile' => ['structuredOutput' => true]]));
    }

    public function testFallsBackToFunctionCallingWhenRouteIsFallback(): void
    {
        self::assertSame('functionCalling', self::resolve(['route' => 'fallback', 'profile' => ['structuredOutput' => true]]));
    }

    // ─── auto-detect based on profile ────────────────────────────────

    public function testReturnsJsonSchemaWhenProfileHasStructuredOutputTrue(): void
    {
        self::assertSame('jsonSchema', self::resolve(['profile' => ['structuredOutput' => true]]));
    }

    public function testReturnsFunctionCallingWhenProfileHasStructuredOutputFalse(): void
    {
        self::assertSame('functionCalling', self::resolve(['profile' => ['structuredOutput' => false]]));
    }

    public function testReturnsFunctionCallingWhenProfileIsEmpty(): void
    {
        self::assertSame('functionCalling', self::resolve(['profile' => []]));
    }
}
