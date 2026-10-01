<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `versionsSeen` is typed `array<string, array<string, int|string>>` — EVERY inner value is a map, so
 * every inner value must serialise as `{}` when empty, exactly like the outer level.
 *
 * The outer cast landed at iteration 362 and fixed `versionsSeen: []` → `{}`. It did not recurse, and
 * `json_encode` writes a PHP `[]` as a JSON array, so:
 *
 *     versionsSeen: ['fan_out' => []]   ->   "versionsSeen":{"fan_out":[]}
 *
 * That shape is not an edge case: the algorithm creates an inner entry for any task whose only trigger
 * is a `Send` push (no entry in `channel_versions`), and never fills it. A JavaScript reader handed
 * `[]` where it expects an object cannot read it back.
 *
 * Asserted on RAW SERIALISED BYTES, which is the only place the defect exists.
 */
#[CoversClass(JsonPlusSerializer::class)]
final class InnerVersionsSeenMapsTest extends TestCase
{
    private function encode(Checkpoint $checkpoint): string
    {
        return (new JsonPlusSerializer())->dumpsTyped($checkpoint)[1];
    }

    /** RED before the fix: the inner map leaves as `[]`. */
    public function testAnEmptyInnerVersionsSeenMapEncodesAsAnObject(): void
    {
        $bytes = $this->encode(new Checkpoint(versionsSeen: ['fan_out' => []]));

        self::assertStringContainsString('"versionsSeen":{"fan_out":{}}', $bytes, $bytes);
        self::assertStringNotContainsString('"fan_out":[]', $bytes, $bytes);
    }

    /** CONTROL: the outer level, fixed at 362, must stay fixed. */
    public function testTheOuterLevelIsStillAnObjectWhenEmpty(): void
    {
        $bytes = $this->encode(new Checkpoint());

        self::assertStringContainsString('"versionsSeen":{}', $bytes, $bytes);
        self::assertStringContainsString('"channelVersions":{}', $bytes, $bytes);
    }

    /** CONTROL: a populated inner map keeps its entries — the case that always worked. */
    public function testAPopulatedInnerMapKeepsItsEntries(): void
    {
        $bytes = $this->encode(new Checkpoint(versionsSeen: ['node' => ['__pregel_tasks' => 1]]));

        self::assertStringContainsString('"versionsSeen":{"node":{"__pregel_tasks":1}}', $bytes, $bytes);
    }

    /** CONTROL: `channelVersions` holds scalars, so its values must NOT be turned into objects. */
    public function testChannelVersionsScalarsAreNotObjects(): void
    {
        $bytes = $this->encode(new Checkpoint(channelVersions: ['a' => 1]));

        self::assertStringContainsString('"channelVersions":{"a":1}', $bytes, $bytes);
    }
}
