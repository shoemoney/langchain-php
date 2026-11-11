<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Load\Load;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\HumanMessageChunk;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\SystemMessageChunk;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source constructor fields and concat wire at construction; PHP promotion/stored unwrapping are adaptations.
 * Post-concat mutations retain captured keys, with unobserved explicit-null writes a PHP 8.2 residual.
 * Pinned JS has no toMessage() and its convertToChunk() rejects ToolMessage. The existing PHP
 * APIs remain supported without claiming source conversion parity or expanding chunk registries.
 */
#[CoversClass(ToolMessageChunk::class)]
#[CoversClass(BaseMessageChunk::class)]
final class ToolMessageChunkArtifactSerializationTest extends TestCase
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
    public function testConstructorAndTrustedLoadKeepSuppliedArtifact(mixed $artifact, mixed $serialized): void
    {
        $chunk = self::chunk($artifact);
        $record = $chunk->toJson();
        self::assertSame(ToolMessageChunk::lcId(), $record['id']);
        self::assertArrayHasKey('artifact', $record['kwargs']);
        self::assertSame($serialized, $record['kwargs']['artifact']);
        self::assertSame($serialized, $chunk->toDict()['data']['artifact']);
        $loaded = Load::load(json_encode($chunk, JSON_THROW_ON_ERROR), ['secretsMap' => ['FAKE_ARTIFACT_SECRET' => 'fake-substitution']]);
        self::assertInstanceOf(ToolMessageChunk::class, $loaded);
        self::assertSame($artifact, $loaded->artifact);
        self::assertSame($record, $loaded->toJson());
    }

    #[DataProvider('artifacts')]
    public function testPhpPromotionKeepsUnescapedSuppliedArtifact(mixed $artifact, mixed $serialized): void
    {
        $message = self::chunk($artifact)->toMessage();
        self::assertInstanceOf(ToolMessage::class, $message);
        self::assertArrayHasKey('artifact', $message->kwargs());
        self::assertSame($artifact, $message->artifact);
        self::assertSame($serialized, $message->toJson()['kwargs']['artifact']);
        self::assertSame('visible result', $message->content);
        self::assertSame('call', $message->toolCallId);
    }

    #[DataProvider('artifacts')]
    public function testStoredChunksPromoteAndPhpUnwrappingRemainsStable(mixed $artifact, mixed $serialized): void
    {
        $message = self::chunk($artifact);
        $initial = $message->toDict();
        self::assertArrayHasKey('artifact', $initial['data']);
        self::assertSame($serialized, $initial['data']['artifact']);
        for ($round = 0; $round < 2; ++$round) {
            $message = MessageUtils::mapStoredMessageToChatMessage(json_decode(json_encode($message->toDict(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            // Both source and PHP promote stored type "tool" to ToolMessage. E2 unwrapping is PHP.
            self::assertInstanceOf(ToolMessage::class, $message);
            self::assertSame($artifact, $message->artifact);
            self::assertSame($initial, $message->toDict());
        }
    }

    #[DataProvider('artifacts')]
    public function testChunkCheckpointStaysUnknownConstructorDataWithEscapedBarrier(mixed $artifact, mixed $serialized): void
    {
        $chunk = self::chunk($artifact);
        $serde = new JsonPlusSerializer();
        [$type, $payload] = $serde->dumpsTyped($chunk);
        $record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('artifact', $record['kwargs']);
        self::assertSame($serialized, $record['kwargs']['artifact']);
        $restored = $serde->loadsTyped($type, $payload);
        self::assertIsArray($restored);
        self::assertSame(ToolMessageChunk::lcId(), $restored['id']);
        // N27: unknown constructor has no kwargs unwrapping/revival boundary.
        self::assertSame($serialized, $restored['kwargs']['artifact']);
    }

    #[DataProvider('artifacts')]
    public function testPromotedMessageSurvivesTwoActualCheckpointRounds(mixed $artifact, mixed $serialized): void
    {
        $serde = new JsonPlusSerializer();
        foreach ([false, true] as $container) {
            $message = self::chunk($artifact)->toMessage();
            $initial = null;
            for ($round = 0; $round < 2; ++$round) {
                [$type, $payload] = $serde->dumpsTyped($container ? ['messages' => [$message]] : $message);
                $initial ??= $payload;
                self::assertSame($initial, $payload);
                $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $record = $container ? $decoded['messages'][0] : $decoded;
                self::assertArrayHasKey('artifact', $record['kwargs']);
                self::assertSame($serialized, $record['kwargs']['artifact']);
                $restored = $serde->loadsTyped($type, $payload);
                $message = $container ? $restored['messages'][0] : $restored;
                self::assertInstanceOf(ToolMessage::class, $message);
                self::assertSame($artifact, $message->artifact);
                self::assertSame('call', $message->toolCallId);
            }
        }
    }

    public function testOmissionStaysOmittedThroughMutationAndPromotion(): void
    {
        $chunk = new ToolMessageChunk(['content' => 'visible result', 'tool_call_id' => 'call']);
        foreach ([['later' => 1], null] as $artifact) {
            $chunk->artifact = $artifact;
            self::assertArrayNotHasKey('artifact', $chunk->kwargs());
            self::assertArrayNotHasKey('artifact', $chunk->toJson()['kwargs']);
            self::assertArrayNotHasKey('artifact', $chunk->toDict()['data']);
            self::assertArrayNotHasKey('artifact', $chunk->toMessage()->kwargs());
            self::assertNull($chunk->toMessage()->artifact);
        }
    }

    public function testSuppliedArtifactTracksCurrentPropertyIncludingNull(): void
    {
        $chunk = self::chunk(null);
        foreach ([['lc' => 0, 'current' => 1], null] as $artifact) {
            $chunk->artifact = $artifact;
            self::assertArrayHasKey('artifact', $chunk->kwargs());
            self::assertSame($artifact, $chunk->kwargs()['artifact']);
            $message = $chunk->toMessage();
            self::assertArrayHasKey('artifact', $message->kwargs());
            self::assertSame($artifact, $message->artifact);
            self::assertSame($chunk->toJson()['kwargs']['artifact'], $message->toJson()['kwargs']['artifact']);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function nullishConcat(): iterable
    {
        foreach ([false, true] as $left) {
            foreach ([false, true] as $right) {
                yield ($left ? 'null' : 'omitted') . '+' . ($right ? 'null' : 'omitted') => [$left, $right];
            }
        }
    }

    #[DataProvider('nullishConcat')]
    public function testNullishConcatOmitsSourceUndefinedEquivalentArtifact(bool $leftNull, bool $rightNull): void
    {
        $left = ['content' => 'a', 'tool_call_id' => 'left'];
        $right = ['content' => 'b', 'tool_call_id' => 'right'];
        if ($leftNull) {
            $left['artifact'] = null;
        }
        if ($rightNull) {
            $right['artifact'] = null;
        }
        $merged = (new ToolMessageChunk($left))->concat(new ToolMessageChunk($right));
        self::assertNull($merged->artifact);
        self::assertArrayNotHasKey('artifact', $merged->kwargs());
        self::assertArrayNotHasKey('artifact', $merged->toJson()['kwargs']);
        self::assertArrayNotHasKey('artifact', $merged->toMessage()->kwargs());
        self::assertSame('ab', $merged->content);
        self::assertSame('left', $merged->toolCallId);
        self::assertArrayHasKey('artifact', self::chunk(null)->toJson()['kwargs']);
    }

    #[DataProvider('nullishConcat')]
    public function testNullishConcatRetainsCapturedKeyAfterLateArtifactAssignment(bool $leftNull, bool $rightNull): void
    {
        $left = ['content' => 'a', 'tool_call_id' => 'call'];
        $right = ['content' => 'b', 'tool_call_id' => 'call'];
        if ($leftNull) {
            $left['artifact'] = null;
        }
        if ($rightNull) {
            $right['artifact'] = null;
        }
        $merged = (new ToolMessageChunk($left))->concat(new ToolMessageChunk($right));
        $artifact = ['late' => 1, 'literal' => ['lc' => 0]];
        $merged->artifact = $artifact;
        self::assertArrayHasKey('artifact', $merged->toJson()['kwargs']);
        self::assertSame(['late' => 1, 'literal' => ['__lc_escaped__' => ['lc' => 0]]], $merged->toJson()['kwargs']['artifact']);
        self::assertSame($artifact, $merged->toMessage()->artifact);
        $merged->artifact = null;
        self::assertArrayHasKey('artifact', $merged->toJson()['kwargs']);
        self::assertNull($merged->toJson()['kwargs']['artifact']);
        self::assertArrayHasKey('artifact', $merged->toMessage()->kwargs());
    }

    public function testPromotionCanBeFirstObservationOfLateConcatArtifact(): void
    {
        $left = new ToolMessageChunk(['content' => 'a', 'tool_call_id' => 'call']);
        $merged = $left->concat(new ToolMessageChunk(['content' => 'b', 'tool_call_id' => 'call']));
        $merged->artifact = false;
        self::assertFalse($merged->toMessage()->artifact);
        $merged->artifact = null;
        self::assertArrayHasKey('artifact', $merged->toMessage()->kwargs());
    }

    public function testUnobservedExplicitNullWriteKeepsPhp82UndefinedValueAmbiguity(): void
    {
        $left = new ToolMessageChunk(['content' => 'a', 'tool_call_id' => 'call']);
        $merged = $left->concat(new ToolMessageChunk(['content' => 'b', 'tool_call_id' => 'call']));
        $merged->artifact = null;
        // Source serializes explicit null. PHP 8.2 has no property hooks to distinguish this write
        // from an unchanged undefined-equivalent null, so this residual remains explicit.
        self::assertArrayNotHasKey('artifact', $merged->toJson()['kwargs']);
        self::assertArrayNotHasKey('artifact', $merged->toMessage()->kwargs());
    }

    /** @return iterable<string, array{mixed, mixed, mixed}> */
    public static function mergedArtifacts(): iterable
    {
        yield 'false' => [false, false, false];
        yield 'zero' => [0, 0, 0];
        yield 'empty string' => ['', '', ''];
        yield 'empty list' => [[], [], []];
        yield 'dict merge' => [['x' => 1], ['y' => 2], ['x' => 1, 'y' => 2]];
        yield 'sum' => [['x' => 1], ['x' => 1], ['x' => 2]];
        yield 'null with false' => [null, false, false];
        yield 'null with zero' => [null, 0, 0];
    }

    #[DataProvider('mergedArtifacts')]
    public function testConcatNonNullArtifactsRemainCaptured(mixed $left, mixed $right, mixed $expected): void
    {
        $merged = self::chunk($left)->concat(self::chunk($right));
        self::assertArrayHasKey('artifact', $merged->kwargs());
        self::assertSame($expected, $merged->kwargs()['artifact']);
        self::assertSame($expected, $merged->toMessage()->artifact);
    }

    public function testConcatCapturesCurrentPropertyFromOriginallyOmittedChunk(): void
    {
        $left = new ToolMessageChunk(['content' => 'a', 'tool_call_id' => 'call']);
        $left->artifact = ['x' => 1];
        $right = self::chunk(['y' => 2]);
        $merged = $left->concat($right);
        self::assertArrayNotHasKey('artifact', $left->kwargs());
        self::assertArrayHasKey('artifact', $merged->kwargs());
        self::assertSame(['x' => 1, 'y' => 2], $merged->kwargs()['artifact']);
        self::assertSame(['x' => 1, 'y' => 2], $merged->toMessage()->artifact);
    }

    public function testConcatIncompatibleArtifactsStillThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot merge objects of different types.\nLeft object\nRight boolean");
        self::chunk(['x' => 1])->concat(self::chunk(false));
    }

    public function testPhpToolConversionNonNullPipelineAndExistingNullKeyResidual(): void
    {
        $first = MessageUtils::convertToChunk(new ToolMessage(['content' => 'a', 'tool_call_id' => 'call', 'artifact' => ['x' => 1]]));
        $second = MessageUtils::convertToChunk(new ToolMessage(['content' => 'b', 'tool_call_id' => 'call', 'artifact' => ['y' => 2]]));
        self::assertSame(['x' => 1, 'y' => 2], $first->concat($second)->toMessage()->artifact);
        $explicitNull = MessageUtils::convertToChunk(new ToolMessage(['content' => 'a', 'tool_call_id' => 'call', 'artifact' => null]));
        // Existing unowned PHP tool-convert extension drops the null key; source rejects tools.
        self::assertArrayNotHasKey('artifact', $explicitNull->kwargs());
    }

    public function testGenuineNestedMessageRevivesWhileLiteralConstructorStaysData(): void
    {
        $literal = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']];
        $chunk = self::chunk(['genuine' => new HumanMessage('real object'), 'literal' => $literal]);
        $loaded = Load::load(json_encode($chunk, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(ToolMessageChunk::class, $loaded);
        self::assertIsArray($loaded->artifact);
        self::assertInstanceOf(HumanMessage::class, $loaded->artifact['genuine']);
        self::assertSame($literal, $loaded->artifact['literal']);
        $message = $chunk->toMessage();
        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped($message);
            $message = $serde->loadsTyped($type, $payload);
            self::assertInstanceOf(HumanMessage::class, $message->artifact['genuine']);
            self::assertSame('real object', $message->artifact['genuine']->content);
            self::assertSame($literal, $message->artifact['literal']);
        }
    }

    public function testEmptyObjectPreservesWireButKeepsExistingDecodeShapeAdaptation(): void
    {
        $chunk = self::chunk(new \stdClass());
        $json = json_encode($chunk, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"artifact":{}', $json);
        self::assertSame([], Load::load($json)->artifact);
        self::assertStringContainsString('"artifact":{}', json_encode($chunk->toMessage(), JSON_THROW_ON_ERROR));
    }

    public function testOtherChunkPromotionPositiveControls(): void
    {
        $fields = ['content' => 'visible', 'additional_kwargs' => ['x' => 1], 'response_metadata' => ['y' => 2], 'name' => 'name', 'id' => 'id'];
        foreach ([new HumanMessageChunk($fields), new SystemMessageChunk($fields), new AIMessageChunk($fields)] as $chunk) {
            $message = $chunk->toMessage();
            self::assertInstanceOf(match (true) {
                $chunk instanceof HumanMessageChunk => HumanMessage::class,
                $chunk instanceof SystemMessageChunk => SystemMessage::class,
                default => AIMessage::class,
            }, $message);
            self::assertSame('visible', $message->content);
            self::assertSame(['x' => 1], $message->additional_kwargs);
            self::assertSame(['y' => 2], $message->response_metadata);
            self::assertSame('name', $message->name);
            self::assertSame('id', $message->id);
            self::assertArrayNotHasKey('artifact', $message->kwargs());
        }
    }

    public function testPromotedCheckpointProviderPipelineExcludesArtifact(): void
    {
        $literal = ['lc' => 0, 'secret' => ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_ARTIFACT_SECRET']], 'wrapper' => ['__lc_escaped__' => ['lc' => null]]];
        $message = self::chunk($literal)->toMessage();
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
        self::assertStringNotContainsString('"artifact"', $http->requests[0]['body']);
        self::assertStringNotContainsString('__lc_escaped__', $http->requests[0]['body']);
        self::assertStringNotContainsString('FAKE_ARTIFACT_SECRET', $http->requests[0]['body']);
    }

    private static function chunk(mixed $artifact): ToolMessageChunk
    {
        return new ToolMessageChunk(['content' => 'visible result', 'tool_call_id' => 'call', 'artifact' => $artifact]);
    }
}
