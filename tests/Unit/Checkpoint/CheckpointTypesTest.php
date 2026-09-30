<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\ChannelVersions;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointConstants;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Checkpoint\CheckpointMetadata;
use LangGraph\Checkpoint\CheckpointTuple;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CheckpointerFixture.php';

/**
 * The value types: identity, version arithmetic, and the wire mapping.
 *
 * Port of the `Base`, `id` and `channel versions` blocks of
 * `libs/checkpoint/src/tests/checkpoints.test.ts`.
 */
#[CoversClass(Checkpoint::class)]
#[CoversClass(CheckpointTuple::class)]
#[CoversClass(CheckpointId::class)]
#[CoversClass(ChannelVersions::class)]
#[CoversClass(CheckpointMetadata::class)]
#[CoversClass(CheckpointListOptions::class)]
#[CoversClass(CheckpointConstants::class)]
final class CheckpointTypesTest extends TestCase
{
    // ---- deepCopy ---------------------------------------------------------

    public function testDeepCopyOfASimpleObject(): void
    {
        $original = ['a' => 1, 'b' => ['c' => 2]];
        $copied = Checkpoint::deepCopy($original);

        self::assertEquals($original, $copied);

        // PHP arrays have value semantics, so "not the same reference" is not
        // observable; independence is. Mutating the copy must not reach back.
        $copied['b']['c'] = 99;
        self::assertSame(2, $original['b']['c']);
    }

    public function testDeepCopyOfAListOfObjects(): void
    {
        $original = [['a' => 1], ['b' => 2]];
        $copied = Checkpoint::deepCopy($original);

        self::assertEquals($original, $copied);

        $copied[0]['a'] = 99;
        self::assertSame(1, $original[0]['a']);
    }

    public function testDeepCopyPassesScalarsThrough(): void
    {
        self::assertSame(1, Checkpoint::deepCopy(1));
        self::assertNull(Checkpoint::deepCopy(null));
        self::assertSame('x', Checkpoint::deepCopy('x'));
    }

    public function testCopyCheckpointDefaultsMissingChannelMapsToEmpty(): void
    {
        $partial = new PregelCheckpoint(v: 4, id: CheckpointId::uuid6(0), ts: '2024-04-19T17:19:07.952Z');

        $copied = Checkpoint::fromArray([
            'v' => $partial->v,
            'id' => $partial->id,
            'ts' => $partial->ts,
        ]);

        // Asserted through the JSON the caller actually stores, not the PHP
        // array. `channel_versions` and `versions_seen` are always maps, so they
        // serialise as `{}`; `channel_values` is genuinely ambiguous (a list
        // channel can be empty) and stays `[]`.
        $wire = json_decode((string) json_encode($copied->toArray()), true);

        self::assertSame(4, $wire['v']);
        self::assertSame($partial->id, $wire['id']);
        self::assertSame('2024-04-19T17:19:07.952Z', $wire['ts']);
        self::assertSame([], $wire['channel_values'], 'a list channel may legitimately be empty');
        self::assertSame([], $wire['channel_versions'], 'always a map, so {} on the wire');
        self::assertSame([], $wire['versions_seen']);

        // The PHP-side value is still an ordinary empty array, so nothing that
        // reads the object needed changing.
        self::assertSame([], $copied->channelVersions);
        self::assertSame([], $copied->versionsSeen);
    }

    public function testCopyReturnsTheSameConcreteClass(): void
    {
        $checkpoint = new Checkpoint(id: 'a', channelValues: ['x' => 1]);
        $copy = $checkpoint->copy();

        self::assertInstanceOf(Checkpoint::class, $copy);
        self::assertNotSame($checkpoint, $copy);
        self::assertEquals($checkpoint->toArray(), $copy->toArray());
    }

    public function testCopyDoesNotAliasNestedVersionMaps(): void
    {
        // `applyWrites` mutates `versionsSeen` in place. Two checkpoints sharing a
        // nested array would let replaying a step rewrite the saved history.
        $checkpoint = new Checkpoint(id: 'a', versionsSeen: ['' => ['x' => 1]]);
        $copy = $checkpoint->copy();

        $copy->versionsSeen['']['x'] = 99;

        self::assertSame(1, $checkpoint->versionsSeen['']['x']);
    }

