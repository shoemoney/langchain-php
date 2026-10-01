<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The wire shape a checkpoint presents to another runtime.
 *
 * `channel_values`, `channel_versions` and `versions_seen` are not the same
 * kind of thing. `channel_values` holds whatever a channel produced and can
 * legitimately be a LIST, so an empty one must stay `[]`. The other two are
 * always MAPS — every consumer reads them as `[$name] => ...` — and were
 * serialising as `[]` when empty, so a fresh checkpoint handed the JavaScript
 * side an array where it expected an object.
 *
 * PHP has one array type, so the ambiguity is real; the fix is to remove it
 * where the type is KNOWN rather than everywhere, which is what makes this safe.
 */
#[CoversClass(Checkpoint::class)]
final class CheckpointWireShapeTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function wire(Checkpoint $c): array
    {
        return (array) json_decode((string) json_encode($c->toArray()), true);
    }

    private static function raw(Checkpoint $c): string
    {
        return (string) json_encode($c->toArray());
    }

    public function testAFreshCheckpointSendsMapsForTheVersionFields(): void
    {
        $empty = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(0),
            ts: '2024-01-01T00:00:00Z',
            channelValues: [],
            channelVersions: [],
            versionsSeen: [],
        );

        $raw = self::raw($empty);

        self::assertStringContainsString('"channel_versions":{}', $raw);
        self::assertStringContainsString('"versions_seen":{}', $raw);
        self::assertStringContainsString('"channel_values":[]', $raw, 'a list channel may legitimately be empty');
    }

    public function testAPopulatedCheckpointKeepsItsMapValues(): void
    {
        $c = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(1),
            ts: '2024-01-01T00:00:00Z',
            channelValues: ['x' => 1],
            channelVersions: ['x' => 1],
            versionsSeen: ['x' => ['x' => 1]],
        );

        $wire = self::wire($c);

        self::assertSame(['x' => 1], $wire['channel_versions']);
        self::assertSame(['x' => ['x' => 1]], $wire['versions_seen']);
    }

    /**
     * The inner maps of `versions_seen` are maps too.
     */
    public function testInnerVersionsSeenMapsRoundTripAsMaps(): void
    {
        $c = new Checkpoint(
            v: 1,
            id: CheckpointId::uuid6(2),
            ts: '2024-01-01T00:00:00Z',
            channelValues: ['x' => 1],
            channelVersions: ['x' => 1],
            versionsSeen: ['__interrupt__' => []],
        );

        $back = Checkpoint::fromArray(self::wire($c));

        self::assertSame(['__interrupt__' => []], $back->versionsSeen);
    }

    /**
     * Round trip is lossless on the PHP side, empty and populated alike.
     */
    public function testRoundTripIsLossless(): void
    {
        foreach ([
            ['cv' => [], 'vs' => []],
            ['cv' => ['x' => 3], 'vs' => ['x' => ['x' => 3]]],
        ] as $case) {
            $c = new Checkpoint(
                v: 1,
                id: CheckpointId::uuid6(3),
                ts: '2024-01-01T00:00:00Z',
                channelValues: ['x' => 1],
                channelVersions: $case['cv'],
                versionsSeen: $case['vs'],
            );

            $back = Checkpoint::fromArray(self::wire($c));

            self::assertSame($case['cv'], $back->channelVersions);
            self::assertSame($case['vs'], $back->versionsSeen);
        }
    }
}
