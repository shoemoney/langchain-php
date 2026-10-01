<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A `catch` block in src/ must contain something.
 *
 * A swallowed error is invisible to every other signal this project has: the suite stays green, the
 * method returns a plausible value, and the failure surfaces somewhere else entirely. That is the
 * "a green suite proves less than it appears" rule pointed straight at `catch`.
 *
 * **The rule is deliberately narrow: a catch whose body holds only a COMMENT is allowed.** A comment is
 * the declaration of intent, and the port has a correct instance of exactly that —
 * `JsonUtils::strictParsePartialJson()` catches `\JsonException` and falls through to the partial parser,
 * which is faithful to upstream `utils/json.ts:35-38`:
 *
 *     export function strictParsePartialJson(s: string): unknown {
 *       try { return JSON.parse(s); } catch { ... }
 *
 * So the test forbids a catch that is EMPTY IN FULL — no code and no explanation — and permits one that
 * explains itself. An empty catch with no comment is a decision nobody recorded.
 *
 * **This uses `token_get_all()`, not a regex, and that is the whole point.** Iteration 427 wrote three
 * ad-hoc regex detectors and every one returned 100% false positives on first contact, because a regex
 * cannot tell a `catch` whose body is a comment from one whose body is nothing. The same tokenizer that
 * separates those two cases here reported 11 comment-only catches as NOT empty, which a regex would have
 * listed alongside real swallowed errors.
 *
 * Scoped to src/ deliberately: test fixtures legitimately swallow a throw they are provoking (see
 * `RetryPredicateTest::testEagerAnthropicRetriesOnlyWhatIsWorthRetrying`, whose empty catch is the
 * assertion), and policing tests would punish the exact technique used to provoke an error path.
 */
#[CoversNothing]
final class NoSilentlySwallowedErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function srcFilesProvider(): iterable
    {
        $root = \dirname(__DIR__, 2) . '/src';
        foreach (self::phpFilesIn($root) as $file) {
            yield $file => [$file, 0, ''];
        }
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
     * Every catch block in the file, as [line, bodySource].
     *
     * @return list<array{int, string}>
     */
    private static function catchesIn(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $count = \count($tokens);
        $out = [];

        for ($i = 0; $i < $count; ++$i) {
            if (!\is_array($tokens[$i]) || $tokens[$i][0] !== T_CATCH) {
                continue;
            }

            $open = null;
            $depth = 0;
            for ($j = $i + 1; $j < $count; ++$j) {
                $ch = \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($ch === '(') {
                    ++$depth;
                } elseif ($ch === ')') {
                    --$depth;
                    if ($depth === 0) {
                        ++$j;
                        break;
                    }
                }
            }
            for (; $j < $count; ++$j) {
                $ch = \is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if ($ch === '{') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }

            $brace = 0;
            $end = null;
            for ($k = $open; $k < $count; ++$k) {
                $ch = \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                if ($ch === '{') {
                    ++$brace;
                } elseif ($ch === '}') {
                    --$brace;
                    if ($brace === 0) {
                        $end = $k;
                        break;
                    }
                }
            }
            if ($end === null) {
                continue;
            }

            $body = '';
            for ($k = $open + 1; $k < $end; ++$k) {
                $body .= \is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            }

            $out[] = [$tokens[$i][2], trim($body)];
            $i = $end;
        }

        return $out;
    }

    public function testNoCatchBlockInSrcIsEmptyWithoutComment(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach (self::phpFilesIn(\dirname(__DIR__, 2) . '/src') as $file) {
            foreach (self::catchesIn($file) as [$line, $body]) {
                ++$scanned;
                if ($body === '') {
                    $offenders[] = $file . ':' . $line;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "these catch blocks are empty in full — no code and no explanation:\n  "
                . implode("\n  ", $offenders)
                . "\nEither handle the error, rethrow it, or leave a comment saying why swallowing it is"
                . ' correct. A catch that is empty AND unexplained is a swallowed error no test can see.',
        );

        // Unconditional, and deliberately BEFORE nothing: if tokenizing ever returned zero catches the
        // loop above would assert nothing and this test would pass having checked nothing at all — the
        // vacuous-guard trap 329, 413, 422 and 424 each had to defend against individually.
        self::assertGreaterThan(
            0,
            $scanned,
            'no catch blocks were found under src/ — the tokenizer pass is not seeing the code it should',
        );
    }

    /**
     * The tokenizer must actually distinguish a commented catch from an empty one.
     *
     * A detector that cannot tell those two apart is worse than no detector, because it produces
     * confident counts. This asserts the distinction on a literal, so a future refactor of the walker
     * that collapsed both into one case fails here instead of quietly flagging every documented
     * fallthrough in the port.
     */
    public function testACommentedCatchIsNotTreatedAsEmpty(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'swallow') . '.php';
        self::assertIsString($file);
        file_put_contents($file, <<<'PHPX'
            <?php
            try { risky(); } catch (\RuntimeException) {
                // deliberate: fall through to the partial parser
            }
            try { risky(); } catch (\RuntimeException) {
            }
            PHPX);

        $found = self::catchesIn($file);
        unlink($file);

        self::assertCount(2, $found, 'the walker lost a catch block');
        self::assertNotSame('', $found[0][1], 'a catch holding only a comment must not count as empty');
        self::assertSame('', $found[1][1], 'a catch with no body at all must count as empty');
    }
}
