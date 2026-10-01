<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\ToolMessageChunk;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Concatenating tool chunks must keep the EXISTING `tool_call_id`.
 *
 * Third member of the family 436 and 437 found: `id` was inverted in all six chunk types, `name` in the
 * two types upstream merges it, and `tool_call_id` here. This one matters most, because `tool_call_id` is
 * what pairs a tool RESULT with the CALL that produced it — an inversion re-points results at the wrong
 * call.
 *
 * Upstream is `tool.ts:205`, read directly:
 *
 *     tool_call_id: this.tool_call_id
 *
 * Unconditional, and with **no fallback to the incoming chunk at all** — not `?? chunk.tool_call_id`. The
 * port read `$other->toolCallId !== '' ? $other->toolCallId : $this->toolCallId`, so the incoming id won
 * unless it happened to be an empty string.
 *
 * The two-different-non-empty-ids case is the one that discriminates. With an EMPTY incoming id the port
 * and upstream already agreed, because the port's `!== ''` guard fell back to `$this` — so a fixture using
 * an empty id would have shown no divergence at all. **Test the case where the values actually compete.**
 */
#[CoversNothing]
final class ToolMessageChunkConcatCallIdTest extends TestCase
{
    public function testConcatKeepsTheExistingToolCallId(): void
    {
        $first = new ToolMessageChunk(['content' => 'r1', 'tool_call_id' => 'CALL-1']);
        $second = new ToolMessageChunk(['content' => 'r2', 'tool_call_id' => 'CALL-2']);

        self::assertSame(
            'CALL-1',
            $first->concat($second)->toolCallId,
            'upstream `tool.ts:205` is `tool_call_id: this.tool_call_id` — the accumulated call id wins',
        );
    }

    /** Upstream has no fallback, so an empty incoming id cannot displace the existing one either. */
    public function testAnEmptyIncomingToolCallIdDoesNotDisplaceTheExistingOne(): void
    {
        $first = new ToolMessageChunk(['content' => 'r1', 'tool_call_id' => 'CALL-1']);
        $second = new ToolMessageChunk(['content' => 'r2', 'tool_call_id' => '']);

        self::assertSame('CALL-1', $first->concat($second)->toolCallId);
    }

    /** The discriminating case, named separately so a future edit cannot quietly weaken it. */
    public function testTwoDifferentNonEmptyCallIdsPreferTheExistingOne(): void
    {
        $merged = (new ToolMessageChunk(['content' => 'r1', 'tool_call_id' => 'CALL-1']))
            ->concat(new ToolMessageChunk(['content' => 'r2', 'tool_call_id' => 'CALL-2']));

        self::assertNotSame('CALL-2', $merged->toolCallId, 'the incoming id must not win');
        self::assertSame('CALL-1', $merged->toolCallId);
    }
}
