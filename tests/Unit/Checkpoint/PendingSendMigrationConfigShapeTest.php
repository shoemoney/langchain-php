<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\SqliteSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Addressing a parent checkpoint must not cost you the routing keys.
 *
 * `migratePendingSends()` is `protected`, and it used to strip the whole
 * `configurable` map before looking up the parent's pending sends. Both shipped
 * call sites hand-build a flat config from the row's own columns, so the strip
 * was a no-op there and the suite stayed green. A subclass — or any future
 * caller — passing the config shape `put()` actually RETURNS got the routing
 * map deleted, `pendingSendsFor()` returned `[]`, and TASKS was overwritten with
 * an empty list.
 *
 * A resumed run that loses its pending sends loses every queued task, silently.
 *
 * The fixture trap here is the obvious one and it is the same shape as two
 * earlier bugs in this project: the existing test drives a FLAT config, which
 * is the one shape the bug cannot affect. So both shapes are pinned below, and
 * they must produce the SAME result — that equality is the actual contract.
 */
#[CoversClass(MemorySaver::class)]
#[CoversClass(SqliteSaver::class)]
final class PendingSendMigrationConfigShapeTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'shape-') . '.sqlite';
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /**
     * Named subclasses, because `migratePendingSends()` is `protected` and an
     * anonymous class in an unrelated scope cannot reach it.
     */
    private function probe(string $kind): OpenMemorySaver|OpenSqliteSaver
    {
        return $kind === 'memory' ? new OpenMemorySaver() : new OpenSqliteSaver($this->file);
    }

    /**
     * Seed a thread with two pending TASKS writes, then migrate a fresh v1
     * checkpoint addressed at the parent.
     */
    private function seed(MemorySaver|SqliteSaver $saver): array
    {
        $parent = new PregelCheckpoint(
            v: 1,
            id: \LangGraph\Checkpoint\CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
        );
        $config = $saver->put(
            ['thread_id' => 't1', 'checkpoint_ns' => ''],
            $parent,
            ['source' => 'loop', 'parents' => [], 'step' => 0],
        );
        $saver->putWrites(
            $config,
            [[CheckpointConstants::TASKS, 'send-1'], [CheckpointConstants::TASKS, 'send-2']],
            'task-1',
        );

        // The SAME put, so the nested shape under test is the one real callers
        // receive rather than a second, freshly minted checkpoint.
        return [$parent->id, $config['configurable']];
    }

    private function freshChild(): PregelCheckpoint
    {
        return new PregelCheckpoint(
            v: 1,
            id: \LangGraph\Checkpoint\CheckpointId::uuid6(1),
            ts: '2024-04-20T17:19:07.952Z',
            channelValues: ['seed' => 'x'],
            channelVersions: ['seed' => 1],
        );
    }

    /** @return array<string, array{0: string}> */
    public static function savers(): array
    {
        return [
            'memory' => ['memory'],
            'sqlite' => ['sqlite'],
        ];
    }

    /**
     * The nested shape — what `put()` returns — must migrate exactly as the flat
     * one does. Before the fix this produced `[]`.
     */
    #[DataProvider('savers')]
    public function testTheNestedConfigShapeStillMigratesPendingSends(string $kind): void
    {
        $saver = $this->probe($kind);
        [$parentId, $nested] = $this->seed($saver);

        self::assertArrayHasKey('thread_id', $nested, 'the point of this test is the nested shape');

        $child = $this->freshChild();
        $saver->migrate($child, ['configurable' => $nested], $parentId);

        self::assertSame(
            ['send-1', 'send-2'],
            $child->channelValues[CheckpointConstants::TASKS] ?? null,
            'a nested config must not lose the pending sends: the routing map was being unset',
        );
    }

    /**
     * And the flat shape, which is what both shipped call sites use, must be
     * unchanged. A fix that only handles the nested form would trade one bug for
     * another.
     */
    #[DataProvider('savers')]
    public function testTheFlatConfigShapeIsUnchanged(string $kind): void
    {
        $saver = $this->probe($kind);
        [$parentId, $nested] = $this->seed($saver);

        $child = $this->freshChild();
        $saver->migrate(
            $child,
            ['thread_id' => 't1', 'checkpoint_ns' => '', 'checkpoint_id' => $parentId],
            $parentId,
        );

        self::assertSame(['send-1', 'send-2'], $child->channelValues[CheckpointConstants::TASKS] ?? null);
    }

    /**
     * A v4 checkpoint is not migrated at all, in either shape.
     */
    #[DataProvider('savers')]
    public function testACurrentVersionCheckpointIsNotMigratedInEitherShape(string $kind): void
    {
        $saver = $this->probe($kind);
        [$parentId, $nested] = $this->seed($saver);

        foreach ([
            'flat' => ['thread_id' => 't1', 'checkpoint_ns' => '', 'checkpoint_id' => $parentId],
            'nested' => ['configurable' => ['thread_id' => 't1', 'checkpoint_ns' => '', 'checkpoint_id' => $parentId]],
        ] as $label => $shape) {
            $child = new PregelCheckpoint(
                v: \LangGraph\Checkpoint\CheckpointConstants::CHECKPOINT_VERSION,
                id: \LangGraph\Checkpoint\CheckpointId::uuid6(1),
                ts: '2024-04-20T17:19:07.952Z',
                channelValues: ['seed' => 'x'],
                channelVersions: ['seed' => 1],
            );
            $saver->migrate($child, $shape, $parentId);

            self::assertArrayNotHasKey(
                CheckpointConstants::TASKS,
                $child->channelValues,
                $label . ': a v' . \LangGraph\Checkpoint\CheckpointConstants::CHECKPOINT_VERSION . ' checkpoint needs no migration',
            );
        }
    }
}

/** Exposes the protected migration so a test can choose the config shape. */
final class OpenMemorySaver extends MemorySaver
{
    public function migrate(PregelCheckpoint $c, array $config, ?string $parent): void
    {
        $this->migratePendingSends($c, $config, $parent);
    }
}

/** The same, for the SQLite saver. */
final class OpenSqliteSaver extends SqliteSaver
{
    public function __construct(string $file)
    {
        parent::__construct(new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]));
    }

    public function migrate(PregelCheckpoint $c, array $config, ?string $parent): void
    {
        $this->migratePendingSends($c, $config, $parent);
    }
}
