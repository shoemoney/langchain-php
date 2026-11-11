<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Load\Load;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source-backed ToolMessage constructor serialization; stored unwrapping is the E2 PHP adaptation.
 * Pinned messages/tool.ts, base.ts, utils.ts and load/serializable.ts keep supplied artifact kwargs.
 */
#[CoversClass(ToolMessage::class)]
#[CoversClass(MessageUtils::class)]
final class ToolMessageArtifactSerializationTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function artifacts(): iterable
    {
        yield 'null' => [null, null];
        yield 'false' => [false, false];
        yield 'zero' => [0, 0];
        yield 'empty string' => ['', ''];
        yield 'empty list' => [[], []];
        yield 'ordinary' => [['rows' => [1, 2]], ['rows' => [1, 2]]];
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_ARTIFACT_SECRET']];
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']];
        foreach (['lc zero' => ['lc' => 0], 'lc null' => ['lc' => null], 'lc arbitrary' => ['lc' => 'literal'], 'literal secret' => $secret, 'literal constructor' => $constructor, 'literal lc2' => ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['literal']]] as $name => $literal) {
            yield $name => [$literal, ['__lc_escaped__' => $literal]];
        }
        yield 'literal singleton' => [['__lc_escaped__' => $secret], ['__lc_escaped__' => ['__lc_escaped__' => $secret]]];
        yield 'nested literals' => [['values' => [$secret, $constructor]], ['values' => [['__lc_escaped__' => $secret], ['__lc_escaped__' => $constructor]]]];
    }

    #[DataProvider('artifacts')]
    public function testConstructorAndTrustedLoadPreserveEverySuppliedArtifact(mixed $artifact, mixed $serializedArtifact): void
    {
        $message = self::message($artifact);
        $record = $message->toJson();

        self::assertSame(ToolMessage::lcId(), $record['id']);
        self::assertArrayHasKey('artifact', $record['kwargs']);
        self::assertSame($serializedArtifact, $record['kwargs']['artifact']);
        self::assertSame('visible result', $record['kwargs']['content']);
        self::assertSame('call', $record['kwargs']['tool_call_id']);
        $restored = Load::load(json_encode($message, JSON_THROW_ON_ERROR), ['secretsMap' => ['FAKE_ARTIFACT_SECRET' => 'fake-substitution']]);
        self::assertInstanceOf(ToolMessage::class, $restored);
        self::assertSame($artifact, $restored->artifact);
        self::assertSame($record, $restored->toJson());
    }

    #[DataProvider('artifacts')]
    public function testStoredRoundTripsUnwrapOnceAndRemainStable(mixed $artifact, mixed $serializedArtifact): void
    {
        $message = self::message($artifact);
        $original = $message->toDict();
        self::assertArrayHasKey('artifact', $original['data']);
        self::assertSame($serializedArtifact, $original['data']['artifact']);

        for ($round = 0; $round < 2; ++$round) {
            $stored = MessageUtils::mapChatMessagesToStoredMessages([$message]);
            self::assertSame([$original], $stored);
            $message = MessageUtils::mapStoredMessagesToChatMessages(json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR))[0];
            self::assertInstanceOf(ToolMessage::class, $message);
            self::assertSame($artifact, $message->artifact);
            self::assertSame('call', $message->toolCallId);
            self::assertSame('visible result', $message->content);
        }
        self::assertSame($original, $message->toDict());
    }

    #[DataProvider('artifacts')]
    public function testTwoActualStandaloneAndContainerCheckpointRoundTripsKeepArtifacts(mixed $artifact, mixed $serializedArtifact): void
    {
        $serde = new JsonPlusSerializer();
        foreach ([false, true] as $container) {
            $message = self::message($artifact);
            $initialPayload = null;
            for ($round = 0; $round < 2; ++$round) {
                $value = $container ? ['messages' => [$message], 'genuine' => new HumanMessage('genuine')] : $message;
                [$type, $payload] = $serde->dumpsTyped($value);
                $initialPayload ??= $payload;
                self::assertSame($initialPayload, $payload);
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $record = $container ? $decoded['messages'][0] : $decoded;
                self::assertArrayHasKey('artifact', $record['kwargs']);
                self::assertSame($serializedArtifact, $record['kwargs']['artifact']);
                $restored = $serde->loadsTyped($type, $payload);
                if ($container) {
                    self::assertInstanceOf(HumanMessage::class, $restored['genuine']);
                    self::assertSame('genuine', $restored['genuine']->content);
                }
                $message = $container ? $restored['messages'][0] : $restored;
                self::assertInstanceOf(ToolMessage::class, $message);
                self::assertSame($artifact, $message->artifact);
                self::assertSame('call', $message->toolCallId);
            }
        }
    }

    public function testOmittedArtifactStaysOmittedEvenIfItsPropertyIsAssignedLater(): void
    {
        $message = new ToolMessage(['content' => 'visible result', 'tool_call_id' => 'call']);
        self::assertArrayNotHasKey('artifact', $message->toJson()['kwargs']);
        $message->artifact = ['later' => 1];
        self::assertArrayNotHasKey('artifact', $message->toJson()['kwargs']);
        self::assertArrayNotHasKey('artifact', $message->toDict()['data']);
    }

    public function testSuppliedArtifactTracksCurrentPropertyIncludingChangesToNull(): void
    {
        $message = self::message(null);
        self::assertArrayHasKey('artifact', $message->kwargs());
        $message->artifact = ['lc' => 0, 'current' => 'value'];
        self::assertSame($message->artifact, $message->kwargs()['artifact']);
        self::assertSame(['__lc_escaped__' => $message->artifact], $message->toJson()['kwargs']['artifact']);
        $message->artifact = null;
        self::assertArrayHasKey('artifact', $message->toJson()['kwargs']);
        self::assertNull($message->toJson()['kwargs']['artifact']);
    }

    public function testOldStoredRecordsWithoutArtifactStayCompatible(): void
    {
        $message = MessageUtils::mapStoredMessageToChatMessage(['type' => 'tool', 'data' => ['content' => 'old result', 'tool_call_id' => 'old-call']]);
        self::assertInstanceOf(ToolMessage::class, $message);
        self::assertNull($message->artifact);
        self::assertArrayNotHasKey('artifact', $message->toJson()['kwargs']);
        self::assertSame('old-call', $message->toolCallId);
    }

    public function testGenuineNestedMessageArtifactLoadsThroughTheExistingClosedRegistries(): void
    {
        $message = self::message(['genuine' => new HumanMessage('real object'), 'literal' => ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']]]);
        $restored = Load::load(json_encode($message, JSON_THROW_ON_ERROR));
        self::assertIsArray($restored->artifact);
        self::assertInstanceOf(HumanMessage::class, $restored->artifact['genuine']);
        self::assertSame('real object', $restored->artifact['genuine']->content);
        self::assertIsArray($restored->artifact['literal']);

        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped($message);
            $message = $serde->loadsTyped($type, $payload);
            self::assertInstanceOf(ToolMessage::class, $message);
            self::assertInstanceOf(HumanMessage::class, $message->artifact['genuine']);
            self::assertSame('real object', $message->artifact['genuine']->content);
            self::assertIsArray($message->artifact['literal']);
            self::assertSame('literal', $message->artifact['literal']['kwargs']['content']);
        }
    }

    public function testGenuineChunkArtifactRetainsRegisteredClassThroughTwoCheckpointRounds(): void
    {
        $artifact = ['lc' => 0, 'k' => ['__lc_escaped__' => 'x']];
        $nested = new ToolMessageChunk(['content' => 'chunk', 'tool_call_id' => 'nested-call', 'artifact' => $artifact]);
        $message = self::message(['genuine' => $nested]);
        $loaded = Load::load(json_encode($message, JSON_THROW_ON_ERROR));
        self::assertIsArray($loaded->artifact);
        self::assertInstanceOf(ToolMessageChunk::class, $loaded->artifact['genuine']);
        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped($message);
            $message = $serde->loadsTyped($type, $payload);
            $chunk = $message->artifact['genuine'];
            self::assertSame(ToolMessageChunk::class, get_debug_type($chunk));
            self::assertSame('chunk', $chunk->content);
            self::assertSame('nested-call', $chunk->toolCallId);
            self::assertSame($artifact, $chunk->artifact);
        }
    }

    public function testEmptyObjectRecordsExistingPhpDecodeShapeAdaptation(): void
    {
        $message = self::message(new \stdClass());
        $json = json_encode($message, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"artifact":{}', $json);
        // Existing Load decodes JSON objects to arrays; preserving {} versus [] remains N27.
        self::assertSame([], Load::load($json)->artifact);
    }

    public function testProviderFakeHttpPipelineExcludesArtifactsAfterBothCheckpointRounds(): void
    {
        $literal = ['lc' => 0, 'secret' => ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_ARTIFACT_SECRET']], 'wrapper' => ['__lc_escaped__' => ['lc' => null]]];
        $message = self::message($literal);
        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped(['messages' => [$message]]);
            $message = $serde->loadsTyped($type, $payload)['messages'][0];
            self::assertSame($literal, $message->artifact);
        }
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'fake', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]])]);
        $model = new ChatOpenAI(['apiKey' => 'fake-key', 'httpClient' => $http, 'maxRetries' => 0]);
        $result = $model->invoke([new HumanMessage('test'), new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call', 'name' => 'test', 'args' => []]]]), $message]);
        self::assertSame('ok', $result->content);
        self::assertCount(1, $http->requests);
        $request = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['role' => 'tool', 'tool_call_id' => 'call', 'content' => 'visible result'], $request['messages'][2]);
        self::assertStringNotContainsString('FAKE_ARTIFACT_SECRET', $http->requests[0]['body']);
        self::assertStringNotContainsString('__lc_escaped__', $http->requests[0]['body']);
        self::assertStringNotContainsString('"artifact"', $http->requests[0]['body']);
    }

    private static function message(mixed $artifact): ToolMessage
    {
        return new ToolMessage(['content' => 'visible result', 'tool_call_id' => 'call', 'artifact' => $artifact]);
    }
}
