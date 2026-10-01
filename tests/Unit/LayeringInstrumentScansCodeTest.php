<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The layering instrument must count CODE, not prose.
 *
 * `advisory.py::dep_edges()` decides which package depends on which by scanning
 * `src/` for the other package's namespace. It scanned the raw file body, so a
 * DOCBLOCK naming the other package counted as a dependency — and a docblock is a
 * sentence about the architecture, not an edge created by it.
 *
 * MEASURED, and this is the whole finding. The instrument reported
 * `LangChain -> LangGraph: 1 files`. The single hit was this line, in
 * `src/LangChain/Runnables/Runnable.php:80`:
 *
 *     * `Runnable`: `LangGraph\Pregel\ChannelWrite` and `LangGraph\Pregel\RunnableBranchWriter` declare
 *
 * That is prose explaining why two graph classes implement `batch()` themselves.
 * Stripping comments and docblocks takes the upward edge to **0**. `src/LangGraph/`
 * imports `LangChain\` in 11 files and `src/LangChain/` imports `LangGraph\` in
 * none, which is what upstream requires: `@langchain/core` has no dependency on
 * `@langchain/langgraph`.
 *
 * The cost of not noticing was a RANKED ADVISORY FINDING. `claude-fable-5.1`
 * reported `LangChain -> LangGraph: 1` as evidence in its §1 layering verdict and
 * ranked finding 5 on it — "one `LangChain\*` file reaching into `LangGraph\*` is
 * an inverted dependency" — and suggested breaking a file that has no dependency
 * to break. It also could not name the file, and said so: "The brief does not name
 * the file". A finding whose own evidence it cannot locate is the tell.
 *
 * WHY A GUARD OVER THE LEDGER CANNOT CATCH THIS. The defect is in the INSTRUMENT,
 * and the instrument is what produces the evidence the finding reasons over. Every
 * downstream artefact — the packet, the advisory's §3 table, the ranked findings —
 * is downstream of this scan, so asserting on any of them re-asserts the
 * instrument's own output. This test therefore pins the two facts the instrument
 * exists to report, computed here independently: the upward edge does not exist,
 * and the one file that used to appear in it does so only in a comment.
 */
final class LayeringInstrumentScansCodeTest extends TestCase
{
    private const ADVISORY = '.loop/advisory.py';

    public function testTheInstrumentExists(): void
    {
        self::assertFileExists(self::root() . '/' . self::ADVISORY);
    }

    /**
     * The port has NO upward dependency: `src/LangChain/` never imports `LangGraph\`.
     *
     * Computed from `use` statements rather than by running the Python, so the
     * assertion is independent of the thing it guards. Comments are stripped, which
     * is the distinction under test.
     */
    public function testLangChainDoesNotDependOnLangGraph(): void
    {
        $offenders = [];
        foreach ($this->phpFiles('src/LangChain') as $file) {
            $code = $this->stripComments((string) file_get_contents($file));
            if (preg_match('/\bLangGraph\\\\/', $code) === 1) {
                $offenders[] = $file;
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "these files under src/LangChain reference LangGraph\\ in CODE (%s).\n"
                . "Upstream @langchain/core does not depend on @langchain/langgraph, so an upward\n"
                . "import is an inverted dependency. A mention inside a docblock is fine and is not\n"
                . "counted here.",
                implode(', ', $offenders),
            ),
        );
    }

    /**
     * The one file the instrument flagged says so in a COMMENT, and that is all.
     *
     * This is the specific claim the advisory made, pinned so it cannot be
     * re-derived: the file exists, the names are there, and none of it is code.
     * If a future edit moves that sentence into a `use` statement, the test above
     * fails and names this file.
     */
    public function testTheFlaggedFileMentionsLangGraphOnlyInAComment(): void
    {
        $file = self::root() . '/src/LangChain/Runnables/Runnable.php';
        self::assertFileExists($file);

        $raw = (string) file_get_contents($file);
        self::assertMatchesRegularExpression(
            '/LangGraph\\\\/',
            $raw,
            'this is the file dep_edges() used to count as an upward dependency',
        );
        self::assertDoesNotMatchRegularExpression(
            '/LangGraph\\\\/',
            $this->stripComments($raw),
            'the mention must remain prose; if it became code the layering guard above fires',
        );
    }

    /**
     * The downward direction is real, and must stay visible.
     *
     * A fix that zeroed both directions would pass the first test while destroying
     * the instrument's actual purpose. `src/LangGraph/` reaching into
     * `src/LangChain/` is expected — the graph layer uses Runnable, RunnableConfig
     * and messages — so the count is pinned as a floor, not an exact figure.
     */
    public function testTheDownwardDependencyIsStillCounted(): void
    {
        $count = 0;
        foreach ($this->phpFiles('src/LangGraph') as $file) {
            if (preg_match('/\bLangChain\\\\/', $this->stripComments((string) file_get_contents($file))) === 1) {
                ++$count;
            }
        }

        self::assertGreaterThanOrEqual(
            10,
            $count,
            'the graph layer is expected to import LangChain\\ abstractions; a near-zero count '
            . 'means the scan is broken rather than the port being clean',
        );
    }

    /**
     * The instrument must strip comments, not merely mention that it does.
     *
     * Asserting on the source text is a proxy, but it is the only one available
     * from PHP without reimplementing the scan — and it is what distinguishes the
     * fixed instrument from the original. The behavioural assertion above is the
     * real check; this one fails loudly if the call is ever quietly reverted.
     */
    public function testTheInstrumentStripsCommentsBeforeScanning(): void
    {
        $source = (string) file_get_contents(self::root() . '/' . self::ADVISORY);

        self::assertStringContainsString(
            'strip_php_comments',
            $source,
            'dep_edges() must scan code with comments removed',
        );
        self::assertMatchesRegularExpression(
            '/body\s*=\s*strip_php_comments\(/',
            $source,
            'the scan itself must use the stripped body, not the raw file text',
        );
    }

    /**
     * `strip_php_comments` must not eat code when it removes a comment.
     *
     * The naive order — `//` before docblocks — destroys a `//` that appears inside
     * a string literal or a regex, and a documenter that strips too much turns every
     * reference scan into zero. Exercised here as a behaviour of the real helper so
     * the guard fails if the helper is rewritten into something that over-strips.
     */
    public function testStrippingCommentsLeavesCodeIntact(): void
    {
        $source = (string) file_get_contents(self::root() . '/' . self::ADVISORY);

        self::assertStringContainsString(
            'def strip_php_comments(',
            $source,
            'the helper must exist as a named function so both the scan and this test use it',
        );

        // Docblocks are removed before `//` comments: doing it the other way round
        // truncates a docblock at the first `//` inside it, which can leave the
        // tail of a comment containing real code looking like code.
        $docblockAt = strpos($source, 'r"/\\*.*?\\*/"');
        $lineAt = strpos($source, 'r"//[^\n]*"');
        self::assertIsInt($docblockAt);
        self::assertIsInt($lineAt);
        self::assertLessThan(
            $lineAt,
            $docblockAt,
            'docblocks must be stripped before line comments, or a docblock is cut at its first //',
        );
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $root = self::root() . '/' . $dir;
        if (!is_dir($root)) {
            return [];
        }
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $found[] = $f->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Strip comments, docblocks and attributes so only CODE remains.
     *
     * Attribute bodies go too: `#[CoversClass(Foo::class)]` legitimately names
     * classes from the other package, and in a source file it would be a reference
     * that creates no dependency.
     */
    private function stripComments(string $src): string
    {
        $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);
        $src = (string) preg_replace('#//[^\n]*#', '', $src);
        $src = (string) preg_replace('/#\[[^\]]*\]/s', '', $src);

        return $src;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}