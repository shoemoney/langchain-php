<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A test method may carry at most ONE `#[DataProvider]`.
 *
 * PHPUnit applies only the first and ignores the rest, so stacking two looks
 * like it works — the suite still collects cases and still reports a plausible
 * test count — while every row from the second provider is passed as the ENTIRE
 * argument list. The symptom is an `ArgumentCountError` on every such row, which
 * at least fails loudly; the dangerous version is a provider whose rows happen
 * to line up, where the test silently runs against the wrong data.
 *
 * Hit twice in two iterations before this guard existed:
 *   * `RetryPredicateTest` — "10 errors", each an `int` where a `class-string`
 *     was expected, because each client was handed the other client's rows.
 *   * `OptionPathMatrixTest` — "1 passed, exactly 3 expected" on every row.
 *
 * Both times the fix was to build one cross-product provider by hand. This
 * makes forgetting to do that a build failure instead of a confusing run.
 */
final class SingleDataProviderTest extends TestCase
{
    /** @return list<string> */
    private static function testFiles(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__)
        );
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    public function testNoTestMethodDeclaresMoreThanOneDataProvider(): void
    {
        $offenders = [];
        $root = dirname(__DIR__, 2);

        foreach (self::testFiles() as $file) {
            $src = (string) file_get_contents($file);
            // Comments and strings are stripped for the same reason as everywhere
            // else in this project: prose about a broken attribute is not a use
            // of it, and a docblock that merely names a provider must not fail
            // the build.
            $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
            $src = (string) preg_replace('!//[^\n]*!', '', $src);

            // Match the CONTIGUOUS attribute block immediately above each
            // signature — `((?:#\[[^\]]*\]\s*)*)` — rather than a fixed
            // lookback window. The window version was wrong in the dangerous
            // direction: a 400-character scan reaches back over the PREVIOUS
            // method and reported 24 methods in CheckpointerSpecTest as
            // stacked, when every one of them has exactly one provider. A guard
            // that cries wolf gets deleted, and then the real case ships.
            if (!preg_match_all(
                '/((?:#\[[^\]]*\]\s*)*)(?:public|protected|private)?\s*function\s+(\w+)/',
                $src,
                $methods,
                PREG_SET_ORDER,
            )) {
                continue;
            }
            foreach ($methods as $m) {
                $count = preg_match_all('/#\[DataProvider\(/', $m[1], $dm);
                if ($count > 1) {
                    $offenders[] = sprintf(
                        '%s::%s() declares %d DataProvider attributes; PHPUnit applies only the first',
                        str_replace($root . '/', '', $file),
                        $m[2],
                        $count,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Stacked DataProvider attributes:\n  " . implode("\n  ", $offenders),
        );
    }
}
