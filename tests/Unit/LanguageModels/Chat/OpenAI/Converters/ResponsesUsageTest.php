<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `describe("convertResponsesUsageToUsageMetadata")` from upstream
 * `converters/tests/responses.test.ts`.
 */
#[CoversClass(ResponsesOutput::class)]
final class ResponsesUsageTest extends TestCase
{
    public function testConvertsUsageWithCachedTokens(): void
    {
        $result = ResponsesOutput::convertResponsesUsageToUsageMetadata([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_tokens_details' => ['cached_tokens' => 75, 'text_tokens' => 25],
            'output_tokens_details' => ['reasoning_tokens' => 10, 'text_tokens' => 40],
        ]);

        self::assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_token_details' => ['cache_read' => 75],
            'output_token_details' => ['reasoning' => 10],
        ], $result);
    }

    public function testHandlesMissingUsageDetailsGracefully(): void
    {
        $result = ResponsesOutput::convertResponsesUsageToUsageMetadata([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
        ]);

        self::assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_token_details' => [],
            'output_token_details' => [],
        ], $result);
    }

    public function testConvertsCacheWriteTokens(): void
    {
        $result = ResponsesOutput::convertResponsesUsageToUsageMetadata([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_tokens_details' => ['cached_tokens' => 75, 'cache_write_tokens' => 30, 'text_tokens' => 25],
            'output_tokens_details' => ['reasoning_tokens' => 10, 'text_tokens' => 40],
        ]);

        self::assertSame(['cache_read' => 75, 'cache_creation' => 30], $result['input_token_details']);
        self::assertSame(['reasoning' => 10], $result['output_token_details']);
    }

    public function testHandlesUndefinedUsage(): void
    {
        self::assertSame([
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'input_token_details' => [],
            'output_token_details' => [],
        ], ResponsesOutput::convertResponsesUsageToUsageMetadata(null));
    }

    public function testAZeroCachedCountIsStillReported(): void
    {
        // `!= null` upstream: a reported 0 is data, an absent member is not.
        $result = ResponsesOutput::convertResponsesUsageToUsageMetadata([
            'input_tokens' => 1,
            'input_tokens_details' => ['cached_tokens' => 0],
            'output_tokens_details' => ['reasoning_tokens' => 0],
        ]);

        self::assertSame(['cache_read' => 0], $result['input_token_details']);
        self::assertSame(['reasoning' => 0], $result['output_token_details']);
    }
}
