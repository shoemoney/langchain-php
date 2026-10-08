<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Graph\PushMessage;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `pushMessage` describe block of `langgraph-core/src/graph/message.test.ts`.
 *
 * Not ported: "should push messages onto the streamEvents v3 messages channel" and the
 * `messages` stream-mode assertions of "should push messages in graph" — both need the
 * `messages` stream handlers (WP-08a). The persistence half of that test is kept.
 */
#[CoversClass(PushMessage::class)]
final class PushMessageTest extends TestCase
{
    public function testShouldThrowOnMessageWithoutId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Message ID is required');

        PushMessage::pushMessage(new AIMessage('No ID'), new RunnableConfig());
    }

    public function testShouldHandleMessageWithId(): void
    {
        $message = new AIMessage(['id' => '1', 'content' => 'With ID']);

        self::assertEquals($message, PushMessage::pushMessage($message, new RunnableConfig()));
    }

    public function testShouldHandleMessageWithCustomStateKey(): void
    {
        $message = new AIMessage(['id' => '1', 'content' => 'With ID']);
        $sent = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $writes) use (&$sent): void {
                $sent = $writes;
            },
        ]);

        PushMessage::pushMessage($message, $config, 'custom');

        self::assertSame([['custom', $message]], $sent);
    }

    public function testTheStateKeyDefaultsToMessages(): void
    {
        $message = new AIMessage(['id' => '1', 'content' => 'x']);
        $sent = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $writes) use (&$sent): void {
                $sent = $writes;
            },
        ]);

        PushMessage::pushMessage($message, $config);

        self::assertSame([['messages', $message]], $sent);
    }

    public function testANullStateKeyDoesNotPersist(): void
    {
        $sent = false;
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function () use (&$sent): void {
                $sent = true;
            },
        ]);

        PushMessage::pushMessage(new AIMessage(['id' => '1', 'content' => 'x']), $config, null);

        self::assertFalse($sent);
    }

    public function testAMessageLikeIsCoercedBeforeTheIdCheck(): void
    {
        $pushed = PushMessage::pushMessage(['role' => 'user', 'content' => 'hi', 'id' => 'u'], new RunnableConfig());

        self::assertInstanceOf(HumanMessage::class, $pushed);
        self::assertSame('u', $pushed->id);
    }

    public function testWithoutAConfigItUsesTheTaskCurrentlyRunning(): void
    {
        $sent = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $writes) use (&$sent): void {
                $sent = $writes;
            },
        ]);
        $message = new AIMessage(['id' => '1', 'content' => 'x']);

        PregelScratchpad::withConfig($config, static fn () => PushMessage::pushMessage($message));

        self::assertSame([['messages', $message]], $sent);
    }

    public function testShouldPushMessagesInGraph(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('chat', function (array $state, RunnableConfig $config): array {
                try {
                    PushMessage::pushMessage(new AIMessage('No ID'), $config);
                    self::fail('a message without an id must be refused');
                } catch (\InvalidArgumentException) {
                    // expected
                }

                PushMessage::pushMessage(new AIMessage(['id' => '1', 'content' => 'First']), $config);
                PushMessage::pushMessage(new HumanMessage(['id' => '2', 'content' => 'Second']), $config);
                PushMessage::pushMessage(new AIMessage(['id' => '3', 'content' => 'Third']), $config);

                return $state;
            })
            ->addEdge(Constants::START, 'chat')
            ->compile();

        $result = $graph->invoke(['messages' => []]);

        self::assertSame(
            [['1', 'First'], ['2', 'Second'], ['3', 'Third']],
            array_map(static fn (BaseMessage $m): array => [$m->id, $m->text()], $result['messages']),
        );
    }

    public function testAPushedMessageAndTheSameMessageReturnedByTheNodeDoNotDuplicate(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('chat', static function (array $state, RunnableConfig $config): array {
                $message = new AIMessage(['id' => 'm', 'content' => 'once']);
                PushMessage::pushMessage($message, $config);

                return ['messages' => [$message]];
            })
            ->addEdge(Constants::START, 'chat')
            ->compile();

        $result = $graph->invoke(['messages' => []]);

        self::assertCount(1, $result['messages'], 'same id, so the reducer folds the two writes into one');
    }
}