    // ---- ids --------------------------------------------------------------

    /** @return list<array{0: int}> */
    public static function clockSeqs(): array
    {
        return [[-1], [0], [1], [3]];
    }

    #[DataProvider('clockSeqs')]
    public function testUuid6IsATimeOrderedUuid(int $clockSeq): void
    {
        $uuid = CheckpointId::uuid6($clockSeq);

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-6[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testUuid6IsStrictlyIncreasingWithinAMillisecond(): void
    {
        // Savers order a thread's history by comparing ids as strings, so two
        // checkpoints written in the same millisecond must still order.
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $ids[] = CheckpointId::uuid6(0);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids);
    }

    public function testUuid6KeepsTimeOrderingAcrossClockSeqs(): void
    {
        // The clock sequence is disambiguating metadata; it must never be able to
        // reorder two checkpoints in time.
        $first = CheckpointId::uuid6(0);
        $second = CheckpointId::uuid6(1);

        self::assertLessThan($second, $first);
    }

    public function testUuid5IsDeterministic(): void
    {
        $namespace = CheckpointId::uuid6(0);

        self::assertSame(
            CheckpointId::uuid5('task:1', $namespace),
            CheckpointId::uuid5('task:1', $namespace),
        );
        self::assertNotSame(
            CheckpointId::uuid5('task:1', $namespace),
            CheckpointId::uuid5('task:2', $namespace),
        );
    }

    public function testGetCheckpointIdReadsEitherAcceptedConfigShape(): void
    {
        $id = CheckpointId::uuid6(0);

        self::assertSame($id, CheckpointId::fromConfig(['configurable' => ['checkpoint_id' => $id]]));
        self::assertSame($id, CheckpointId::fromConfig(['checkpoint_id' => $id]));
        self::assertSame('legacy', CheckpointId::fromConfig(['thread_ts' => 'legacy']));
        self::assertSame('', CheckpointId::fromConfig([]));
    }

    // ---- channel versions -------------------------------------------------

    public function testChannelVersionComparison(): void
    {
        self::assertSame(-1, ChannelVersions::compare(1, 2));
        self::assertSame(0, ChannelVersions::compare(1, 1));
        self::assertSame(1, ChannelVersions::compare(2, 1));

        self::assertSame(-1, ChannelVersions::compare('1.abc', '2'));
        self::assertSame(-1, ChannelVersions::compare('10.a', '10.b'));
    }

    public function testMaxChannelVersion(): void
    {
        self::assertSame('10.a', ChannelVersions::max('01.a', '02.a', '10.a'));
        self::assertSame(3, ChannelVersions::max(1, 3, 2));
        self::assertNull(ChannelVersions::maxOfMap([]));
        self::assertSame('02.a', ChannelVersions::maxOfMap(['a' => '01.a', 'b' => '02.a']));
    }

    public function testMaxChannelVersionNeedsAtLeastOneVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ChannelVersions::max();
    }

    // ---- write indices ----------------------------------------------------

    public function testWriteIndicesSeparateSpecialChannelsFromRegularOnes(): void
    {
        self::assertSame(0, CheckpointConstants::writeIndex('animals', 0));
        self::assertSame(-1, CheckpointConstants::writeIndex(CheckpointConstants::ERROR, 0));
        self::assertSame(-2, CheckpointConstants::writeIndex(CheckpointConstants::SCHEDULED, 0));
        self::assertSame(-3, CheckpointConstants::writeIndex(CheckpointConstants::INTERRUPT, 5));
        self::assertSame(-4, CheckpointConstants::writeIndex(CheckpointConstants::RESUME, 5));
    }

    public function testAllSpecialChannels(): void
    {
        self::assertTrue(CheckpointConstants::allSpecialChannels([
            [CheckpointConstants::INTERRUPT, 'a'],
            [CheckpointConstants::RESUME, 'b'],
        ]));
        self::assertFalse(CheckpointConstants::allSpecialChannels([
            [CheckpointConstants::INTERRUPT, 'a'],
            ['animals', 'b'],
        ]));
        self::assertFalse(CheckpointConstants::allSpecialChannels([]));
    }

