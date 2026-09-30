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

        // The coverage step specifically, not the whole file — the `test` job
        // runs the suites separately and must keep doing that.
        self::assertMatchesRegularExpression(
            '/--testsuite\s+unit\s+--testsuite\s+integration[^\n]*\n?[^\n]*--coverage-clover|--coverage-clover[^\n]*\n?[^\n]*--testsuite\s+unit\s+--testsuite\s+integration/',
            $yml,
            'the coverage job must measure the integration suite: the HTTP and SSE seams live there',
        );
    }
}
