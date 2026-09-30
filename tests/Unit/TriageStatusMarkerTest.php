<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every audit entry must declare its status in its FIRST note.
 *
 * Three separate failures in this loop were all the same mistake made by different
 * things reading the ledger:
 *
 *  - iteration 60: an advisory recommended `RunnableSequence::stream()` as an open
 *    defect five iterations after it was fixed and mutation-verified;
 *  - iteration 87: the resolution had been APPENDED to the note list, so everything
 *    above it still read like an open investigation;
 *  - iteration 96: the same entry was reported a second time, for the same reason.
 *
 * A resolution recorded at the bottom of a list is invisible to anything that skims —
 * including me. So the status has to be the FIRST thing in the entry, not a
 * convention anyone has to remember.
 *
 * This guard therefore requires the first note of every `audit/` key to open with
 * `#RESOLVED`, `#OPEN` or `#CLOSED`. It does not decide which of those is correct; it
 * only refuses to let an entry state nothing at all, which is the condition that let a
 * fixed defect be recommended as open for thirty iterations.
 */
final class TriageStatusMarkerTest extends TestCase
{
    /**
     * `#RETRACTED` was added in iteration 118 after the guard fired on a TRUE
     * marker: an entry whose finding was itself disproved had been labelled
     * `#RETRACTED`, which is an accurate status and was not in the vocabulary.
     *
     * The alternative was to relabel a retracted finding as `#CLOSED`, which is
     * the same vocabulary error in the other direction — a marker chosen to
     * satisfy a check rather than to describe the work. A guard that forces
     * accurate words out of its own vocabulary will be met, eventually, by
     * choosing a word the guard prefers over the word that is true.
     */
    /**
     * `#PARTLY` was added in iteration 143 after this guard rejected it. The entry
     * is genuinely half-done — a top-up retry landed, its merge path is written to
     * be non-destructive, and it has not yet fired — so `#PARTLY RESOLVED` is an
     * accurate description, not a synonym smuggled past the check.
     *
     * The guard matched by `str_starts_with`, so a compound status could never be
     * expressed at all. That is a real limitation of a prefix test: it confuses
     * "a status this guard does not know about" with "a status this guard rejects",
     * and only the first should require a code change.
     */
    private const MARKERS = ['#RESOLVED', '#OPEN', '#CLOSED', '#RETRACTED', '#PARTLY'];

    public function testEveryAuditEntryDeclaresItsStatusFirst(): void
    {
        $path = dirname(__DIR__, 2) . '/.loop/triage.json';
        self::assertFileExists($path, 'the triage ledger is what the advisories read');

        /** @var array<string, list<string>> $triage */
        $triage = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $missing = [];
        foreach ($triage as $key => $notes) {
            if (!str_starts_with((string) $key, 'audit/') || $notes === []) {
                continue;
            }

            $first = (string) ($notes[array_key_first($notes)] ?? '');
            foreach (self::MARKERS as $marker) {
                if (str_starts_with($first, $marker)) {
                    continue 2;
                }
            }

            $missing[] = (string) $key;
        }

        self::assertSame(
            [],
            $missing,
            sprintf(
                'these audit entries do not declare a status first (%s). Prepend a note '
                . 'starting with %s, so a reader that skims sees the status before the history.',
                implode(', ', $missing),
                implode(' / ', self::MARKERS),
            ),
        );
    }
}