    // ---- metadata ---------------------------------------------------------

    public function testMetadataValidation(): void
    {
        self::assertTrue(CheckpointMetadata::isValid(['source' => 'loop', 'step' => 0, 'parents' => []]));
        self::assertFalse(CheckpointMetadata::isValid(['step' => 0]));
        self::assertFalse(CheckpointMetadata::isValid(['source' => 'nonsense', 'step' => 0]));
        // A string step sorts lexicographically and turns a resume into a replay.
        self::assertFalse(CheckpointMetadata::isValid(['source' => 'loop', 'step' => '0']));
        self::assertFalse(CheckpointMetadata::isValid(['source' => 'loop', 'step' => 0, 'parents' => 'x']));
    }

    public function testDefinedMetadataKeysApplyDefaults(): void
    {
        self::assertSame(
            ['source' => 'loop', 'step' => -1, 'parents' => []],
            CheckpointMetadata::definedKeys([]),
        );
        self::assertSame(
            ['source' => 'fork', 'step' => 7, 'parents' => ['' => 'abc']],
            CheckpointMetadata::definedKeys(['source' => 'fork', 'step' => 7, 'parents' => ['' => 'abc']]),
        );
    }

    // ---- list options -----------------------------------------------------

    public function testListOptionsNormaliseABareLimit(): void
    {
        self::assertSame(3, CheckpointListOptions::of(3)->limit);
        self::assertNull(CheckpointListOptions::of(null)->limit);

        $options = new CheckpointListOptions(before: ['configurable' => ['checkpoint_id' => 'abc']], limit: 2);
        self::assertSame($options, CheckpointListOptions::of($options));
        self::assertSame('abc', $options->beforeCheckpointId());
    }

    public function testListOptionsFiltering(): void
    {
        $metadata = ['source' => 'loop', 'step' => 1, 'tenant' => 'acme'];

        self::assertTrue((new CheckpointListOptions())->matches($metadata));
        self::assertTrue((new CheckpointListOptions(filter: []))->matches($metadata));
        self::assertTrue((new CheckpointListOptions(filter: ['source' => 'loop']))->matches($metadata));
        self::assertTrue(
            (new CheckpointListOptions(filter: ['source' => 'loop', 'tenant' => 'acme']))->matches($metadata),
        );
        self::assertFalse((new CheckpointListOptions(filter: ['source' => 'input']))->matches($metadata));
        // A missing key never matches, even against a null filter value.
        self::assertFalse((new CheckpointListOptions(filter: ['absent' => null]))->matches($metadata));
    }

    // ---- tuple ------------------------------------------------------------

    public function testTupleRoundTripsThroughItsWireForm(): void
    {
        $tuple = new CheckpointTuple(
            config: CheckpointerFixture::config('t', '', 'c'),
            checkpoint: new Checkpoint(v: 4, id: 'c', ts: 'now', channelValues: ['a' => 1]),
            metadata: ['source' => 'loop', 'step' => 1, 'parents' => []],
            parentConfig: CheckpointerFixture::config('t', '', 'p'),
            pendingWrites: [['task', 'a', 1]],
        );

        $restored = CheckpointTuple::fromArray($tuple->toArray(), $tuple->checkpoint);

        self::assertEquals($tuple->config, $restored->config);
        self::assertEquals($tuple->metadata, $restored->metadata);
        self::assertEquals($tuple->parentConfig, $restored->parentConfig);
        self::assertEquals($tuple->pendingWrites, $restored->pendingWrites);
    }

    public function testATupleIsUsableWhereverTheEngineCheckpointTupleIsExpected(): void
    {
        $tuple = new CheckpointTuple(
            config: ['configurable' => ['thread_id' => 't']],
            checkpoint: new Checkpoint(id: 'c'),
        );

        self::assertInstanceOf(\LangGraph\Pregel\Checkpoint\CheckpointTuple::class, $tuple);
        self::assertInstanceOf(\LangGraph\Pregel\Checkpoint\Checkpoint::class, $tuple->checkpoint);
    }
}
