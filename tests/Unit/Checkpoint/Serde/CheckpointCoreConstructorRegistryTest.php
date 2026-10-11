<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Serde;

use LangChain\Messages;
use LangChain\Schema;
use LangChain\Tests\Unit\Messages\ToolMessageChunkArtifactSerializationTest;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Checkpoint\Serde\LcConstructorLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Canonical checkpoint registry with exact genuine identities and explicit PHP legacy/typed/literal policies. */
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
        yield 'ChatPromptValue' => [new Schema\ChatPromptValue(self::mixedMessages())];
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
                self::assertSame($original->getType(), $object->getType());
                self::assertSame($original->name, $object->name);
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
                foreach ($original->messages as $key => $expected) {
                    self::assertMessage($expected, $object->messages[$key]);
                }
            }
        }
        self::assertSame($initial, json_encode($original, JSON_THROW_ON_ERROR), 'caller serialization is unchanged');
    }

    public function testPromptValueAdaptersAcceptBottomUpGenuineMessagesAndPreserveCallerIdentity(): void
    {
        foreach (self::mixedMessages() as $message) {
            $value = LcConstructorLoader::load(['id' => Schema\ChatPromptValue::lcId(), 'kwargs' => ['messages' => [$message]]]);
            self::assertSame($message, $value->messages[0]);
        }
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
        $serde = new JsonPlusSerializer();
        foreach (['omitted late', 'explicit null', 'captured late', 'concat nullish late', 'concat nullish'] as $kind) {
            foreach ([false, true] as $nested) {
                $fields = ['content' => 'public', 'tool_call_id' => 'call'];
                if ($kind === 'explicit null' || $kind === 'captured late') {
                    $fields['artifact'] = null;
                }
                $chunk = new Messages\ToolMessageChunk($fields);
                if (str_starts_with($kind, 'concat')) {
                    $chunk = $chunk->concat(new Messages\ToolMessageChunk($fields));
                }
                if (str_ends_with($kind, 'late')) {
                    $chunk->artifact = ['lc' => 0, 'k' => ['__lc_escaped__' => 'x']];
                }
                $expected = $chunk->toJson();
                $captured = array_key_exists('artifact', $chunk->kwargs());
                $artifact = $captured ? $chunk->artifact : null;
                $value = $nested ? new Schema\ChatPromptValue([$chunk]) : $chunk;
                for ($round = 0; $round < 2; ++$round) {
                    $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
                    $restored = $nested ? $value->messages[0] : $value;
                    self::assertSame(Messages\ToolMessageChunk::class, get_debug_type($restored));
                    self::assertSame($expected, $restored->toJson());
                    self::assertSame($captured, array_key_exists('artifact', $restored->kwargs()));
                    self::assertSame($artifact, $restored->artifact);
                }
                self::assertSame($expected, $chunk->toJson());
            }
        }
    }

    /** @return list<Messages\BaseMessage> */
    private static function mixedMessages(): array
    {
        $messages = [];
        foreach (self::coreObjects() as $name => [$object]) {
            if (!$object instanceof Messages\BaseMessage) {
                break;
            }
            $messages[] = $object;
        }
        foreach (['human', 'tool', 'remove'] as $role) {
            $messages[] = new Messages\ChatMessage(['content' => 'ordinary', 'role' => $role]);
            $messages[] = new Messages\ChatMessageChunk(['content' => 'chunk', 'role' => $role]);
        }

        return $messages;
    }

    private static function assertMessage(Messages\BaseMessage $expected, mixed $actual): void
    {
        self::assertSame($expected::class, get_debug_type($actual));
        self::assertSame($expected->content, $actual->content);
        self::assertSame($expected->getType(), $actual->getType());
        self::assertSame($expected->id, $actual->id);
        self::assertSame($expected->name, $actual->name);
        self::assertSame($expected->additional_kwargs, $actual->additional_kwargs);
        self::assertSame($expected->response_metadata, $actual->response_metadata);
        self::assertSame($expected->toJson(), $actual->toJson());
        if ($expected instanceof Messages\ToolMessage || $expected instanceof Messages\ToolMessageChunk) {
            self::assertSame($expected->artifact, $actual->artifact);
            self::assertSame($expected->toolCallId, $actual->toolCallId);
        }
        if ($expected instanceof Messages\AIMessageChunk) {
            self::assertSame($expected->toolCallChunks, $actual->toolCallChunks);
        }
        if ($expected instanceof Messages\AIMessage) {
            self::assertSame($expected->toolCalls, $actual->toolCalls);
        }
    }

    public function testMixedNineteenExactClassesFieldsAndCollidingRolesSurviveTwoRounds(): void
    {
        $original = new Schema\ChatPromptValue(self::mixedMessages());
        self::assertCount(19, $original->messages);
        $before = serialize($original);
        $value = $original;
        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
            self::assertSame(Schema\ChatPromptValue::class, get_debug_type($value));
            foreach ($original->messages as $key => $message) {
                self::assertMessage($message, $value->messages[$key]);
            }
        }
        self::assertSame($before, serialize($original));
    }

    public function testOriginalFourteenArtifactsAndNestedSingletonDiscriminatorOverBothPaths(): void
    {
        $cases = iterator_to_array(ToolMessageChunkArtifactSerializationTest::artifacts());
        self::assertCount(14, $cases);
        $cases['nested singleton discriminator'] = [['lc' => 0, 'k' => ['__lc_escaped__' => 'x']], null];
        $serde = new JsonPlusSerializer();
        foreach ($cases as [$artifact]) {
            foreach ([false, true] as $nested) {
                $chunk = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call', 'artifact' => $artifact]);
                $value = $nested ? new Schema\ChatPromptValue([$chunk]) : $chunk;
                for ($round = 0; $round < 2; ++$round) {
                    $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
                    $chunk = $nested ? $value->messages[0] : $value;
                    self::assertSame(Messages\ToolMessageChunk::class, get_debug_type($chunk));
                    self::assertSame($artifact, $chunk->artifact);
                    self::assertArrayHasKey('artifact', $chunk->kwargs());
                    self::assertSame('public', $chunk->content);
                    self::assertSame('call', $chunk->toolCallId);
                }
            }
        }
    }

    /** Execute a real load and assert stable policy errors without PHP warnings. */
    private static function assertFailure(array $record, string $class, ?string $message = null): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $text) use (&$warnings): bool {
            $warnings[] = [$level, $text];

            return true;
        });
        $failure = null;
        try {
            (new JsonPlusSerializer())->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            restore_error_handler();
        }
        self::assertSame([], $warnings);
        self::assertInstanceOf($class, $failure);
        if ($message !== null) {
            self::assertSame($message, $failure->getMessage());
        }
    }

    private static function record(array $id, mixed $kwargs): array
    {
        return ['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => $kwargs];
    }

    public function testTypedPromptMissingNullAndInvalidFieldsAreWarningFree(): void
    {
        foreach ([Schema\ChatPromptValue::lcId(), Schema\StringPromptValue::lcId()] as $id) {
            $missing = self::record($id, []);
            unset($missing['kwargs']);
            self::assertFailure($missing, \TypeError::class);
            foreach ([null, 'bad', 5, true, [], ['unrelated' => 'x']] as $kwargs) {
                self::assertFailure(self::record($id, $kwargs), \TypeError::class);
            }
        }
        foreach ([null, 'bad', 5, true] as $messages) {
            self::assertFailure(self::record(Schema\ChatPromptValue::lcId(), ['messages' => $messages]), \TypeError::class);
        }
        foreach ([null, 5, [], ['value' => 'x']] as $text) {
            self::assertFailure(self::record(Schema\StringPromptValue::lcId(), ['text' => $text]), \TypeError::class);
        }
        self::assertFailure(self::record(Schema\StringPromptValue::lcId(), ['value' => 'source positional bug']), \TypeError::class);
        $serde = new JsonPlusSerializer();
        $empty = $serde->loadsTyped(...$serde->dumpsTyped(new Schema\ChatPromptValue([])));
        self::assertSame([], $empty->messages);
        $text = $serde->loadsTyped('json', json_encode(self::record(Schema\StringPromptValue::lcId(), ['text' => '5']), JSON_THROW_ON_ERROR));
        self::assertSame('5', $text->toStringValue());
    }

    public function testMalformedLegacyEntriesRejectExactlyWithoutWarnings(): void
    {
        $plain = ['data' => ['content' => 'public']];
        $cases = [$plain, ['type' => null] + $plain, ['type' => ['x']] + $plain,
            ['type' => 5] + $plain, ['type' => true] + $plain, ['type' => (object) ['x' => 'public']] + $plain,
            ['type' => 'human', 'text' => 'public'], ['type' => 'human'],
            ['type' => 'human', 'data' => null], ['type' => 'human', 'data' => 'public'],
            ['type' => 'human', 'data' => 5], ['type' => 'human', 'data' => true],
            ['lc' => 1, 'type' => 'constructor', 'id' => ['synthetic', 'Unknown'], 'kwargs' => []] + $plain,
            ['lc' => 2, 'type' => 'constructor', 'id' => ['SyntheticUnknown'], 'args' => []] + $plain,
            ['lc' => 0, 'type' => 'human'] + $plain, ['lc' => null, 'type' => 'human'] + $plain];
        self::assertCount(16, $cases);
        foreach ($cases as $entry) {
            self::assertFailure(self::record(Schema\ChatPromptValue::lcId(), ['messages' => [$entry]]),
                \InvalidArgumentException::class, 'ChatPromptValue requires serialized message objects or message dictionaries.');
        }
    }

    public function testCanonicalIdGuardRejectsCoercionsForEveryRegisteredClassWithoutWarningsOrUnescaping(): void
    {
        $serde = new JsonPlusSerializer();
        foreach (self::coreObjects() as [$object]) {
            $id = $object::lcId();
            $ids = [[implode('|', $id)], [implode('|', array_slice($id, 0, 2)), $id[2]],
                ['a' => $id[0], 'b' => $id[1], 'c' => $id[2]], [$id[0], [$id[1]], $id[2]],
                [$id[0], $id[1], 'extra', $id[2]], [], [$id[0], 5, $id[2]], [$id[0], null, $id[2]],
                [$id[0], true, $id[2]], [$id[0], $id[1], strtolower($id[2])], ['wrong', $id[1], $id[2]],
                [1 => $id[0], 2 => $id[1], 3 => $id[2]], [$id[0], $id[1], $id[2] . '|']];
            foreach ($ids as $badId) {
                $record = self::record($badId, ['artifact' => ['__lc_escaped__' => ['lc' => 0]], 'content' => 'inert']);
                $warnings = [];
                set_error_handler(static function (int $n, string $s) use (&$warnings): bool { $warnings[] = [$n, $s]; return true; });
                try {
                    self::assertSame($record, LcConstructorLoader::load($record));
                    self::assertSame($record, $serde->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR)));
                } finally {
                    restore_error_handler();
                }
                self::assertSame([], $warnings);
            }
        }
    }

    public function testUnknownChildIsLoudButUnknownParentRetainsBottomUpGenuineChildren(): void
    {
        foreach ([['synthetic', 'Unknown'], ['langchain_core|messages|HumanMessage']] as $id) {
            self::assertFailure(self::record(Schema\ChatPromptValue::lcId(), ['messages' => [self::record($id, ['content' => 'public'])]]),
                \InvalidArgumentException::class, 'ChatPromptValue requires serialized message objects or message dictionaries.');
        }
        $record = self::record(['synthetic', 'Parent'], ['child' => new Messages\HumanMessage('genuine'),
            'literal' => ['__lc_escaped__' => ['lc' => 0]]]);
        $value = (new JsonPlusSerializer())->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertIsArray($value);
        self::assertSame(Messages\HumanMessage::class, get_debug_type($value['kwargs']['child']));
        self::assertSame('genuine', $value['kwargs']['child']->content);
        self::assertSame($record['kwargs']['literal'], $value['kwargs']['literal']);
    }

    public function testRemoveValidationAndAmbiguousLegacyRemoveRefusalAreExplicit(): void
    {
        foreach ([null, 'bad', 5, [], ['id' => null], ['id' => 5]] as $kwargs) {
            self::assertFailure(self::record(Messages\RemoveMessage::lcId(), $kwargs),
                \InvalidArgumentException::class, 'RemoveMessage requires a string id.');
        }
        self::assertFailure(self::record(Schema\ChatPromptValue::lcId(), ['messages' => [['type' => 'remove', 'data' => ['id' => 'ambiguous']]]]),
            \InvalidArgumentException::class, 'Cannot revive legacy stored remove message without constructor identity.');
    }

    public function testSavedHeadAndUnreleasedRoot43PayloadsLoadDirectlyThenRoundTrip(): void
    {
        $payloads = [
            'HEAD zero-wrapper' => <<<'HEAD_JSON'
{"lc":1,"type":"constructor","id":["langchain_core","prompt_values","ChatPromptValue"],"kwargs":{"messages":[{"type":"human","data":{"content":"legacy human","additional_kwargs":{"plain":"public","literal":{"lc":0},"nested":{"__lc_escaped__":{"__lc_escaped__":"x"}}},"response_metadata":[],"id":"old-human"}},{"type":"tool","data":{"content":"legacy tool","additional_kwargs":[],"response_metadata":[],"tool_call_id":"old-call"}}]}}
HEAD_JSON,
            'unreleased root43 escaped' => <<<'ROOT43_JSON'
{"lc":1,"type":"constructor","id":["langchain_core","prompt_values","ChatPromptValue"],"kwargs":{"messages":[{"type":"human","data":{"content":"legacy human","additional_kwargs":{"plain":"public","literal":{"__lc_escaped__":{"__lc_escaped__":{"lc":0}}},"nested":{"__lc_escaped__":{"__lc_escaped__":{"__lc_escaped__":{"__lc_escaped__":"x"}}}}},"response_metadata":[],"id":"old-human"}},{"type":"tool","data":{"content":"legacy tool","additional_kwargs":[],"response_metadata":[],"artifact":{"__lc_escaped__":{"__lc_escaped__":{"lc":0,"k":{"__lc_escaped__":"x"}}}},"tool_call_id":"old-call"}}]}}
ROOT43_JSON,
        ];
        $serde = new JsonPlusSerializer();
        foreach ($payloads as $era => $payload) {
            // These are original saved bytes, not a fresh dump of an inert raw record.
            $value = $serde->loadsTyped('json', $payload);
            for ($round = 0; $round < 2; ++$round) {
                self::assertSame(Schema\ChatPromptValue::class, get_debug_type($value));
                self::assertSame(Messages\HumanMessage::class, get_debug_type($value->messages[0]));
                self::assertSame(Messages\ToolMessage::class, get_debug_type($value->messages[1]));
                self::assertSame('legacy human', $value->messages[0]->content);
                self::assertSame('old-human', $value->messages[0]->id);
                self::assertSame(['lc' => 0], $value->messages[0]->additional_kwargs['literal']);
                self::assertSame($era === 'HEAD zero-wrapper' ? 'x' : ['__lc_escaped__' => ['__lc_escaped__' => 'x']],
                    $value->messages[0]->additional_kwargs['nested']);
                self::assertSame('legacy tool', $value->messages[1]->content);
                self::assertSame('old-call', $value->messages[1]->toolCallId);
                if ($era === 'HEAD zero-wrapper') {
                    self::assertArrayNotHasKey('artifact', $value->messages[1]->kwargs());
                } else {
                    self::assertSame(['lc' => 0, 'k' => ['__lc_escaped__' => 'x']], $value->messages[1]->artifact);
                }
                $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
            }
        }
    }

    public function testLegacyPositiveBoundariesPreserveKeyedMessagesAndRetainedCoercions(): void
    {
        $cases = [
            ['type' => 'moderator', 'data' => ['content' => 'public']],
            ['type' => '', 'data' => ['content' => 'public']],
            ['type' => 'constructor', 'id' => Messages\HumanMessage::lcId(), 'kwargs' => [], 'data' => ['content' => 'public']],
            ['type' => 'human', 'data' => ['content' => 5, 'id' => 5]],
        ];
        $serde = new JsonPlusSerializer();
        foreach ($cases as $key => $entry) {
            $record = self::record(Schema\ChatPromptValue::lcId(), ['messages' => ['k' => $entry]]);
            $value = $serde->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
            for ($round = 0; $round < 2; ++$round) {
                self::assertSame(['k'], array_keys($value->messages));
                $message = $value->messages['k'];
                self::assertSame($key === 3 ? Messages\HumanMessage::class : Messages\ChatMessage::class, get_debug_type($message));
                self::assertSame(['moderator', 'chat', 'constructor', 'human'][$key], $message->getType());
                self::assertSame($key === 3 ? '5' : 'public', $message->content);
                self::assertNull($message->id);
                $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
            }
        }
        $escaped = ['__lc_escaped__' => ['type' => 'human', 'data' => ['content' => 'escaped legacy']]];
        $value = $serde->loadsTyped('json', json_encode(self::record(Schema\ChatPromptValue::lcId(), ['messages' => [$escaped]]), JSON_THROW_ON_ERROR));
        self::assertSame(Messages\HumanMessage::class, get_debug_type($value->messages[0]));
        self::assertSame('escaped legacy', $value->messages[0]->content);
    }

    public function testLegacyZeroWrapperLiteralConstructorAmbiguityAndModernBarrierRemainDistinct(): void
    {
        $literal = (new Messages\HumanMessage('literal public'))->toJson();
        $headEntry = ['type' => 'human', 'data' => ['content' => 'public', 'additional_kwargs' => ['x' => $literal]]];
        $escapedEntry = (new Messages\HumanMessage(['content' => 'public', 'additional_kwargs' => ['x' => $literal]]))->toDict();
        $modernKwargs = \LangChain\Load\Validation::escapeKwargs(['messages' => [$escapedEntry]], new \stdClass());
        $serde = new JsonPlusSerializer();
        foreach ([['messages' => [$headEntry]], $modernKwargs] as $era => $kwargs) {
            $value = $serde->loadsTyped('json', json_encode(self::record(Schema\ChatPromptValue::lcId(), $kwargs), JSON_THROW_ON_ERROR));
            for ($round = 0; $round < 2; ++$round) {
                $artifact = $value->messages[0]->additional_kwargs['x'];
                if ($era === 0) {
                    self::assertSame(Messages\HumanMessage::class, get_debug_type($artifact));
                    self::assertSame('literal public', $artifact->content);
                } else {
                    self::assertSame($literal, $artifact);
                }
                $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
            }
        }
    }

    public function testAllMessageDefaultsRemainConstructorPolicyWithoutNewShapeHardening(): void
    {
        foreach (self::coreObjects() as [$object]) {
            if (!$object instanceof Messages\BaseMessage || $object instanceof Messages\RemoveMessage) {
                continue;
            }
            $class = $object::class;
            foreach ([null, 'bad', 5, true, []] as $kwargs) {
                $record = self::record($class::lcId(), $kwargs);
                $value = LcConstructorLoader::load($record);
                self::assertSame($class, get_debug_type($value));
                self::assertSame((new $class([]))->toJson(), $value->toJson());
            }
            self::assertFailure(self::record($class::lcId(), ['id' => 'without-content']), \InvalidArgumentException::class);
        }
    }

    public function testGenuineNestedNativeFirstLoadLimitAndTopLevelNativeControlAreClassSafe(): void
    {
        $native = ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['synthetic public']];
        $child = self::record(Messages\ToolMessageChunk::lcId(), ['content' => 'public', 'tool_call_id' => 'call', 'artifact' => ['native' => $native]]);
        $serde = new JsonPlusSerializer();
        $value = $serde->loadsTyped('json', json_encode(self::record(Schema\ChatPromptValue::lcId(), ['messages' => [$child]]), JSON_THROW_ON_ERROR));
        self::assertSame(Messages\ToolMessageChunk::class, get_debug_type($value->messages[0]));
        self::assertSame(\RuntimeException::class, get_debug_type($value->messages[0]->artifact['native']));
        self::assertSame('synthetic public', $value->messages[0]->artifact['native']->getMessage());
        // Existing nested-native encoder loss is outside this registry slice.
        $value = $serde->loadsTyped(...$serde->dumpsTyped($value));
        self::assertSame([], $value->messages[0]->artifact['native']);
        $top = $serde->loadsTyped(...$serde->dumpsTyped(new \RuntimeException('synthetic public')));
        self::assertSame(\RuntimeException::class, get_debug_type($top));
        self::assertSame('synthetic public', $top->getMessage());
    }

    public function testNonArrayIdsAndNonStringPartsRemainInertThroughBothPublicPaths(): void
    {
        foreach ([null, 'langchain_core|messages|HumanMessage', 5, false,
            ['langchain_core', (object) ['value' => 'messages'], 'HumanMessage']] as $id) {
            $record = ['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => ['content' => 'inert',
                'artifact' => ['__lc_escaped__' => ['lc' => 0]]]];
            self::assertSame($record, LcConstructorLoader::load($record));
            $json = json_encode($record, JSON_THROW_ON_ERROR);
            self::assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), (new JsonPlusSerializer())->loadsTyped('json', $json));
        }
    }

    public function testNestedUnknownChunkArtifactStaysEscapedByItsOwnUnknownBoundary(): void
    {
        $chunk = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call',
            'artifact' => ['lc' => 0, 'k' => ['__lc_escaped__' => 'x']]]);
        $outer = new Messages\ToolMessage(['content' => 'outer', 'tool_call_id' => 'outer-call', 'artifact' => ['genuine' => $chunk]]);
        [$type, $payload] = (new JsonPlusSerializer())->dumpsTyped($outer);
        $record = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $nested = $record['kwargs']['artifact']['genuine'];
        $nested['id'] = ['synthetic', 'UnknownChunk'];
        $record['kwargs']['artifact']['genuine'] = $nested;
        $value = (new JsonPlusSerializer())->loadsTyped($type, json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(Messages\ToolMessage::class, get_debug_type($value));
        // The registered outer constructor unescapes its kwargs once; the unknown inner has no extra boundary.
        self::assertSame(['synthetic', 'UnknownChunk'], $value->artifact['genuine']['id']);
        self::assertSame(['lc' => 0, 'k' => ['__lc_escaped__' => 'x']], $value->artifact['genuine']['kwargs']['artifact']);
    }
}
