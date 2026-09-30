<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\TextSplitters\CharacterTextSplitter;
use LangChain\TextSplitters\TextSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An oversized chunk must be reported without failing the caller.
 *
 * Upstream calls `console.warn` — a log, and execution continues. The port used
 * `trigger_error(E_USER_WARNING)`, and this package's phpunit.xml sets
 * `failOnWarning="true"`, so a test exercising an oversized chunk failed the
 * suite. Measured before the fix: `CharacterTextSplitter(chunkSize: 3)` over
 * "aaaa bbbbbbbbbbbbbbbb" printed the notice and exited 1. The library could
 * not be tested for its own documented case — which is exactly the case that
 * fires when no separator can bring a fragment under budget.
 *
 * The notice is now recorded and readable, mirroring
 * {@see \LangChain\Tracers\BaseRunManager::handlerErrors()}, which settled the
 * same translation problem for handler failures.
 */
#[CoversClass(TextSplitter::class)]
#[CoversClass(CharacterTextSplitter::class)]
final class OversizedChunkNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        TextSplitter::clearOversizedChunkWarnings();
    }

    /**
     * The regression itself: emitting the notice must not raise. A test that
     * merely counted chunks would pass either way, because the pre-fix code
     * produced the same chunks AND the same warning — the difference is only
     * visible as a runner-level failure.
     */
    public function testTheNoticeDoesNotRaiseAPhpWarning(): void
    {
        $raised = [];
        set_error_handler(static function (int $no, string $str) use (&$raised): bool {
            $raised[] = $str;

            return true;
        });

        try {
            $splitter = new CharacterTextSplitter(chunkSize: 3, chunkOverlap: 0, separator: ' ');
            $chunks = $splitter->splitText('aaaa bbbbbbbbbbbbbbbb');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $raised, 'an oversized chunk must not raise E_USER_WARNING');
        // The behaviour being reported is unchanged: the chunk is still emitted,
        // not dropped. A fix that silenced the notice by swallowing the chunk
        // would pass the assertion above.
        self::assertCount(2, $chunks);
    }

    public function testTheNoticeIsRecordedAndReadable(): void
    {
        $splitter = new CharacterTextSplitter(chunkSize: 3, chunkOverlap: 0, separator: ' ');
        $splitter->splitText('aaaa bbbbbbbbbbbbbbbb');

        $notices = TextSplitter::oversizedChunkWarnings();
        self::assertCount(1, $notices);
        self::assertStringContainsString('Created a chunk of size 4', $notices[0]);
        self::assertStringContainsString('longer than the specified 3', $notices[0]);
    }

    /**
     * A split that fits must record nothing — otherwise the record is noise.
     */
    public function testAChunkWithinBudgetRecordsNothing(): void
    {
        $splitter = new CharacterTextSplitter(chunkSize: 40, chunkOverlap: 0, separator: ' ');
        $splitter->splitText('alpha beta gamma delta');

        self::assertSame([], TextSplitter::oversizedChunkWarnings());
    }

    public function testTheRecordCanBeCleared(): void
    {
        $splitter = new CharacterTextSplitter(chunkSize: 3, chunkOverlap: 0, separator: ' ');
        $splitter->splitText('aaaa bbbbbbbbbbbbbbbb');
        self::assertNotSame([], TextSplitter::oversizedChunkWarnings());

        TextSplitter::clearOversizedChunkWarnings();
        self::assertSame([], TextSplitter::oversizedChunkWarnings());
    }
}
