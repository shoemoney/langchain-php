<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangGraph\Graph\MessageGraph;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `MessageGraph` is `graph/message.ts`'s other export (its test file only exercises `pushMessage`,
 * ported in `PushMessageTest`), so these tests are written against its documented contract: one
 * `__root__` channel, reduced by `messagesStateReducer`, defaulting to `[]`.
 */
#[CoversClass(MessageGraph::class)]
final class MessageGraphTest extends TestCase
{
    public function testItIsAStateGraphWithASingleRootChannel(): void
    {
        $graph = new MessageGraph();

        $this->assertInstanceOf(StateGraph::class, $graph);
        $this->assertSame([StateGraph::ROOT], array_keys($graph->channels()));
    }

    public function testNodesReceiveTheMessageListAndTheirReturnIsAppended(): void
    {
        $seen = null;
        $graph = (new MessageGraph())
            ->addNode('reply', static function (array $messages) use (&$seen): array {
                $seen = $messages;

                return [new AIMessage(['id' => 'ai-1', 'content' => 'hello human'])];
            })
            ->addEdge(Constants::START, 'reply')
            ->addEdge('reply', Constants::END)
            ->compile();

        $result = $graph->invoke([new HumanMessage(['id' => 'h-1', 'content' => 'hi'])]);

        $this->assertCount(1, $seen);
        $this->assertSame('hi', $seen[0]->content);
        $this->assertCount(2, $result);
        $this->assertSame('hi', $result[0]->content);
        $this->assertSame('hello human', $result[1]->content);
    }

    public function testASingleReturnedMessageIsAppendedToo(): void
    {
        $graph = (new MessageGraph())
            ->addNode('reply', static fn (array $m): AIMessage => new AIMessage(['id' => 'ai-1', 'content' => 'one']))
            ->addEdge(Constants::START, 'reply')
            ->addEdge('reply', Constants::END)
            ->compile();

        $result = $graph->invoke([new HumanMessage(['id' => 'h-1', 'content' => 'hi'])]);

        $this->assertSame(['hi', 'one'], array_map(static fn ($m) => $m->content, $result));
    }

    public function testAReturnedMessageWithAnExistingIdReplacesIt(): void
    {
        $graph = (new MessageGraph())
            ->addNode('edit', static fn (array $m): array => [new HumanMessage(['id' => 'h-1', 'content' => 'edited'])])
            ->addEdge(Constants::START, 'edit')
            ->addEdge('edit', Constants::END)
            ->compile();

        $result = $graph->invoke([new HumanMessage(['id' => 'h-1', 'content' => 'original'])]);

        $this->assertCount(1, $result);
        $this->assertSame('edited', $result[0]->content);
    }

    public function testMessagesAccumulateAcrossNodes(): void
    {
        $graph = (new MessageGraph())
            ->addNode('first', static fn (array $m): array => [new AIMessage(['id' => 'a', 'content' => 'first'])])
            ->addNode('second', static fn (array $m): array => [new AIMessage(['id' => 'b', 'content' => 'saw ' . count($m)])])
            ->addEdge(Constants::START, 'first')
            ->addEdge('first', 'second')
            ->addEdge('second', Constants::END)
            ->compile();

        $result = $graph->invoke([new HumanMessage(['id' => 'h', 'content' => 'q'])]);

        $this->assertSame(['q', 'first', 'saw 2'], array_map(static fn ($m) => $m->content, $result));
    }

    public function testAConditionalEdgeRoutesOnTheMessages(): void
    {
        $graph = (new MessageGraph())
            ->addNode('agent', static fn (array $m): array => [new AIMessage(['id' => 'a', 'content' => 'thinking'])])
            ->addNode('tools', static fn (array $m): array => [new AIMessage(['id' => 't', 'content' => 'tool ran'])])
            ->addEdge(Constants::START, 'agent')
            ->addConditionalEdges('agent', static fn (array $m): string => count($m) < 3 ? 'tools' : Constants::END, ['tools', Constants::END])
            ->addEdge('tools', Constants::END)
            ->compile();

        $result = $graph->invoke([new HumanMessage(['id' => 'h', 'content' => 'q'])]);

        $this->assertSame(['q', 'thinking', 'tool ran'], array_map(static fn ($m) => $m->content, $result));
    }
}
