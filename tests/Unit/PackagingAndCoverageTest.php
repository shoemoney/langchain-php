<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Two declarations that must not quietly stop matching reality.
 *
 * 1. `SqliteSaver` ships in `src/` and constructs a `PDO` against SQLite, so
 *    `ext-pdo_sqlite` is a real dependency. It was declared nowhere, so a
 *    consumer without the extension got a fatal at the first checkpoint write
 *    rather than a clear message from composer.
 *
 *    Upstream keeps that saver in a SEPARATE package
 *    (`@langchain/langgraph-checkpoint-sqlite`), so it is optional there. This
 *    port bundles it, so the extension is required to TEST and suggested for
 *    USE — a hard `require` would force pdo_sqlite on every consumer for a
 *    backend most never touch.
 *
 * 2. The coverage job measured `--testsuite unit` only. The integration suite is
 *    where the HTTP and SSE seams are exercised — the request body that leaves
 *    and the bytes that come back — and that is precisely where this port's
 *    release-blocking defects lived. A coverage figure that excludes them
 *    describes a library that is not the one being shipped.
 */
final class PackagingAndCoverageTest extends TestCase
{
    private static function composer(): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        $json = json_decode($raw, true);
        self::assertIsArray($json, 'composer.json must be valid JSON');

        return $json;
    }

    public function testTheSqliteExtensionIsDeclared(): void
    {
        $composer = self::composer();

        self::assertArrayHasKey(
            'ext-pdo_sqlite',
            $composer['require-dev'] ?? [],
            'the suite exercises SqliteSaver, so CI and a contributor clone need the extension declared',
        );
        self::assertArrayHasKey(
            'ext-pdo_sqlite',
            $composer['suggest'] ?? [],
            'the saver ships in src/, so a consumer needs to be told the extension exists',
        );
    }

    public function testTheSqliteExtensionIsNotAHardRuntimeRequirement(): void
    {
        $composer = self::composer();

        self::assertArrayNotHasKey(
            'ext-pdo_sqlite',
            $composer['require'] ?? [],
            'making it a hard requirement would force pdo_sqlite on every consumer for a backend most '
            . 'never touch — upstream keeps that saver in a separate optional package',
        );
    }

    public function testTheCoverageJobMeasuresBothSuites(): void
    {
        $yml = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');

        // The whole COVERAGE job block. Scoping to a single `run:` line failed
        // twice: the job's first step is `composer install`, and a fixed-width
        // window around it swallowed the comment on the NEXT step, which
        // mentions `--coverage-clover`, so the helper happily returned the
        // composer line.
        $block = self::coverageJobBlock($yml);

        // The COMMA form specifically. `--testsuite unit --testsuite integration`
        // names both suites and unions NEITHER: the second value replaces the
        // first, so PHPUnit runs the integration suite ALONE and 2200 unit
        // tests drop out of the coverage figure without an error. A guard that
        // matched the two names separately was satisfied by a command that did
        // not do what it said — the failing CI job is what caught that, not the
        // test. Assert the FORM, not the vocabulary.
        self::assertMatchesRegularExpression(
            '/--testsuite\s+unit\s*,\s*integration/',
            $block,
            'the coverage job needs ONE comma-separated --testsuite, or `unit` is dropped from the '
            . 'coverage figure entirely',
        );
        // Comments are stripped first. The warning comment in ci.yml QUOTES the
        // broken form on purpose, so without this the guard flags its own
        // explanation — which is the false positive this project has now hit in
        // three separate guards.
        $code = (string) preg_replace('/^\s*#.*$/m', '', $block);

        self::assertDoesNotMatchRegularExpression(
            '/--testsuite\s+\S+\s+--testsuite\s/',
            $code,
            'a repeated --testsuite flag replaces the previous value instead of adding to it',
        );
    }

    /** The coverage job's YAML, from its name to the next job at the same indent. */
    private static function coverageJobBlock(string $yml): string
    {
        self::assertSame(
            1,
            preg_match('/^  coverage:\s*$.*?(?=^  [a-zA-Z]|\z)/ms', $yml, $m),
            'the coverage job must exist in ci.yml',
        );

        return $m[0];
    }
}
