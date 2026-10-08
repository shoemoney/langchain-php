<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangGraph\Sdk\Utils\Tools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tools.test.ts`. The generic-typing test ("works with custom tool call types") has
 * no PHP meaning, so it is kept as a check that arbitrary call shapes pass through untouched.
 */
#[CoversClass(Tools::class)]
final class ToolsTest extends TestCase
{
    public function testReturnsEmptyForNoMessages(): void
    {
        $this->assertSame([], Tools::getToolCallsWithResults([]));
    }

    public function testReturnsEmptyWhenNoAiMessageHasToolCalls(): void
    {
        $messages = [
            ['type' => 'human', 'content' => 'Hello'],
            ['type' => 'ai', 'content' => 'Hi there!'],
            ['type' => 'system', 'content' => 'You are a helpful assistant'],
        ];

        $this->assertSame([], Tools::getToolCallsWithResults($messages));
    }

    public function testReturnsEmptyForAnAiMessageWithAnEmptyToolCallsArray(): void
    {
        $this->assertSame([], Tools::getToolCallsWithResults([['type' => 'ai', 'content' => '', 'tool_calls' => []]]));
    }

    public function testPairsAToolCallWithItsResult(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc1']]];
        $tool = ['type' => 'tool', 'content' => 'Sunny, 72°F', 'tool_call_id' => 'tc1'];

        $result = Tools::getToolCallsWithResults([$ai, $tool]);

        $this->assertSame([[
            'id' => 'tc1',
            'call' => ['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc1'],
            'result' => $tool,
            'aiMessage' => $ai,
            'index' => 0,
            'state' => 'completed',
        ]], $result);
    }

    public function testResultIsNullWhenAToolCallHasNoMatchingResult(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc1']]];

        $result = Tools::getToolCallsWithResults([$ai]);

        $this->assertCount(1, $result);
        $this->assertSame('get_weather', $result[0]['call']['name']);
        $this->assertNull($result[0]['result']);
        $this->assertSame($ai, $result[0]['aiMessage']);
        $this->assertSame(0, $result[0]['index']);
    }

    public function testResultIsNullWhenAToolCallHasNoId(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'get_weather', 'args' => ['location' => 'NYC']]]];
        $tool = ['type' => 'tool', 'content' => 'Sunny', 'tool_call_id' => 'some_id'];

        $result = Tools::getToolCallsWithResults([$ai, $tool]);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['result']);
        $this->assertSame('unknown-0', $result[0]['id'], 'a call without an id is keyed by message id and index');
    }

    public function testHandlesMultipleToolCallsInOneAiMessage(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [
            ['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc1'],
            ['name' => 'get_weather', 'args' => ['location' => 'LA'], 'id' => 'tc2'],
        ]];
        $t1 = ['type' => 'tool', 'content' => 'Sunny, 72°F', 'tool_call_id' => 'tc1'];
        $t2 = ['type' => 'tool', 'content' => 'Cloudy, 65°F', 'tool_call_id' => 'tc2'];

        $result = Tools::getToolCallsWithResults([$ai, $t1, $t2]);

        $this->assertCount(2, $result);
        $this->assertSame($t1, $result[0]['result']);
        $this->assertSame(0, $result[0]['index']);
        $this->assertSame($t2, $result[1]['result']);
        $this->assertSame(1, $result[1]['index']);
    }

    public function testHandlesMultipleAiMessagesWithToolCalls(): void
    {
        $ai1 = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'search', 'args' => ['query' => 'test'], 'id' => 'tc1']]];
        $t1 = ['type' => 'tool', 'content' => 'Search results', 'tool_call_id' => 'tc1'];
        $ai2 = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc2']]];
        $t2 = ['type' => 'tool', 'content' => 'Sunny', 'tool_call_id' => 'tc2'];

        $result = Tools::getToolCallsWithResults([$ai1, $t1, $ai2, $t2]);

        $this->assertCount(2, $result);
        $this->assertSame('search', $result[0]['call']['name']);
        $this->assertSame($ai1, $result[0]['aiMessage']);
        $this->assertSame($t1, $result[0]['result']);
        $this->assertSame('get_weather', $result[1]['call']['name']);
        $this->assertSame($ai2, $result[1]['aiMessage']);
        $this->assertSame($t2, $result[1]['result']);
    }

    public function testAToolResultBeforeItsAiMessageStillPairs(): void
    {
        $tool = ['type' => 'tool', 'content' => 'Result', 'tool_call_id' => 'tc1'];
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'test_tool', 'args' => [], 'id' => 'tc1']]];

        $result = Tools::getToolCallsWithResults([$tool, $ai]);

        $this->assertCount(1, $result);
        $this->assertSame($tool, $result[0]['result']);
    }

    public function testCustomToolCallShapesPassThroughUnchanged(): void
    {
        $call = ['name' => 'get_weather', 'args' => ['location' => 'NYC'], 'id' => 'tc1', 'extra' => ['x' => 1]];
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [$call]];

        $result = Tools::getToolCallsWithResults([$ai, ['type' => 'tool', 'content' => 'Sunny', 'tool_call_id' => 'tc1']]);

        $this->assertSame($call, $result[0]['call']);
        $this->assertSame('NYC', $result[0]['call']['args']['location']);
    }

    public function testIgnoresOtherMessageTypesWhenCollectingResults(): void
    {
        $messages = [
            ['type' => 'human', 'content' => 'Hi'],
            ['type' => 'system', 'content' => 'System prompt'],
            ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'greet', 'args' => [], 'id' => 'tc1']]],
            ['type' => 'tool', 'content' => 'Hello!', 'tool_call_id' => 'tc1'],
        ];

        $result = Tools::getToolCallsWithResults($messages);

        $this->assertCount(1, $result);
        $this->assertSame('greet', $result[0]['call']['name']);
    }

    public function testFirstRoundCommandToolCallsAreCompletedWhileSecondRoundStaysPending(): void
    {
        $messages = [
            ['type' => 'ai', 'id' => 'ai1', 'content' => '', 'tool_calls' => [
                ['name' => 'write_file', 'args' => ['path' => 'a.txt'], 'id' => 'tc1'],
                ['name' => 'write_file', 'args' => ['path' => 'b.txt'], 'id' => 'tc2'],
            ]],
            ['type' => 'ai', 'id' => 'ai2', 'content' => '', 'tool_calls' => [['name' => 'write_file', 'args' => ['path' => 'c.txt'], 'id' => 'tc3']]],
        ];

        $result = Tools::getToolCallsWithResults($messages);

        $this->assertCount(3, $result);
        $this->assertSame(['completed', 'completed', 'pending'], array_column($result, 'state'));
    }

    public function testImpliedCompletionIsStableWhenANewAiMessageGainsToolCalls(): void
    {
        $messages = [
            ['type' => 'ai', 'id' => 'ai1', 'content' => '', 'tool_calls' => [['name' => 'write_file', 'args' => ['path' => 'a.txt'], 'id' => 'tc1']]],
            ['type' => 'ai', 'id' => 'ai2', 'content' => '', 'tool_calls' => [
                ['name' => 'write_file', 'args' => ['path' => 'b.txt'], 'id' => 'tc2'],
                ['name' => 'write_file', 'args' => ['path' => 'c.txt'], 'id' => 'tc3'],
            ]],
        ];

        $this->assertSame(['completed', 'pending', 'pending'], array_column(Tools::getToolCallsWithResults($messages), 'state'));
    }

    public function testHandlesPartialResults(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [
            ['name' => 'tool1', 'args' => [], 'id' => 'tc1'],
            ['name' => 'tool2', 'args' => [], 'id' => 'tc2'],
            ['name' => 'tool3', 'args' => [], 'id' => 'tc3'],
        ]];
        $t1 = ['type' => 'tool', 'content' => 'Result 1', 'tool_call_id' => 'tc1'];
        $t3 = ['type' => 'tool', 'content' => 'Result 3', 'tool_call_id' => 'tc3'];

        $result = Tools::getToolCallsWithResults([$ai, $t1, $t3]);

        $this->assertCount(3, $result);
        $this->assertSame($t1, $result[0]['result']);
        $this->assertNull($result[1]['result']);
        $this->assertSame($t3, $result[2]['result']);
        $this->assertSame(['completed', 'pending', 'completed'], array_column($result, 'state'));
    }

    public function testAnErroredToolResultMarksTheCallAsError(): void
    {
        $ai = ['type' => 'ai', 'content' => '', 'tool_calls' => [['name' => 'boom', 'args' => [], 'id' => 'tc1']]];
        $tool = ['type' => 'tool', 'content' => 'it broke', 'tool_call_id' => 'tc1', 'status' => 'error'];

        $this->assertSame('error', Tools::getToolCallsWithResults([$ai, $tool])[0]['state']);
    }
}
