<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\FunctionMessageChunk;
use LangChain\Messages\SystemMessageChunk;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `name` merges in the same direction as `id`: the already-accumulated chunk wins.
 *
 * This is the companion to `MessageChunkConcatIdPrecedenceTest` (436), which found the `id` preference
 * inverted in all six chunk types. `name` had the identical inversion, and 436 recorded it as unmeasured
 * scope rather than changing it blind.
 *
 * Upstream, read directly rather than pattern-matched:
 *
 *   messages/system.ts:63    name: this.name ?? chunk.name   -> existing wins, falls back to incoming
 *   messages/function.ts:73  name: this.name ?? ""            -> existing wins, DEFAULTS TO EMPTY STRING
 *
 * The port read `$other->name ?? $this->name` in all six types, so a streamed message took its name from
 * the last chunk — and for `FunctionMessageChunk` it also produced `null` where upstream produces `""`.
 *
 * **Only the two types upstream actually merges are asserted here.** `ai.ts`, `chat.ts`, `human.ts` and
 * `tool.ts` carry NO `name` in `concat` (verified by reading `tool.ts:192-208` directly), so upstream
 * drops it entirely; the port preserves it. That is a real divergence in the opposite direction — a
 * preservation the upstream does not perform — and it is recorded in PORT_STATUS rather than asserted
 * here, because removing preservation is a behaviour removal whose effect on port consumers has not been
 * measured. Asserting upstream's `null` would also mean asserting that a useful field is thrown away.
 */
#[CoversNothing]
final class MessageChunkConcatNamePrecedenceTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function upstreamMergesName(): iterable
    {
        yield 'SystemMessageChunk (system.ts:63)' => [SystemMessageChunk::class];
        yield 'FunctionMessageChunk (function.ts:73)' => [FunctionMessageChunk::class];
    }

    /** @param class-string $cls */
    #[DataProvider('upstreamMergesName')]
    public function testConcatKeepsTheExistingName(string $cls): void
    {
        $first = new $cls(['content' => 'A', 'name' => 'FIRST']);
        $second = new $cls(['content' => 'B', 'name' => 'SECOND']);

        self::assertSame(
            'FIRST',
            $first->concat($second)->name,
            'upstream reads `name: this.name ?? chunk.name` — the accumulated chunk keeps the name',
        );
    }

    /**
     * ONLY SystemMessageChunk falls back to the incoming name.
     *
     * The two upstream sources differ and an earlier version of this test shared one assertion across
     * both, which asserted `SECOND` for `FunctionMessageChunk` and therefore demanded behaviour that
     * upstream contradicts: `system.ts:63` is `this.name ?? chunk.name`, but `function.ts:73` is
     * `this.name ?? ""` with NO reference to `chunk.name` at all. A shared provider here would have
     * "verified" a divergence — the fixture-encodes-the-wrong-expectation trap.
     */
    public function testSystemMessageChunkFallsBackToTheIncomingNameWhenTheFirstHasNone(): void
    {
        $first = new SystemMessageChunk(['content' => 'A']);
        $second = new SystemMessageChunk(['content' => 'B', 'name' => 'SECOND']);

        self::assertNull($first->name, 'fixture sanity: the first chunk must have no name');
        self::assertSame(
            'SECOND',
            $first->concat($second)->name,
            '`system.ts:63` is `this.name ?? chunk.name`, so a nameless first chunk yields the incoming name',
        );
    }

    /** `function.ts:73` never consults `chunk.name`, so a nameless first chunk yields `""`. */
    public function testFunctionMessageChunkDoesNotFallBackToTheIncomingName(): void
    {
        $first = new FunctionMessageChunk(['content' => 'A']);
        $second = new FunctionMessageChunk(['content' => 'B', 'name' => 'SECOND']);

        self::assertSame(
            '',
            $first->concat($second)->name,
            '`function.ts:73` is `this.name ?? ""` — the incoming chunk name is never consulted',
        );
    }

    /** `function.ts:73` is `name: this.name ?? ""` — an empty string, not null. */
    public function testFunctionMessageChunkDefaultsTheNameToAnEmptyStringNotNull(): void
    {
        $merged = (new FunctionMessageChunk(['content' => 'A']))->concat(new FunctionMessageChunk(['content' => 'B']));

        self::assertSame(
            '',
            $merged->name,
            'upstream `name: this.name ?? ""` yields the empty string when neither chunk has a name',
        );
    }
}
