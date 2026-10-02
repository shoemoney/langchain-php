<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A brand-new thread writes an EMPTY `channel_versions` and an EMPTY `versions_seen` — so this is the
 * FIRST thing a checkpoint contains, not an edge case. Both are MAPS upstream (object literals in
 * `toJson()`), so they must reach the bytes as `{}`. PHP encodes an empty array as `[]`, which a
 * JavaScript reader cannot read back as an object.
 *
 * `PORT_STATUS.md:255` records this as fixed by `fce8bc4`, and the cast it added is real and correct —
 * in `src/LangGraph/Checkpoint/Checkpoint.php:58`. But the class the Pregel engine actually WRITES is
 * `src/LangGraph/Pregel/Checkpoint/Checkpoint.php`, which has no `toArray()` and no cast, and the
 * encoder's `instanceof` at `JsonPlusEncoder.php:130` imports only the first one. So the fix is present,
 * documented, and absent from every checkpoint the engine writes.
 *
 * This asserts on the RAW SERIALISED BYTES, which is the only place the defect is observable: an empty
 * PHP array is exactly what the caller passed, and the cast either happens or does not at encode time.
 * The populated case is the control — it passed all along, which is why the bug survived.
 */
#[CoversClass(JsonPlusSerializer::class)]
final class EmptyCheckpointMapsSerializeAsObjectsTest extends TestCase
{
    private function encode(Checkpoint $checkpoint): string
    {
        return (new JsonPlusSerializer())->dumpsTyped($checkpoint)[1];
    }

    /** RED before the fix: the engine's own empty checkpoint serialises both maps as `[]`. */
    public function testTheEnginesOwnEmptyCheckpointWritesMapsAsObjects(): void
    {
        $bytes = $this->encode(CheckpointFunctions::emptyCheckpoint());

        self::assertStringContainsString('"channel_versions":{}', $bytes, $bytes);
        self::assertStringContainsString('"versions_seen":{}', $bytes, $bytes);
        self::assertStringNotContainsString('"channel_versions":[]', $bytes, $bytes);
        self::assertStringNotContainsString('"versions_seen":[]', $bytes, $bytes);
    }

    /** A hand-built one, same shape, so the assertion is not tied to one factory. */
    public function testAHandBuiltEmptyCheckpointAlsoWritesMapsAsObjects(): void
    {
        $bytes = $this->encode(new Checkpoint());

        self::assertStringContainsString('"channel_versions":{}', $bytes, $bytes);
        self::assertStringContainsString('"versions_seen":{}', $bytes, $bytes);
    }

    /** Control: a POPULATED checkpoint was always right, and must stay right. */
    public function testAPopulatedCheckpointStillWritesItsEntries(): void
    {
        $bytes = $this->encode(new Checkpoint(
            channelVersions: ['a' => 1],
            versionsSeen: ['b' => ['node' => 2]],
        ));

        self::assertStringContainsString('"channel_versions":{"a":1}', $bytes, $bytes);
        self::assertStringContainsString('"versions_seen":{"b":{"node":2}}', $bytes, $bytes);
    }

    /** Control: the sibling class that carries the cast must be unaffected by widening the match. */
    public function testTheOtherCheckpointClassIsUnaffected(): void
    {
        $bytes = (new JsonPlusSerializer())->dumpsTyped(
            new \LangGraph\Checkpoint\Checkpoint(channelVersions: [], versionsSeen: [])
        )[1];

        self::assertStringContainsString('"channel_versions":{}', $bytes, $bytes);
    }
}
