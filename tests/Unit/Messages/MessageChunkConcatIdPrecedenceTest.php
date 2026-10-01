<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\ChatMessageChunk;
use LangChain\Messages\FunctionMessageChunk;
use LangChain\Messages\HumanMessageChunk;
use LangChain\Messages\SystemMessageChunk;
use LangChain\Messages\ToolMessageChunk;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Concatenating message chunks must keep the EXISTING id, not the incoming one.
 *
 * Upstream is unambiguous and consistent: every `concat` reads `id: this.id ?? chunk.id` — the left
 * (already-accumulated) chunk wins. Measured across all seven upstream sites:
 *
 *   messages/ai.ts:445, chat.ts:122, function.ts:74, human.ts:77,
 *   system.ts:62, system.ts:112, tool.ts:206
 *
 * This port had the preference INVERTED in all six of its chunk types — `$other->id ?? $this->id` — so
 * concatenating a stream re-assigned the message id from whichever chunk arrived last. That is on a live
 * path: chunk concatenation is how a streamed assistant message is assembled, so the id of a message
 * changed as it streamed.
 *
 * The fixture passes an explicit `content` key on purpose. `BaseMessage::__construct` decides between a
 * field map and bare content with `looksLikeFieldMap()`, so `new AIMessageChunk(['id' => 'x'])` is read
 * as CONTENT and the id is silently dropped — a fixture that produced an empty id and nearly hid this
 * whole finding. The degenerate case matters here for the same reason it does everywhere else in this
 * port: the coercion lives in the constructor.
 */
#[CoversNothing]
final class MessageChunkConcatIdPrecedenceTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function chunkClasses(): iterable
    {
        yield 'AIMessageChunk' => [AIMessageChunk::class];
        yield 'ChatMessageChunk' => [ChatMessageChunk::class];
        yield 'HumanMessageChunk' => [HumanMessageChunk::class];
        yield 'SystemMessageChunk' => [SystemMessageChunk::class];
        yield 'FunctionMessageChunk' => [FunctionMessageChunk::class];
        yield 'ToolMessageChunk' => [ToolMessageChunk::class];
    }

    /** @param class-string $cls */
    #[DataProvider('chunkClasses')]
    public function testConcatKeepsTheExistingIdWhenBothChunksHaveOne(string $cls): void
    {
        $first = new $cls(['content' => 'A', 'id' => 'FIRST']);
        $second = new $cls(['content' => 'B', 'id' => 'SECOND']);

        self::assertSame(
            'FIRST',
            $first->concat($second)->id,
            'upstream reads `id: this.id ?? chunk.id` — the already-accumulated chunk keeps the id',
        );
    }

    /** @param class-string $cls */
    #[DataProvider('chunkClasses')]
    public function testConcatFallsBackToTheIncomingIdWhenTheFirstHasNone(string $cls): void
    {
        $first = new $cls(['content' => 'A']);
        $second = new $cls(['content' => 'B', 'id' => 'SECOND']);

        self::assertNull($first->id, 'fixture sanity: the first chunk must have no id for this to mean anything');
        self::assertSame(
            'SECOND',
            $first->concat($second)->id,
            '`?? ` must still fall through to the incoming id when the existing one is null',
        );
    }
}
