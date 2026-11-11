<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Serde;

use LangChain\Messages;
use LangChain\Schema;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Checkpoint\Serde\LcConstructorLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Closed checkpoint subset, with source class revival and retained PHP literal/unknown-record hardening. */
#[CoversClass(LcConstructorLoader::class)]
final class CheckpointCoreConstructorRegistryTest extends TestCase
{
    private static function literals(): array
    {
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => Messages\HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']];

        return ['constructor' => $constructor, 'secret' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_MISSING']],
            'singleton' => ['__lc_escaped__' => $constructor], 'lc2' => ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['literal']]];
    }

    /** @return iterable<string, array{object}> */
    public static function coreObjects(): iterable
    {
        $fields = ['content' => 'public', 'id' => 'message-id', 'name' => 'public-name',
            'additional_kwargs' => self::literals(), 'response_metadata' => ['finish_reason' => 'done']];
        foreach (['HumanMessage', 'AIMessage', 'SystemMessage', 'ChatMessage', 'FunctionMessage', 'ToolMessage',
            'HumanMessageChunk', 'AIMessageChunk', 'SystemMessageChunk', 'ChatMessageChunk', 'FunctionMessageChunk', 'ToolMessageChunk'] as $name) {
            $class = 'LangChain\\Messages\\' . $name;
            $specific = match ($name) {
                'ChatMessage', 'ChatMessageChunk' => ['role' => 'moderator'],
                'ToolMessage', 'ToolMessageChunk' => ['tool_call_id' => 'call', 'artifact' => self::literals()],
                'AIMessage' => ['tool_calls' => [['id' => 'call', 'name' => 'tool', 'args' => self::literals()]]],
                'AIMessageChunk' => ['tool_call_chunks' => [['id' => 'call', 'name' => 'tool', 'index' => 0, 'args' => json_encode(self::literals(), JSON_THROW_ON_ERROR)]]],
                default => [],
            };
            yield $name => [new $class($fields + $specific)];
        }
        yield 'RemoveMessage' => [new Messages\RemoveMessage(['id' => 'remove-id'])];
        yield 'StringPromptValue' => [new Schema\StringPromptValue('public text')];
        yield 'ChatPromptValue' => [new Schema\ChatPromptValue([
            new Messages\HumanMessage($fields),
            new Messages\ToolMessage(['content' => 'tool text', 'tool_call_id' => 'call', 'artifact' => self::literals()]),
        ])];
    }

    #[DataProvider('coreObjects')]
    public function testTwoRealCheckpointRoundsRetainExactClassAndSemanticFields(object $original): void
    {
        $serde = new JsonPlusSerializer();
        $initial = json_encode($original, JSON_THROW_ON_ERROR);
        $object = $original;
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped(['value' => $object]);
            self::assertSame('json', $type);
            $object = $serde->loadsTyped($type, $payload)['value'];
            self::assertSame($original::class, is_object($object) ? $object::class : gettype($object));
            self::assertSame($initial, json_encode($object, JSON_THROW_ON_ERROR));
            if ($object instanceof Messages\BaseMessage) {
                self::assertSame($original->content, $object->content);
                self::assertSame($original->id, $object->id);
                self::assertSame($original->additional_kwargs, $object->additional_kwargs);
                self::assertSame($original->response_metadata, $object->response_metadata);
                if ($object instanceof Messages\ToolMessage || $object instanceof Messages\ToolMessageChunk) {
                    self::assertSame('call', $object->toolCallId);
                    self::assertSame(self::literals(), $object->artifact);
                }
                if ($object instanceof Messages\AIMessage) {
                    self::assertSame(self::literals(), $object->toolCalls[0]['args']);
                }
                if ($object instanceof Messages\AIMessageChunk) {
                    self::assertSame($original->toolCallChunks, $object->toolCallChunks);
                }
            } elseif ($object instanceof Schema\StringPromptValue) {
                self::assertSame('public text', $object->toStringValue());
            } elseif ($object instanceof Schema\ChatPromptValue) {
                self::assertSame(Messages\HumanMessage::class, $object->messages[0]::class);
                self::assertSame('public', $object->messages[0]->content);
                self::assertSame(self::literals(), $object->messages[0]->additional_kwargs);
                self::assertSame(Messages\ToolMessage::class, $object->messages[1]::class);
                self::assertSame(self::literals(), $object->messages[1]->artifact);
            }
        }
        self::assertSame($initial, json_encode($original, JSON_THROW_ON_ERROR), 'caller serialization is unchanged');
    }

    public function testPromptValueAdaptersAcceptBottomUpGenuineMessagesAndPreserveCallerIdentity(): void
    {
        $message = new Messages\HumanMessage('genuine');
        $record = ['lc' => 1, 'type' => 'constructor', 'id' => Schema\ChatPromptValue::lcId(), 'kwargs' => ['messages' => [$message]]];
        $before = serialize($record);
        $value = LcConstructorLoader::load($record);
        self::assertSame(Schema\ChatPromptValue::class, $value::class);
        self::assertSame($message, $value->messages[0]);
        self::assertSame($before, serialize($record));
        $record['kwargs']['messages'] = [$message->toJson()];
        $value = (new JsonPlusSerializer())->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(Schema\ChatPromptValue::class, $value::class);
        self::assertSame(Messages\HumanMessage::class, $value->messages[0]::class);
        self::assertSame('genuine', $value->messages[0]->content);
    }

    /** @return iterable<string, array{array}> */
    public static function unknownRecords(): iterable
    {
        $kwargs = ['content' => 'public', 'metadata' => ['__lc_escaped__' => ['lc' => 0]],
            'nested' => ['__lc_escaped__' => (new Messages\HumanMessage('literal'))->toJson()]];
        foreach ([['php', 'stdClass'], ['synthetic', 'Unregistered'], Schema\Document::lcId(),
            ['langchain', 'chat_models', 'openai', 'ChatOpenAI'], ['langchain', 'tools', 'DynamicTool'],
            ['langchain_core', 'messages', 'BaseMessage']] as $id) {
            yield implode('/', $id) => [['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => $kwargs]];
        }
    }

    #[DataProvider('unknownRecords')]
    public function testUnknownProviderDocumentAndAbstractRecordsStayInertWithoutUnescaping(array $record): void
    {
        $before = serialize($record);
        self::assertSame($record, LcConstructorLoader::load($record));
        $serde = new JsonPlusSerializer();
        // Feed the already serialized envelope directly: dumping it would wrap the outer LC marker.
        self::assertSame($record, $serde->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR)));
        self::assertSame($before, serialize($record));
    }

    public function testWrappedKnownConstructorsAndNotImplementedDispositionRemainLiteral(): void
    {
        $serde = new JsonPlusSerializer();
        foreach ([Messages\HumanMessage::lcId(), Messages\ToolMessageChunk::lcId(), Schema\ChatPromptValue::lcId()] as $id) {
            $literal = ['__lc_escaped__' => ['lc' => 1, 'type' => 'constructor', 'id' => $id,
                'kwargs' => ['content' => 'literal', 'tool_call_id' => 'call', 'messages' => [(new Messages\HumanMessage('nested literal'))->toJson()]]]];
            self::assertSame($literal, $serde->loadsTyped('json', json_encode($literal, JSON_THROW_ON_ERROR)));
        }
        $notImplemented = ['lc' => 1, 'type' => 'not_implemented', 'id' => ['langchain_core', 'example_selectors', 'base', 'LengthBasedExampleSelector']];
        self::assertSame($notImplemented, $serde->loadsTyped('json', json_encode($notImplemented, JSON_THROW_ON_ERROR)));
        $lc2 = ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'method' => 'phpversion', 'args' => ['public']];
        self::assertSame($lc2, $serde->loadsTyped('json', json_encode($lc2, JSON_THROW_ON_ERROR)));
    }

    public function testAlreadyRevivedNativeChildrenKeepTheirRuntimeIdentityAtTheKnownBoundary(): void
    {
        $error = new \RuntimeException('native public');
        $date = new \DateTimeImmutable('2020-01-01T00:00:00Z');
        $record = ['lc' => 1, 'type' => 'constructor', 'id' => Messages\HumanMessageChunk::lcId(),
            'kwargs' => ['content' => 'public', 'additional_kwargs' => ['error' => $error, 'date' => $date]]];
        $chunk = LcConstructorLoader::load($record);
        self::assertSame(Messages\HumanMessageChunk::class, is_object($chunk) ? $chunk::class : gettype($chunk));
        self::assertSame($error, $chunk->additional_kwargs['error']);
        self::assertSame($date, $chunk->additional_kwargs['date']);
        $record['kwargs']['additional_kwargs'] = ['error' => ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['genuine public']]];
        $chunk = (new JsonPlusSerializer())->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(Messages\HumanMessageChunk::class, $chunk::class);
        self::assertInstanceOf(\RuntimeException::class, $chunk->additional_kwargs['error']);
        self::assertSame('genuine public', $chunk->additional_kwargs['error']->getMessage());
    }

    public function testExistingKnownMessageMalformedKwargsPolicyIsRetained(): void
    {
        foreach ([null, 'bad', 42, []] as $kwargs) {
            $record = ['lc' => 1, 'type' => 'constructor', 'id' => Messages\HumanMessage::lcId(), 'kwargs' => $kwargs];
            $value = LcConstructorLoader::load($record);
            self::assertSame(Messages\HumanMessage::class, $value::class);
            self::assertSame((new Messages\HumanMessage([]))->toJson(), $value->toJson());
        }
    }

    public function testChunkArtifactOmissionExplicitNullAndObservedLateConcatValueStayDistinct(): void
    {
        $omitted = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call']);
        $omitted->artifact = ['uncaptured' => true];
        $null = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call', 'artifact' => null]);
        $late = $omitted->concat(new Messages\ToolMessageChunk(['content' => '', 'tool_call_id' => 'call']));
        $late->artifact = self::literals();
        $serde = new JsonPlusSerializer();
        foreach ([$omitted, $null, $late] as $index => $chunk) {
            for ($round = 0; $round < 2; ++$round) {
                $chunk = $serde->loadsTyped(...$serde->dumpsTyped($chunk));
                self::assertSame(Messages\ToolMessageChunk::class, $chunk::class);
                if ($index === 0) {
                    self::assertArrayNotHasKey('artifact', $chunk->kwargs());
                    self::assertNull($chunk->artifact);
                } elseif ($index === 1) {
                    self::assertArrayHasKey('artifact', $chunk->kwargs());
                    self::assertNull($chunk->artifact);
                } else {
                    self::assertSame(self::literals(), $chunk->artifact);
                }
            }
        }
    }
}
