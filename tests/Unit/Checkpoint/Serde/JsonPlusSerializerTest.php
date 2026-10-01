<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Serde;

use LangGraph\Channels\DeltaSnapshot;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangGraph\Pregel\Send;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JSON-plus envelope: what goes in must come back out.
 *
 * Port of `libs/checkpoint/src/serde/tests/jsonplus.test.ts`.
 *
 * The theme is round-tripping. A value that survives a save but not a load
 * corrupts a resumed run in a way that is invisible until the graph reads a
 * channel and finds the wrong shape — so every value here is written and read
 * back, and the envelope's own wire format is pinned so a checkpoint written by
 * this library is readable by the TypeScript runtime.
 */
#[CoversClass(JsonPlusSerializer::class)]
#[CoversClass(\LangGraph\Checkpoint\Serde\JsonPlusEncoder::class)]
#[CoversClass(\LangGraph\Checkpoint\Serde\JsonPlusDecoder::class)]
#[CoversClass(\LangGraph\Checkpoint\Serde\LcConstructorLoader::class)]
#[CoversClass(\LangGraph\Checkpoint\Serde\BaseCheckpointSerializer::class)]
final class JsonPlusSerializerTest extends TestCase
{
    private JsonPlusSerializer $serde;

    protected function setUp(): void
    {
        $this->serde = new JsonPlusSerializer();
    }

    /** @return list<array{0: string, 1: mixed}> */
    public static function roundTripValues(): array
    {
        return [
            ['null', null],
            ['empty string', ''],
            ['simple string', 'foobar'],
            ['a nested state object', [
                'number' => 1,
                'id' => 'fixed-id',
                'map' => ['a' => 1, 'b' => 2],
                'set' => [1, 2, 3, 4],
                'array' => [5, true, null, false, ['a' => 'b', 'set' => [4, 3, 2, 1]]],
                'object' => [
                    'messages' => ['hey there', 'hi how are you'],
                    'nestedNullVal' => null,
                    'emptyString' => '',
                ],
                'emptyString' => '',
                'nullVal' => null,
            ]],
            ['a value duplicated but not nested', [
                'duped1' => ['x' => 1, 'set' => [1, 2]],
                'duped2' => ['x' => 1, 'set' => [1, 2]],
            ]],
            ['a top-level byte string', "\x48\x65\x6c\x6c\x6f"],
            ['a byte string nested in an object', ['data' => "\x48\x65\x6c\x6c\x6f", 'label' => 'hello']],
            ['byte strings nested in an array', ["\x01\x02\x03", "\x04\x05\x06"]],
            ['a byte string deeply nested', ['files' => ['image' => "\x89PNG"]]],
            ['a float that json would round', 1.5],
            ['a zero', 0],
        ];
    }

    #[DataProvider('roundTripValues')]
    public function testSerializesAndDeserializes(string $description, mixed $value): void
    {
        [$type, $serialized] = $this->serde->dumpsTyped($value);
        $deserialized = $this->serde->loadsTyped($type, $serialized);

        self::assertEquals($value, $deserialized, $description);
    }

    public function testTagsBytesSeparatelyFromJson(): void
    {
        [$type, $payload] = $this->serde->dumpsTyped("\xff\xfe\x00binary");

        self::assertSame('bytes', $type);
        self::assertSame("\xff\xfe\x00binary", $payload);
        self::assertSame("\xff\xfe\x00binary", $this->serde->loadsTyped('bytes', $payload));
    }

    public function testTagsJsonDocumentsAsJson(): void
    {
        [$type, $payload] = $this->serde->dumpsTyped(['a' => 1]);

        self::assertSame('json', $type);
        self::assertSame('{"a":1}', $payload);
    }

    public function testRejectsAnUnknownSerializationType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown serialization type: protobuf');

