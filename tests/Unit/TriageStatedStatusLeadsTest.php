<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stated status must be the entry's FIRST line, not its second.
 *
 * `TriageStatusMarkerTest` requires the first note of every `audit/` key to open
 * with a status marker, and it is right to. But `#OPEN` IS one of the markers, so
 * that guard passes just as happily on an entry that says `#OPEN` while the actual
 * resolution sits underneath — which is precisely the failure the guard was written
 * to prevent.
 *
 * `triage_add.py` made that unavoidable rather than merely likely. It stamped a
 * brand-new `audit/` key with `#OPEN - status not stated` and only then appended the
 * caller's notes, so `--stdin KEY --status=RESOLVED` produced:
 *
 *     #OPEN - status not stated; set it when the work closes.
 *     #RESOLVED - <the actual note>
 *
 * The status was stated, correctly, by the tool's own argument — and the entry still
 * read as open to anything that skims. Measured on this repository's real ledger,
 * three `audit/` entries were in exactly that shape, including one whose body is a
 * `#RETRACTED` and one whose body is a `#PARTLY RESOLVED`. The ledger-status-honesty
 * entry describes this as the failure "that is the same failure as everything else
 * this week", and its stated fix was to add `--status=` — which reordered the
 * caller's notes without suppressing the stamp, so the fix did not take.
 *
 * This test therefore exercises the WRITER rather than reading the ledger. A guard
 * over `triage.json` cannot see this class of defect: it is the writer that produces
 * the misleading entry, so the only instrument that can fail is the writer itself.
 */
