<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pending writes must come back in (task_id, idx) order, not scan order.
 *
 * The `ORDER BY` sat OUTSIDE the `json_group_array(...)` subquery. A query whose
 * SELECT list is a single aggregate returns ONE row, so that ORDER BY ordered the
 * result — nothing — and left the order in which rows reached the aggregate
 * unspecified. Pending writes for a checkpoint could then come back scrambled, and a
 * consumer rebuilding a write sequence from them gets one that may be wrong with
 * nothing to indicate it.
 *
 * The two tasks are written in ALPHABETICAL REVERSE (`task-b` first), so a
 * scan-order read returns them the wrong way round and a sorted read returns them
 * right — the test therefore fails on the old query rather than passing by luck.
 */
#[CoversClass(SqliteSaver::class)]
final class SqlitePendingWriteOrderTest extends TestCase
{
    public function testPendingWritesComeBackOrderedByTaskThenIndex(): void
    {
        $saver = new SqliteSaver(new \PDO('sqlite::memory:'));
        $config = ['thread_id' => 'thread-order', 'checkpoint_ns' => ''];

        $returned = $saver->put(
            $config,
            new Checkpoint(v: 1, id: CheckpointId::uuid6(1), ts: '2024-04-20T17:19:07.951Z'),
            ['source' => 'loop', 'parents' => [], 'step' => 1],
        );

        // Written out of order on purpose: 'task-b' first.
        $saver->putWrites($returned, [['animals', 'dog'], ['plants', 'rose']], 'task-b');
        $saver->putWrites($returned, [['animals', 'cat']], 'task-a');

        $saved = $saver->getTuple($returned);
        self::assertNotNull($saved);

        $pairs = array_map(
            static fn (array $w): string => $w[0] . ':' . $w[1],
            $saved->pendingWrites ?? [],
        );

        self::assertSame(
            ['task-a:animals', 'task-b:animals', 'task-b:plants'],
            $pairs,
            'pending writes must be ordered by (task_id, idx) regardless of write order',
        );
    }
}
