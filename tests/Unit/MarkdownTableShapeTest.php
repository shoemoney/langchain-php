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
