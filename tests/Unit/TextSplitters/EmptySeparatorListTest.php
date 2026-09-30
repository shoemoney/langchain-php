<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\TextSplitters\RecursiveCharacterTextSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An empty separator list must split like upstream, not blow up.
 *
 * Both sides index the same expression — `separators[length - 1]`, upstream at
 * `text_splitter.ts:301` and here — and upstream has no guard either. The
 * difference is entirely the language:
 *
 *   JS  an empty list indexes to `undefined`, and `String.split(undefined)`
 *       returns the whole string, so upstream returns `[text]`.
 *   PHP the same expression is "Undefined array key -1" followed by
 *       `splitOnSeparator(): Argument #2 ($separator) must be of type string,
 *       null given` — a warning and then a fatal, at SPLIT time rather than
 *       construction time, from a splitter that constructed without complaint.
 *
 * Resolving an empty list to `''` — the floor separator — reproduces upstream's
 * result. A constructor guard would also remove the fatal, but it would reject
 * an input upstream accepts, and rejecting it is a divergence rather than a
 * fix.
 */
#[CoversClass(RecursiveCharacterTextSplitter::class)]
final class EmptySeparatorListTest extends TestCase
{
    public function testAnEmptySeparatorListReturnsTheWholeTextAsOneChunk(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(separators: []);
        $text = 'hello world this is a test of the empty separator path';

        self::assertSame([$text], $splitter->splitText($text));
    }

    /**
     * The regression is the absence of a warning and a fatal, so both are
     * asserted explicitly. A test that only checked the returned chunks would
     * never have failed: the pre-fix code never returned a chunk at all, it
     * raised.
     */
    public function testItRaisesNeitherAWarningNorAnError(): void
    {
        $raised = [];
        set_error_handler(static function (int $no, string $str) use (&$raised): bool {
            $raised[] = $str;

            return true;
        });

        $fatal = null;
        try {
            (new RecursiveCharacterTextSplitter(separators: []))->splitText('a b c');
        } catch (\Throwable $e) {
            $fatal = $e;
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised, 'an empty separator list must not raise a PHP warning');
        self::assertNull($fatal, 'an empty separator list must not fatal: ' . ($fatal?->getMessage() ?? ''));
    }

    /**
     * A list that merely omits the trailing `''` floor still works — the empty
     * case is a special case of that, not a different path.
     */
    public function testAListWithoutTheFloorSeparatorStillSplits(): void
    {
        $splitter = new RecursiveCharacterTextSplitter(separators: ['\n\n']);
        $chunks = $splitter->splitText("alpha beta\n\ngamma delta");

        self::assertNotEmpty($chunks);
        self::assertSame(implode("\n\n", $chunks), "alpha beta\n\ngamma delta");
    }
}
