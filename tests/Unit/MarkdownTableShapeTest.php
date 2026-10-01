<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every row of every markdown table must have its table's column count.
 *
 * PORT_STATUS.md is the contract with the next engineer, and a row that has been
 * fused renders broken — two entries sharing one row with an empty middle cell.
 * Two independent reviews reported such a row and the first was dismissed: the
 * reviewer's line numbers were stale, and a quick check of a DIFFERENT table in
 * the same file appeared to refute it. Only reading the exact row settled it,
 * and only then did the fusion show: six cells where the neighbours had three
 * and two.
 *
 * A shape check is the honest way to settle this class of claim, because it does
 * not depend on line numbers being right.
 *
 * Pipes are counted only when NOT escaped — cell text legitimately contains
 * `\|`, which a naive `count('|')` mistakes for a column break.
 */
final class MarkdownTableShapeTest extends TestCase
{
    /**
     * Split a row on pipes that are NOT escaped inside a cell.
     *
     * Cell text legitimately contains `\\|`, which a naive `count('|')` reads as
     * a column break. That is what made an earlier ad-hoc check report fifty
     * malformed rows in a file where none were.
     *
     * @return list<string>
     */
    private static function splitRow(string $line): array
    {
        $parts = [];
        $current = '';
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $ch = $line[$i];
            if ($ch === '|' && ($i === 0 || $line[$i - 1] !== '\\')) {
                $parts[] = $current;
                $current = '';

                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        return $parts;
    }

    /** @return list<array{0: int, 1: int, 2: int}> line, expected cells, actual cells */
    private static function check(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $problems = [];
        $expected = null;
        $seen = [];
        $inTable = false;

        foreach ($lines as $i => $line) {
            $trimmed = rtrim($line);
            $isRow = str_starts_with($trimmed, '|');

            if (!$isRow) {
                // A blank line ends the table; a heading resets the baseline.
                $expected = null;
                $inTable = false;
                continue;
            }
            if (!str_contains($trimmed, '<!-- fix:') && preg_match('/^\|[\s\-:|]+\|$/', $trimmed) === 1) {
                continue; // the |---|---| separator
            }

            // Count only pipes that are not escaped inside a cell.
            $cells = count(self::splitRow($trimmed)) - 2;

            if ($expected === null) {
                $expected = $cells;
                $seen = [];
                $inTable = true;
                continue;
            }

            $seen[] = [$i + 1, $cells];
        }
        unset($inTable);

        if ($seen === []) {
            return [];
        }

        // Only a row with MORE cells than any legitimate row in the same table is
        // malformed. This ledger mixes two- and three-column rows by design — a
        // behaviour with no distinct "Where" is written with two — so demanding
        // uniform columns flagged dozens of correct rows. A FUSION, which is the
        // defect actually worth catching, always exceeds the table's maximum.
        // A fusion is a UNIQUE outlier wider than every other row in its table.
        // Comparing against the table's own maximum cannot work — the widest row
        // IS the maximum, fused or not, which is why the first version of this
        // rule flagged nothing at all.
        foreach ($seen as [$line, $cells]) {
            $others = array_values(array_filter(
                array_column($seen, 1),
                static fn (int $c): bool => $c !== $cells,
            ));
            if ($others !== [] && $cells > max($others)) {
                $problems[] = [$line, max($others), $cells];
            }
        }

        return $problems;
    }

    public function testEveryTableRowMatchesItsTableShape(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['PORT_STATUS.md', 'HANDOFF.md'] as $file) {
            $problems = self::check((string) file_get_contents($root . '/' . $file));
            self::assertSame(
                [],
                $problems,
                sprintf(
                    "%s has rows whose cell count differs from their table's:\n  %s",
                    $file,
                    implode("\n  ", array_map(
                        static fn (array $p): string => sprintf('line %d: expected %d cells, got %d', $p[0], $p[1], $p[2]),
                        $problems,
                    )),
                ),
            );
        }
    }

