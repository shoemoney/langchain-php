<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The dead-code question must be answered with referrer counts, not directory counts.
 *
 * `advisory.py::inventory()` attributes a test file to a src namespace by its PATH.
 * That makes the "test files" column a statement about where tests are filed, not
 * about what they exercise — so a shared abstraction referenced from four namespaces
 * reads as untested however many tests touch it.
 *
 * The consequence was not subtle. `claude-fable-5-1` §8 reported
 * `LangChain\Schema 4 src / 186 lines / 0 test files` as evidence for a dead-code
 * verdict, and suggested "delete or merge any with zero non-test referrers". Measured:
 * the four classes carry **3, 9, 13 and 6** referrers and all four are covered by
 * tests (`Document` by 11 test files, `PromptValue` by 5).
 *
 * WORTH KEEPING: the brief ALREADY warned about this, in three paragraphs, naming
 * `LangChain\Utils\Testing` as the example of a 0 that means nothing. The reviewer
 * read the warning and used the number anyway. That is the third time this project
 * has learned the same thing about prose — a rule enforced by attention is a habit,
 * not a mechanism — so the fix is to supply the number rather than the caution.
 *
 * These tests pin the new instrument's two load-bearing properties: it counts
 * referrers from CODE, and it agrees with the tree.
 */
final class ReferenceCountInstrumentTest extends TestCase
{
    private const ADVISORY = '.loop/advisory.py';

    public function testTheInstrumentExists(): void
    {
        self::assertFileExists(self::root() . '/' . self::ADVISORY);
    }

    /**
     * The instrument must be WIRED into the brief, not merely defined.
     *
     * Caught by mutation: replacing `reference_counts()` with `{}` in `brief()`
     * left every other test green, because they all assert that the helper EXISTS.
     * A function nothing calls is the `declared-but-never-invoked` defect — the
     * whole column simply disappears from the brief and the reviewer is back to
     * inferring coverage from a directory count, with no test objecting. So this
     * asserts the CALL SITE, which is the part that was missing.
     */
    public function testTheInstrumentIsCalledWhenTheBriefIsBuilt(): void
    {
        $source = (string) file_get_contents(self::root() . '/' . self::ADVISORY);

        self::assertMatchesRegularExpression(
            '/refs\s*=\s*reference_counts\(\)/',
            $source,
            'brief() must compute the referrer counts; a defined-but-uncalled instrument is invisible',
        );
        self::assertMatchesRegularExpression(
            '/\{least_referenced\(refs/',
            $source,
            'the brief must RENDER the least-referenced table, not merely compute the counts',
        );
        self::assertMatchesRegularExpression(
            '/orphans\s*=\s*sum\(/',
            $source,
            'the brief must state how many classes have zero referrers',
        );
    }

    /**
     * `LangChain\Schema` is NOT dead, and the numbers are pinned.
     *
     * This is the exact namespace an advisory recommended deleting. The test fails if
     * a future edit removes or strands any of the four, so the recommendation cannot
     * be re-derived from a directory count.
     */
    public function testTheSchemaNamespaceIsNotDeadCode(): void
    {
        $expected = [
            'PromptValue' => 5,
            'StringPromptValue' => 2,
            'Document' => 5,
            'ChatPromptValue' => 1,
        ];

        foreach ($expected as $class => $minTests) {
            $path = self::root() . '/src/LangChain/Schema/' . $class . '.php';
            self::assertFileExists($path, $class . ' is a live port, not dead code');

            self::assertGreaterThanOrEqual(
                1,
                $this->codeReferrers($class, $path),
                $class . ' has no code referrer anywhere in src/ or tests/ — that is what dead looks like',
            );

            self::assertGreaterThanOrEqual(
                $minTests,
                $this->testFilesReferencing($class),
                $class . ' must remain covered by tests',
            );
        }
    }

    /**
     * The three KNOWN orphans must stay documented, not silently deleted.
     *
     * Deleting a faithful port of something upstream has, on the grounds that nothing
     * calls it *today*, removes work instead of completing it — the mistake that had
     * `pipeTo()` removed for the stronger reason of also being invented. HANDOFF now
     * carries a table of them; if a future iteration wires one up or removes one
     * deliberately, that table must change with it.
     */
    public function testKnownOrphansAreDocumentedInHandoff(): void
    {
        $handoff = (string) file_get_contents(self::root() . '/HANDOFF.md');

        self::assertStringContainsString(
            'Ported but not yet wired',
            $handoff,
            'unreferenced-but-ported classes must be listed, so "no referrers" is a decision on record',
        );

        foreach (['BaseToolkit', 'FakeTool', 'Observable'] as $class) {
            self::assertStringContainsString(
                $class,
                $handoff,
                $class . ' is an unreferenced port and must be accounted for in HANDOFF',
            );
        }
    }

