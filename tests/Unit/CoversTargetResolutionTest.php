<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `#[CoversClass]` / `#[CoversMethod]` target must actually resolve.
 *
 * Found by CI, not locally. `#[CoversClass(ToolOutput::class)]` inside
 * `namespace LangChain\Tests\Unit\Tools` resolves to
 * `LangChain\Tests\Unit\Tools\ToolOutput` — which does not exist. The
 * attribute still parses, the test still passes, and without a coverage driver
 * nothing complains, so the whole suite was green locally and on every 8.2 /
 * 8.3 / 8.4 job. Only the coverage job, which has xdebug, failed:
 *
 *     "LangChain\Tests\Unit\Tools\ToolOutput" is not a valid target for
 *     code coverage
 *
 * 55 warnings, one per test method, and a non-zero exit. The lesson is the
 * familiar one: a dev box without the coverage driver cannot see a defect
 * that only exists when coverage is on. This guard needs no driver, so it
 * fails the ordinary suite instead.
 */
final class CoversTargetResolutionTest extends TestCase
{
    public function testEveryCoverageAttributeTargetResolves(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::testFiles($root . '/tests') as $file) {
            // This file's own docblock QUOTES the broken attribute in order to
            // explain it, so scanning its prose would make the guard fail on
            // itself forever. Scan code, not commentary.
            if (basename($file) === self::class . '.php') {
                continue;
            }

            $src = self::stripComments((string) file_get_contents($file));

            $resolvable = self::importedShortNames($src);
            foreach (self::classesDeclaredIn($src) as $declared) {
                $resolvable[] = $declared;
            }

            foreach (self::coverageAttributeTargets($src) as $ref) {
                // A leading backslash or an embedded separator is already
                // fully qualified and cannot be mis-resolved.
                if (str_contains($ref, '\\')) {
                    continue;
                }
                if (!in_array($ref, $resolvable, true)) {
                    $ns = self::namespaceOf($src);
                    $offenders[] = sprintf(
                        '%s: %s::class resolves to %s\\%s, which does not exist (add a use statement)',
                        str_replace($root . '/', '', $file),
                        $ref,
                        $ns,
                        $ref,
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Unresolvable coverage-attribute targets:\n  " . implode("\n  ", $offenders),
        );
    }

    private static function stripComments(string $src): string
    {
        // Docblocks and line comments must not be searched: prose about a
        // defect is not a use of it.
        $src = (string) preg_replace('!//[^\n]*!', '', $src);
        $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);

        return $src;
    }

    /** @return list<string> */
    private static function testFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** @return list<string> */
    private static function importedShortNames(string $src): array
    {
        preg_match_all('/^use\s+([^;]+);/m', $src, $m);

        $out = [];
        foreach ($m[1] as $u) {
            $u = trim($u);

            // Grouped imports count. `use LangChain\Tools\{Schema, ToolException};`
            // is idiomatic, and parsing it as one string produced the short name
            // `ToolException}` — so every grouped import read as an unresolved
            // target. Found because a NEW test used a grouped use and the guard
            // reported a defect in the test rather than in itself.
            if (str_contains($u, '{')) {
                $inside = explode('}', $u, 2)[0];
                $inside = substr($inside, (int) strpos($inside, '{') + 1);
                foreach (explode(',', $inside) as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $out[] = str_contains($name, ' as ')
                            ? trim(explode(' as ', $name, 2)[1])
                            : $name;
                    }
                }

                continue;
            }

            // `use function x` and `as` aliases both reduce to the last segment.
            $u = preg_replace('/^use\s+(function|const)\s+/', '', $u);
            $u = preg_split('/\s+as\s+/i', (string) $u)[0];
            $out[] = substr((string) strrchr('\\' . trim((string) $u), '\\'), 1);
        }

        return $out;
    }

    /** @return list<string> */
    private static function classesDeclaredIn(string $src): array
    {
        preg_match_all(
            '/^\s*(?:final\s+|abstract\s+)?(?:class|interface|enum|trait)\s+(\w+)/m',
            $src,
            $m,
        );

        return $m[1];
    }

    /** @return list<string> */
    private static function coverageAttributeTargets(string $src): array
    {
        preg_match_all(
            '/#\[Covers(?:Class|Methods?|Nothing|Trait)\s*\(?\s*([A-Za-z_][\w\\\\]*)::class/',
            $src,
            $m,
        );

        return $m[1];
    }

    private static function namespaceOf(string $src): string
    {
        return preg_match('/^namespace\s+([^;]+);/m', $src, $m) ? trim($m[1]) : '';
    }
}
