<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `reasoning_content` handling in the generic Chat Completions converter
 * (`converters/completions.ts`, `convertCompletionsDeltaToBaseMessageChunk`
 * and `convertCompletionsMessageToBaseMessage`).
 */
#[CoversClass(Completions::class)]
final class CompletionsReasoningContentTest extends TestCase
{
    private const RAW_CHUNK = ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => []]]];

    public function testDeltaCarriesReasoningContent(): void
    {
        $chunk = Completions::deltaToChunk(['content' => '', 'reasoning_content' => 'Let me reason...'], self::RAW_CHUNK);

        self::assertSame('Let me reason...', $chunk->additional_kwargs['reasoning_content']);
    }

    public function testMessageCarriesReasoningContent(): void
    {
        $message = Completions::choiceToMessage(
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'hi', 'reasoning_content' => 'r'], 'finish_reason' => 'stop'],
            ['id' => 'chatcmpl-1'],
        );

        self::assertSame('r', $message->additional_kwargs['reasoning_content']);
        self::assertSame('hi', $message->content);
    }

    public function testKeyIsAbsentWhenTheProviderSentNone(): void
    {
        $chunk = Completions::deltaToChunk(['content' => 'plain'], self::RAW_CHUNK);
        $message = Completions::choiceToMessage(
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'plain'], 'finish_reason' => 'stop'],
            ['id' => 'chatcmpl-1'],
        );

        self::assertArrayNotHasKey('reasoning_content', $chunk->additional_kwargs);
        self::assertArrayNotHasKey('reasoning_content', $message->additional_kwargs);
    }

    public function testToolCallDeltaAlsoCarriesReasoning(): void
    {
        $chunk = Completions::deltaToChunk([
            'reasoning_content' => 'I should search.',
            'tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '{"q":1}']]],
        ], self::RAW_CHUNK);

        self::assertSame('I should search.', $chunk->additional_kwargs['reasoning_content']);
        self::assertSame('web_search', $chunk->toolCallChunks[0]['name']);
    }

    public function testFoldedChunksConcatenateReasoning(): void
    {
        $a = Completions::deltaToChunk(['reasoning_content' => 'Let me '], self::RAW_CHUNK);
        $b = Completions::deltaToChunk(['reasoning_content' => 'reason...'], self::RAW_CHUNK);

        self::assertSame('Let me reason...', $a->concat($b)->additional_kwargs['reasoning_content']);
    }
}
