<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `@param` in a docblock must name a parameter of the signature it documents.
 *
 * Found by the reviewer in `.loop/reviews/38-inclusionai__ling-3.0-flash-vl.md` #2, which
 * read `RunnableInterface::batch()`'s SECOND docblock: it documented `$inputs` and
 * `$options` and omitted `$config` on a three-parameter signature. Invisible to every
 * static analyser, because with two adjacent PHPDoc blocks the FIRST is what tools parse
 * and the defect lives in the one a human reads.
 *
 * The sweep is exhaustive, not a sample: every method in `src/`. Four hits at the first
 * run — `batch()` (omission, fixed 235), `withFallbacks` and `generatePrompt` (name
 * mismatches, fixed 236) — and one FALSE POSITIVE that this test's parser is written to
 * exclude: `TextSplitterChunkHeaderOptions::__construct` has a default value of
 * `"(cont'd) "`, and a naive `[^)]*` parameter-list match stops at the `)` inside that
 * string and never sees the third parameter. That false positive is why the splitter below
 * tracks quoting and nesting rather than scanning to the first paren.
 */
final class DocblockParamTest extends TestCase
{
    public function testEveryDocblockParamNamesASignatureParameter(): void
    {
        $mismatches = [];
        $checked = 0;

        foreach (self::phpFilesIn('src') as $file) {
            $src = (string) file_get_contents($file);
            foreach (self::methods($src) as $m) {
                $params = self::signatureParams($m['signature']);
                if ($params === []) {
                    continue;
                }
                // A VARIADIC signature legitimately absorbs documented names:
                // `initialize(outputKeys: [...])` is valid PHP and lands in
                // `...$params`. PregelLoop::initialize documents `$outputKeys`
                // and `$interruptAfter` that way, and that is a real calling
                // convention rather than a stale docblock — renaming them
                // would delete the only description of the options. A guard
                // that flagged these would be wrong, and a guard that is
                // wrong is worse than no guard.
                if (in_array('params', $params, true) && str_contains($m['signature'], '...')) {
                    continue;
                }
                $checked++;
                preg_match_all('/@param\s+\S+\s+\$(\w+)/', $m['docblock'], $found);
                foreach ($found[1] as $documented) {
                    if (!in_array($documented, $params, true)) {
                        $mismatches[] = sprintf(
                            '%s::%s documents $%s, signature has (%s)',
                            str_replace('src/', '', $file),
                            $m['name'],
                            $documented,
                            implode(', ', $params),
                        );
                    }
                }
            }
        }

        self::assertGreaterThan(400, $checked, 'the sweep must actually cover the codebase');
        self::assertSame([], $mismatches, "docblock @param names not in the signature:\n  " . implode("\n  ", $mismatches));
    }

    /** @return list<string> */
    private static function phpFilesIn(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @return list<array{docblock: string, name: string, signature: string}>
     */
    private static function methods(string $src): array
    {
        $out = [];
        // The docblock is the LAST comment immediately above the function keyword.
        if (preg_match_all('/((?:\s*\*[^\n]*\n)+)\s*(?:(?:abstract|final|public|protected|private|static)\s+)*function\s+(\w+)\s*\(/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($m as $mm) {
                $start = $mm[0][1];
                $after = $start + strlen($mm[0][0]);
                $end = self::matchParen($src, $after - 1);
                if ($end === null) {
                    continue;
                }
                $out[] = [
                    'docblock' => $mm[1][0],
                    'name' => $mm[2][0],
                    'signature' => substr($src, $after, $end - $after + 1),
                ];
            }
        }

        return $out;
    }

    /** Index of the `)` closing the paren at $open, honouring quotes and nesting. */
    private static function matchParen(string $s, int $open): ?int
    {
        $depth = 0;
        $q = null;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($q !== null) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $q) { $q = null; }
                continue;
            }
            if ($c === "'" || $c === '"') { $q = $c; continue; }
            if ($c === '(') { $depth++; continue; }
            if ($c === ')') { $depth--; if ($depth === 0) { return $i; } }
        }

        return null;
    }

    /**
     * Split a parameter list on top-level commas only, then pull the names out of
     * promoted properties (`public string $x = ''`) and plain parameters alike.
     *
     * @return list<string>
     */
    private static function signatureParams(string $list): array
    {
        $parts = [];
        $depth = 0;
        $q = null;
        $buf = '';
        for ($i = 0, $n = strlen($list); $i < $n; $i++) {
            $c = $list[$i];
            if ($q !== null) {
                if ($c === '\\') { $buf .= $c . ($list[$i + 1] ?? ''); $i++; continue; }
                if ($c === $q) { $q = null; }
                $buf .= $c;
                continue;
            }
            if ($c === "'" || $c === '"') { $q = $c; $buf .= $c; continue; }
            if ($c === '(' || $c === '[') { $depth++; }
            if ($c === ')' || $c === ']') { $depth--; }
            if ($c === ',' && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }
        $names = [];
        foreach ($parts as $p) {
            if (preg_match('/\$(\w+)/', $p, $m)) {
                $names[] = $m[1];
            }
        }

        return $names;
    }
}
