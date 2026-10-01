<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\MessageUtils;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A tool result's `artifact` must survive being turned into chunks and folded back together.
 *
 * Upstream `tool.ts` stores `artifact` on the chunk and merges it in `concat` with
 * `_mergeObj(this.artifact, chunk.artifact)` — an OBJECT merge, so both sides contribute. The port stored
 * `artifact` on `ToolMessage` only, so a STREAMED tool result lost it: `convertToChunk()` had nothing to
 * carry and `concat()` could not recover it (measured at 480).
 *
 * **The fix has four sites across two files**, and 484 established why three is not enough: with the
 * chunk's field, constructor and concat merge in place, the concat test passed while `convertToChunk()`
 * still dropped the value — because that method builds the chunk's field array itself. **Adding a field to a
 * class only matters if something constructs the class with it, and the construction site is another file.**
 *
 * Nothing documented this omission and nothing enforced it, which is what separates it from
 * `AIMessageChunk`'s `tool_calls`, documented three times and rejected with an explicit error at 478. The
 * loss is silent: no exception, no invalid-list routing, a field that simply vanishes.
 */
#[CoversNothing]
final class ToolMessageArtifactPreservedTest extends TestCase
{
    public function testAChunkCarriesTheArtifact(): void
    {
        $message = new ToolMessage([
            'content' => 'rows',
            'tool_call_id' => 'c1',
            'tool_name' => 'query',
            'artifact' => ['rows' => [1, 2, 3]],
        ]);

        $chunk = MessageUtils::convertToChunk($message);

        self::assertInstanceOf(ToolMessageChunk::class, $chunk);
        self::assertSame(
            ['rows' => [1, 2, 3]],
            $chunk->artifact,
            'convertToChunk must carry the artifact — upstream keeps it on the chunk',
        );
    }

    public function testConcatMergesBothSidesOfTheArtifact(): void
    {
        $first = new ToolMessageChunk(['content' => 'a', 'tool_call_id' => 'c1', 'artifact' => ['x' => 1]]);
        $second = new ToolMessageChunk(['content' => 'b', 'tool_call_id' => 'c1', 'artifact' => ['y' => 2]]);

        $merged = $first->concat($second);

        self::assertSame(
            ['x' => 1, 'y' => 2],
            $merged->artifact,
            'upstream merges with _mergeObj, so both sides contribute — not a list append, not a replace',
        );
    }
}
