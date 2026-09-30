<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the declared PHP floor.
 *
 * `composer.json` declares `php: >=8.2` and CI runs a matrix of 8.2 / 8.3 / 8.4.
 * A function added in a later release is a **hard fatal** on the older members of
 * that matrix, and it is completely invisible locally: development runs PHP 8.5,
 * where every one of these exists.
 *
 * That is not hypothetical. `Schema::validatesOnlyStrings()` called
 * `array_all()` (PHP 8.4+), which would have failed two of the three supported
 * CI jobs the moment a test reached that branch. Found by a reviewer's model
 * reading the file, not by the suite, because the suite runs on 8.5.
 *
 * This test is a static scan rather than a runtime probe because the whole point
 * is that the function *is* available on the machine running the suite.
 */
final class PhpVersionCompatibilityTest extends TestCase
{
    /**
     * Functions introduced after the declared floor, mapped to the version that
     * added them.
     *
     * @var array<string, string>
     */
    private const TOO_NEW = [
        'array_all' => '8.4',
        'array_any' => '8.4',
        'array_find' => '8.4',
        'array_find_key' => '8.4',
        'array_first' => '8.4',
        'array_last' => '8.4',
        'json_validate' => '8.3',
        'mb_str_pad' => '8.3',
        'str_increment' => '8.3',
        'str_decrement' => '8.3',
        'mb_trim' => '8.3',
        'output_reset_quote_style' => '8.3',
        'ldap_connect_wallet' => '8.4',
        'request_parse_body' => '8.4',
        'http_get_try_response_status' => '8.4',
    ];

    private const FLOOR = '8.2';

    public function testTheDeclaredFloorIsSupported(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        self::assertSame('>=' . self::FLOOR, $composer['require']['php']);
    }

    /**
     * No source file may call a function newer than the declared floor.
     */
    public function testNoSourceFileUsesAFunctionNewerThanTheFloor(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];

        foreach ($this->phpFiles($root) as $file) {
            $source = (string) file_get_contents($file);
            // Strip comments and strings so a mention in prose or a docblock is
            // not mistaken for a call.
            $code = $this->stripNonCode($source);

            foreach (self::TOO_NEW as $fn => $since) {
                if (version_compare($since, self::FLOOR, '<=')) {
                    continue; // available on the floor, fine
                }

                if (preg_match('/(?<![\w\\\\$>])' . preg_quote($fn, '/') . '\s*\(/', $code) === 1) {
                    $offenders[] = sprintf('%s uses %s() (PHP %s+)', $this->rel($file), $fn, $since);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These call functions newer than the declared PHP " . self::FLOOR . " floor and "
            . "will fatal on the older CI matrix jobs:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private function rel(string $path): string
    {
        return str_replace(dirname(__DIR__, 2) . '/', '', $path);
    }

    /**
     * Remove comments and string literals.
     *
     * Needed because this codebase documents its own constraints in prose: a
     * comment saying "`array_all()` landed in PHP 8.4" is not a call to it, and
     * without stripping, the guard would flag its own explanation.
     */
    private function stripNonCode(string $source): string
    {
        // Block comments, then line comments, then quoted strings. Order
        // matters: a // inside a block comment must not restart line mode.
        $patterns = [
            '#/\*.*?\*/#s',
            '#//[^\n]*#',
            '#\'(?:\\\\.|[^\'\\\\])*\'#s',
            '#"(?:\\\\.|[^"\\\\])*"#s',
            '#<<<\s*\'?\'?([A-Z_]+)\'?\n.*?\n\1;#s',
        ];

        $out = preg_replace($patterns, '', $source);

        return $out ?? $source;
    }
}
