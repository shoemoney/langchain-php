<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageOutputs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Anthropic response content, and the shape rule upstream applies to it.
 */
#[CoversClass(MessageOutputs::class)]
final class AnthropicContentShapeTest extends TestCase
{
    /**
     * Exactly one text block is a plain string.
     *
     * This is the overwhelmingly common case, and it is upstream's rule
     * (`anthropicResponseToChatMessages`): a lone text block becomes that text.
     */
    public function testASingleTextBlockBecomesAString(): void
    {
        $message = MessageOutputs::responseToMessage([
            'id' => 'm1',
            'content' => [['type' => 'text', 'text' => 'just this']],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ]);

        self::assertSame('just this', $message->content);
    }

    /**
     * Several text blocks keep their structure.
     *
     * Joining them with a separator loses the fact that there were two: a
     * caller cannot tell one paragraph from two, and the block types are gone.
     */
    public function testSeveralTextBlocksKeepTheirStructure(): void
    {
        $message = MessageOutputs::responseToMessage([
            'id' => 'm1',
            'content' => [
                ['type' => 'text', 'text' => 'first'],
                ['type' => 'text', 'text' => 'second'],
            ],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ]);

        self::assertIsArray($message->content);
        self::assertCount(2, $message->content);
        self::assertSame('first', $message->content[0]['text']);
        self::assertSame('second', $message->content[1]['text']);
    }

    /**
     * Text interleaved with a reasoning block keeps both, in order.
     *
     * A separator-join would merge the text into the neighbouring block and lose
     * which part was reasoning and which was the answer.
     */
    public function testTextInterleavedWithThinkingKeepsBothInOrder(): void
    {
        $message = MessageOutputs::responseToMessage([
            'id' => 'm1',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 's'],
                ['type' => 'text', 'text' => 'the answer'],
            ],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ]);

        self::assertIsArray($message->content);
        self::assertCount(2, $message->content);
        self::assertSame('thinking', $message->content[0]['type']);
        self::assertSame('text', $message->content[1]['type']);
        self::assertSame('the answer', $message->content[1]['text']);
        self::assertArrayHasKey('thinking', $message->additional_kwargs);
    }

    /**
     * A tool_use turn keeps the block array and exposes the call.
     */
    public function testAToolUseTurnKeepsBlocksAndToolCalls(): void
    {
        $message = MessageOutputs::responseToMessage([
            'id' => 'm1',
            'content' => [
                ['type' => 'text', 'text' => 'checking'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'get_weather', 'input' => ['city' => 'Austin']],
            ],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 1],
        ]);

        self::assertIsArray($message->content);
        self::assertSame('tool_use', $message->content[1]['type']);
        self::assertSame([['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 't1', 'type' => 'tool_call']], $message->toolCalls);
    }

    /**
     * An empty response is an empty block array — upstream's rule, not a guess.
     *
     * `messages.length === 1 && type === "text"` is false for `[]`, so upstream
     * takes the else branch and sets `content` to the block array itself. An
     * earlier version of this test asserted `''`, which would have been a
     * divergence from upstream dressed as a fix.
     */
    public function testAnEmptyResponseIsAnEmptyBlockArray(): void
    {
        $message = MessageOutputs::responseToMessage([
            'id' => 'm1',
            'content' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ]);

        self::assertSame([], $message->content);
    }
}