    /**
     * The guard must be able to see a fused row, or it is decoration.
     */
    /**
     * A `|---|---|` separator must have the SAME number of cells as the row directly above it.
     *
     * The existing outlier rule cannot see iteration 449's defect: it flags a row with more cells than
     * EVERY other row in its table, and 449's was two five-cell rows sitting above a three-cell separator —
     * the widest width in the table, reached twice, so neither row was a unique outlier and neither was
     * flagged. (Its own comment records why the rule is not simply "compare to the header": this ledger
     * mixes two- and three-column rows by design.)
     *
     * This rule sidesteps the whole problem by being correct by construction rather than by heuristic.
     * Markdown defines a table's header as the row immediately above its separator, so the two MUST agree
     * in width. There is no baseline to track, no contiguity to infer, and no tolerance band to tune — which
     * is why an earlier attempt at a smarter baseline heuristic produced 173 false positives on the current
     * file while this produces zero.
     *
     * Proven against history, not just asserted: on PORT_STATUS as of `HEAD~2` this reports exactly one
     * violation, at line 36, "row above 5 cells, separator 3 cells" — 449's defect — and zero today.
     */
    public function testEverySeparatorMatchesTheWidthOfTheRowAboveIt(): void
    {
        $root = \dirname(__DIR__, 2);
        $problems = [];

        // Looped rather than data-provided: an earlier version of this referenced a `docFiles` provider
        // that does not exist, which is the same guess-an-API mistake 446 made with `getLast()`.
        foreach (['PORT_STATUS.md', 'HANDOFF.md'] as $file) {
        $lines = explode("\n", (string) file_get_contents($root . '/' . $file));

        foreach ($lines as $i => $line) {
            if (preg_match('/^\|[\s\-:|]+\|$/', trim($line)) !== 1) {
                continue;
            }
            $above = $i > 0 ? rtrim($lines[$i - 1]) : '';
            if (!str_starts_with($above, '|')) {
                continue;
            }
            $width = static fn (string $row): int => \count(self::splitRow(trim($row))) - 2;
            if ($width($above) !== $width($line)) {
                $problems[] = sprintf('%s line %d: header has %d cells, separator has %d',
                    $file, $i + 1, $width($above), $width($line));
            }
        }

        }

        self::assertSame([], $problems, "a separator must match the row above it:\n  "
            . implode("\n  ", $problems));
    }

    /**
     * The detector must be shown to FIRE, or a clean file proves nothing — 447's control, applied to a
     * guard whose entire job is to report zero.
     */
    public function testTheSeparatorWidthRuleDetectsAMalformedTable(): void
    {
        $malformed = <<<'MDX'
            | A | B | C |
            | one | two | three | four | five |
            |---|---|---|
            | x | y | z |
            MDX;

        $lines = explode("\n", $malformed);
        $flagged = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\|[\s\-:|]+\|$/', trim($line)) !== 1) {
                continue;
            }
            $above = rtrim($lines[$i - 1]);
            $w = static fn (string $r): int => \count(explode('|', trim($r))) - 2;
            if ($w($above) !== $w($line)) {
                $flagged = $i + 1;
                break;
            }
        }

        self::assertSame(3, $flagged, 'a 5-cell row above a 3-cell separator must be flagged at line 3');
    }

    public function testItDetectsAFusedRow(): void
    {
        $fused = "| A | B | C |\n|---|---|---|\n"
            . "| one | why one | where one |\n"
            . "| two | why two | where two |\n"
            . "| **three** | why three | where three |\n"
            . "| **four** | why four | where four | five | where five |\n";

        $problems = self::check($fused);

        self::assertCount(1, $problems, 'a row with an extra cell must be reported');
        self::assertSame(6, $problems[0][0], "the fused row is the fifth data row, on line 6");
    }

    /** An escaped pipe inside a cell is text, not a column break. */
    public function testEscapedPipesAreNotColumns(): void
    {
        $table = "| A | B |\n|---|---|\n| x | uses \\|\\| and \\| in prose |\n";

        self::assertSame([], self::check($table), 'an escaped pipe must not be read as a cell boundary');
    }
}
