<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\Constants;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `MessagesAnnotation` (`langgraph-core/src/graph/messages_annotation.ts`),
 * run through a real graph so the reducer is exercised by the engine's own fold
 * rather than called by hand.
 */
#[CoversClass(MessagesAnnotation::class)]
#[CoversClass(MessagesReducer::class)]
final class MessagesAnnotationTest extends TestCase
{
    public function testRootDeclaresASingleAccumulatingMessagesChannel(): void
    {
        $root = MessagesAnnotation::root();

        self::assertInstanceOf(AnnotationRoot::class, $root);
        self::assertSame(['messages'], $root->keys());
        self::assertInstanceOf(BinaryOperatorAggregate::class, $root->spec['messages']);
    }

    public function testEachCallBuildsFreshChannels(): void
    {
        self::assertNotSame(MessagesAnnotation::root()->spec['messages'], MessagesAnnotation::root()->spec['messages']);
    }

    public function testAGraphAccumulatesMessagesAcrossNodes(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('greet', static fn (): array => ['messages' => [new AIMessage(['id' => 'a', 'content' => 'hello'])]])
            ->addNode('follow', static fn (): array => ['messages' => ['and another']])
            ->addEdge(Constants::START, 'greet')
            ->addEdge('greet', 'follow')
            ->addEdge('follow', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage(['id' => 'h', 'content' => 'hi'])]]);

        self::assertSame(
            ['hi', 'hello', 'and another'],
            array_map(static fn (BaseMessage $m): string => $m->text(), $result['messages']),
        );
        self::assertInstanceOf(HumanMessage::class, $result['messages'][2], 'a bare string is coerced to a human message');
        foreach ($result['messages'] as $message) {
            self::assertNotNull($message->id, 'the reducer assigns every message an id');
        }
    }

    public function testAGraphReplacesAMessageWithTheSameId(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('edit', static fn (): array => ['messages' => [new HumanMessage(['id' => 'h', 'content' => 'edited'])]])
            ->addEdge(Constants::START, 'edit')
            ->addEdge('edit', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage(['id' => 'h', 'content' => 'original'])]]);

        self::assertCount(1, $result['messages']);
        self::assertSame('edited', $result['messages'][0]->content);
    }

    public function testAGraphRemovesAMessageWithARemoveMessage(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('prune', static fn (): array => ['messages' => [new RemoveMessage(['id' => 'old'])]])
            ->addEdge(Constants::START, 'prune')
            ->addEdge('prune', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [
            new HumanMessage(['id' => 'old', 'content' => 'drop me']),
            new HumanMessage(['id' => 'keep', 'content' => 'keep me']),
        ]]);

        self::assertSame(['keep'], array_map(static fn (BaseMessage $m): ?string => $m->id, $result['messages']));
    }

    public function testRemoveAllClearsTheHistoryBeforeTheNewMessages(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('reset', static fn (): array => ['messages' => [
                new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
                new AIMessage(['id' => 'fresh', 'content' => 'start over']),
            ]])
            ->addEdge(Constants::START, 'reset')
            ->addEdge('reset', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage(['id' => 'a', 'content' => 'x']), new HumanMessage(['id' => 'b', 'content' => 'y'])]]);

        self::assertSame(['fresh'], array_map(static fn (BaseMessage $m): ?string => $m->id, $result['messages']));
    }

    public function testRemovingAMessageThatDoesNotExistFailsTheRun(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('prune', static fn (): array => ['messages' => [new RemoveMessage(['id' => 'ghost'])]])
            ->addEdge(Constants::START, 'prune')
            ->addEdge('prune', Constants::END)
            ->compile();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("doesn't exist");

        $graph->invoke(['messages' => [new HumanMessage(['id' => 'a', 'content' => 'x'])]]);
    }

    public function testTheMessagesDefaultToAnEmptyListWhenNoneAreProvided(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('noop', static fn (): array => [])
            ->addEdge(Constants::START, 'noop')
            ->addEdge('noop', Constants::END)
            ->compile();

        self::assertSame([], $graph->invoke(['messages' => []])['messages']);
    }
}
