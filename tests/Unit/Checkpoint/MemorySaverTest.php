<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Messages\HumanMessage;
use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The in-process saver, on its own terms.
 *
 * Port of the `MemorySaver` describe block in
 * `libs/checkpoint/src/tests/checkpoints.test.ts`.
 */
#[CoversClass(MemorySaver::class)]
#[CoversClass(BaseCheckpointSaver::class)]
final class MemorySaverTest extends TestCase
{
    public function testSavesAndRetrievesCheckpointsCorrectly(): void
    {
        $saver = new MemorySaver();

        $checkpoint1 = new Checkpoint(
            v: 4,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue1'],
            channelVersions: ['someKey2' => 1],
            versionsSeen: ['someKey3' => ['someKey4' => 1]],
        );
        $checkpoint2 = new Checkpoint(
            v: 4,
            id: CheckpointId::uuid6(1),
            ts: '2024-04-20T17:19:07.952Z',
            channelValues: ['someKey1' => 'someValue2'],
            channelVersions: ['someKey2' => 2],
            versionsSeen: ['someKey3' => ['someKey4' => 2]],
        );

        $config = $saver->put(
            ['thread_id' => '1', 'checkpoint_ns' => ''],
            $checkpoint1,
            ['source' => 'update', 'step' => -1, 'parents' => []],
        );

        self::assertSame([
            'configurable' => [
                'thread_id' => '1',
                'checkpoint_ns' => '',
                'checkpoint_id' => $checkpoint1->id,
            ],
        ], $config);

        $tuple = $saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']);
        self::assertNotNull($tuple);
        self::assertSame($config, $tuple->config);
        self::assertEquals($checkpoint1->toArray(), $tuple->checkpoint->toArray());

        $saver->put(
            ['thread_id' => '1', 'checkpoint_ns' => ''],
            $checkpoint2,
            ['source' => 'update', 'step' => -1, 'parents' => []],
        );

        $tuples = $saver->list(['thread_id' => '1', 'checkpoint_ns' => '']);

        self::assertCount(2, $tuples);
        self::assertSame('2024-04-20T17:19:07.952Z', $tuples[0]->checkpoint->ts);
        self::assertSame('2024-04-19T17:19:07.952Z', $tuples[1]->checkpoint->ts);
    }

