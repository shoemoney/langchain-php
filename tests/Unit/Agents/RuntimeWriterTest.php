<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolRuntime;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Agents\Runtime;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The stream writer a tool or middleware reads off its runtime.
 *
 * Upstream builds every runtime with `writer: lgConfig.writer` (`AgentNode.ts:526,735`, `ToolNode.ts:328`,
 * `nodes/middleware.ts:99`) and `tools.test.ts:38` asserts `typeof runtime.writer === "function"` on a plain
 * agent invoke. LangGraph's `Pregel.stream()` hands that function to the run as `options['writer']`
 * (`config.writer ??= ...`), with `configurable['writer']` the lookup `Config::getWriter()` falls back to.
 *
 * The first group pins the lookup on both runtime classes. The second drives a real fake-model agent run and
 * reads the result off the stream, so a drifting key on either side of the engine/runtime boundary fails it
 * rather than a hand-built config agreeing with the implementation.
 */
#[CoversClass(Runtime::class)]
#[CoversClass(ToolRuntime::class)]
final class RuntimeWriterTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $configurable
     */
    private static function config(array $options = [], array $configurable = []): RunnableConfig
    {
        // `ToolRuntime::fromConfig()` is null without a tool call, so both classes get one.
        return new RunnableConfig(
            configurable: $configurable,
            options: $options,
            toolCall: ['id' => 'call_1', 'name' => 'probe', 'args' => []],
        );
    }

    /** @return iterable<string, array{0: \Closure(RunnableConfig): mixed}> */
    public static function runtimeClasses(): iterable
    {
        yield 'Agents\Runtime' => [static fn (RunnableConfig $config): mixed => Runtime::fromConfig($config)->writer];
        yield 'Tools\ToolRuntime' => [static fn (RunnableConfig $config): mixed => ToolRuntime::fromConfig($config)?->writer];
    }

    /** @param \Closure(RunnableConfig): mixed $writerOf */
    #[DataProvider('runtimeClasses')]
    public function testExposesTheEngineWriterPublishedUnderOptions(\Closure $writerOf): void
    {
        $writer = static function (mixed $chunk): void {
        };

        self::assertSame($writer, $writerOf(self::config(options: ['writer' => $writer])));
    }

    /** @param \Closure(RunnableConfig): mixed $writerOf */
    #[DataProvider('runtimeClasses')]
    public function testFallsBackToAWriterSetUnderConfigurable(\Closure $writerOf): void
    {
        $writer = static function (mixed $chunk): void {
        };

        self::assertSame($writer, $writerOf(self::config(configurable: ['writer' => $writer])));
        self::assertSame($writer, $writerOf(self::config(
            options: ['writer' => null],
            configurable: ['writer' => $writer],
        )));
    }

    /** @param \Closure(RunnableConfig): mixed $writerOf */
    #[DataProvider('runtimeClasses')]
    public function testOptionsWriterWinsOverConfigurableWriter(\Closure $writerOf): void
    {
        $fromOptions = static function (mixed $chunk): void {
        };
        $fromConfigurable = static function (mixed $chunk): void {
        };

        $writer = $writerOf(self::config(
            options: ['writer' => $fromOptions],
            configurable: ['writer' => $fromConfigurable],
        ));

        self::assertSame($fromOptions, $writer);
        self::assertNotSame($fromConfigurable, $writer);
    }

    /** @param \Closure(RunnableConfig): mixed $writerOf */
    #[DataProvider('runtimeClasses')]
    public function testWriterIsNullWhenTheConfigCarriesNone(\Closure $writerOf): void
    {
        self::assertNull($writerOf(self::config()), 'no writer anywhere: nothing is invented');
        self::assertNull(
            $writerOf(self::config(options: ['writer' => null], configurable: ['writer' => null])),
            'explicit nulls are the same as absent',
        );
        self::assertNull(
            $writerOf(self::config(options: ['store' => new \stdClass()], configurable: ['thread_id' => 't'])),
            'unrelated keys are not mistaken for a writer',
        );
    }

    /** @param \Closure(RunnableConfig): mixed $writerOf */
    #[DataProvider('runtimeClasses')]
    public function testANonCallableWriterIsNotExposed(\Closure $writerOf): void
    {
        self::assertNull($writerOf(self::config(options: ['writer' => 42])));
        self::assertNull($writerOf(self::config(configurable: ['writer' => 'no_such_function_xyz'])));
        self::assertNull($writerOf(self::config(
            options: ['writer' => 42],
            configurable: ['writer' => static function (mixed $chunk): void {
            }],
        )), 'a non-null options writer wins the lookup before callable validation');
    }

    /**
     * The runtime must hand a node the same function `Config::getWriter()` would, for every shape of config.
     *
     * @param \Closure(RunnableConfig): mixed $writerOf
     */
    #[DataProvider('runtimeClasses')]
    public function testMatchesTheEnginesOwnWriterAccessor(\Closure $writerOf): void
    {
        $a = static function (mixed $chunk): void {
        };
        $b = static function (mixed $chunk): void {
        };

        foreach ([
            self::config(),
            self::config(options: ['writer' => $a]),
            self::config(configurable: ['writer' => $b]),
            self::config(options: ['writer' => $a], configurable: ['writer' => $b]),
            self::config(options: ['writer' => 42], configurable: ['writer' => $b]),
        ] as $config) {
            self::assertSame(Config::getWriter($config), $writerOf($config));
        }
    }

    public function testRuntimeFromNoConfigHasNoWriter(): void
    {
        self::assertNull(Runtime::fromConfig(null)->writer);
        self::assertNull(ToolRuntime::fromConfig(null));
    }

    /**
     * A one-tool agent over a scripted model, so nothing touches the network.
     *
     * @param \Closure(array<string, mixed>, ToolRuntime): string $body
     * @param array<string, mixed>                                 $options extra `Agent::create()` options
     */
    private static function agentWithTool(\Closure $body, array $options = []): ReactAgent
    {
        return Agent::create([
            'model' => new FakeToolCallingModel([
                'toolCalls' => [[['type' => 'tool_call', 'name' => 'probe', 'args' => ['city' => 'Tokyo'], 'id' => 'call_1']]],
            ]),
            'tools' => [tool($body, [
                'name' => 'probe',
                'description' => 'probe',
                'schema' => Schema::object(['city' => ['type' => 'string']], ['city']),
            ])],
            ...$options,
        ]);
    }

    /** @return array{messages: list<HumanMessage>} */
    private static function input(): array
    {
        return ['messages' => [new HumanMessage('What is the weather in Tokyo?')]];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<mixed>
     */
    private static function streamOf(ReactAgent $agent, array $config): array
    {
        return iterator_to_array($agent->stream(self::input(), $config), false);
    }

    public function testAToolWriterEmitsACustomChunkThroughTheAgentStream(): void
    {
        $seen = [];
        $agent = self::agentWithTool(static function (array $input, ToolRuntime $runtime) use (&$seen): string {
            $seen[] = $runtime->writer;
            ($runtime->writer)(['progress' => 'looking up ' . $input['city']]);

            return 'sunny';
        });

        $chunks = self::streamOf($agent, ['streamMode' => ['custom']]);

        self::assertCount(1, $seen, 'the tool ran exactly once');
        self::assertIsCallable($seen[0]);
        self::assertSame([['custom', ['progress' => 'looking up Tokyo']]], $chunks);
    }

    public function testAToolWriterChunkCarriesTheRootNamespaceWhenSubgraphsAreStreamed(): void
    {
        $agent = self::agentWithTool(static function (array $input, ToolRuntime $runtime): string {
            ($runtime->writer)('from-the-tool');

            return 'sunny';
        });

        $chunks = self::streamOf($agent, ['streamMode' => ['custom'], 'subgraphs' => true]);

        // A root graph has the empty namespace: `[namespace, mode, payload]`.
        self::assertSame([[[], 'custom', 'from-the-tool']], $chunks);
    }

    public function testAToolWriterChunkLandsUnderTheSubgraphNamespaceOfItsAgent(): void
    {
        $agent = self::agentWithTool(static function (array $input, ToolRuntime $runtime): string {
            ($runtime->writer)('nested-progress');

            return 'sunny';
        });
        $parent = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('child', $agent->graph)
            ->addEdge(Constants::START, 'child')
            ->addEdge('child', Constants::END)
            ->compile();

        $chunks = iterator_to_array($parent->stream(self::input(), new RunnableConfig(
            options: ['subgraphs' => true, 'streamMode' => ['updates', 'custom']],
        )), false);

        // The oracle is the engine's own placement of the same node: the `tools` node of the nested agent
        // reports its `updates` under the child's namespace, and the custom chunk must sit beside it.
        $toolUpdates = array_values(array_filter(
            $chunks,
            static fn (array $chunk): bool => $chunk[1] === 'updates' && isset($chunk[2]['tools']),
        ));
        self::assertCount(1, $toolUpdates);
        self::assertCount(1, $toolUpdates[0][0]);
        self::assertStringStartsWith('child:', $toolUpdates[0][0][0]);

        $custom = array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[1] === 'custom'));
        self::assertSame([[$toolUpdates[0][0], 'custom', 'nested-progress']], $custom);
    }

    public function testABeforeModelMiddlewareWriterEmitsACustomChunk(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [Middleware::create([
                'name' => 'emitter',
                'beforeModel' => static function (array $state, Runtime $runtime): void {
                    ($runtime->writer)('before-model');
                },
            ])],
        ]);

        self::assertSame([['custom', 'before-model']], self::streamOf($agent, ['streamMode' => ['custom']]));
    }

    public function testAWrapModelCallMiddlewareWriterEmitsACustomChunk(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'middleware' => [Middleware::create([
                'name' => 'emitter',
                'wrapModelCall' => static function (array $request, callable $handler): mixed {
                    ($request['runtime']->writer)('wrap-model-call');

                    return $handler($request);
                },
            ])],
        ]);

        self::assertSame([['custom', 'wrap-model-call']], self::streamOf($agent, ['streamMode' => ['custom']]));
    }

    public function testAWrapToolCallMiddlewareWriterEmitsACustomChunk(): void
    {
        $agent = self::agentWithTool(
            static fn (array $input, ToolRuntime $runtime): string => 'sunny',
            ['middleware' => [Middleware::create([
                'name' => 'emitter',
                'wrapToolCall' => static function (array $request, callable $handler): mixed {
                    ($request['runtime']->writer)('wrap-tool-call:' . $request['toolCall']['name']);

                    return $handler($request);
                },
            ])]],
        );

        self::assertSame([['custom', 'wrap-tool-call:probe']], self::streamOf($agent, ['streamMode' => ['custom']]));
    }

    public function testAWriterCallerSuppliedOnTheRunConfigIsWhatTheToolSees(): void
    {
        $received = [];
        $sink = static function (mixed $chunk) use (&$received): void {
            $received[] = $chunk;
        };
        $seen = [];
        $agent = self::agentWithTool(static function (array $input, ToolRuntime $runtime) use (&$seen): string {
            $seen[] = $runtime->writer;
            ($runtime->writer)('to-the-callers-sink');

            return 'sunny';
        });

        $agent->invoke(self::input(), ['writer' => $sink]);

        self::assertCount(1, $seen);
        self::assertSame($sink, $seen[0], 'the engine keeps a writer the caller already published');
        self::assertSame(['to-the-callers-sink'], $received);
    }

    public function testCallingTheWriterWithoutCustomStreamingIsASilentNoOp(): void
    {
        $agent = self::agentWithTool(static function (array $input, ToolRuntime $runtime): string {
            ($runtime->writer)('nobody-asked-for-custom');

            return 'sunny';
        });

        // A plain invoke (the `tools.test.ts` shape) must still run to the end with a callable in hand.
        $result = $agent->invoke(self::input());
        self::assertSame('sunny', $result['messages'][array_key_last($result['messages'])]->content);

        // And a stream that did not ask for `custom` carries none of it.
        $modes = array_values(array_unique(array_column(self::streamOf($agent, ['streamMode' => ['updates']]), 0)));
        self::assertSame(['updates'], $modes);
    }

    public function testAToolInvokedDirectlyWithAWriterOnItsConfigReceivesIt(): void
    {
        $writer = static function (mixed $chunk): void {
        };
        $seen = [];
        $tool = new DynamicStructuredTool(
            ['name' => 'probe', 'description' => 'probe', 'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]]],
            static function (array $in, ToolRuntime $runtime) use (&$seen): string {
                $seen[] = $runtime->writer;

                return 'ok';
            },
        );

        $tool->invoke(['x' => 1], self::config(options: ['writer' => $writer]));
        $tool->invoke(['x' => 1], self::config());

        self::assertSame([$writer, null], $seen);
    }
}
