<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every upstream method PORT_STATUS still declares ABSENT must really be absent from the whole tree.
 *
 * This table is a documentation claim, and documentation claims are the one artefact in this project that
 * nothing executes. That is how it came to hold a FALSE entry for forty iterations: `assign(mapping)` was
 * listed as an OPEN gap from iteration 401, which "diffed the public surface of `Runnable` on both sides" —
 * a search scoped to ONE file — while the port has always shipped
 * `RunnablePassthrough::assign()` (`RunnablePassthrough.php:55`, upstream `passthrough.ts:143`), covered by
 * nine assertions. 446 implemented the "missing" method and fataled the suite on
 * `Cannot make non static method Runnable::assign() static`.
 *
 * So the claim is now executable, and it carries a **CONTROL** — the lesson 447 paid for. An absence sweep
 * that cannot report its own failure is indistinguishable from a sweep that passed; 447's first attempt
 * returned fourteen zeros because zsh globbed `--include=*.php` and grep never ran. `testTheSweepControl
 * Matches` below asserts a method that MUST exist does, so a broken sweep fails loudly instead of
 * reporting three confident zeros.
 *
 * Only rows still marked OPEN are checked. Rows marked ADDED are deliberately excluded — asserting their
 * names absent would be asserting the opposite of the truth.
 */
#[CoversNothing]
final class KnownAbsentUpstreamApiTest extends TestCase
{
    private const PORT_STATUS = 'PORT_STATUS.md';

    private static function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private static function occurrencesOf(string $needle): int
    {
        $n = 0;
        foreach (self::sourceFiles() as $file) {
            $n += substr_count((string) file_get_contents($file), $needle);
        }

        return $n;
    }

    /**
     * Method names from rows the Known-absent table still marks OPEN.
     *
     * @return array<string, string> shortName => the table row it came from
     */
    private static function openClaims(): array
    {
        $path = \dirname(__DIR__, 2) . '/' . self::PORT_STATUS;
        self::assertFileExists($path);

        $rows = [];
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            // `**OPEN` and not `**OPEN**`: one row is marked
            // `**OPEN — proposed for the documented-unported list**`, and an exact match silently dropped it.
            if (!str_starts_with($line, '| `') || !str_contains($line, '**OPEN')) {
                continue;
            }
            // First cell looks like: | `withListeners({onStart,onEnd,onError})` | ...
            // The trailing `(` is OPTIONAL: the `streamEvents` row cites it with no parentheses, and
            // requiring one silently dropped that row too — which is how the first run of this guard
            // checked 1 of the 3 open claims while still reporting green.
            if (preg_match('/^\|\s*`([A-Za-z_][A-Za-z0-9_]*)/', $line, $m) === 1) {
                $rows[$m[1]] = $line;
            }
        }

        return $rows;
    }

    /** @return iterable<string, array{string}> */
    public static function openClaimsProvider(): iterable
    {
        foreach (self::openClaims() as $name => $row) {
            yield $name => [$name];
        }
    }

    /**
     * The CONTROL. Without this, every assertion below can pass on a sweep that never ran.
     */
    public function testTheSweepControlMatches(): void
    {
        self::assertGreaterThan(
            0,
            self::occurrencesOf('function batchEach'),
            'the control method `batchEach` must be found in src/ — if it is not, this sweep cannot '
                . 'distinguish "absent" from "the sweep is broken", and every absence claim below is void',
        );
    }

    /** The parse must not silently come back empty, or the provider asserts nothing. */
    public function testTheTableStillDeclaresOpenGapsToCheck(): void
    {
        self::assertNotEmpty(
            self::openClaims(),
            'no OPEN rows were parsed out of the Known-absent table — either the table moved, the marker '
                . 'changed, or the row format did, and this test is now checking nothing',
        );
    }

    #[DataProvider('openClaimsProvider')]
    public function testAnOpenlyDeclaredGapIsGenuinelyAbsentFromTheTree(string $method): void
    {
        self::assertSame(
            0,
            self::occurrencesOf('function ' . $method),
            self::PORT_STATUS . ' lists `' . $method . '` as an OPEN gap, but `function ' . $method . '` '
                . 'exists in src/. Either implement it and mark the row ADDED, or remove the row — a false '
                . 'absence claim sends the next pass to build something that is already there.',
        );
    }
}
