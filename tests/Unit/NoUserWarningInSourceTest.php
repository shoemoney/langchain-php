<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `src/` must not raise `E_USER_WARNING`.
 *
 * This package's phpunit.xml sets `failOnWarning="true"`. That makes an
 * `E_USER_WARNING` raised by library code indistinguishable from a test failure
 * — so a NOTICE upstream writes with `console.warn` becomes a broken build.
 *
 * Measured, three times, in three places:
 *
 *   * `TextSplitter::warnOversizedChunk` — `chunkSize: 3` over a long fragment
 *     printed the notice and exited 1. The library could not be tested for its
 *     own documented behaviour.
 *   * `Algorithm` dropping an invalid pending-send packet, and dropping a
 *     `Send` naming an unknown node. Upstream warns and continues for both
 *     (algo.ts:860-867) with the same messages.
 *   * Each of those had driven a test to install a `set_error_handler` purely to
 *     trap the warning the code under test raised on purpose.
 *
 * `E_USER_NOTICE` is deliberately ALLOWED, and `PregelRunner` uses it for its
 * retry log. Measured: an `E_USER_NOTICE` exits 0 under this configuration,
 * because `failOnNotice` is not set. Blanket-forbidding `trigger_error` would
 * have been wrong — the severity choice there is deliberate and does not break
 * anything. This guard therefore names the one level that does.
 *
 * Comments and string literals are stripped before the scan: the explanation
 * of this very rule quotes `trigger_error` and `E_USER_WARNING`, and prose about
 * a forbidden call is not a forbidden call.
 */
final class NoUserWarningInSourceTest extends TestCase
{
    public function testNoSourceFileRaisesAUserWarning(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());

            // Strip comments first, then string literals: a guard that trips on
            // its own explanation is a guard that gets switched off.
            $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
            $src = (string) preg_replace('!//[^\n]*!', '', $src);
            $src = (string) preg_replace(
                "/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/",
                "''",
                $src
            );

            if (!str_contains($src, 'trigger_error')) {
                continue;
            }
            foreach (explode("\n", $src) as $i => $line) {
                if (str_contains($line, 'trigger_error') && str_contains($line, 'E_USER_WARNING')) {
                    $offenders[] = str_replace(
                        $root . '/',
                        '',
                        $f->getPathname() . ':' . ($i + 1) . '  ' . trim($line),
                    );
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "src/ must not raise E_USER_WARNING — failOnWarning makes it a test failure, "
            . "where upstream writes the same condition with console.warn. Use "
            . "LangChain\\Utils\\Notice::record() instead.\n  " . implode("\n  ", $offenders),
        );
    }

    public function testTheAllowedNoticeLevelReallyDoesNotFailTheSuite(): void
    {
        // Pins the premise of the rule above. If `failOnNotice` is ever added to
        // phpunit.xml, `PregelRunner`'s E_USER_NOTICE becomes a failure too and
        // this guard is under-enforcing.
        $xml = (string) file_get_contents(dirname(__DIR__, 2) . '/phpunit.xml');

        self::assertStringContainsString('failOnWarning="true"', $xml);
        self::assertStringNotContainsString(
            'failOnNotice="true"',
            $xml,
            'failOnNotice is now set, so E_USER_NOTICE fails the suite too — PregelRunner needs '
            . 'converting to Notice::record() and this guard should forbid it.',
        );
    }
}
