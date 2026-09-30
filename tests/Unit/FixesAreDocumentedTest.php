<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every `fix:` commit must be traceable to a row in PORT_STATUS.md.
 *
 * Rows carry a `<!-- fix:HASH -->` marker naming the commit they document.
 *
 * Getting this guard to actually GUARD took four attempts, and all four
 * failures are the same mistake, which is worth stating plainly because it has
 * now happened four times in this project:
 *
 *   1. Matched the commit's distinctive WORDS against the ledger's prose.
 *      Removing a row still passed — `config` and `checkpoint` occur in dozens
 *      of other rows. Both mutations survived.
 *   2. Required most of those words instead of one. Same result.
 *   3. Switched to exact per-commit hash markers, which works — but queried
 *      `git log main`. CI checks out a DETACHED HEAD with no local `main`
 *      ref, so git printed `fatal: ambiguous argument 'main'`, the guard read
 *      an empty log, found zero fix commits, and passed. CI reported green on a
 *      test that had verified nothing, for an entire iteration.
 *   4. This version: query `HEAD` (which exists however the tree was checked
 *      out) and, crucially, FAIL when no history is visible.
 *
 * The lesson is not "add more assertions", it is: a guard must prove it can see
 * what it is meant to see. An assertion satisfied by an EMPTY input is not a
 * passing assertion, and the failure is silent in exactly the way that matters —
 * the build is green while nothing was checked.
 */
final class FixesAreDocumentedTest extends TestCase
{
    /** Fixes that document themselves and need no ledger row. */
    private const IGNORED_SUBJECTS = [
        'resolve',        // the sync script's own commits, covered by DocsMatchRealityTest
        'sync_docs',
        'the shell ate',  // the ledger repair itself
    ];

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function fixCommits(): array
    {
        $root = dirname(__DIR__, 2);

        // HEAD, not `main`: a CI checkout is detached with no local branch, and
        // `git log main` fails there outright.
        $log = (string) shell_exec(
            'cd ' . escapeshellarg($root) . ' && git log --pretty=format:%h%x09%s HEAD 2>&1'
        );

        // A guard that cannot see the history must FAIL, not pass. Anything else
        // turns "no data" into "no problems", which is the whole bug this file
        // exists to prevent.
        self::assertNotSame(
            '',
            trim($log),
            'git log produced nothing — this guard cannot verify anything, so it fails rather than passes vacuously',
        );
        self::assertStringNotContainsString(
            'fatal:',
            $log,
            'git log failed. A CI checkout is detached, so this must query HEAD, not a branch name.',
        );

        $commits = [];
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

            // PORT_STATUS.md documents THE PORT — where the PHP diverges from
            // the TypeScript. A fix that touches only `.loop/`, `tests/` or
            // `docs/` changes the harness or the guard, not the ported
            // behaviour, and has nothing to say there.
            //
            // Scoping arrived the other way round: the first version demanded a
            // row for every `fix:` commit, and CI failed on a commit that
            // repaired this very guard. That is the opposite failure from the
            // three before it — too strict rather than vacuous — but the same
            // lesson in reverse: a guard that demands the wrong thing is as
            // useless as one that checks nothing, and it trains you to ignore
            // it.
            if (!self::touchesThePort($hash)) {
                continue;
            }

            $commits[] = [$hash, $subject];
        }

        return $commits;
    }

    /**
     * Whether a commit changed anything under `src/` — the ported code.
     */
    private static function touchesThePort(string $hash): bool
    {
        $root = dirname(__DIR__, 2);
        $files = (string) shell_exec(
            'cd ' . escapeshellarg($root)
            . ' && git show --name-only --pretty=format: HEAD 2>/dev/null ' . escapeshellarg($hash)
        );

        $paths = array_values(array_filter(array_map('trim', explode("\n", $files))));

        // A shallow clone has no parent to diff against, so `git show` diffs
        // against the EMPTY tree and returns the entire repository — measured at
        // 419 files, every one of the 229 under src/. That does not mean the
        // commit touched src/; it means the question cannot be answered, and
        // answering "yes" silently exempts nothing.
        //
        // Rather than guess, treat an implausible list as no information and let
        // the commit be judged on its subject. Same principle as the history
        // assertion above: prove you can see what you need, or say you cannot.
        $srcCount = count(array_filter($paths, static fn (string $p): bool => str_starts_with($p, 'src/')));
        if ($paths !== [] && $srcCount > 50) {
            // A whole-tree listing, not a diff. Guessing either way is wrong:
            // returning true demands a ledger row for commits that only touched
            // the harness and fails the build on them, which is exactly what
            // happened once already. Returning false exempts every port fix,
            // which is worse and quieter. So the guard stops and says so.
            self::fail(
                'git show returned ' . count($paths) . ' files (' . $srcCount . ' under src/), which is a '
                . 'whole-tree listing rather than a commit diff — the checkout is shallow. '
                . 'CI must use fetch-depth: 0 for this guard to work; see .github/workflows/ci.yml.',
            );
        }

        foreach ($paths as $f) {
            if (str_starts_with($f, 'src/')) {
                return true;
            }
        }

        return false;
    }

    public function testTheGuardCanActuallySeeHistory(): void
    {
        // A shallow CI checkout legitimately shows one commit; the point is that
        // it is ONE, not none.
        self::assertNotEmpty(
            self::fixCommits(),
            'no fix: commits found — either the history is invisible (guard is blind) or every fix is ignored',
        );
    }

    public function testEveryFixCommitHasALedgerRow(): void
    {
        $ledger = (string) file_get_contents(dirname(__DIR__, 2) . '/PORT_STATUS.md');

        $missing = [];
        foreach (self::fixCommits() as [$hash, $subject]) {
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
        $ledger = (string) file_get_contents(dirname(__DIR__, 2) . '/PORT_STATUS.md');

        self::assertGreaterThan(
            5,
            preg_match_all('/<!-- fix:[0-9a-f]{7,40} -->/', $ledger, $m),
            'the marker scheme is in use; a ledger with none means the checks above are vacuous',
        );
    }
}
