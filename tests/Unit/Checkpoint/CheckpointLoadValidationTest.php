<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A record that is PRESENT but malformed must not load as an empty checkpoint.
 *
 * Loading one produced a checkpoint with `id: ''` and no channel values, and
 * the graph resumed from blank state with no error at load time — the read-side
 * twin of refusing an unserialisable write. Upstream cannot produce an empty id
 * here: its `emptyCheckpoint()` always mints one with `uuid6(0)`.
 *
 * A genuinely ABSENT record is a different thing — a saver asking about a thread
 * with no checkpoints — and must still yield an empty checkpoint.
 */
#[CoversClass(Checkpoint::class)]
final class CheckpointLoadValidationTest extends TestCase
{
    public function testAnAbsentRecordStillLoadsAsAnEmptyCheckpoint(): void
    {
        $c = Checkpoint::fromArray([]);

        self::assertSame([], $c->channelValues);
        self::assertSame(CheckpointConstants::CHECKPOINT_VERSION, $c->v);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function corruptRecords(): iterable
    {
        yield 'channel_values is a string' => [
            ['v' => 4, 'id' => 'abc', 'ts' => '2024-01-01T00:00:00Z', 'channel_values' => 'CORRUPT'],
        ];
        yield 'channel_values is null' => [
            ['v' => 4, 'id' => 'abc', 'ts' => '2024-01-01T00:00:00Z', 'channel_values' => null],
        ];
        yield 'channel_versions is a string' => [
            ['v' => 4, 'id' => 'abc', 'ts' => '2024-01-01T00:00:00Z', 'channel_versions' => 'nope'],
        ];
        yield 'versions_seen is a scalar' => [
            ['v' => 4, 'id' => 'abc', 'ts' => '2024-01-01T00:00:00Z', 'versions_seen' => 7],
        ];
        yield 'id is not a string' => [
            ['v' => 4, 'id' => 99, 'ts' => '2024-01-01T00:00:00Z', 'channel_values' => []],
        ];
    }

    #[DataProvider('corruptRecords')]
    public function testACorruptRecordIsRefusedRatherThanLoadedBlank(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Checkpoint::fromArray($data);
    }

    public function testAVersionBeyondThisBuildIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported checkpoint version');

        Checkpoint::fromArray([
            'v' => CheckpointConstants::CHECKPOINT_VERSION + 1,
            'id' => 'abc',
            'ts' => '2024-01-01T00:00:00Z',
            'channel_values' => [],
        ]);
    }

    public function testTheCurrentVersionStillLoads(): void
    {
        $c = Checkpoint::fromArray([
            'v' => CheckpointConstants::CHECKPOINT_VERSION,
            'id' => 'abc',
            'ts' => '2024-01-01T00:00:00Z',
            'channel_values' => ['x' => 1],
        ]);

        self::assertSame(['x' => 1], $c->channelValues);
        self::assertSame('abc', $c->id);
    }

    /**
     * An older-format record missing the version still loads.
     *
     * The refusal is for corruption, not for history: a checkpoint written
     * before a field existed must remain loadable, or the guard would trade one
     * silent failure for a hard one.
     */
    public function testARecordWithoutAVersionStillLoads(): void
    {
        $c = Checkpoint::fromArray(['id' => 'abc', 'ts' => '2024-01-01T00:00:00Z', 'channel_values' => ['x' => 1]]);

        self::assertSame(CheckpointConstants::CHECKPOINT_VERSION, $c->v);
        self::assertSame(['x' => 1], $c->channelValues);
    }
}