        $this->serde->loadsTyped('protobuf', 'anything');
    }

    public function testUndefinedRecordIsUnderstoodAndReadsAsNull(): void
    {
        // PHP has no `undefined`, so nothing here writes this record — but a
        // checkpoint written by the TypeScript runtime can contain one, and a
        // reader that cannot understand it turns a channel value into an
        // unserialisable array.
        $restored = $this->serde->loadsTyped('json', '{"lc":2,"type":"undefined"}');

        self::assertNull($restored);
    }

    public function testEncodesAThrowableAsTheUpstreamErrorConstructorRecord(): void
    {
        [, $payload] = $this->serde->dumpsTyped(['error' => new \RuntimeException('test error')]);

        self::assertStringContainsString('"lc":2', $payload);
        self::assertStringContainsString('"id":["Error"]', $payload);
        self::assertStringContainsString('test error', $payload);
    }

    public function testRestoresAnErrorRecordAsAThrowableCarryingTheMessage(): void
    {
        $restored = $this->serde->loadsTyped('json', json_encode([
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Error'],
            'method' => null,
            'args' => ['boom'],
            'kwargs' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(\RuntimeException::class, $restored);
        self::assertSame('boom', $restored->getMessage());
    }

    public function testRoundTripsAThrowableByMessage(): void
    {
        [, $payload] = $this->serde->dumpsTyped(['error' => new \RuntimeException('test error')]);
        $restored = $this->serde->loadsTyped('json', $payload);

        self::assertIsArray($restored);
        self::assertInstanceOf(\RuntimeException::class, $restored['error']);
        self::assertSame('test error', $restored['error']->getMessage());
    }

    public function testSerializesAnAIMessageWithAToolCall(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [[
                'name' => 'current_weather_sf',
                'args' => ['input' => ''],
                'type' => 'tool_call',
                'id' => 'call_Co6nrPmiAdWWZQHCNdEZUjTe',
            ]],
            'additional_kwargs' => ['tool_calls' => [[
                'id' => 'call_Co6nrPmiAdWWZQHCNdEZUjTe',
                'type' => 'function',
                'function' => ['name' => 'current_weather_sf', 'arguments' => '{"input":""}'],
            ]]],
            'response_metadata' => [
                'tokenUsage' => ['completionTokens' => 15, 'promptTokens' => 84, 'totalTokens' => 99],
                'finish_reason' => 'tool_calls',
                'system_fingerprint' => 'fp_a2ff031fb5',
            ],
            'id' => 'chatcmpl-A0s8Rd97RnFo6xMlYgpJDDfV8J1cl',
        ]);

        [$type, $serialized] = $this->serde->dumpsTyped($message);
        $restored = $this->serde->loadsTyped($type, $serialized);

        self::assertSame('json', $type);
        self::assertStringContainsString('"lc":1', $serialized);
        self::assertInstanceOf(AIMessage::class, $restored);
        self::assertSame('', $restored->content);
        self::assertSame('chatcmpl-A0s8Rd97RnFo6xMlYgpJDDfV8J1cl', $restored->id);
        self::assertEquals($message->toolCalls, $restored->toolCalls);
        self::assertEquals($message->additional_kwargs, $restored->additional_kwargs);
        self::assertEquals($message->response_metadata, $restored->response_metadata);
    }

    public function testSerializesAConversationOfMessages(): void
    {
        $conversation = ['messages' => [new HumanMessage('hey there'), new AIMessage('hi how are you')]];

        [$type, $serialized] = $this->serde->dumpsTyped($conversation);
        $restored = $this->serde->loadsTyped($type, $serialized);

        self::assertIsArray($restored);
        self::assertInstanceOf(HumanMessage::class, $restored['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $restored['messages'][1]);
        self::assertSame('hey there', $restored['messages'][0]->content);
        self::assertSame('hi how are you', $restored['messages'][1]->content);
    }

    public function testPreservesASendsTimeoutPolicyAcrossSerialization(): void
    {
        $packet = [
            'lg_name' => Send::LG_NAME,
            'node' => 'worker',
            'args' => ['x' => 1],
            'timeout' => ['runTimeout' => 1000, 'idleTimeout' => 2000, 'refreshOn' => 'auto'],
        ];

        [$type, $serialized] = $this->serde->dumpsTyped($packet);

        self::assertSame(
            [
                'node' => 'worker',
                'args' => ['x' => 1],
                'timeout' => ['runTimeout' => 1000, 'idleTimeout' => 2000, 'refreshOn' => 'auto'],
            ],
            $this->serde->loadsTyped($type, $serialized),
        );
    }

    public function testSerializesASendWithoutATimeoutUnchanged(): void
    {
        $packet = ['lg_name' => Send::LG_NAME, 'node' => 'worker', 'args' => ['x' => 1]];

        [$type, $serialized] = $this->serde->dumpsTyped($packet);

        self::assertSame(
            ['node' => 'worker', 'args' => ['x' => 1]],
            $this->serde->loadsTyped($type, $serialized),
        );
    }

    public function testRoundTripsADeltaSnapshot(): void
    {
        $value = new DeltaSnapshot(['nested' => ['checkpoint'], 'bytes' => "\x01\x02\x03"]);

        [$type, $serialized] = $this->serde->dumpsTyped($value);
        $restored = $this->serde->loadsTyped($type, $serialized);

        self::assertInstanceOf(DeltaSnapshot::class, $restored);
        self::assertTrue(DeltaSnapshot::isSnapshot($restored));
        self::assertSame(['checkpoint'], $restored->value['nested']);
        self::assertSame("\x01\x02\x03", $restored->value['bytes']);
    }

    public function testRestoresCanonicalUint8ArrayConstructorRecords(): void
    {
        $restored = $this->serde->loadsTyped('json', json_encode([
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => null,
            'args' => [[0, 127, 255]],
            'kwargs' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame("\x00\x7f\xff", $restored);
    }

    public function testRestoresLegacyUint8ArrayFromRecordsAsValidatedBytes(): void
    {
        $restored = $this->serde->loadsTyped('json', json_encode([
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'from',
            'args' => [[0, 127, 255]],
            'kwargs' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame("\x00\x7f\xff", $restored);
    }

    public function testDoesNotInvokeForgedConstructorRecords(): void
    {
        // The upstream case is a callback smuggled in as a second argument to a
        // `Uint8Array.from` call. Nothing here evaluates a value it read from a
        // payload, and a record that does not match the closed set is returned
        // as itself — so the record comes back as data.
        $forgedCallback = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'constructor',
            'args' => ['system("touch /tmp/pwned")'],
            'kwargs' => [],
        ];
        $forgedOuter = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'from',
            'args' => [[1], $forgedCallback],
            'kwargs' => [],
        ];
        $forgedMap = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Map'],
            'method' => 'groupBy',
            'args' => [[1], $forgedCallback],
            'kwargs' => [],
        ];

        $restoredFromBytes = $this->serde->loadsTyped('json', json_encode(['callback' => $forgedOuter], JSON_THROW_ON_ERROR));
        $restoredFromMap = $this->serde->loadsTyped('json', json_encode(['callback' => $forgedMap], JSON_THROW_ON_ERROR));

        self::assertSame(['callback' => $forgedOuter], $restoredFromBytes);
        self::assertSame(['callback' => $forgedMap], $restoredFromMap);
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    public static function invalidByteLists(): array
    {
        return [
            ['fractional', [1.5]],
            ['negative', [-1]],
            ['out-of-range', [256]],
            ['non-numeric', ['1']],
        ];
    }

    /**
     * @param list<mixed> $bytes
     */
    #[DataProvider('invalidByteLists')]
    public function testKeepsInvalidUint8ArrayBytesInert(string $description, array $bytes): void
    {
        $record = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Uint8Array'],
            'method' => 'from',
            'args' => [$bytes],
            'kwargs' => [],
        ];

        self::assertSame($record, $this->serde->loadsTyped('json', (string) json_encode($record)), $description);
    }

    public function testKeepsMalformedMapEntriesInert(): void
    {
        $record = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Map'],
            'method' => null,
            'args' => [[['valid', 'entry'], ['not a pair']]],
            'kwargs' => [],
        ];

        self::assertSame($record, $this->serde->loadsTyped('json', (string) json_encode($record)));
    }

    public function testRestoresAWellFormedMapRecord(): void
    {
        $restored = $this->serde->loadsTyped('json', (string) json_encode([
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['Map'],
            'method' => null,
            'args' => [[['a', 1], ['b', 2]]],
            'kwargs' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['a' => 1, 'b' => 2], $restored);
    }

    public function testKeepsAnUnknownConstructorRecordInert(): void
    {
        // A `RegExp` written by the TypeScript runtime has no faithful PHP
        // counterpart — a JS pattern is not a PCRE pattern, and translating one by
        // hand would produce a matcher that silently disagrees with the original.
        // Inert is the honest outcome.
        $record = [
            'lc' => 2,
            'type' => 'constructor',
            'id' => ['RegExp'],
            'method' => null,
            'args' => ['foo*', 'gi'],
            'kwargs' => [],
        ];

        self::assertSame($record, $this->serde->loadsTyped('json', (string) json_encode($record)));
    }

    public function testReplacesCircularReferences(): void
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->b = $b;
        $b->a = $a;
        $circular = new \stdClass();
        $circular->a = $a;
        $circular->b = $b;

        [$type, $serialized] = $this->serde->dumpsTyped($circular);

        self::assertSame('json', $type);
        self::assertSame(
            '{"a":{"b":{"a":"[Circular]"}},"b":{"a":"[Circular]"}}',
            $serialized,
        );
    }

    public function testACircularMarkerIsStickyAcrossTheWholePayload(): void
    {
        // The same object reached twice. The cycle is found on the first visit
        // and the second visit must agree, or a payload would be written two
        // different ways depending on traversal order.
        $shared = new \stdClass();
        $shared->self = $shared;

        [, $serialized] = $this->serde->dumpsTyped(['first' => $shared, 'second' => $shared]);

        self::assertSame('{"first":{"self":"[Circular]"},"second":{"self":"[Circular]"}}', $serialized);
    }

    public function testACheckpointIsWrittenUnderTheWireFieldNames(): void
    {
        // The stored keys are the TypeScript runtime's, not the PHP property
        // names. That is what makes a checkpoint written here readable by the JS
        // savers, and it is why a PHP property rename could not silently
        // invalidate stored history.
        $checkpoint = \LangGraph\Checkpoint\Checkpoint::empty();
        $checkpoint->channelValues = ['messages' => ['hi']];
        $checkpoint->channelVersions = ['messages' => 1];
        $checkpoint->versionsSeen = ['' => ['messages' => 1]];

        [, $serialized] = $this->serde->dumpsTyped($checkpoint);

        self::assertSame(
            ['v', 'id', 'ts', 'channel_values', 'channel_versions', 'versions_seen'],
            array_keys((array) json_decode($serialized, true, 512, JSON_THROW_ON_ERROR)),
        );
        self::assertSame(
            ['messages' => ['hi']],
            ((array) json_decode($serialized, true, 512, JSON_THROW_ON_ERROR))['channel_values'],
        );
    }

    public function testAnEmptyCheckpointRoundTrips(): void
    {
        $checkpoint = \LangGraph\Checkpoint\Checkpoint::empty();

        [$type, $serialized] = $this->serde->dumpsTyped($checkpoint);
        $restored = \LangGraph\Checkpoint\Checkpoint::fromArray(
            (array) $this->serde->loadsTyped($type, $serialized),
        );

        self::assertSame(4, $restored->v);
        self::assertSame($checkpoint->id, $restored->id);
        self::assertSame([], $restored->channelValues);
        self::assertSame([], $restored->channelVersions);
        self::assertSame([], $restored->versionsSeen);
    }

    public function testACheckpointIdIsAValidTimeOrderedUuid(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-6[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            CheckpointId::uuid6(0),
        );
    }
}
