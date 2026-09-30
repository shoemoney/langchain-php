<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `list()` with a `checkpoint_id` must return that checkpoint ALONE.
 *
 * `MemorySaver::list()` honours `configurable['checkpoint_id']` as an only-this-
 * checkpoint filter (:102, :121). `SqliteSaver::list()` had no predicate for it at
 * all, so asking SQLite for one checkpoint's history returned the whole thread — two
 * savers, one interface, different answers, and SQLite is the production one.
 *
 * Two assertions, because the fix is trivially over-appliable: the filter must
 * NARROW, and a list() WITHOUT a checkpoint_id must still return the whole history.
 * A guard that filtered unconditionally would satisfy the first and break the second.
 */
#[CoversClass(SqliteSaver::class)]
final class SqliteOnlyCheckpointIdTest extends TestCase
{
    public function testListWithACheckpointIdReturnsOnlyThatCheckpoint(): void
    {
        $saver = new SqliteSaver(new \PDO('sqlite::memory:'));
        $config = ['thread_id' => 'thread-1', 'checkpoint_ns' => ''];

        foreach ([1 => 'one', 2 => 'two', 3 => 'three'] as $seq => $label) {
            $saver->put(
                $config,
                new Checkpoint(
                    v: 1,
                    id: CheckpointId::uuid6($seq),
                    ts: '2024-04-20T17:19:07.95' . $seq . 'Z',
                ),
                ['source' => 'loop', 'parents' => [], 'step' => $seq],
            );
        }

        // The ids are read back from a plain list() rather than from the ids
        // handed to put(): the saver generates its own, so passing one in and
        // filtering on it would test nothing but my assumption about id storage.
        $all = $saver->list($config);
        self::assertCount(3, $all, 'the whole thread comes back with no checkpoint_id');

        $wanted = $all[1]->config['configurable']['checkpoint_id'] ?? null;
        self::assertIsString($wanted, 'a stored tuple names its own checkpoint_id');

        $narrowed = $saver->list($config + ['checkpoint_id' => $wanted]);
        self::assertCount(1, $narrowed, 'a checkpoint_id must narrow the history to one row');
        self::assertSame($wanted, $narrowed[0]->config['configurable']['checkpoint_id'] ?? null);
    }
}
