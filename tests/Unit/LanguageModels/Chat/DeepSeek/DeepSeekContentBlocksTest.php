<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\DeepSeek;

use LangChain\LanguageModels\Chat\DeepSeek\DeepSeekContentBlocks;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `block_translators/tests/deepseek.test.ts` from `@langchain/core`.
 */
#[CoversClass(DeepSeekContentBlocks::class)]
final class DeepSeekContentBlocksTest extends TestCase
{
    public function testTranslatesReasoningContentToAReasoningBlock(): void
    {
        $message = new AIMessage([
            'content' => 'The answer is 42',
            'additional_kwargs' => ['reasoning_content' => 'Let me think about this carefully...'],
            'response_metadata' => ['model_provider' => 'deepseek'],
        ]);

        self::assertSame(
            [
                ['type' => 'reasoning', 'reasoning' => 'Let me think about this carefully...'],
                ['type' => 'text', 'text' => 'The answer is 42'],
            ],
            DeepSeekContentBlocks::of($message),
        );
    }

    public function testHandlesMessagesWithoutReasoningContent(): void
    {
        $message = new AIMessage(['content' => 'Hello world', 'response_metadata' => ['model_provider' => 'deepseek']]);

        self::assertSame([['type' => 'text', 'text' => 'Hello world']], DeepSeekContentBlocks::of($message));
    }

    public function testHandlesEmptyReasoningContent(): void
    {
        $message = new AIMessage(['content' => 'Hello world', 'additional_kwargs' => ['reasoning_content' => '']]);

        self::assertSame([['type' => 'text', 'text' => 'Hello world']], DeepSeekContentBlocks::of($message));
    }

    public function testTranslatesReasoningWithToolCalls(): void
    {
        $message = new AIMessage([
            'content' => 'Let me check the weather for you.',
            'additional_kwargs' => ['reasoning_content' => 'The user wants to know the weather in SF.'],
            'tool_calls' => [['id' => 'call_123', 'name' => 'get_weather', 'args' => ['location' => 'San Francisco']]],
        ]);

        self::assertSame(
            [
                ['type' => 'reasoning', 'reasoning' => 'The user wants to know the weather in SF.'],
                ['type' => 'text', 'text' => 'Let me check the weather for you.'],
                ['type' => 'tool_call', 'id' => 'call_123', 'name' => 'get_weather', 'args' => ['location' => 'San Francisco']],
            ],
            DeepSeekContentBlocks::of($message),
        );
    }

    public function testTranslatesAnAiMessageChunkWithReasoningContent(): void
    {
        $chunk = new AIMessageChunk([
            'content' => 'Calculating...',
            'additional_kwargs' => ['reasoning_content' => 'Step 1: analyze the input'],
        ]);

        self::assertSame(
            [
                ['type' => 'reasoning', 'reasoning' => 'Step 1: analyze the input'],
                ['type' => 'text', 'text' => 'Calculating...'],
            ],
            DeepSeekContentBlocks::of($chunk),
        );
    }

    public function testTranslatesToolCallChunksOfAChunk(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 'call_1', 'name' => 'web_search', 'args' => '{"query":"weather"}']],
        ]);

        self::assertSame(
            [['type' => 'tool_call', 'id' => 'call_1', 'name' => 'web_search', 'args' => ['query' => 'weather']]],
            DeepSeekContentBlocks::of($chunk),
        );
    }

    public function testHandlesArrayContentWithTextBlocks(): void
    {
        $message = new AIMessage([
            'content' => [['type' => 'text', 'text' => 'First part'], ['type' => 'text', 'text' => 'Second part']],
            'additional_kwargs' => ['reasoning_content' => 'Thinking...'],
        ]);

        self::assertSame(
            [
                ['type' => 'reasoning', 'reasoning' => 'Thinking...'],
                ['type' => 'text', 'text' => 'First part'],
                ['type' => 'text', 'text' => 'Second part'],
            ],
            DeepSeekContentBlocks::of($message),
        );
    }

    public function testHandlesEmptyTextContentWithReasoning(): void
    {
        $message = new AIMessage(['content' => '', 'additional_kwargs' => ['reasoning_content' => 'Just thinking, no output yet']]);

        self::assertSame(
            [['type' => 'reasoning', 'reasoning' => 'Just thinking, no output yet']],
            DeepSeekContentBlocks::of($message),
        );
    }
}
