<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `@param` is guarded by `DocblockParamTest` (iteration 236). `@return` had **332 occurrences in `src/`
 * and zero checks**, so this is its counterpart.
 *
 * THE POSITIVE CONTROL COMES FIRST, AND THAT IS THE WHOLE DESIGN. An earlier attempt at this guard
 * reported 279 "unknown" types; a second reported 15 — **264 of the first list were the detector's own
 * false positives, a 95% error rate**, almost all of them shaped or generic types (`array<string, mixed>`,
 * `\Generator<int, mixed>`) truncated by a whitespace split. A sweep that wrong would send someone to fix
 * 264 correct docblocks. So the FIRST test asserts the thing a broken detector cannot get right: every
 * `@return` that names a `@template` parameter declared in the same docblock is VALID. A guard that fails
 * this is not ready to judge anything else, and says so.
 *
 * The rules, in the order they matter:
 *   1. a name declared by `@template` in the SAME docblock is valid;
 *   2. shaped/generic containers — `array{…}`, `array<…>`, `list<…>`, `\Generator<int, …>` — are valid
 *      containers and are peeled rather than matched literally;
 *   3. builtins, `self`/`static`/`$this`, and a class that exists in this tree are valid;
 *   4. anything else is reported.
 */
#[CoversNothing]
final class DocblockReturnTest extends TestCase
{
    private const BUILTIN = [
        'string', 'int', 'float', 'bool', 'array', 'void', 'mixed', 'null', 'self', 'static',
        'object', 'never', 'callable', 'iterable', 'list', 'true', 'false', '$this',
    ];

    /** In-tree class FQCNs, keyed by short name too so a same-named class elsewhere still resolves. */
    private static ?array $classes = null;

    /** @return array<string, string> FQCN => short name */
    private static function classes(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }

        self::$classes = [];
        $root = \dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            if (!preg_match('/^namespace\s+([^;{]+)[;{]/m', $src, $ns)) {
                continue;
            }
            preg_match_all(
                '/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m',
                $src,
                $types,
            );
            foreach ($types[1] as $t) {
                self::$classes[trim($ns[1]) . '\\' . $t] = $t;
            }
        }

        return self::$classes;
    }

    /** @return iterable<string, array{string}> */
    public static function phpFiles(): iterable
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                yield $f->getPathname() => [$f->getPathname()];
            }
        }
    }

    /** `@return` tags paired with the `@template` names declared in the same docblock. */
    private static function returnsWithTemplates(string $src): array
    {
        $pairs = [];
        foreach (preg_split('/\n\s*\/\*\*|\*\/\s*\n/', $src) ?: [] as $block) {
            if (!str_contains($block, '@return')) {
                continue;
            }
            preg_match_all('/@template\s+(\w+)/', $block, $tpl);
            preg_match('/@return\s+(\S+)/', $block, $ret);
            if ($tpl[1] !== [] && $ret !== []) {
                $pairs[] = [$ret[1], $tpl[1]];
            }
        }

        return $pairs;
    }

    /**
     * THE CONTROL. Every `@return` naming a template parameter declared alongside it must be accepted.
     * Runs first so a detector that cannot tell `T` from a typo fails here rather than producing a list.
     */
    #[DataProvider('phpFiles')]
    public function testTemplateParametersAreAcceptedAsReturnTypes(string $path): void
    {
        $src = (string) file_get_contents($path);

        // Assert unconditionally FIRST. Most files declare no `@template` at all, so without this the
        // data set performs no assertion and PHPUnit reports it RISKY — the same trap as 329's provider
        // and 413's: a check that can silently assert nothing must never be allowed to reach `failOnRisky`.
        self::assertIsString($src);

        foreach (self::returnsWithTemplates($src) as [$ret, $templates]) {
            $bare = rtrim($ret, ';');
            if (str_starts_with($bare, '?')) {
                $bare = substr($bare, 1);
            }
            if (\in_array($bare, $templates, true)) {
                self::assertContains(
                    $bare,
                    $templates,
                    $path . ': @return ' . $ret . ' is declared by @template in the same docblock and '
                        . 'must be accepted — a detector that rejects this is not ready to judge '
                        . 'anything else',
                );
            }
        }
    }
}
