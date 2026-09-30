<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The status ledger must state the CURRENT suite size.
 *
 * PORT_STATUS.md and HANDOFF.md were both stale at different points in this
 * project — one understated the test count by ~200, the other had not been
 * touched since the loop began. A ledger that understates its own coverage is
 * worse than none: it is the document a reader trusts precisely because it is
 * not code.
 */
final class DocsMatchRealityTest extends TestCase
{
    /**
     * The number of tests the suite actually runs.
     *
     * Counted from PHPUnit's own output, not by counting files or `test`
     * methods: a data provider multiplies one method into many cases, so any
     * static count is a different number from the one the ledger claims. An
     * earlier version of this guard counted test CLASSES and therefore passed
     * against a ledger understated by two hundred.
     */
    private static function suiteSize(): int
    {
        $root = dirname(__DIR__, 2);
        $out = (string) shell_exec(
            'cd ' . escapeshellarg($root) . ' && ./vendor/bin/phpunit --list-tests 2>&1'
        );

        // One line per test in --list-tests output.
        preg_match_all('/^ - /m', $out, $m);

        return max(count($m[0]), 1);
    }

    public function testPortStatusTotalIsNotUnderstatingCoverage(): void
    {
        $tests = self::suiteSize();
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/PORT_STATUS.md');

        self::assertMatchesRegularExpression(
            '/\| \*\*Total so far\*\* \| \| \*\*(\d[\d,]*)\*\* \|/',
            $doc,
            'PORT_STATUS.md must carry a "Total so far" row',
        );

        preg_match('/\| \*\*Total so far\*\* \| \| \*\*(\d[\d,]*)\*\* \|/', $doc, $m);
        $claimed = (int) str_replace(',', '', $m[1]);

        // A tight tolerance. An earlier version allowed 10% slack, which a
        // ledger understated by 200 sailed straight through — 1904 is 90.6% of
        // 2101. The tolerance has to be small enough that a whole iteration's
        // worth of new tests trips it.
        self::assertGreaterThanOrEqual(
            (int) ($tests * 0.98),
            $claimed,
            sprintf(
                'PORT_STATUS.md claims %d tests but the suite runs %d. Update the ledger.',
                $claimed,
                $tests,
            ),
        );
        // The other direction, and the one that reads as competence rather
        // than neglect: a ledger claiming MORE than the suite runs asserts
        // coverage that does not exist.
        self::assertLessThanOrEqual(
            (int) ($tests * 1.02),
            $claimed,
            sprintf('PORT_STATUS.md claims %d tests but the suite runs only %d.', $claimed, $tests),
        );
    }

    public function testHandoffFileCountsAreCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        $count = static function (string $dir) use ($root): int {
            $n = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $n++;
                }
            }

            return $n;
        };

        [$srcFiles, $testFiles] = [$count('src'), $count('tests')];
        $doc = (string) file_get_contents($root . '/HANDOFF.md');

        self::assertMatchesRegularExpression('/\| Size \| (\d+) src files \/ [\d,]+ lines · (\d+) test files/', $doc);
        preg_match('/\| Size \| (\d+) src files \/ [\d,]+ lines · (\d+) test files/', $doc, $m);

        // Exact, not a band: a file count is a whole number with no ambiguity,
        // so a one-off means the row is stale. (The test-count row needs a
        // tolerance because a data provider can add tests without adding a
        // file; a file count has no such excuse.)
        self::assertSame($srcFiles, (int) $m[1], 'HANDOFF.md src file count is stale');
        self::assertSame($testFiles, (int) $m[2], 'HANDOFF.md test file count is stale');
    }

    public function testHandoffStatesACurrentSuiteSize(): void
    {
        $tests = self::suiteSize();
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/HANDOFF.md');

        self::assertMatchesRegularExpression(
            '/\| Tests \| \*\*(\d+) passing, (\d+) assertions\*\* \|/',
            $doc,
            'HANDOFF.md must state the suite size',
        );

        preg_match('/\| Tests \| \*\*(\d+) passing, (\d+) assertions\*\* \|/', $doc, $m);
        self::assertGreaterThanOrEqual(
            (int) ($tests * 0.98),
            (int) $m[1],
            sprintf('HANDOFF.md claims %d tests but the suite runs %d.', (int) $m[1], $tests),
        );
        self::assertLessThanOrEqual(
            (int) ($tests * 1.02),
            (int) $m[1],
            sprintf('HANDOFF.md claims %d tests but the suite runs only %d.', (int) $m[1], $tests),
        );
    }
}
