<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the order `pendingWrites` comes back in, because a resume applies them in that order.
 *
 * WHY THIS TEST EXISTS, since the ordering is currently CORRECT and nothing was broken: the query's
 * `ORDER BY pw.task_id, pw.idx` sits BESIDE `json_group_array(...)`, and in SQLite an `ORDER BY` beside an
 * aggregate is INERT — it does not order the aggregate's output. Measured on a scratch table, with and
 * without an index on `(task_id, idx)`: insertion order both times. The real query is a CORRELATED
 * subquery over `checkpoints`, and its plan happens to emit rows already grouped by `(task_id, idx)`.
 *
 * So the ordering is a property of the EXECUTION PLAN, not of the SQL. If SQLite ever picks a different
 * plan — a different version, a different index, a larger table that stops using the index — the ordering
 * silently becomes insertion order and a resume replays one task's writes after another's, with no error
 * anywhere.
 *
 * This test is that canary. It writes the tasks OUT OF ORDER on purpose, so a plan that stops ordering
 * fails here rather than in a user's checkpoint.
 *
 * Note the entry shape: `pendingWrites` entries are LISTS — `[task_id, channel, value]` — not maps.
 */
#[CoversClass(SqliteSaver::class)]
final class PendingWriteOrderTest extends TestCase
{
    public function testPendingWritesComeBackGroupedByTaskThenIndex(): void
    {
        $saver = SqliteSaver::fromConnString(':memory:');

        $checkpoint = new Checkpoint(
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue1'],
            channelVersions: ['someKey2' => 1],
            versionsSeen: ['someKey3' => ['someKey4' => 1]],
        );

        // `put()` returns the config carrying `configurable.checkpoint_id`, which `getTuple()` requires.
        $config = $saver->put(['thread_id' => 't1'], $checkpoint);

        // taskB FIRST — the reverse of the expected order. `putWrites()` takes [channel, value] pairs
        // under ONE task id and derives `idx` from position, so interleaving takes two calls.
        $saver->putWrites($config, [['b0', 'x'], ['b1', 'x']], 'taskB');
        $saver->putWrites($config, [['a0', 'x'], ['a1', 'x'], ['a2', 'x']], 'taskA');

        $tuple = $saver->getTuple($config);

        self::assertNotNull($tuple, 'the checkpoint was not found');

        $pending = $tuple->pendingWrites ?? [];
        self::assertCount(5, $pending);

        // Entries are [task_id, channel, value].
        $keys = array_map(
            static fn (array $w): string => $w[0] . '/' . $w[1],
            $pending,
        );

        self::assertSame(
            ['taskA/a0', 'taskA/a1', 'taskA/a2', 'taskB/b0', 'taskB/b1'],
            $keys,
            'pending writes must be grouped by task_id then idx; if this fails the query plan changed, '
            . 'and the ORDER BY beside json_group_array will not fix it - the subquery form is needed',
        );
    }
}
