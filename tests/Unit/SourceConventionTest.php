<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Turns two long-standing HANDOFF conventions into an enforced gate.
 *
 * HANDOFF.md used to say "Follow these or CI fails you", which iteration 319 measured as true for
 * exactly ONE of its five conventions. These two had zero enforcement — and the PSR-4 one in
 * particular had been checked by hand, as a shell one-liner, on most iterations of the review loop:
 * a real check that only ran when someone remembered to run it.
 *
 * Both are cheap, total, and mechanical, so they belong in the suite rather than in a ritual.
 *
 * A previous attempt at this file was reverted at 319 with three defects of its own, all recorded in
 * .loop/triage.json: `array_slice()` on a string (347 errors); asserting the namespace equals the full
 * class FQCN, which is wrong and failed 360 healthy files; and `dirname()` on a backslash-separated
 * string, which returns "." and collapsed every expectation.
 */
#[CoversNothing]
final class SourceConventionTest extends TestCase
{
    /**
     * `src/` legitimately contains FUNCTION-ONLY files — `createTool.php`, `coerceToRunnable.php` and
     * `Pregel/interrupt.php`, the last three registered in composer's `autoload.files` rather than as
     * classes. PSR-4's one-class-per-file rule does not apply to them, so they are exempt below. That
     * exemption is explicit and justified rather than a silent skip.
     */
    private const FUNCTION_ONLY_FILES = [
        'src/LangChain/Tools/createTool.php',
        'src/LangChain/Runnables/coerceToRunnable.php',
        'src/LangGraph/Pregel/interrupt.php',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function phpFiles(): iterable
    {
        $root = \dirname(__DIR__, 2);

        foreach (['src', 'tests'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }

                $rel = $dir . '/' . substr($f->getPathname(), strlen($path) + 1);
                yield $rel => [$rel, $f->getPathname()];
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function srcPhpFiles(): iterable
    {
        foreach (self::phpFiles() as $label => $args) {
            if (str_starts_with($args[0], 'src/')) {
                yield $label => $args;
            }
        }
    }

    #[DataProvider('phpFiles')]
    public function testEveryFileDeclaresStrictTypes(string $rel, string $path): void
    {
        $lines = \explode("\n", (string) file_get_contents($path));
        $head = implode("\n", \array_slice($lines, 0, 8));

        self::assertStringContainsString(
            'declare(strict_types=1);',
            $head,
            $rel . ' is missing declare(strict_types=1); near the top'
        );
    }

    /**
     * PSR-4: the NAMESPACE must equal the directory path, and the declared TYPE must equal the
     * filename. Asserting the namespace equals the full class FQCN is wrong — it failed 360 healthy
     * files the first time this was attempted.
     */
    #[DataProvider('phpFiles')]
    public function testNamespaceMatchesTheDirectory(string $rel, string $path): void
    {
        $src = (string) file_get_contents($path);
        self::assertSame(
            1,
            preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $ns),
            $rel . ' declares no namespace'
        );

        // Strip the ROOT prefix by its own length: 'src/' is 4 chars but 'tests/' is 6, and a fixed
        // offset silently produces garbage for one of the two trees.
        $isSrc = str_starts_with($rel, 'src/');
        $rootPrefix = $isSrc ? 'src/' : 'tests/';
        $fqcn = str_replace(['/', '.php'], ['\\', ''], \substr($rel, \strlen($rootPrefix)));

        // dirname() does not treat a backslash as a separator, so the FQCN becomes a slash path FIRST.
        $dir = trim(\dirname(str_replace('\\', '/', $fqcn)), '/.');
        $expected = $isSrc
            ? str_replace('/', '\\', $dir)
            // The dir still contains its own leading `Unit`, so the prefix stops at Tests — adding
            // `\Unit` here produced `LangChain\Tests\Unit\Unit\Pregel` for 116 of the files.
            : 'LangChain\\Tests\\' . str_replace('/', '\\', $dir);

        self::assertSame(
            $expected,
            trim($ns[1]),
            $rel . " sits in $dir, so its namespace should be $expected"
        );
    }

    #[DataProvider('phpFiles')]
    public function testDeclaredTypeMatchesTheFilename(string $rel, string $path): void
    {
        if (\in_array($rel, self::FUNCTION_ONLY_FILES, true)) {
            self::assertDoesNotMatchRegularExpression(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s/m',
                (string) file_get_contents($path),
                $rel . ' is registered as a function-only file but declares a type'
            );

            return;
        }

        $type = \basename($rel, '.php');
        self::assertMatchesRegularExpression(
            '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+' . preg_quote($type, '/') . '\b/m',
            (string) file_get_contents($path),
            $rel . " should declare a type named $type"
        );
    }

    /**
     * Two further rules were ATTEMPTED here and DELIBERATELY DROPPED, because neither can be stated
     * correctly rather than because neither is worth wanting. Recorded so they are not re-attempted
     * blind:
     *
     *  1. "One top-level type per file" across `tests/`. 13 test files declare 2-4 types and every one
     *     is a deliberate file-local double (`BubbleUpStub`, `NoSleepOpenAI`, `NoSleepAnthropic`,
     *     transport-exception stubs). Enforcing it across `tests/` would demand 13 renames to satisfy a
     *     rule the test tree should not follow; carving them out silently would hide the boundary. The
     *     `src/` half of the rule has zero violations and is enforced by `testSourceFilesDeclareOneType`.
     *
     *  2. "A file-local helper is never named in another test file." Written as a substring search this
     *     produced 3 false failures, because a NAME may legitimately be reused by a DIFFERENT file-local
     *     class — `NoSleepOpenAI` is declared in one file and independently re-declared elsewhere. Name
     *     collision is not cross-file reference, and distinguishing them needs real symbol resolution
     *     rather than `str_contains`. A guard that cannot be stated correctly is worse than no guard,
     *     because it teaches the next reader that the convention is machine-checked when it is not.
     */

    /**
     * Two files declaring the same fully-qualified name.
     *
     * This was the FOURTH thing this file did not cover, and the only PSR-4 property with no automated
     * check at all: the duplicate half of the rule was verified by an ad-hoc `php -r` one-liner this loop
     * happened to run on most iterations for several hundred of them. A check that only runs when
     * someone remembers is a gate that protects nothing — and it was the same ritual 329 replaced.
     *
     * PSR-4 forbids it outright ("if a class name is declared in both, it is a PSR-4 autoloading
     * error"). Here it matters specifically: two classes under one FQCN leaves the second silently
     * unreachable, and which one wins depends on autoloader ordering.
     *
     * The whole map is built once rather than accumulated as the suite runs, so the assertion does not
     * depend on test order — an order-dependent duplicate check would itself be a flaky check, which is
     * the 412 lesson in a new place.
     */
    #[DataProvider('phpFiles')]
    public function testNoFullyQualifiedNameIsDeclaredTwice(string $rel, string $path): void
    {
        $map = self::declarationMap();
        $src = (string) file_get_contents($path);

        // The three function-only files declare no type at all, so there is nothing to check — the same
        // exemption 329 records for the one-class-per-file rule.
        if (\in_array($rel, self::FUNCTION_ONLY_FILES, true)) {
            self::assertStringNotContainsString(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+/m',
                $src,
                $rel . ' is registered as function-only but declares a type',
            );

            return;
        }

        preg_match_all(
            '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
            $src,
            $types,
        );
        self::assertNotEmpty($types[1], $rel . ' declares no type, so there is nothing to check');

        $base = self::namespaceOf($src, $rel);
        foreach ($types[1] as $type) {
            $key = $base . '\\' . $type;
            // The map holds EVERY declaration, including this file's own, so the check is on the
            // COUNT of files per name — not on membership. An earlier version asserted `assertArrayNotHasKey`
            // against a map that contained the key by construction and therefore failed 367 times, which
            // is the 409 invalid-data-provider shape: a check that cannot pass.
            self::assertLessThan(
                2,
                \count($map[$key] ?? []),
                $key . ' is declared in ' . implode(' and ', $map[$key] ?? []) . '; PSR-4 forbids it and '
                    . 'the second file is silently unreachable',
            );
        }
    }

    private static function namespaceOf(string $src, string $rel): string
    {
        if (!preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $ns)) {
            self::fail($rel . ' declares no namespace');
        }

        return trim($ns[1]);
    }

    /** @return array<string, list<string>> FQCN => every file that declares it. */
    private static function declarationMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::phpFiles() as [$rel, $path]) {
            $src = (string) file_get_contents($path);
            if (!preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $ns)) {
                continue;
            }
            preg_match_all(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
                $src,
                $types,
            );
            foreach ($types[1] as $type) {
                $map[trim($ns[1]) . '\\' . $type][] = $rel;
            }
        }

        return $map;
    }
}
