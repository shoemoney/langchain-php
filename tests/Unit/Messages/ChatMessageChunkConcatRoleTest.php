<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\ChatMessage;
use LangChain\Messages\ChatMessageChunk;
use LangChain\Messages\MessageUtils;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Concatenating chat chunks must keep the EXISTING role, as upstream does.
 *
 * Upstream `chat.ts` carries `role: this.role` inside `concat` — the already-accumulated role wins, the
 * same left-wins precedence 436 reconciled for `id`. `human.ts` has no such line, which is why
 * `HumanMessageChunk::concat()` correctly does not carry a role either.
 *
 * The port's `ChatMessageChunk::concat()` never mentioned the role, so the constructor's default path
 * reset it. Measured at 473 through the port's OWN API rather than a hand-built fixture:
 *
 *     MessageUtils::convertToChunk(new ChatMessage(['content'=>'x','role'=>'custom-role']))
 *       -> ChatMessageChunk[type='custom-role']
 *     concat of two such chunks -> type='chat'          the role is LOST
 *
 * Since concatenating chunks is how a streamed message is assembled, a streamed `ChatMessage` with a
 * non-default role silently became `chat`.
 *
 * `AIMessage` is unaffected — `AIMessageChunk` carries its own type and preserves it — so this is
 * specific to the chat chunk, and `MessageUtils::convertToChunk()` is the reachability proof: no reflection
 * and no test subclass is needed to produce the losing input.
 */
#[CoversNothing]
final class ChatMessageChunkConcatRoleTest extends TestCase
{
    public function testConcatKeepsTheExistingRole(): void
    {
        $first = new ChatMessageChunk(['content' => 'A', 'role' => 'custom-role']);
        $second = new ChatMessageChunk(['content' => 'B', 'role' => 'other-role']);

        $merged = $first->concat($second);

        self::assertSame('custom-role', $merged->type,
            'upstream `chat.ts` carries `role: this.role` — the accumulated role wins, it is not reset');
    }

    /** The same defect reached through the port's own conversion helper, not a hand-built chunk. */
    public function testTheRoleSurvivesAStreamedStyleConcatOfConvertedChunks(): void
    {
        $message = new ChatMessage(['content' => 'x', 'role' => 'custom-role']);
        $first = MessageUtils::convertToChunk($message);
        $second = MessageUtils::convertToChunk($message);

        self::assertSame('custom-role', $first->type, 'fixture sanity: the converted chunk carries the role');

        $merged = $first->concat($second);

        self::assertSame('custom-role', $merged->type,
            'assembling a streamed message from chunks must not silently downgrade the role to "chat"');
    }
}
