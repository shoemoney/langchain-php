<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\MessageMerge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `mergeContent()` must not fatal on a null side.
 *
 * Upstream reaches its final `else` for a null second side and returns
 * `[...left, { type: "text", text: secondContent }]` — a block whose text is
 * null. No error: `contentBlocksFromNonStringFirst` returns `[]` for a null
 * first side.
 *
 * The port threw instead. Measured before the fix:
 * `mergeContent(null, null)` and `mergeContent([...blocks], null)` both raised
 * `ContentBlock::text(): Argument #1 ($text) must be of type string, null
 * given`.
 *
 * Null is unreachable through the message classes — `AIMessageChunk`
 * coerces `content: null` to `[]` — so this only fires when a caller passes
 * null to this PUBLIC method. That is the difference from the
 * `Completions::encode()` landmine fixed earlier: a private method nothing can
 * reach is a hazard, a public method a caller can invoke is an interface.
 */
#[CoversClass(MessageMerge::class)]
final class MergeContentNullTest extends TestCase
{
    /** @return list<array{0: mixed, 1: mixed}> */
    public static function nullCombinations(): array
    {
        return [
            'both null' => [null, null],
            'null then string' => [null, 'hi'],
            'string then null' => ['hi', null],
            'blocks then null' => [[['type' => 'text', 'text' => 'a']], null],
            'null then blocks' => [null, [['type' => 'text', 'text' => 'a']]],
            'null then empty string' => [null, ''],
        ];
    }

    #[DataProvider('nullCombinations')]
    public function testANullSideMergesInsteadOfRaising(mixed $a, mixed $b): void
    {
        $result = MessageMerge::mergeContent($a, $b);

        // Whatever it returns, it must be a representable content value and not
        // a block carrying an unrepresentable `text: null`.
        self::assertTrue(
            is_string($result) || is_array($result),
            'mergeContent must return a string or a list of blocks',
        );

        foreach ((array) $result as $block) {
            if (is_array($block) && array_key_exists('text', $block)) {
                self::assertIsString(
                    $block['text'],
                    'a merged text block must hold a string; PHP cannot represent upstream\'s text: null',
                );
            }
        }
    }

    public function testTheOrdinaryPathsAreUnchanged(): void
    {
        self::assertSame('ab', MessageMerge::mergeContent('a', 'b'));
        self::assertSame('b', MessageMerge::mergeContent('', 'b'));
        // A list side is merged element-wise and a bare string element passes
        // through unwrapped. Verified identical with and without the null guard
        // above, so this pins the ordinary path rather than the new one.
        self::assertSame(
            ['a', ['type' => 'text', 'text' => 'b']],
            MessageMerge::mergeContent(['a'], [['type' => 'text', 'text' => 'b']]),
        );
    }

    public function testNullBehavesAsEmptyRatherThanAsContent(): void
    {
        // null is "nothing here", so it must contribute nothing — not turn into
        // the literal string "null" and not become a text block of its own.
        self::assertSame('hi', MessageMerge::mergeContent(null, 'hi'));
        self::assertSame('hi', MessageMerge::mergeContent('hi', null));
        self::assertSame('', MessageMerge::mergeContent(null, ''));
    }
}