final class TriageStatedStatusLeadsTest extends TestCase
{
    private const WRITER = '.loop/triage_add.py';

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $path) {
            @unlink($path);
        }
        $this->scratch = [];
    }

    public function testTheWriterExists(): void
    {
        self::assertFileExists(
            self::root() . '/' . self::WRITER,
            'the writer is the only instrument that can catch a writer defect',
        );
    }

    /**
     * Run the real writer against a THROWAWAY copy of the ledger.
     *
     * The copy matters twice over: the writer is the loop's sanctioned path into
     * `triage.json`, so a test that used the real file would mutate the repository
     * to prove a point, and a test that mocked the writer would be testing the mock.
     *
     * @return array{0: int, 1: list<string>, 2: string} exit code, the entry's notes, stderr
     */
    private function runWriter(string $key, string $note, ?string $status): array
    {
        $ledger = tempnam(sys_get_temp_dir(), 'triage');
        self::assertIsString($ledger);
        $this->scratch[] = $ledger;
        file_put_contents($ledger, "{}\n");

        // The script resolves its own target from __file__, so it writes the REAL
        // triage.json rather than the copy. Repoint it at the copy explicitly.
        $script = tempnam(sys_get_temp_dir(), 'triage_add');
        self::assertIsString($script);
        $this->scratch[] = $script;
        $source = (string) file_get_contents(self::root() . '/' . self::WRITER);
        // Match the WHOLE assignment, not up to the first ')' — the expression
        // contains `__file__)`, so a `[^)]*` body stops early and leaves a
        // truncated statement behind.
        $source = preg_replace(
            '/^TRIAGE = .*$/m',
            'TRIAGE = ' . var_export($ledger, true),
            $source,
            1,
        ) ?? $source;
        self::assertStringContainsString(
            'TRIAGE = ' . var_export($ledger, true),
            $source,
            'the writer copy must actually be repointed at the scratch ledger',
        );
        file_put_contents($script, $source);

        $out = (string) shell_exec(sprintf(
            'printf %s | python3 %s --stdin %s%s 2>&1; echo "RC=$?"',
            escapeshellarg($note . "\n"),
            escapeshellarg($script),
            escapeshellarg($key),
            $status === null ? '' : ' --status=' . escapeshellarg($status),
        ));

        preg_match('/RC=(\d+)/', $out, $rc);
        $notes = [];
        $decoded = json_decode((string) file_get_contents($ledger), true);
        if (is_array($decoded) && isset($decoded[$key]) && is_array($decoded[$key])) {
            $notes = array_values(array_map('strval', $decoded[$key]));
        }

        return [(int) ($rc[1] ?? -1), $notes, $out];
    }

    /**
     * The defect itself: a stated RESOLVED must not read as OPEN.
     */
    #[DataProvider('statedStatuses')]
    public function testAStatedStatusIsTheFirstNoteOfANewEntry(string $status): void
    {
        [$rc, $notes] = $this->runWriter('audit/probe-' . strtolower($status), 'A stated note.', $status);

        self::assertSame(0, $rc, 'the writer must accept a stated status');
        self::assertNotSame([], $notes, 'the entry must exist');
        self::assertStringStartsWith(
            '#' . $status,
            $notes[0],
            sprintf(
                'the entry LEADING note must state %s. A status buried under the auto-stamp is '
                . 'invisible to a skimming reader, which is the whole failure this guards.',
                $status,
            ),
        );
        self::assertStringNotContainsString(
            'status not stated',
            $notes[0],
            'the auto-stamp claims no status was stated, but the caller stated one',
        );
    }

    /** @return iterable<string, array{string}> */
    public static function statedStatuses(): iterable
    {
        yield 'resolved' => ['RESOLVED'];
        yield 'retracted' => ['RETRACTED'];
        yield 'closed' => ['CLOSED'];
        yield 'partly' => ['PARTLY'];
        yield 'open' => ['OPEN'];
    }

    /**
     * The original guarantee must survive: a note with NO stated status still gets
     * the auto-stamp.
     *
     * Without this the fix would be "delete the stamp", which satisfies every case
     * above while deleting the protection the stamp was added for — a bare note
     * genuinely does not state a status, and `TriageStatusMarkerTest` would then
     * have nothing to check.
     */
    public function testABareNoteStillGetsTheAutoStamp(): void
    {
        [$rc, $notes] = $this->runWriter('audit/probe-bare', 'A bare note.', null);

        self::assertSame(0, $rc);
        self::assertNotSame([], $notes);
        self::assertStringStartsWith('#OPEN', $notes[0]);
        self::assertStringContainsString('status not stated', $notes[0]);
    }

    /**
     * A note that already carries its own marker is not double-prefixed.
     *
     * The writer's guard is `not notes[0].startswith("#")`, so a caller who writes
     * `#RESOLVED - ...` by hand must get exactly that, not `#RESOLVED - #RESOLVED - ...`.
     */
    public function testAnAlreadyMarkedNoteIsNotDoublePrefixed(): void
    {
        [$rc, $notes] = $this->runWriter(
            'audit/probe-marked',
            '#RESOLVED - already marked by hand.',
            'RESOLVED',
        );

        self::assertSame(0, $rc);
        self::assertSame('#RESOLVED - already marked by hand.', $notes[0] ?? '');
    }

    /**
     * No entry in the REAL ledger may be in the shape the defect produces.
     *
     * A guard over the artifact as well as the writer: fixing the writer does not
     * repair the three entries already written in the misleading shape, and those are
     * the ones a reader is actually misled by.
     */
    public function testTheRealLedgerHasNoEntryBuriedUnderTheAutoStamp(): void
    {
        $path = self::root() . '/.loop/triage.json';
        self::assertFileExists($path);

        /** @var array<string, list<string>> $triage */
        $triage = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $buried = [];
        foreach ($triage as $key => $notes) {
            if (!str_starts_with((string) $key, 'audit/') || $notes === []) {
                continue;
            }
            $first = (string) ($notes[array_key_first($notes)] ?? '');
            if (!str_contains($first, 'status not stated')) {
                continue;
            }
            // The stamp is honest ONLY when nothing after it states a real status.
            foreach (array_slice($notes, 1) as $note) {
                foreach (['#RESOLVED', '#RETRACTED', '#CLOSED', '#PARTLY'] as $marker) {
                    if (str_starts_with($note, $marker)) {
                        $buried[] = (string) $key . ' -> ' . substr($note, 0, 24);
                        break 2;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $buried,
            sprintf(
                "these audit entries read #OPEN while a real status sits underneath (%s).\n"
                . "Re-stamp them so the FIRST note states the status, or fix the entry.",
                implode('; ', $buried),
            ),
        );
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}