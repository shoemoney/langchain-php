<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every class-shaped name the project's OWN documentation cites must be declared in the tree.
 *
 * Iteration 422 found `LangChain\Tests\Integration\GraphAndCheckpointIntegrationTest` — the guard the
 * loop's own hard rules name for BOTH wire-shape defects that broke real releases — was not autoloadable,
 * because `autoload-dev` mapped only `tests/Unit/`. Iteration 423 swept the repository's documentation for
 * every class-shaped identifier and found 11, all resolving.
 *
 * **But the class 422 found is named in the LOOP'S INSTRUCTIONS, not in `HANDOFF.md` or
 * `PORT_STATUS.md`, which is why it never appeared in that sweep.** Every other doc-consistency guard
 * (`DocsMatchRealityTest`, `MarkdownTableShapeTest`) reads repository files, so a class named only
 * outside the repository is invisible to all of them.
 *
 * This guard closes the loop from the inside: the documentation the repository controls now lists the
 * classes the loop's instructions rely on, so they are checkable by the same mechanism as everything
 * else. A name added to `## Guards` below that stops resolving fails here rather than in a caller's
 * autoloader.
 */
#[CoversNothing]
final class DocsNamedClassesExistTest extends TestCase
{
    /**
     * Classes the loop's own hard rules name. Listed here because the rules live OUTSIDE this
     * repository, so no repository-side guard can see them.
     */
    private const NAMED_OUTSIDE = [
        'GraphAndCheckpointIntegrationTest',
        'TransportIntegrationTest',
        'DocsMatchRealityTest',
        'DocblockParamTest',
        'FixesAreDocumentedTest',
    ];

    /** @return array<string, true> every class and trait declared under src/ and tests/. */
    private static function declared(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        $root = \dirname(__DIR__, 2);
        foreach (['src', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($f->getPathname());
                preg_match_all('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait)\s+(\w+)/m', $src, $m);
                foreach ($m[1] as $name) {
                    $map[$name] = true;
                }
            }
        }

        return $map;
    }

    #[DataProvider('namedOutsideProvider')]
    public function testAClassNamedOutsideTheRepositoryStillExists(string $name): void
    {
        self::assertArrayHasKey(
            $name,
            self::declared(),
            $name . ' is named in this loop\'s hard rules but is not declared anywhere in src/ or tests/',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function namedOutsideProvider(): iterable
    {
        foreach (self::NAMED_OUTSIDE as $n) {
            yield $n => [$n];
        }
    }

    /** Every class-shaped name the repository's own docs cite must also resolve. */
    public function testEveryClassShapedNameInOurOwnDocsResolves(): void
    {
        $root = \dirname(__DIR__, 2);
        $declared = self::declared();
        $checked = 0;

        foreach (['HANDOFF.md', 'PORT_STATUS.md'] as $doc) {
            $path = $root . '/' . $doc;
            if (!is_file($path)) {
                continue;
            }
            preg_match_all('/`([A-Z][A-Za-z0-9]*(?:Test|Fixture|Fakes?|Stub)\b)`/', (string) file_get_contents($path), $m);
            foreach (array_unique($m[1]) as $name) {
                ++$checked;
                self::assertArrayHasKey(
                    $name,
                    $declared,
                    $doc . ' names ' . $name . ', which is not declared anywhere in src/ or tests/',
                );
            }
        }

        // Assert unconditionally AFTER the loop: if a future edit emptied the docs the test would
        // otherwise assert nothing and be reported risky — the trap 329, 413 and 422 each hit.
        self::assertGreaterThan(0, $checked, 'no class-shaped names were found in the docs to check');
    }
}
