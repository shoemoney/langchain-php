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

    /**
     * The Size row's LINE counts must be current.
     *
     * Added because they were not. This guard checks test counts and file
     * counts — and a line count is none of those — so HANDOFF.md sat claiming
     * 33,493 src and 21,361 test lines against a real 33,984 and 23,776: 491 and
     * 2,415 lines out of date, suite green throughout, because sync_docs.py had
     * ZERO mentions of "lines" and so could never update them.
     *
     * A number nothing measures is a number nothing keeps true.
     *
     * CORRECTION (486): this docblock previously read "This guard checks test
     * counts, ASSERTION counts and file counts". That was false, and the same
     * false claim stood in sync_docs.py:139 — so two comments agreed that an
     * assertion-count guard existed, and believing them is precisely why the
     * assertion count was the LAST number in HANDOFF.md with nothing checking
     * it. It drifted by one (9,328 claimed, 9,329 measured) and nothing said so.
     *
     * Why there is still no assertion assertion HERE, honestly stated: this
     * class measures the suite with `--list-tests`, which yields a test COUNT
     * and no assertion count. An assertion count exists only after the suite
     * has actually RUN, so a test cannot check its own assertion total without
     * recursively running the suite from inside a suite.
     *
     * And the vestige proves the omission was an oversight, not a decision:
     * `testHandoffStatesACurrentSuiteSize()`'s regex captures BOTH numbers —
     * `(\d+) passing, (\d+) assertions` — so the assertion count IS parsed into
     * `$m[2]`… and that method then reads only `$m[1]` (verified: its two
     * asserts, at the `assertGreaterThanOrEqual`/`assertLessThanOrEqual` pair,
     * both interpolate `$m[1]`). The `$m[2]` uses elsewhere in this file belong
     * to the FILE-count and LINE-count regexes, not this one. Captured,
     * bound, never read — the "written-but-never-read" shape this repo's own
     * hard rules name first, sitting in the guard that was supposed to catch it.
     *
     * The number is written by `sync_docs.py` (which runs the suite via a JUnit
     * log) and is therefore correct whenever that script is run — and
     * unguarded whenever it is not. CI does not run sync_docs.py either.
     * Closing that needs a CI step (`sync_docs.py` then `git diff --exit-code`),
     * not a fourth assertion here.
     */
    public function testTheSizeRowLineCountsAreCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        $count = static function (string $dir) use ($root): int {
            $lines = 0;
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $lines += count(file($f->getPathname()));
                }
            }

            return $lines;
        };

        $src = $count('src');
        $test = $count('tests');
        $doc = (string) file_get_contents($root . '/HANDOFF.md');

        self::assertSame(
            1,
            preg_match(
                '/\| Size \| (\d+) src files \/ ([\d,]+) lines · (\d+) test files \/ ([\d,]+) lines \|/',
                $doc,
                $m,
            ),
            'the Size row must be machine-parseable: N src files / N lines · N test files / N lines',
        );

        $num = static fn (string $s): int => (int) str_replace(',', '', $s);

        self::assertSame($num($m[2]), $src, sprintf('HANDOFF.md claims %s src lines; there are %d', $m[2], $src));
        self::assertSame($num($m[4]), $test, sprintf('HANDOFF.md claims %s test lines; there are %d', $m[4], $test));
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
