<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolRuntime;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\ToolNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;
use function LangChain\Tools\tool;

/**
 * Ports "createReactAgent with ToolNode", "parallel tool calls", "inherit task context" and "should handle tool
 * errors" of `prebuilt.test.ts`, plus `returnDirect`, which the agent routes on and upstream tests elsewhere.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentToolNodeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    private static function thread(string $id = '1'): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $id]);
    }

    #[DataProvider('versions')]
    public function testShouldWorkWithToolNode(string $version): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'tool_abcd123', ['query' => 'foo'])]]),
            new AIMessage('result'),
        ]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => new ToolNode([ReactAgentFixtures::searchApi()]), 'version' => $version]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        ReactAgentFixtures::assertMessages([
            new HumanMessage('Hello Input!'),
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'tool_abcd123', ['query' => 'foo'])]]),
            new ToolMessage(['content' => 'result for foo', 'name' => 'search_api', 'tool_call_id' => 'tool_abcd123', 'additional_kwargs' => ['status' => 'success']]),
            new AIMessage('result'),
        ], $result['messages']);
    }

    #[DataProvider('versions')]
    public function testShouldWorkWithToolNodeWithHandleToolErrorsSetToFalse(string $version): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'tool_abcd123', ['query' => 'error'])]]),
        ]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => new ToolNode([ReactAgentFixtures::searchApi()], ['handleToolErrors' => false]),
            'version' => $version,
        ]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Error');

        $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);
    }

    #[DataProvider('versions')]
    public function testAToolErrorBecomesAnErrorToolMessageTheModelCanReadByDefault(string $version): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 't1', ['query' => 'error'])]]),
            new AIMessage('recovered'),
        ]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [ReactAgentFixtures::searchApi()], 'version' => $version]);

        $result = $agent->invoke(['messages' => 'go']);

        self::assertSame('error', $result['messages'][2]->additional_kwargs['status']);
        self::assertSame("Error: Error\n Please fix your mistakes.", $result['messages'][2]->content);
        self::assertSame('recovered', end($result['messages'])->content);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function interruptCases(): array
    {
        $rows = [];
        foreach (ReactAgentFixtures::versionNames() as $version) {
            $rows["$version default error handling"] = [$version, true];
            $rows["$version tool error handling disabled"] = [$version, false];
        }

        return $rows;
    }

    #[DataProvider('interruptCases')]
    public function testShouldWorkWithInterrupt(string $version, bool $handleToolErrors): void
    {
        $toolWithInterrupt = tool(
            static fn (): mixed => interrupt('Please review.'),
            ['name' => 'tool_with_interrupt', 'description' => 'A tool that returns an interrupt', 'schema' => Schema::object([])],
        );
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('tool_with_interrupt', 'testid')]]),
            new AIMessage('Final response'),
        ]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => $handleToolErrors ? [$toolWithInterrupt] : new ToolNode([$toolWithInterrupt], ['handleToolErrors' => false]),
            'version' => $version,
            'checkpointer' => new MemorySaver(),
        ]);

        $res = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]], self::thread());
        // Only the human message and the tool-calling AI message exist before the interrupt.
        self::assertCount(2, $res['messages']);

        $resumed = $agent->invoke(new Command(resume: 'Approved.'), self::thread());
        self::assertCount(4, $resumed['messages']);
        self::assertSame('Approved.', $resumed['messages'][2]->content);
        self::assertSame('Final response', $resumed['messages'][3]->content);
    }

    public function testParallelToolCallsRunAsSeparateTasksUnderV2AndResumeIndependently(): void
    {
        $humanAssistance = tool(
            static function (array $args): mixed {
                $human = interrupt(['query' => $args['query']]);

                return $human['data'];
            },
            ['name' => 'human_assistance', 'description' => 'Human assistance', 'schema' => Schema::object(['query' => ['type' => 'string']])],
        );
        $weather = tool(
            static fn (array $args): string => "It's sunny in " . $args['location'],
            ['name' => 'weather', 'description' => 'Weather tool', 'schema' => Schema::object(['location' => ['type' => 'string']])],
        );

        // The Wave 3 Topic fix made resuming with two queued Sends safe; this case now exercises it.
        $toolCalls = [
            ReactAgentFixtures::toolCall('weather', 'get_weather', ['location' => 'sf']),
            ReactAgentFixtures::toolCall('human_assistance', 'get_help', ['query' => 'help me']),
        ];
        $agent = ReactAgent::create([
            'llm' => ReactAgentFixtures::fake([
                new AIMessage(['content' => 'ai response', 'tool_calls' => $toolCalls]),
                new AIMessage('final response'),
            ]),
            'tools' => [$humanAssistance, $weather],
            'version' => 'v2',
            'checkpointer' => new MemorySaver(),
        ]);

        $chunks = iterator_to_array($agent->stream(
            ['messages' => 'Get user assistance and also check the weather'],
            self::thread(),
        ), false);

        $values = array_values(array_map(static fn (array $c): mixed => $c[1], array_filter($chunks, static fn (array $c): bool => $c[0] === 'values')));
        $last = end($values);
        // The weather task finished; the human task is waiting.
        self::assertSame(
            ['Get user assistance and also check the weather', 'ai response', "It's sunny in sf"],
            ReactAgentFixtures::texts($last['messages']),
        );
        $interrupts = [];
        foreach ($chunks as [$mode, $chunk]) {
            if ($mode === 'updates' && isset($chunk['__interrupt__'])) {
                $interrupts = [...$interrupts, ...$chunk['__interrupt__']];
            }
        }
        self::assertCount(1, $interrupts);
        self::assertSame(['query' => 'help me'], $interrupts[0]['value']);

        $resumed = $agent->invoke(new Command(resume: ['data' => 'Resumed!']), self::thread());

        self::assertSame(
            ['Get user assistance and also check the weather', 'ai response', "It's sunny in sf", 'Resumed!', 'final response'],
            ReactAgentFixtures::texts($resumed['messages']),
        );
        self::assertSame($toolCalls, array_map(
            static fn (array $c): array => ['name' => $c['name'], 'id' => $c['id'], 'args' => $c['args']],
            $resumed['messages'][1]->toolCalls,
        ));
    }

    #[DataProvider('versions')]
    public function testToolsInheritTheAgentStateThroughTheRuntime(string $version): void
    {
        $parrot = tool(
            static function (array $args, ToolRuntime $runtime): string {
                $texts = array_map(static fn ($m): string => (string) $m->content, $runtime->state['messages']);

                return '[tool] ' . $args['prefix'] . ': ' . implode(', ', $texts);
            },
            ['name' => 'test_tool', 'description' => 'Test tool', 'schema' => Schema::object(['prefix' => ['type' => 'string']])],
        );
        $agent = ReactAgent::create([
            'llm' => ReactAgentFixtures::fake([
                new AIMessage(['content' => 'ai', 'tool_calls' => [ReactAgentFixtures::toolCall('test_tool', 'testid', ['prefix' => 'parrot'])]]),
            ]),
            'tools' => [$parrot],
            'version' => $version,
        ]);

        $result = $agent->invoke(['messages' => 'input']);

        self::assertSame(['input', 'ai', '[tool] parrot: input, ai'], ReactAgentFixtures::texts($result['messages']));
        self::assertSame('testid', $result['messages'][1]->toolCalls[0]['id']);
    }

    #[DataProvider('versions')]
    public function testAToolThatReturnsDirectlyEndsTheLoopWithoutAnotherModelCall(string $version): void
    {
        $lookup = tool(
            static fn (array $in): string => 'direct answer',
            ['name' => 'lookup', 'description' => 'd', 'schema' => Schema::object([]), 'returnDirect' => true],
        );
        $llm = ReactAgentFixtures::spy([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('lookup', 'c1')]]),
            new AIMessage('never reached'),
        ]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [$lookup], 'version' => $version]);

        $result = $agent->invoke(['messages' => 'go']);

        self::assertSame(['go', '', 'direct answer'], ReactAgentFixtures::texts($result['messages']));
        self::assertCount(1, $llm->generateCalls);
    }

    public function testShouldHandleToolErrors(): void
    {
        $toolNode = new ToolNode([ReactAgentFixtures::searchApi()]);
        $res = $toolNode->invoke([new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('badtool', 'testid')]])]);

        self::assertSame('error', $res[0]->additional_kwargs['status']);
        self::assertSame("Error: Tool \"badtool\" not found.\n Please fix your mistakes.", $res[0]->content);
    }

    #[DataProvider('versions')]
    public function testAnAgentWithNoToolCallsFinishesAfterOneModelCall(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('plain answer')]);

        $result = ReactAgent::create(['llm' => $llm, 'tools' => [ReactAgentFixtures::searchApi()], 'version' => $version])
            ->invoke(['messages' => 'hi']);

        self::assertSame(['hi', 'plain answer'], ReactAgentFixtures::texts($result['messages']));
        self::assertCount(1, $llm->generateCalls);
    }

    /** @return array<string, array{0: string, 1: bool, 2: int}> */
    public static function fanOutCases(): array
    {
        return [
            'v1 without a post-model hook' => ['v1', false, 1],
            'v2 without a post-model hook' => ['v2', false, 2],
            'v1 with a post-model hook' => ['v1', true, 1],
            'v2 with a post-model hook' => ['v2', true, 2],
        ];
    }

    #[DataProvider('fanOutCases')]
    public function testVersionDecidesWhetherToolCallsShareOneToolsTask(string $version, bool $withPostModelHook, int $expectedToolsTasks): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [
                ReactAgentFixtures::toolCall('search_api', 'a', ['query' => 'foo']),
                ReactAgentFixtures::toolCall('search_api', 'b', ['query' => 'bar']),
            ]]),
            new AIMessage('done'),
        ]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
        ] + ($withPostModelHook ? ['postModelHook' => static fn (): array => []] : []));

        $toolsTasks = 0;
        $last = null;
        foreach ($agent->stream(['messages' => 'go']) as [$mode, $chunk]) {
            if ($mode === 'updates' && isset($chunk['tools'])) {
                $toolsTasks++;
            }
            if ($mode === 'values') {
                $last = $chunk;
            }
        }

        self::assertSame($expectedToolsTasks, $toolsTasks);
        self::assertSame(['go', '', 'result for foo', 'result for bar', 'done'], ReactAgentFixtures::texts($last['messages']));
    }

    public function testTheToolBindingIsDoneOnceAndReusedAcrossModelCalls(): void
    {
        $llm = ReactAgentFixtures::spy([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'a', ['query' => 'foo'])]]),
            new AIMessage('done'),
        ]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [ReactAgentFixtures::searchApi()]]);

        $agent->invoke(['messages' => 'one']);
        $agent->invoke(['messages' => 'two']);

        self::assertCount(1, $llm->bindToolsCalls, 'four model calls, one bind');
        self::assertCount(4, $llm->generateCalls);
    }
}
