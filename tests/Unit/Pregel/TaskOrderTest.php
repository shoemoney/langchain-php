<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Pregel\Algorithm;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Task order inside `applyWrites` must be NUMERIC.
 *
 * Upstream compares raw path values with `<` / `>` (algo.ts:289), and path
 * segments are task indices, so JavaScript orders 9 before 10. A string compare
 * gets that backwards — "10" < "9" is true lexicographically — so the tenth
 * concurrent task folded BEFORE the ninth and its write won.
 *
 * Nothing crashes and nothing flakes: the graph simply resumes from a state
 * nobody intended, and only once a superstep has ten or more tasks.
 */
#[CoversClass(Algorithm::class)]
final class TaskOrderTest extends TestCase
{
    private static function compare(mixed $a, mixed $b): int
    {
        $m = new \ReflectionMethod(Algorithm::class, 'comparePathSegments');

        return $m->invoke(null, $a, $b);
    }

    public function testSingleDigitIndicesSortNumericallyNotAlphabetically(): void
    {
        self::assertLessThan(0, self::compare(9, 10), '9 must sort before 10');
        self::assertGreaterThan(0, self::compare(10, 9));
        self::assertLessThan(0, self::compare(2, 10));
    }

    public function testEqualIndicesAreEqual(): void
    {
        self::assertSame(0, self::compare(7, 7));
    }

    public function testNumericSegmentsOrderAsNumbersEndToEnd(): void
    {
        $order = range(1, 12);
        usort($order, static fn (int $a, int $b): int => self::compare($a, $b));

        self::assertSame(range(1, 12), $order, 'ten or more concurrent tasks must fold in index order');
    }

    public function testStringSegmentsStillSortByString(): void
    {
        self::assertLessThan(0, self::compare('a', 'b'));
    }

    /**
     * The exact failure a lexicographic compare produces: "10" before "9".
     */
    public function testAStringComparisonWouldPutTenBeforeNine(): void
    {
        // strcmp('10','9') is NEGATIVE: lexicographically "10" sorts BEFORE "9".
        // That is precisely the inversion the old comparison had.
        self::assertLessThan(0, strcmp('10', '9'), 'a string compare puts ten before nine');
        self::assertLessThan(0, self::compare(9, 10), 'the fix must put nine before ten');
    }

    public function testBooleanSegmentsStillCompareAsBooleans(): void
    {
        self::assertLessThan(0, self::compare(false, true));
    }
}