    /**
     * A checkpoint id is a checkpoint's identity in a config, so a stored value
     * that cannot survive serialisation has to be inert rather than executed.
     */
    public function testKeepsMetadataConstructorRecordsInertOnALaterRead(): void
    {
        $saver = new MemorySaver();
        $forgedCallback = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'constructor',
            'args' => ['system("touch /tmp/pwned")'],
            'kwargs' => [],
        ];
        $forgedRecord = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'from',
            'args' => [[1], $forgedCallback],
            'kwargs' => [],
        ];
        $config = ['thread_id' => 'metadata-security'];

        $saver->put($config, Checkpoint::empty(), [
            'source' => 'update',
            'step' => -1,
            'parents' => [],
            'forged' => $forgedRecord,
        ]);

        $restored = $saver->getTuple($config);

        self::assertNotNull($restored);
        self::assertSame($forgedRecord, $restored->metadata['forged']);
    }

    public function testKeepsForgedAdditionalKwargsConstructorRecordsInertOnRestore(): void
    {
        $saver = new MemorySaver();
        $forgedRecord = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'from',
            'args' => [[1], [
                'lc' => 2,
                'type' => 'constructor',
                'id' => ['Uint8Array'],
                'method' => 'constructor',
                'args' => ['system("touch /tmp/pwned")'],
                'kwargs' => [],
            ]],
            'kwargs' => [],
        ];
        $config = ['thread_id' => 'message-security'];

        $saved = $saver->put($config, Checkpoint::empty(), [
            'source' => 'update',
            'step' => -1,
            'parents' => [],
        ]);
        $saver->putWrites($saved, [[
            'messages',
            new HumanMessage(['content' => 'ordinary message', 'additional_kwargs' => ['forged' => $forgedRecord]]),
        ]], 'message-security-task');

        $restored = $saver->getTuple($saved);
        $message = $restored?->pendingWrites[0][2] ?? null;

        self::assertInstanceOf(HumanMessage::class, $message);
        self::assertSame('ordinary message', $message->content);
        self::assertSame($forgedRecord, $message->additional_kwargs['forged']);
    }

    public function testADeletedThreadLeavesItsOtherThreadsAlone(): void
    {
        $saver = new MemorySaver();
        $meta = ['source' => 'update', 'step' => -1, 'parents' => []];

        $saver->put(['thread_id' => '1', 'checkpoint_ns' => ''], Checkpoint::empty(), $meta);
        $saver->put(['thread_id' => '2', 'checkpoint_ns' => ''], Checkpoint::empty(), $meta);

        self::assertNotNull($saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']));

        $saver->deleteThread('1');

        self::assertNull($saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']));
        self::assertNotNull($saver->getTuple(['thread_id' => '2', 'checkpoint_ns' => '']));
    }

    public function testASaverStaysOutOfTheWayWhenAConfigIsEncoded(): void
    {
        // A saver parked in a runnable's `configurable` must not be walked
        // property by property by a serialiser.
        $saver = new MemorySaver();

        $encoded = json_encode(['configurable' => ['checkpointer' => $saver]], JSON_THROW_ON_ERROR);

        self::assertSame(
            (string) json_encode(
                ['configurable' => ['checkpointer' => '[' . MemorySaver::class . ']']],
                JSON_THROW_ON_ERROR,
            ),
            $encoded,
        );
    }

    public function testGetNextVersionIncrementsIntegers(): void
    {
        $saver = new MemorySaver();

        self::assertSame(1, $saver->getNextVersion(null));
        self::assertSame(2, $saver->getNextVersion(1));
    }

    /**
     * The full lifecycle: a put, a get, a second put, a second get. Every value
     * has to come back exactly as it went in, because a resumed run reads these
     * channels and nothing else.
     */
    public function testANestedStateSurvivesTwoRoundTripsUnchanged(): void
    {
        $saver = new MemorySaver();
        $threadId = CheckpointId::uuid6(3);

        $state = [
            'messages' => [
                ['role' => 'user', 'content' => 'hey there'],
                ['role' => 'assistant', 'content' => 'hi how are you'],
            ],
            'counters' => ['a' => 1, 'b' => -1, 'c' => 0, 'd' => 1.5],
            'flags' => ['on' => true, 'off' => false, 'unset' => null],
            'nested' => ['deep' => ['deeper' => ['deepest' => ['x', 'y', 'z']]]],
            'empty' => [],
            'emptyString' => '',
            'unicode' => 'héllo — 世界 🌍',
        ];

        $first = new Checkpoint(
            v: 4,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: $state,
            channelVersions: ['messages' => 1, 'counters' => 1],
            versionsSeen: ['node' => ['messages' => 1]],
        );

        $configA = $saver->put(['thread_id' => $threadId], $first, [
            'source' => 'input',
            'step' => -1,
            'parents' => [],
        ]);
        $readA = $saver->getTuple($configA);

        self::assertNotNull($readA);
        self::assertSame($state, $readA->checkpoint->channelValues);
        self::assertSame($first->versionsSeen, $readA->checkpoint->versionsSeen);

        // Second round trip: the state is re-saved under a new id and read back.
        $second = new Checkpoint(
            v: 4,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-20T17:19:07.952Z',
            channelValues: $state,
            channelVersions: ['messages' => 2, 'counters' => 2],
            versionsSeen: ['node' => ['messages' => 2]],
        );

        $configB = $saver->put($configA, $second, ['source' => 'loop', 'step' => 1, 'parents' => ['' => $first->id]]);
        $readB = $saver->getTuple($configB);

        self::assertNotNull($readB);
        self::assertSame($state, $readB->checkpoint->channelValues);
        self::assertSame($second->channelVersions, $readB->checkpoint->channelVersions);
        self::assertSame($first->id, $readB->parentConfig['configurable']['checkpoint_id']);

        // And the first checkpoint is untouched by the second write.
        self::assertSame($state, $saver->getTuple($configA)?->checkpoint->channelValues);
    }
}
