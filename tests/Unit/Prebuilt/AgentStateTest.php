<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\AgentState;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `createReactAgentAnnotation` and `PreHookAnnotation` from `react_agent_executor.ts`. */
#[CoversClass(AgentState::class)]
final class AgentStateTest extends TestCase
{
    public function testTheAnnotationDeclaresMessagesAndStructuredResponse(): void
    {
        self::assertSame(['messages', 'structuredResponse'], AgentState::annotation()->keys());
    }

    public function testMessagesAccumulateThroughTheMessagesReducerAndDefaultToEmpty(): void
    {
        $graph = (new StateGraph(AgentState::annotation()))
            ->addNode('a', static fn (array $state): array => ['messages' => [new AIMessage('from a')]])
            ->addEdge(Constants::START, 'a')
            ->compile();

        $result = $graph->invoke(['messages' => 'hello']);

        self::assertSame(['hello', 'from a'], ReactAgentFixtures::texts($result['messages']));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][0]);
        self::assertNotEmpty($result['messages'][0]->id, 'the reducer gives every message an id');
    }

    public function testStructuredResponseIsLastValueWins(): void
    {
        $graph = (new StateGraph(AgentState::annotation()))
            ->addNode('a', static fn (): array => ['structuredResponse' => ['n' => 1]])
            ->addNode('b', static fn (): array => ['structuredResponse' => ['n' => 2]])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile();

        self::assertSame(['n' => 2], $graph->invoke(['messages' => []])['structuredResponse']);
    }

    public function testThePreHookChannelIsReplacedNotAccumulated(): void
    {
        $channel = AgentState::preHookAnnotation()->spec[AgentState::LLM_INPUT_MESSAGES];

        $first = $channel->fromCheckpoint(null);
        $first->update(['pre-hook']);
        $second = $first->fromCheckpoint($first->checkpoint());
        $second->update([[new HumanMessage('replacement')]]);

        self::assertSame(['replacement'], ReactAgentFixtures::texts($second->get()));
    }

    public function testAStringWrittenToThePreHookChannelBecomesAHumanMessage(): void
    {
        $channel = AgentState::preHookAnnotation()->spec[AgentState::LLM_INPUT_MESSAGES]->fromCheckpoint(null);
        $channel->update(['pre-hook']);

        $messages = $channel->get();

        self::assertCount(1, $messages);
        self::assertInstanceOf(HumanMessage::class, $messages[0]);
        self::assertSame('pre-hook', $messages[0]->content);
    }
}
