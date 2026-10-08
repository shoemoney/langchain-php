<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ToolsCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolsCondition::class)]
final class ToolsConditionTest extends TestCase
{
    private static function withCall(): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 't', 'args' => []]]]);
    }

    public function testRoutesToToolsWhenTheLastMessageHasToolCalls(): void
    {
        self::assertSame('tools', ToolsCondition::toolsCondition([new HumanMessage('hi'), self::withCall()]));
        self::assertSame('tools', ToolsCondition::toolsCondition(['messages' => [self::withCall()]]));
    }

    public function testEndsWhenThereAreNoToolCalls(): void
    {
        self::assertSame(Constants::END, ToolsCondition::toolsCondition(['messages' => [new AIMessage('done')]]));
        self::assertSame(Constants::END, ToolsCondition::toolsCondition([new HumanMessage('hi')]));
    }

    public function testOnlyTheLastMessageCounts(): void
    {
        self::assertSame(Constants::END, ToolsCondition::toolsCondition([self::withCall(), new AIMessage('done')]));
    }

    public function testAnEmptyStateEnds(): void
    {
        self::assertSame(Constants::END, ToolsCondition::toolsCondition([]));
        self::assertSame(Constants::END, ToolsCondition::toolsCondition(['messages' => []]));
    }

    public function testAStreamedChunkCarryingCompleteToolCallChunksRoutesToTools(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 'c1', 'name' => 'search', 'args' => '{}']],
        ]);

        self::assertSame('tools', ToolsCondition::toolsCondition([$chunk]));
    }

    public function testAnInstanceIsCallableSoItWorksAsAConditionalEdge(): void
    {
        self::assertSame('tools', (new ToolsCondition())(['messages' => [self::withCall()]]));
    }
}
