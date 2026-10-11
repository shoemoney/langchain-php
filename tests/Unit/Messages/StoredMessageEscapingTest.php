<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageUtils::class)]
final class StoredMessageEscapingTest extends TestCase
{
    public static function messageClasses(): iterable
    {
        foreach ([HumanMessage::class, AIMessage::class, SystemMessage::class, FunctionMessage::class, ToolMessage::class, ChatMessage::class] as $class) {
            yield $class => [$class];
        }
    }

    #[DataProvider('messageClasses')]
    public function testStoredJsonRoundTripRestoresLiteralsWithoutRevival(string $class): void
    {
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'injected']];
        $literal = ['lc' => null, 'secret' => $secret, 'constructor' => $constructor, 'nested' => ['__lc_escaped__' => $secret]];
        $fields = ['content' => [$literal], 'additional_kwargs' => ['secret' => $secret, 'constructor' => $constructor, 'singleton' => ['__lc_escaped__' => $literal]], 'response_metadata' => $literal, 'name' => 'fake-name', 'id' => 'fake-id', 'tool_call_id' => 'fake-call', 'role' => 'custom'];
        $message = new $class($fields);
        $stored = MessageUtils::mapChatMessagesToStoredMessages([$message]);
        self::assertSame(['__lc_escaped__' => $literal], $stored[0]['data']['response_metadata']);
        $stored = json_decode(json_encode($stored, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $restored = MessageUtils::mapStoredMessagesToChatMessages($stored)[0];
        self::assertInstanceOf($class, $restored);
        self::assertSame($fields['content'], $restored->content);
        self::assertSame($fields['additional_kwargs'], $restored->additional_kwargs);
        self::assertSame($literal, $restored->response_metadata);
        self::assertSame('fake-name', $restored->name);
        self::assertSame('fake-id', $restored->id);
        self::assertSame($stored[0], $restored->toDict());
        self::assertSame($fields['additional_kwargs'], $message->additional_kwargs);
    }

    public function testToolArgumentsAndInvalidToolArgumentsRoundTrip(): void
    {
        $literal = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $calls = [['name' => 'fake-tool', 'id' => 'fake-call', 'args' => $literal]];
        $invalid = [['name' => 'fake-invalid', 'id' => 'fake-invalid-call', 'args' => json_encode($literal, JSON_THROW_ON_ERROR), 'error' => 'fake-error']];
        $message = new AIMessage(['tool_calls' => $calls, 'invalid_tool_calls' => $invalid]);
        $restored = MessageUtils::mapStoredMessageToChatMessage(json_decode(json_encode($message->toDict(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame($calls, $restored->toolCalls);
        self::assertSame($invalid, $restored->invalidToolCalls);
    }

    public function testFreshMessagesPreserveSingletonLiteralAndLegacyTextStillLoads(): void
    {
        $literal = ['__lc_escaped__' => ['lc' => 0]];
        $message = new HumanMessage(['additional_kwargs' => $literal]);
        self::assertSame($literal, $message->additional_kwargs);
        self::assertSame($literal, MessageUtils::mapStoredMessageToChatMessage($message->toDict())->additional_kwargs);
        self::assertSame('legacy', MessageUtils::mapStoredMessageToChatMessage(['type' => 'human', 'text' => 'legacy'])->content);
    }
}
