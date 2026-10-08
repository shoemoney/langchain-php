<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackHandler;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The `withConfig` and `lc_agent_name metadata` describe blocks of `reactAgent.test.ts`.
 *
 * Not converted: "preserves callbacks on the extracted graph" and the assertions on `agent.graph.config` (a
 * compiled graph here has no config of its own, see `ReactAgent`), the callback-manager variants (a config's
 * callbacks are a list of handlers), `streamEvents` version `v3`, and the two LangSmith "tracing metadata" tests.
 * What those assertions establish (the defaults reach the run) is asserted through what a tool receives.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentWithConfigTest extends TestCase
{
    /**
     * An agent whose model calls `config_capture_tool` once; the tool records the config it ran with.
     *
     * @return array{0: ReactAgent, 1: \ArrayObject<string, RunnableConfig>}
     */
    private function captureAgent(string $toolName = 'config_capture_tool', ?string $name = null): array
    {
        $captured = new \ArrayObject();
        $captureTool = tool(
            static function (array $in, mixed $runManager = null, ?RunnableConfig $config = null) use ($captured): string {
                $captured['config'] = $config;

                return 'done';
            },
            ['name' => $toolName, 'description' => 'Captures the config for testing', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['name' => $toolName, 'id' => 'test-1', 'args' => ['input' => 'test']]]]),
                new AIMessage('Done'),
            ]),
            'tools' => [$captureTool],
            ...($name !== null ? ['name' => $name] : []),
        ]);

        return [$agent, $captured];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function methodsAndInvocationCallbacks(): array
    {
        $rows = [];
        foreach (['invoke', 'stream', 'streamEvents'] as $method) {
            foreach ([true, false] as $withInvocationCallbacks) {
                $rows[$method . ($withInvocationCallbacks ? ' with invocation callbacks' : ' without invocation callbacks')] = [$method, $withInvocationCallbacks];
            }
        }

        return $rows;
    }

    /**
     * A callback handler that logs the events it sees into `$log`.
     *
     * @param \ArrayObject<string, mixed> $log
     */
    private function recordingHandler(\ArrayObject $log): CallbackHandler
    {
        return new CallbackHandler([
            'handleChatModelStart' => static function () use ($log): void {
                $log['chatModelStart'] = ($log['chatModelStart'] ?? 0) + 1;
            },
            'handleChainStart' => static function (mixed $chain, mixed $inputs, string $runId) use ($log): void {
                $starts = $log['chainStarts'] ?? [];
                $starts[] = $runId;
                $log['chainStarts'] = $starts;
            },
            'handleChainEnd' => static function (mixed $outputs, string $runId) use ($log): void {
                $ends = $log['chainEnds'] ?? [];
                $ends[] = $runId;
                $log['chainEnds'] = $ends;
            },
        ]);
    }

    #[DataProvider('methodsAndInvocationCallbacks')]
    public function testCallsEachHandlerOnceThroughEveryMethod(string $method, bool $withInvocationCallbacks): void
    {
        $boundLog = new \ArrayObject();
        $invokedLog = new \ArrayObject();
        $bound = $this->recordingHandler($boundLog);
        $invoked = $this->recordingHandler($invokedLog);
        $agent = Agent::create(['model' => new FakeToolCallingModel()])
            ->withConfig(['callbacks' => [$bound]])
            ->withConfig(['tags' => ['copied']]);
        $input = ['messages' => [new HumanMessage('hello')]];
        $config = $withInvocationCallbacks ? ['callbacks' => [$invoked]] : null;

        if ($method === 'invoke') {
            $agent->invoke($input, $config);
        } else {
            $stream = $method === 'stream' ? $agent->stream($input, $config) : $agent->streamEvents($input, $config, 'v2');
            foreach ($stream as $ignored) {
                // Consume the stream to complete callback delivery.
            }
        }

        foreach ($withInvocationCallbacks ? [$boundLog, $invokedLog] : [$boundLog] as $log) {
            self::assertSame(1, $log['chatModelStart'] ?? 0);

            // A graph run reports chain events only through the event stream.
            if ($method === 'streamEvents') {
                $starts = $log['chainStarts'] ?? [];
                $ends = $log['chainEnds'] ?? [];
                self::assertGreaterThan(0, \count($starts));
                self::assertCount(\count($starts), array_unique($starts));
                self::assertCount(\count($ends), array_unique($ends));
                self::assertCount(\count($starts), $ends);
            }
        }
    }

    /** @return array<string, array{0: string}> */
    public static function invokeAndStream(): array
    {
        return ['invoke' => ['invoke'], 'stream' => ['stream']];
    }

    #[DataProvider('invokeAndStream')]
    public function testPreservesMiddlewareStateInitializationWithCallbacks(string $method): void
    {
        $seen = [];
        $log = new \ArrayObject();
        $handler = $this->recordingHandler($log);
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [
                Middleware::create([
                    'name' => 'defaults',
                    'stateSchema' => ['type' => 'object', 'properties' => ['count' => ['type' => 'number', 'default' => 7]]],
                    'beforeModel' => static function (array $state) use (&$seen): ?array {
                        $seen[] = $state['count'];

                        return null;
                    },
                ]),
            ],
        ])->withConfig(['callbacks' => [$handler]]);

        $input = ['messages' => [new HumanMessage('hello')]];
        if ($method === 'invoke') {
            $agent->invoke($input);
        } else {
            foreach ($agent->stream($input) as $ignored) {
                // Consume the stream to complete callback delivery.
            }
        }

        self::assertSame([7], $seen);
        self::assertSame(1, $log['chatModelStart'] ?? 0);
    }

    public function testShouldReturnANewReactAgentInstance(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => []]);

        $configuredAgent = $agent->withConfig(['recursionLimit' => 1000]);

        self::assertNotSame($agent, $configuredAgent);
        self::assertInstanceOf(ReactAgent::class, $configuredAgent);
        self::assertTrue(method_exists($configuredAgent, 'invoke'));
        self::assertTrue(method_exists($configuredAgent, 'stream'));
        self::assertTrue(method_exists($configuredAgent, 'withConfig'));
    }

    public function testShouldAllowChainingMultipleWithConfigCalls(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => []]);

        $configuredAgent = $agent->withConfig(['recursionLimit' => 500])->withConfig(['tags' => ['test']]);

        self::assertNotSame($agent, $configuredAgent);
        self::assertTrue(method_exists($configuredAgent, 'invoke'));
    }

    public function testShouldPreserveOriginalAgentOptions(): void
    {
        $systemPrompt = 'You are a helpful assistant';
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => [], 'systemPrompt' => $systemPrompt]);

        $configuredAgent = $agent->withConfig(['recursionLimit' => 1000]);

        self::assertSame($systemPrompt, $configuredAgent->options['systemPrompt']);
    }

    public function testShouldPropagateWithConfigDefaultsToTheRun(): void
    {
        [$agent, $captured] = $this->captureAgent();

        $agent->withConfig(['recursionLimit' => 1000, 'tags' => ['test']])
            ->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertSame(1000, $captured['config']->recursionLimit);
        self::assertContains('test', $captured['config']->tags);
    }

    public function testShouldApplyBuiltInDefaultMetadata(): void
    {
        [$agent, $captured] = $this->captureAgent(name: 'weather-agent');

        $agent->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertSame('langchain_create_agent', $captured['config']->metadata['ls_integration']);
        self::assertSame('weather-agent', $captured['config']->metadata['lc_agent_name']);
        self::assertSame('root', $captured['config']->configurable['ls_agent_type']);
    }

    public function testShouldPropagateConfigurableValuesToTools(): void
    {
        [$agent, $captured] = $this->captureAgent();

        // Set a custom configurable value via withConfig.
        $agent->withConfig(['configurable' => ['custom_test_value' => 'hello-from-withConfig']])
            ->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertSame('hello-from-withConfig', $captured['config']->configurable['custom_test_value']);
    }

    public function testShouldMergeWithConfigValuesWithInvocationConfig(): void
    {
        [$agent, $captured] = $this->captureAgent();

        $agent->withConfig(['configurable' => ['default_value' => 'from-withConfig']])
            ->invoke(['messages' => [new HumanMessage('test')]], ['configurable' => ['invocation_value' => 'from-invoke']]);

        // Both values are present (merged).
        self::assertSame('from-withConfig', $captured['config']->configurable['default_value']);
        self::assertSame('from-invoke', $captured['config']->configurable['invocation_value']);
    }

    public function testShouldAllowInvocationConfigToOverrideWithConfigValues(): void
    {
        [$agent, $captured] = $this->captureAgent();

        $agent->withConfig(['configurable' => ['shared_key' => 'default-value']])
            ->invoke(['messages' => [new HumanMessage('test')]], ['configurable' => ['shared_key' => 'overridden-value']]);

        // The invocation value wins.
        self::assertSame('overridden-value', $captured['config']->configurable['shared_key']);
    }

    public function testShouldPropagateLcAgentNameInConfigMetadataWhenNameIsProvided(): void
    {
        [$agent, $captured] = $this->captureAgent('metadata_capture_tool', 'my-test-agent');

        $agent->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertSame('my-test-agent', $captured['config']->metadata['lc_agent_name']);
    }

    public function testShouldNotSetLcAgentNameWhenNameIsNotProvided(): void
    {
        [$agent, $captured] = $this->captureAgent('metadata_capture_tool');

        $agent->invoke(['messages' => [new HumanMessage('test')]]);

        self::assertNotNull($captured['config']);
        self::assertArrayNotHasKey('lc_agent_name', $captured['config']->metadata);
    }

    public function testShouldAllowLsAgentTypeToBeOverriddenViaConfigurable(): void
    {
        [$agent, $captured] = $this->captureAgent();

        $agent->invoke(['messages' => [new HumanMessage('test')]], ['configurable' => ['ls_agent_type' => 'subagent']]);

        self::assertSame('subagent', $captured['config']->configurable['ls_agent_type']);
    }
}
