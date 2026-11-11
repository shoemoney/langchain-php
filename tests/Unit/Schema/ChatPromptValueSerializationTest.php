<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Schema;

use LangChain\Caches\BaseCache;
use LangChain\Load\ImportMap;
use LangChain\Load\Load;
use LangChain\Messages;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\Schema\ChatPromptValue;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Middleware\PiiRedactionMiddleware;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Sdk\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatPromptValue::class)]
#[CoversClass(ImportMap::class)]
#[CoversClass(Messages\ChatMessage::class)]
#[CoversClass(Messages\ChatMessageChunk::class)]
final class ChatPromptValueSerializationTest extends TestCase
{
    private const LITERAL = ['lc' => 0, 'k' => ['__lc_escaped__' => 'x']];

    // Saved HEAD 8a588b04 output: no escape wrappers or tool artifact capture.
    private const HEAD_ERA = '{"lc":1,"type":"constructor","id":["langchain_core","prompt_values","ChatPromptValue"],"kwargs":{"messages":[{"type":"human","data":{"content":"legacy human","additional_kwargs":{"plain":"public","literal":{"lc":0},"nested":{"__lc_escaped__":{"__lc_escaped__":"x"}}},"response_metadata":[],"id":"old-human"}},{"type":"tool","data":{"content":"legacy tool","additional_kwargs":[],"response_metadata":[],"tool_call_id":"old-call"}}]}}';

    // Saved unreleased root43 S1 output: two escape boundaries.
    private const ROOT43_ERA = '{"lc":1,"type":"constructor","id":["langchain_core","prompt_values","ChatPromptValue"],"kwargs":{"messages":[{"type":"human","data":{"content":"legacy human","additional_kwargs":{"plain":"public","literal":{"__lc_escaped__":{"__lc_escaped__":{"lc":0}}},"nested":{"__lc_escaped__":{"__lc_escaped__":{"__lc_escaped__":{"__lc_escaped__":"x"}}}}},"response_metadata":[],"id":"old-human"}},{"type":"tool","data":{"content":"legacy tool","additional_kwargs":[],"response_metadata":[],"artifact":{"__lc_escaped__":{"__lc_escaped__":{"lc":0,"k":{"__lc_escaped__":"x"}}}},"tool_call_id":"old-call"}}]}}';