    /**
     * The instrument must count CODE referrers.
     *
     * Comments are stripped before counting, because `Runnable.php` names two
     * LangGraph classes in a docblock and that is prose about the architecture, not
     * a dependency. A count that included comments reported `LangChain -> LangGraph: 1`
     * for dozens of iterations and produced a ranked finding about an inverted
     * dependency that does not exist.
     */
    public function testReferrerCountingIgnoresComments(): void
    {
        // The exact case dep_edges() got wrong: `Runnable.php` names two LangGraph
        // classes in a docblock explaining why they implement batch() themselves.
        // Asserted FILE-LOCALLY, because that is the property — prose in this file
        // is not a reference — and a whole-tree count would also pick up the real
        // code references from LangGraph's own files, hiding it.
        $raw = (string) file_get_contents(self::root() . '/src/LangChain/Runnables/Runnable.php');

        foreach (['ChannelWrite', 'RunnableBranchWriter'] as $class) {
            self::assertStringContainsString(
                $class,
                $raw,
                $class . ' must still be named in Runnable.php — that prose is what the old scan miscounted',
            );
            self::assertStringNotContainsString(
                $class,
                $this->stripComments($raw),
                $class . ' must remain prose in Runnable.php; if it became code, src/LangChain would '
                . 'genuinely depend on src/LangGraph and the layering guard must fail',
            );
        }

        $source = (string) file_get_contents(self::root() . '/' . self::ADVISORY);
        self::assertMatchesRegularExpression(
            '/def reference_counts\(/',
            $source,
            'the referrer-counting instrument must exist by name',
        );
        self::assertStringContainsString(
            'strip_php_comments',
            $source,
            'referrer counting must strip comments, or prose becomes a dependency',
        );
    }

    /**
     * The instrument must declare what a zero means.
     *
     * A `0` referrer count is a QUESTION — the class may be reached by a name
     * string, a factory or serialization. Rendering it as a verdict is what turns a
     * measurement into a false dead-code finding, so the brief has to say so in the
     * same breath as the number.
     */
    public function testTheBriefFramesZeroAsAQuestionNotAVerdict(): void
    {
        $source = (string) file_get_contents(self::root() . '/' . self::ADVISORY);

        self::assertStringContainsString(
            'is a QUESTION, not a verdict',
            $source,
            'a zero-referrer row must be framed as something to check, not a deletion order',
        );
        self::assertStringContainsString(
            'ORPHAN?',
            $source,
            'the rendered table must visibly mark a zero rather than printing a bare 0',
        );
    }

    /**
     * Code (comment-stripped) referrers for a class, excluding its OWN file.
     *
     * The own-file path is a parameter rather than derived from the class name
     * because a helper that guesses where a class lives is the same mistake that
     * put five files in `LanguageModels/Chat/` instead of `Chat/OpenAI/`, and it
     * fails by counting the declaration itself as a referrer.
     */
    private function codeReferrers(string $class, string $ownPath): int
    {
        $own = realpath($ownPath);
        $n = 0;
        foreach ($this->allPhpFiles() as $file) {
            if (realpath($file) === $own) {
                continue;
            }
            if (str_contains($this->stripComments((string) file_get_contents($file)), $class)) {
                ++$n;
            }
        }

        return $n;
    }

    private function testFilesReferencing(string $class): int
    {
        $n = 0;
        foreach ($this->allPhpFiles() as $file) {
            if (!str_contains($file, '/tests/')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), $class)) {
                ++$n;
            }
        }

        return $n;
    }

    /** @return list<string> */
    private function allPhpFiles(): array
    {
        $found = [];
        foreach (['src', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $dir),
            );
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $found[] = $f->getPathname();
                }
            }
        }
        sort($found);

        return $found;
    }

    private function stripComments(string $src): string
    {
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
        $src = (string) preg_replace('#//[^\n]*#', '', $src);

        return (string) preg_replace('/#\[[^\]]*\]/s', '', $src);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}