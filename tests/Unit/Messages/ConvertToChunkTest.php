<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\ChatMessageChunk;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\FunctionMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\HumanMessageChunk;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\SystemMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `convertToChunk()` must not change a message's speaker.
 *
 * Upstream returns a chunk class matching the message
 * (langchain-core/src/messages/utils.ts:526-551), including
 * `FunctionMessageChunk` and `ChatMessageChunk`, and throws
 * `new Error("Unknown message type.")` for anything else.
 *
 * This port had neither chunk class, so `convertToChunk()`'s `default` arm turned
 * every function message and every chat-role turn into an `AIMessageChunk` — a
 * function's turn relabelled as the assistant's — and an unknown type was relabelled
 * too rather than refused. Measured before the fix:
 *
 *     FunctionMessage  ->  AIMessageChunk
 *     ChatMessage      ->  AIMessageChunk
 *
 * A ChatMessage is matched on the CLASS, not the role, because its `type` IS its
 * role ('user', 'assistant', ...) and a role-keyed match can never reach it.
 */
#[CoversClass(MessageUtils::class)]
final class ConvertToChunkTest extends TestCase
{
    #[DataProvider('messageTypes')]
    public function testEveryMessageTypeConvertsToItsOwnChunkClass(
        \LangChain\Messages\BaseMessage $message,
        string $expected,
    ): void {
        self::assertSame($expected, MessageUtils::convertToChunk($message)::class);
    }

    public static function messageTypes(): array
    {
        return [
            'human' => [new HumanMessage('h'), HumanMessageChunk::class],
            'system' => [new SystemMessage('s'), SystemMessageChunk::class],
            'ai' => [new AIMessage('a'), AIMessageChunk::class],
            'function' => [new FunctionMessage('f', 'x'), FunctionMessageChunk::class],
            'chat keeps its role' => [
                new ChatMessage(['role' => 'user', 'content' => 'c']),
                ChatMessageChunk::class,
            ],
        ];
    }

    /** A chat chunk carries the same role its message had, not ROLE_CHAT. */
    public function testAChatChunkKeepsTheRoleOfItsMessage(): void
    {
        $chunk = MessageUtils::convertToChunk(
            new ChatMessage(['role' => 'assistant', 'content' => 'c']),
        );

        self::assertSame('assistant', $chunk->type);
    }

    /** Upstream throws; relabelling an unknown turn as the assistant's is the defect. */
    public function testAnUnknownMessageTypeIsRefusedRatherThanRelabelled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageUtils::convertToChunk($this->unknownTypedMessage());
    }

    private function unknownTypedMessage(): \LangChain\Messages\BaseMessage
    {
        $message = new HumanMessage('h');
        $message->type = 'mystery';

        return $message;
    }
}
