<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A file listed in composer's `autoload.files` must tolerate being included a second time.
 *
 * PHP cannot autoload functions, so a function-only file has to be listed in `autoload.files` to be
 * available after `require vendor/autoload.php`. Composer's `files` mechanism guards its own includes,
 * but the PSR-4 class loader uses a plain `include` — so if the file's basename also looks like a class
 * name in that namespace, `class_exists()` on it makes the class loader include the file AGAIN and PHP
 * raises an uncatchable `Cannot redeclare function ...` fatal.
 *
 * Iteration 426 found this by sweeping the class-shaped names the project's own tooling cites:
 * `class_exists('LangGraph\Pregel\Interrupt')` killed the process outright, while
 * `function_exists('LangGraph\Pregel\interrupt')` returned true. **Nothing in the suite noticed, because
 * every test calls the function and none probes for the class** — the failure needs a string a
 * reflection-based caller would supply, not a call the port itself makes.
 *
 * The FQCNs are DERIVED from `composer.json` (files entries x psr-4 prefixes) rather than hand-listed.
 * A hand-maintained list here would be the same defect this loop spent iteration 310 fixing in
 * `RunnableConfig::mergeConfigs` and 412 fixing across every `batch()` implementation: a partial list
 * that reports "fine" while the next entry added is broken. Both casings are probed because macOS APFS
 * is case-insensitive, which is what makes the StudlyCase spelling reach a lowercase filename at all.
 */
#[CoversNothing]
final class FilesAutoloadRedeclarationTest extends TestCase
{
    /** @return array<string, string> composer autoload.files entries that live under a psr-4 dir. */
    private static function filesEntries(): array
    {
        $root = \dirname(__DIR__, 2);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
        $psr4 = $composer['autoload']['psr-4'] ?? [];

        $out = [];
        foreach ($composer['autoload']['files'] ?? [] as $rel) {
            if (!str_ends_with($rel, '.php')) {
                continue;
            }
            foreach ($psr4 as $prefix => $dir) {
                $dir = rtrim((string) $dir, '/') . '/';
                if (str_starts_with($rel, $dir)) {
                    $fqcn = rtrim((string) $prefix, '\\') . '\\'
                        . str_replace('/', '\\', substr($rel, \strlen($dir), -4));
                    $out[$rel] = $fqcn;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function classShapedNamesProvider(): iterable
    {
        foreach (self::filesEntries() as $rel => $fqcn) {
            $short = substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
            yield $rel . ' (exact case)' => [$fqcn];
            yield $rel . ' (StudlyCase)' => [substr($fqcn, 0, (int) strrpos($fqcn, '\\') + 1) . ucfirst($short)];
        }
    }

    #[DataProvider('classShapedNamesProvider')]
    public function testProbingAClassShapedNameOfAFilesAutoloadEntryDoesNotRedeclare(string $fqcn): void
    {
        $root = \dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true)
            . '; var_dump(class_exists(' . var_export($fqcn, true) . '));';

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes, $root);
        self::assertIsResource($proc, 'could not spawn a probe process for ' . $fqcn);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code_ = proc_close($proc);

        self::assertStringNotContainsString(
            'Cannot redeclare',
            $err . $out,
            'class_exists(' . $fqcn . ') redeclared a function: ' . $fqcn . ' names a file that is BOTH an '
                . 'autoload.files entry and a PSR-4-reachable class name, so the class loader includes it a '
                . 'second time. The 4,000+ test suite cannot see this because it only ever calls the '
                . 'function. Guard the declaration with function_exists().',
        );
        self::assertSame(0, $code_, 'probe process for ' . $fqcn . ' exited non-zero: ' . trim($err));
    }

    /** The derivation itself must not silently go empty, or every case above vanishes. */
    public function testTheDerivationFindsTheFilesAutoloadEntries(): void
    {
        self::assertNotEmpty(
            self::filesEntries(),
            'no autoload.files entry resolved to a psr-4 class name — the provider above asserts nothing',
        );
    }
}
