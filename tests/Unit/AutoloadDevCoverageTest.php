<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every directory under `tests/` that holds PHP must have a PSR-4 rule in `autoload-dev`.
 *
 * `autoload-dev` mapped only `LangChain\Tests\Unit\` -> `tests/Unit/`, so the two classes in
 * `tests/Integration/` were NOT autoloadable — including `GraphAndCheckpointIntegrationTest`, which the
 * project's own hard rules name as the guard for the two wire-shape defects that broke real releases. They
 * ran only because PHPUnit includes test files by path; a cross-reference between an integration test and
 * any Unit helper would have fataled with "class not found", and nothing in the suite warned.
 *
 * Caught by asking whether a class the documentation names actually loads, which no review asked.
 */
#[CoversNothing]
final class AutoloadDevCoverageTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function directoriesWithPhp(): iterable
    {
        $root = \dirname(__DIR__, 2) . '/tests';
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
                continue;
            }
            $php = glob($root . '/' . $entry . '/*.php') ?: [];
            if ($php !== []) {
                yield $entry => [$root . '/' . $entry];
            }
        }
    }

    #[DataProvider('directoriesWithPhp')]
    public function testEveryTestDirectoryHasAPsr4Rule(string $dir): void
    {
        $composer = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        $rules = $composer['autoload-dev']['psr-4'] ?? [];

        $expected = 'LangChain\\Tests\\' . basename($dir) . '\\';

        self::assertArrayHasKey(
            $expected,
            $rules,
            "tests/{$dir}/ holds PHP but autoload-dev has no '{$expected}' rule, so its classes cannot "
            . 'be autoloaded',
        );
        self::assertSame(
            'tests/' . basename($dir) . '/',
            rtrim($rules[$expected], '/') . '/',
            "the '{$expected}' rule must point at tests/" . basename($dir) . '/',
        );
    }
}
