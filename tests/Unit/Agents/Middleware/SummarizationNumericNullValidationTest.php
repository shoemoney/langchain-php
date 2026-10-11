<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangGraph\Agents\Middleware\SummarizationMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Source-derived optional number boundaries in summarization.ts's three Zod schemas. */
#[CoversClass(SummarizationMiddleware::class)]
final class SummarizationNumericNullValidationTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function nullNumbers(): iterable
    {
        foreach (['trimTokensToSummarize', 'maxTokensBeforeSummary', 'messagesToKeep'] as $key) {
            yield $key => [[$key => null], $key];
        }
        foreach (['fraction', 'tokens', 'messages'] as $key) {
            $validKey = $key === 'messages' ? 'tokens' : 'messages';
            yield 'trigger:' . $key => [['trigger' => [$validKey => 1, $key => null]], 'trigger.' . $key];
            yield 'trigger-array:' . $key => [['trigger' => [[$validKey => 1, $key => null]]], 'trigger.0.' . $key];
            yield 'keep:' . $key => [['keep' => [$validKey => 0, $key => null]], 'keep.' . $key];
        }
    }

    #[DataProvider('nullNumbers')]
    public function testPresentNullNumbersAreRejectedEvenAlongsideAValidSize(array $options, string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected number, received null');
        $this->expectExceptionMessage('at ' . $path);

        SummarizationMiddleware::create(['model' => new \stdClass(), ...$options]);
    }

    public function testOmittedOptionalNumbersRemainValid(): void
    {
        self::assertSame('SummarizationMiddleware', SummarizationMiddleware::create(['model' => new \stdClass()])['name']);
        self::assertSame(['messages' => 1], SummarizationMiddleware::parseContextSize(['messages' => 1]));
    }

    public function testZeroKeepAndTrimValuesRemainValid(): void
    {
        self::assertSame(['messages' => 0], SummarizationMiddleware::parseKeepSize(['messages' => 0]));
        self::assertSame('SummarizationMiddleware', SummarizationMiddleware::create(['model' => new \stdClass(), 'trimTokensToSummarize' => 0, 'keep' => ['tokens' => 0]])['name']);
    }
}
