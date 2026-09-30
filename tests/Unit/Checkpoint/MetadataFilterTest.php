<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A metadata filter must match the value that was actually stored.
 *
 * The filter compared `json_quote(json_extract(metadata, ?))` against a bound
 * `json_encode($value)`. Those come from different rules, and SQLite has no
 * boolean type: `json_extract` over a JSON `true` yields the INTEGER 1, while
 * `json_encode(true)` is the string "true". So a boolean filter could never
 * match — silently, returning zero rows rather than raising.
 *
 * Measured before the fix, against stored metadata
 * `step=7, flag=true, name=p`:
 *
 *     ['step' => 7]      -> 1        ['step' => '7'] -> 0
 *     ['flag' => true]   -> 0        ['flag' => 'true'] -> 0
 *     ['name' => 'p']    -> 1        ['source' => 'loop'] -> 1
 *
 * Both sides now go through `json_extract`, so SQLite applies the same coercion
 * to each.
 */
#[CoversClass(SqliteSaver::class)]
final class MetadataFilterTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'mdfilter-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    private function saverWithMetadata(): SqliteSaver
    {
        $saver = SqliteSaver::fromConnString($this->file);
        $saver->put(
            ['thread_id' => 't1', 'checkpoint_ns' => ''],
            new Checkpoint(v: 4, id: CheckpointId::uuid6(0), ts: '2024-04-19T17:19:07.952Z'),
            [
                'source' => 'loop',
                'parents' => [],
                'step' => 7,
                'flag' => true,
                'name' => 'p',
                'nested' => ['k' => 'v'],
            ],
        );

        return $saver;
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function matchingFilters(): array
    {
        return [
            'string' => ['name', 'p'],
            'string source' => ['source', 'loop'],
            // The regression: a boolean never matched before the fix.
            'boolean true' => ['flag', true],
            'integer' => ['step', 7],
            // An object must still compare as JSON, not as "[object Object]".
            'object' => ['nested', ['k' => 'v']],
        ];
    }

    #[DataProvider('matchingFilters')]
    public function testAStoredValueMatchesItsOwnFilter(string $key, mixed $value): void
    {
        $saver = $this->saverWithMetadata();

        self::assertCount(
            1,
            $saver->list(['thread_id' => 't1'], new CheckpointListOptions(filter: [$key => $value])),
            sprintf('filtering metadata by %s = %s must find the row it was stored with', $key, json_encode($value)),
        );
    }

    public function testAValueThatWasNotStoredDoesNotMatch(): void
    {
        $saver = $this->saverWithMetadata();

        self::assertCount(
            0,
            $saver->list(['thread_id' => 't1'], new CheckpointListOptions(filter: ['name' => 'other'])),
        );
    }

    /**
     * A number stored as a number is not the string "7", and SQLite does not
     * equate the two. Pinned so the fix is not later "improved" into a loose
     * comparison that would also start matching the wrong rows.
     */
    public function testAnIntegerStoredValueIsNotMatchedByItsStringSpelling(): void
    {
        $saver = $this->saverWithMetadata();

        self::assertCount(
            0,
            $saver->list(['thread_id' => 't1'], new CheckpointListOptions(filter: ['step' => '7'])),
            'SQLite keeps 7 and "7" distinct; loosening that would match rows it should not',
        );
    }

    public function testNoFilterReturnsTheRows(): void
    {
        self::assertCount(1, $this->saverWithMetadata()->list(['thread_id' => 't1']));
    }
}