    private static function wire(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function roundTrip(ChatPromptValue $value): ChatPromptValue
    {
        $restored = Load::load(json_encode($value, JSON_THROW_ON_ERROR));
        self::assertSame(ChatPromptValue::class, $restored::class);

        return $restored;
    }

    private static function mixedMessages(): array
    {
        $f = ['content' => 'public', 'id' => 'message-id', 'name' => 'public-name', 'additional_kwargs' => ['literal' => self::LITERAL], 'response_metadata' => ['literal' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTHETIC_ONLY']]]];

        return [
            new Messages\HumanMessageChunk($f),
            new Messages\AIMessageChunk($f + ['tool_call_chunks' => [['name' => 'tool', 'args' => '{"q":"public"}', 'id' => 'call-id', 'index' => 0]]]),
            new Messages\SystemMessageChunk($f),
            new Messages\ToolMessageChunk($f + ['tool_call_id' => 'call-id', 'artifact' => self::LITERAL]),
            new Messages\FunctionMessageChunk($f),
            new Messages\ChatMessageChunk($f + ['role' => 'moderator']),
            new Messages\RemoveMessage(['id' => 'remove-id']),
            new Messages\HumanMessage($f),
            new Messages\ChatMessage($f + ['role' => 'moderator']),
        ];
    }

    public function testGenuineChildrenKeepExactClassesFieldsAndIdentityAcrossTwoRounds(): void
    {
        $messages = self::mixedMessages();
        $value = new ChatPromptValue($messages);
        $expected = self::wire($messages);
        $factory = ImportMap::core()['langchain_core/prompt_values']['ChatPromptValue'];
        $direct = $factory(['messages' => $messages]);
        foreach ($messages as $i => $m) {
            self::assertSame($m, $value->messages[$i]);
            self::assertSame($m, $value->toMessages()[$i]);
            self::assertSame($m, $value->kwargs()['messages'][$i]);
            self::assertSame($m, $value->toJson()['kwargs']['messages'][$i]);
            self::assertSame($m, $direct->messages[$i]);
        }
        for ($round = 0; $round < 2; ++$round) {
            $wire = self::wire($value);
            foreach ($messages as $i => $m) {
                self::assertArrayHasKey('id', $wire['kwargs']['messages'][$i]);
                self::assertSame($m::lcId(), $wire['kwargs']['messages'][$i]['id']);
                self::assertSame('constructor', $wire['kwargs']['messages'][$i]['type']);
            }
            $value = self::roundTrip($value);
            foreach ($messages as $i => $m) {
                self::assertSame($m::class, $value->messages[$i]::class);
                foreach (['content', 'id', 'name', 'additional_kwargs', 'response_metadata'] as $field) {
                    self::assertSame($m->{$field}, $value->messages[$i]->{$field});
                }
                self::assertSame($m->getType(), $value->messages[$i]->getType());
            }
            self::assertSame($messages[1]->toolCallChunks, $value->messages[1]->toolCallChunks);
            self::assertSame('call-id', $value->messages[3]->toolCallId);
            self::assertSame(self::LITERAL, $value->messages[3]->artifact);
            self::assertSame($expected, self::wire($value->messages));
        }
        self::assertSame($expected, self::wire($messages));
    }

    public function testSourceShapedGenuineInputKeepsClassesOnItsNextActualEmission(): void
    {
        $messages = self::mixedMessages();
        // Genuine source-shaped input: children serialize themselves, without wrapping the envelope.
        $record = ['lc' => 1, 'type' => 'constructor', 'id' => ChatPromptValue::lcId(), 'kwargs' => ['messages' => $messages]];
        $first = Load::load(json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame(ChatPromptValue::class, $first::class);
        foreach ($messages as $i => $message) {
            self::assertSame($message::class, $first->messages[$i]::class);
        }
        // A loader can pass the first round yet demote children when the real value re-emits.
        $second = self::roundTrip($first);
        foreach ($messages as $i => $message) {
            self::assertSame($message::class, $second->messages[$i]::class);
        }
        self::assertSame(self::LITERAL, $second->messages[3]->artifact);
        self::assertSame('moderator', $second->messages[5]->getType());
    }

    public function testCaptureDoesNotCallChildSerializersOrFreezeCurrentProperties(): void
    {
        $sentinel = new class('public') extends Messages\HumanMessage {
            public int $serializations = 0;

            public function toDict(): array
            {
                throw new \LogicException('No stored dictionaries at capture.');
            }

            public function toJson(): array
            {
                ++$this->serializations;

                return parent::toJson();
            }
        };
        $tool = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call', 'artifact' => null]);
        $omitted = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call']);
        $chat = new Messages\ChatMessage(['content' => 'public', 'role' => 'moderator']);
        $value = new ChatPromptValue([$sentinel, $tool, $omitted, $chat]);
        self::assertSame(0, $sentinel->serializations);
        self::assertSame($sentinel, $value->toJson()['kwargs']['messages'][0]);
        self::assertSame(0, $sentinel->serializations);
        $tool->artifact = self::LITERAL;
        $tool->updateId('late-id');
        $omitted->artifact = 'not-captured';
        $chat->type = 'judge';
        $wire = self::wire($value);
        self::assertSame(1, $sentinel->serializations);
        self::assertSame(self::wire($tool), $wire['kwargs']['messages'][1]);
        self::assertArrayNotHasKey('artifact', $wire['kwargs']['messages'][2]['kwargs']);
        self::assertArrayHasKey('kwargs', $wire['kwargs']['messages'][3]);
        self::assertArrayHasKey('role', $wire['kwargs']['messages'][3]['kwargs']);
        self::assertSame('judge', $wire['kwargs']['messages'][3]['kwargs']['role']);
        $restored = self::roundTrip($value);
        self::assertSame(self::LITERAL, $restored->messages[1]->artifact);
        self::assertSame('late-id', $restored->messages[1]->id);
        self::assertArrayNotHasKey('artifact', $restored->messages[2]->kwargs());
        self::assertSame('judge', $restored->messages[3]->getType());
    }

    public function testCaptureAndFactoryPreserveNativeArtifactObjectIdentity(): void
    {
        $native = new \DateTimeImmutable('2020-01-01T00:00:00Z');
        $plain = (object) ['literal' => self::LITERAL];
        foreach ([$native, $plain] as $artifact) {
            $tool = new Messages\ToolMessageChunk(['content' => 'public', 'tool_call_id' => 'call', 'artifact' => $artifact]);
            $before = json_encode($artifact, JSON_THROW_ON_ERROR);
            $value = new ChatPromptValue([$tool]);
            $factory = ImportMap::core()['langchain_core/prompt_values']['ChatPromptValue'];
            self::assertSame($tool, $factory(['messages' => $value->kwargs()['messages']])->messages[0]);
            self::assertSame($artifact, $value->toJson()['kwargs']['messages'][0]->artifact);
            self::assertSame($before, json_encode($artifact, JSON_THROW_ON_ERROR));
        }
    }

    public function testConstructorStillRejectsNonMessageEntries(): void
    {
        $this->expectException(\TypeError::class);
        new ChatPromptValue([['type' => 'human', 'data' => ['content' => 'public']]]);
    }

    public static function artifacts(): iterable
    {
        yield 'omitted' => [false, null];
        yield 'null' => [true, null];
        yield 'nested singleton' => [true, self::LITERAL];
        yield 'known constructor' => [true, ['lc' => 1, 'type' => 'constructor', 'id' => Messages\HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']]];
        yield 'unknown constructor' => [true, ['lc' => 1, 'type' => 'constructor', 'id' => ['synthetic', 'Unknown'], 'kwargs' => []]];
        yield 'secret' => [true, ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTHETIC_ONLY']]];
        yield 'not implemented' => [true, ['lc' => 1, 'type' => 'not_implemented', 'id' => ['Object']]];
        yield 'lc2' => [true, ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['public']]];
        yield 'outer singleton' => [true, ['__lc_escaped__' => ['lc' => 0]]];
        yield 'wrapped known constructor' => [true, ['__lc_escaped__' => ['lc' => 1, 'type' => 'constructor', 'id' => Messages\HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']]]];
    }

    #[DataProvider('artifacts')]
    public function testArtifactProvenanceAndLiteralBarriersAcrossTwoRounds(bool $supplied, mixed $artifact): void
    {
        $f = ['content' => 'public', 'tool_call_id' => 'call'];
        if ($supplied) {
            $f['artifact'] = $artifact;
        }
        $tool = new Messages\ToolMessageChunk($f);
        $value = new ChatPromptValue([$tool]);
        $before = self::wire($tool);
        for ($round = 0; $round < 2; ++$round) {
            self::assertSame($before, self::wire($value)['kwargs']['messages'][0]);
            $value = self::roundTrip($value);
            self::assertSame(Messages\ToolMessageChunk::class, $value->messages[0]::class);
            self::assertSame($supplied, array_key_exists('artifact', $value->messages[0]->kwargs()));
            self::assertSame($artifact, $value->messages[0]->artifact);
        }
        self::assertSame($before, self::wire($tool));
    }

    public function testNullishConcatProvenanceUsesTheCurrentChildSerializer(): void
    {
        $left = new Messages\ToolMessageChunk(['content' => 'left', 'tool_call_id' => 'call', 'artifact' => null]);
        $right = new Messages\ToolMessageChunk(['content' => 'right', 'tool_call_id' => 'call', 'artifact' => null]);
        $combined = $left->concat($right);
        $value = new ChatPromptValue([$combined]);
        $wire = self::wire($value);
        self::assertArrayHasKey('kwargs', $wire['kwargs']['messages'][0]);
        self::assertArrayNotHasKey('artifact', $wire['kwargs']['messages'][0]['kwargs']);
        $combined->artifact = self::LITERAL;
        for ($round = 0; $round < 2; ++$round) {
            self::assertSame(self::wire($combined), self::wire($value)['kwargs']['messages'][0]);
            $value = self::roundTrip($value);
            self::assertSame(Messages\ToolMessageChunk::class, $value->messages[0]::class);
            self::assertSame(self::LITERAL, $value->messages[0]->artifact);
        }
        self::assertNull($left->artifact);
        self::assertNull($right->artifact);
    }

    public function testWrappedOuterConstructorStaysLiteralAndUnknownGenuineChildIsRefused(): void
    {
        $record = self::wire(new ChatPromptValue([new Messages\HumanMessageChunk('public')]));
        self::assertSame($record, Load::load(json_encode(['__lc_escaped__' => $record], JSON_THROW_ON_ERROR)));
        $record['kwargs']['messages'][0]['id'] = ['synthetic', 'UnknownMessage'];
        $this->expectException(\RuntimeException::class);
        Load::load(json_encode($record, JSON_THROW_ON_ERROR));
    }

    public static function legacyPayloads(): iterable
    {
        yield 'HEAD zero wrappers' => [self::HEAD_ERA, ['plain' => 'public', 'literal' => ['lc' => 0], 'nested' => 'x'], false];
        yield 'unreleased root43' => [self::ROOT43_ERA, ['plain' => 'public', 'literal' => ['lc' => 0], 'nested' => ['__lc_escaped__' => ['__lc_escaped__' => 'x']]], true];
    }

    #[DataProvider('legacyPayloads')]
    public function testSavedOrdinaryLegacyPayloadsUpgradeAcrossTwoActualRounds(string $payload, array $metadata, bool $capturedArtifact): void
    {
        // Unversioned HEAD singleton literals are ambiguous; the nested literal loses two levels.
        $saved = $payload;
        $value = Load::load($payload);
        for ($round = 0; $round < 2; ++$round) {
            self::assertSame(Messages\HumanMessage::class, $value->messages[0]::class);
            self::assertSame(Messages\ToolMessage::class, $value->messages[1]::class);
            self::assertSame('legacy human', $value->messages[0]->content);
            self::assertSame('old-human', $value->messages[0]->id);
            self::assertSame($metadata, $value->messages[0]->additional_kwargs);
            self::assertSame('legacy tool', $value->messages[1]->content);
            self::assertSame('old-call', $value->messages[1]->toolCallId);
            self::assertSame($capturedArtifact, array_key_exists('artifact', $value->messages[1]->kwargs()));
            if ($capturedArtifact) {
                self::assertSame(self::LITERAL, $value->messages[1]->artifact);
            }
            $wire = self::wire($value);
            self::assertSame(Messages\HumanMessage::lcId(), $wire['kwargs']['messages'][0]['id']);
            self::assertSame(Messages\ToolMessage::lcId(), $wire['kwargs']['messages'][1]['id']);
            if ($round === 0) {
                $value = self::roundTrip($value);
            }
        }
        self::assertSame($saved, $payload);
    }

    public function testAmbiguousStoredRemoveIsRefusedAndGenuineRemoveAndChatStayDistinct(): void
    {
        $remove = new Messages\RemoveMessage(['id' => 'remove-id']);
        $chat = new Messages\ChatMessage(['content' => '', 'id' => 'remove-id', 'role' => 'remove']);
        self::assertSame(Messages\RemoveMessage::class, self::roundTrip(new ChatPromptValue([$remove]))->messages[0]::class);
        self::assertSame(Messages\ChatMessage::class, self::roundTrip(new ChatPromptValue([$chat]))->messages[0]::class);
        $legacy = ['type' => 'remove', 'data' => ['content' => '', 'additional_kwargs' => [], 'response_metadata' => [], 'id' => 'remove-id']];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot revive legacy stored remove message without constructor identity.');
        Load::load(json_encode(['lc' => 1, 'type' => 'constructor', 'id' => ChatPromptValue::lcId(), 'kwargs' => ['messages' => [$legacy]]], JSON_THROW_ON_ERROR));
    }

    public static function roleShapes(): iterable
    {
        foreach ([Messages\ChatMessage::class, Messages\ChatMessageChunk::class] as $class) {
            yield $class . ' explicit' => [$class, ['content' => 'public', 'role' => 'moderator'], true, 'moderator'];
            yield $class . ' string' => [$class, 'public', false, 'chat'];
            yield $class . ' absent' => [$class, ['content' => 'public'], false, 'chat'];
            yield $class . ' null' => [$class, ['content' => 'public', 'role' => null], false, 'chat'];
            yield $class . ' empty' => [$class, ['content' => 'public', 'role' => ''], false, 'chat'];
            yield $class . ' numeric' => [$class, ['content' => 'public', 'role' => 5], false, 'chat'];
        }
    }

    #[DataProvider('roleShapes')]
    public function testOnlyOriginalExplicitValidRolesAreCapturedAndRefreshed(string $class, string|array $fields, bool $captured, string $role): void
    {
        $message = new $class($fields);
        self::assertSame($role, $message->getType());
        self::assertSame($captured, array_key_exists('role', $message->kwargs()));
        $message->type = 'judge';
        $wire = self::wire($message);
        self::assertSame($captured, array_key_exists('role', $wire['kwargs']));
        if ($captured) {
            self::assertSame('judge', $message->kwargs()['role']);
            self::assertSame('judge', $wire['kwargs']['role']);
        }
        $restored = self::roundTrip(new ChatPromptValue([$message]));
        self::assertSame($class, $restored->messages[0]::class);
        self::assertSame($captured ? 'judge' : 'chat', $restored->messages[0]->getType());
    }

    public function testConcatKeepsLeftRoleAndCallerObjects(): void
    {
        $left = new Messages\ChatMessageChunk(['content' => 'left', 'role' => 'moderator']);
        $right = new Messages\ChatMessageChunk(['content' => 'right', 'role' => 'judge']);
        $before = self::wire([$left, $right]);
        $combined = $left->concat($right);
        self::assertSame('moderator', $combined->getType());
        self::assertSame('leftright', $combined->content);
        self::assertSame('moderator', self::roundTrip(new ChatPromptValue([$combined]))->messages[0]->getType());
        self::assertSame($before, self::wire([$left, $right]));
    }

    public function testPublicReconstructionStoredMessagesAndCachePreserveCustomRole(): void
    {
        foreach ([Messages\ChatMessage::class, Messages\ChatMessageChunk::class] as $class) {
            $message = new $class(['content' => 'public', 'role' => 'moderator', 'additional_kwargs' => ['literal' => self::LITERAL]]);
            $before = self::wire($message);
            $rebuilt = new $class([...$message->kwargs(), 'content' => 'changed']);
            self::assertSame('moderator', $rebuilt->getType());
            self::assertSame('changed', $rebuilt->content);
            $stored = Messages\MessageUtils::mapChatMessagesToStoredMessages([$message]);
            self::assertSame('moderator', $stored[0]['data']['role']);
            self::assertSame('moderator', Messages\MessageUtils::mapStoredMessageToChatMessage($stored[0])->getType());
            self::assertSame($before, self::wire($message));
        }
        $message = new Messages\ChatMessage(['content' => 'cached', 'role' => 'moderator', 'additional_kwargs' => ['literal' => self::LITERAL]]);
        $stored = BaseCache::serializeGeneration(new ChatGeneration($message, 'cached text'));
        self::assertSame('moderator', $stored['message']['data']['role']);
        $restored = BaseCache::deserializeStoredGeneration($stored);
        self::assertSame(Messages\ChatMessage::class, $restored->message::class);
        self::assertSame('moderator', $restored->message->getType());
        self::assertSame($message->additional_kwargs, $restored->message->additional_kwargs);
        self::assertSame('cached text', $restored->text);
    }

    public function testActualPiiMiddlewareKeepsChatRejectionAndRedactsSupportedMessage(): void
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $text) use (&$deprecations): bool {
            if ($level === E_USER_DEPRECATED) {
                $deprecations[] = $text;

                return true;
            }

            return false;
        });
        try {
            $middleware = PiiRedactionMiddleware::create(['rules' => ['marker' => '/PUBLIC_TOKEN/']]);
        } finally {
            restore_error_handler();
        }
        self::assertCount(1, $deprecations);
        foreach ([new Messages\ChatMessage(['content' => 'PUBLIC_TOKEN', 'role' => 'moderator']), new Messages\ChatMessageChunk(['content' => 'PUBLIC_TOKEN', 'role' => 'moderator'])] as $message) {
            try {
                ($middleware['wrapModelCall'])(['state' => ['messages' => [$message]]], static fn (array $request): array => $request);
                self::fail('Unowned public middleware must still refuse chat classes.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Unsupported message type: moderator', $e->getMessage());
            }
            self::assertSame('PUBLIC_TOKEN', $message->content);
            self::assertSame('moderator', $message->getType());
        }
        $human = new Messages\HumanMessage('PUBLIC_TOKEN');
        $processed = ($middleware['wrapModelCall'])(['state' => ['messages' => [$human]]], static fn (array $request): array => $request);
        self::assertSame(Messages\HumanMessage::class, $processed['messages'][0]::class);
        self::assertMatchesRegularExpression('/^\[REDACTED_MARKER_[a-zA-Z0-9]+\]$/', $processed['messages'][0]->content);
        self::assertSame('PUBLIC_TOKEN', $human->content);
    }

    public function testActualRemoteGraphFakeWirePreservesRoleAndCaller(): void
    {
        $fake = new FakeHttpClient([new HttpResponse(200, ['Content-Type' => ['text/event-stream']], "event: values\ndata: {\"done\":true}\n\n")]);
        $client = new Client(['apiUrl' => 'http://synthetic.invalid', 'apiKey' => null], $fake);
        $graph = new RemoteGraph(graphId: 'synthetic', client: $client);
        $messages = [new Messages\ChatMessage(['content' => 'public', 'role' => 'moderator']), new Messages\ChatMessageChunk(['content' => 'chunk', 'role' => 'moderator'])];
        $before = self::wire($messages);
        self::assertSame(['done' => true], $graph->invoke(['messages' => $messages]));
        self::assertCount(1, $fake->requests);
        $body = $fake->lastRequestBody();
        foreach ($messages as $i => $message) {
            self::assertSame('moderator', $body['input']['messages'][$i]['role']);
            self::assertSame($message->content, $body['input']['messages'][$i]['content']);
            self::assertArrayNotHasKey('lc', $body['input']['messages'][$i]);
        }
        self::assertSame($before, self::wire($messages));
    }
}
