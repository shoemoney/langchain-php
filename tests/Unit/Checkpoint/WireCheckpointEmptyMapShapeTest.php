<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Checkpoint\SqliteSaver;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A checkpoint stored by a saver must encode its maps as OBJECTS, not as arrays.
 *
 * Iterations 362 and 368 fixed exactly this inside `Checkpoint::toArray()` and `JsonPlusEncoder`: PHP has
 * one array type, so an empty map serialises as `[]`, a JavaScript reader gets an array where it expects a
 * map, and a cross-runtime resume compares the wrong shape. That defect shipped once.
 *
 ** It was back, in `wireCheckpoint()` — duplicated byte-identically in `MemorySaver` and `SqliteSaver`.
 * That method's first branch calls `$checkpoint->toArray()` and inherits the fix; its second hand-wrote the
 * array for a `Pregel\Checkpoint\Checkpoint` and omitted every cast. Measured before the fix, on a
 * checkpoint whose only trigger is a `Send` push:
 *
 *     {"v":4,"id":"c1","ts":"t","channel_values":[],"channel_versions":[],"versions_seen":{"fan_out":[]}}
 *
 * **The assertion is on the RAW STORED BYTES, not on the array.** An array-level assertion cannot tell
 * `[]` from `{}` — they are the same PHP value, and the whole defect lives in the gap between what PHP holds
 * and what `json_encode` writes. The empty INNER map is the discriminating case, and it is the one a
 * populated fixture hides.
 *
 * The fix does NOT delegate to `toArray()`: the read path is
 * `Checkpoint::fromArray((array) $this->serde->loadsTyped(...))` (MemorySaver.php:298) against the
 * snake_case `LangGraph\Checkpoint\Checkpoint`, while `PregelCheckpoint::toArray()` is camelCase — so
 * delegating would write a key set the reader cannot find. The casts are added in place instead.
 */
#[CoversNothing]
final class WireCheckpointEmptyMapShapeTest extends TestCase
{
    /** @return iterable<string, array{callable(): MemorySaver|SqliteSaver}> */
    public static function savers(): iterable
    {
        yield 'MemorySaver' => [static fn (): MemorySaver => new MemorySaver()];
        yield 'SqliteSaver' => [static fn (): SqliteSaver => SqliteSaver::fromConnString(':memory:')];
    }

    /** @param callable(): MemorySaver|SqliteSaver $make */
    #[DataProvider('savers')]
    public function testEmptyMapsAreStoredAsJsonObjects(callable $make): void
    {
        $saver = $make();

        // `fan_out` with an EMPTY inner map is the shape a `Send`-only task produces, and the one a
        // populated fixture cannot reproduce.
        $checkpoint = new PregelCheckpoint(v: 4, id: 'c1', ts: 't', versionsSeen: ['fan_out' => []]);

        $wire = new \ReflectionMethod($saver, 'wireCheckpoint');
        $stored = $wire->invoke($saver, $checkpoint);

        [, $serialized] = (new JsonPlusSerializer())->dumpsTyped($stored);
        $bytes = (string) $serialized;

        self::assertStringContainsString('"fan_out":{}', $bytes,
            'an empty inner map must serialise as a JSON object; `[]` is the 362/368 defect returning. '
                . 'Stored bytes: ' . $bytes);
        self::assertStringContainsString('"channel_versions":{}', $bytes,
            'channel_versions is always a map. Stored bytes: ' . $bytes);
    }
}
