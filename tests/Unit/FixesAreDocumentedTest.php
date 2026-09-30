<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `fix:` commit must be traceable to a row in PORT_STATUS.md.
 *
 * Rows carry a `<!-- fix:HASH -->` marker naming the commit they document, and
 * this walks `git log` checking each one is present. The marker is what makes
 * the check exact.
 *
 * It was not exact at first. Two earlier versions matched the commit's
 * distinctive words against the ledger's prose and required most of them to
 * appear, which sounds strict and is not: removing the `fromConnString` row
 * still passed, because `config`, `checkpoint` and `pending` occur in dozens of
 * other rows. Both mutations survived both versions. Word-matching prose is the
 * wrong mechanism — a guard that always passes is worse than none, because it
 * reads as coverage while checking nothing.
 *
 * Why this exists at all: four consecutive iterations shipped real fixes whose
 * ledger rows never landed. Each PORT_STATUS edit sat in the same shell command
 * as a `git commit -m "..."` whose message contained BACKTICKS, and zsh performed
 * command substitution on them before the heredoc holding the edit was read. The
 * commit landed, the row did not, and CI stayed green. `DocsMatchRealityTest`
 * cannot see it — it verifies the ledger's counts, not whether a fix is recorded.
 */
final class FixesAreDocumentedTest extends TestCase
{
    /** Fixes that document themselves and need no ledger row. */
    private const IGNORED_SUBJECTS = [
        'resolve',        // the sync script's own commits, covered by DocsMatchRealityTest
        'sync_docs',
        'the shell ate',  // the ledger repair itself
    ];

    public function testEveryFixCommitHasALedgerRow(): void
    {
        $root = dirname(__DIR__, 2);
        $ledger = (string) file_get_contents($root . '/PORT_STATUS.md');

        $log = (string) shell_exec(
            'cd ' . escapeshellarg($root) . ' && git log --pretty=format:%h%x09%s main 2>/dev/null'
        );

        $missing = [];
        foreach (explode("\n", $log) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, "\t")) {
                continue;
            }
            [$hash, $subject] = explode("\t", $line, 2);
            if (!str_starts_with($subject, 'fix')) {
                continue;
            }
            foreach (self::IGNORED_SUBJECTS as $ignored) {
                if (stripos($subject, $ignored) !== false) {
                    continue 2;
                }
            }
            if (!str_contains($ledger, '<!-- fix:' . $hash . ' -->')) {
                $missing[] = sprintf('%s  %s', $hash, $subject);
            }
        }

        self::assertSame(
            [],
            $missing,
            "These fix: commits have no <!-- fix:HASH --> row in PORT_STATUS.md:\n  "
            . implode("\n  ", $missing)
            . "\n\nAdd the row, then tag it with the commit's short hash.",
        );
    }

    public function testTheLedgerActuallyCarriesMarkers(): void
    {
        $root = dirname(__DIR__, 2);
        $ledger = (string) file_get_contents($root . '/PORT_STATUS.md');

        self::assertGreaterThan(
            5,
            preg_match_all('/<!-- fix:[0-9a-f]{7,40} -->/', $ledger, $m),
            'the marker scheme is in use; a ledger with none means the check below is vacuous',
        );
    }
}
