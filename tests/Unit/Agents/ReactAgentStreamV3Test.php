<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Agents\Transformers\AgentMessageStream;
use LangGraph\Agents\Transformers\AgentRunStream;
use LangGraph\Agents\Transformers\ToolCallStream;
use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\RunStream;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\StreamTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `ReactAgent::streamEvents(..., 'v3')` end to end: a real agent graph (model node, tools node, middleware)
 * run through the run stream, with the native and user transformers registered the way `ReactAgent.ts:679-687`
 * assembles them.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentStreamV3Test extends TestCase
{
    private static function weatherAgent(array $options = []): ReactAgent
    {
        $weather = tool(
            static fn (array $in): string => 'Sunny in ' . $in['city'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );

        return Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'Tokyo'], 'id' => 'call_1', 'type' => 'tool_call']]]),
                new AIMessage('It is sunny in Tokyo.'),
            ]),
            'tools' => [$weather],
            ...$options,
        ]);
    }

    private static function input(string $text = 'Weather in Tokyo?'): array
    {
        return ['messages' => [new HumanMessage($text)]];
    }

    /**
     * A factory for a transformer that appends its label to `$log` the first time it sees an event.
     *
     * @param list<string> $log
     */
    private static function labelled(string $label, array &$log): \Closure
    {
        return static function () use ($label, &$log): StreamTransformer {
            return new class($label, $log) extends AbstractStreamTransformer {
                private bool $seen = false;

                /** @param list<string> $log */
                public function __construct(private readonly string $label, private array &$log)
                {
                }

                public function init(): array
                {
                    return [$this->label => StreamChannel::local()];
                }

                public function process(array $event): bool
                {
                    if (!$this->seen) {
                        $this->seen = true;
                        $this->log[] = $this->label;
                    }

                    return true;
                }
            };
        };
    }

    public function testV3ReturnsARunStreamWhoseProjectionsCoverTheWholeRun(): void
    {
        $run = self::weatherAgent()->streamEvents(self::input(), null, 'v3');

        self::assertInstanceOf(AgentRunStream::class, $run);
        self::assertInstanceOf(RunStream::class, $run);

        $texts = array_map(static fn (AgentMessageStream $m): string => $m->text(), iterator_to_array($run->messages(), false));
        self::assertSame(['', 'It is sunny in Tokyo.'], $texts);

        $toolCalls = iterator_to_array($run->toolCalls, false);
        self::assertCount(1, $toolCalls);
        self::assertInstanceOf(ToolCallStream::class, $toolCalls[0]);
        self::assertSame('get_weather', $toolCalls[0]->name);
        self::assertSame('call_1', $toolCalls[0]->callId);
        self::assertSame(['city' => 'Tokyo'], $toolCalls[0]->input);
        self::assertSame('Sunny in Tokyo', $toolCalls[0]->output->value());
        self::assertSame('finished', $toolCalls[0]->status->value());

        self::assertSame([], iterator_to_array($run->subagents, false));

        $snapshots = iterator_to_array($run->values(), false);
        $final = $run->output();
        self::assertNotEmpty($snapshots);
        self::assertSame(['human', 'ai', 'tool', 'ai'], array_map(static fn ($m): string => $m->type, $final['messages']));
        self::assertSame('It is sunny in Tokyo.', $final['messages'][3]->content);
        self::assertFalse($run->interrupted());
    }

    public function testMessagesReadLikeMessagesAndLikeChatModelStreams(): void
    {
        $run = self::weatherAgent()->streamEvents(self::input(), null, 'v3');

        $messages = iterator_to_array($run->messages(), false);

        self::assertCount(2, $messages);
        self::assertContainsOnlyInstancesOf(AgentMessageStream::class, $messages);
        self::assertTrue(isset($messages[1]->content));
        self::assertSame('It is sunny in Tokyo.', $messages[1]->content);
        self::assertSame($messages[1]->text(), $messages[1]->content);
        self::assertSame(['model_request'], [$messages[1]->node()]);
        self::assertSame('message-start', iterator_to_array($messages[1], false)[0]['event']);
        self::assertFalse(isset($messages[1]->nothing));
    }

    public function testMessagesFromNarrowsToOneNode(): void
    {
        $run = self::weatherAgent()->streamEvents(self::input(), null, 'v3');

        $fromModel = iterator_to_array($run->messagesFrom('model_request'), false);
        $fromTools = iterator_to_array($run->messagesFrom('tools'), false);

        self::assertSame(['', 'It is sunny in Tokyo.'], array_map(static fn (AgentMessageStream $m): string => $m->content, $fromModel));
        self::assertSame([], $fromTools);
    }

    public function testEventsCarryTheAgentsNodesAndTheToolLifecycle(): void
    {
        $run = self::weatherAgent()->streamEvents(self::input(), null, 'v3');

        $methods = [];
        $tools = [];
        foreach ($run as $event) {
            $methods[$event['method']] = true;
            if ($event['method'] === 'tools') {
                $tools[] = $event['params']['data']['event'];
            }
        }

        self::assertArrayHasKey('values', $methods);
        self::assertArrayHasKey('messages', $methods);
        self::assertArrayHasKey('updates', $methods);
        self::assertSame(['tool-started', 'tool-finished'], $tools);
    }

    public function testTheToolsStreamNamesTheToolNotTheNode(): void
    {
        $run = self::weatherAgent()->streamEvents(self::input(), null, 'v3');

        $started = null;
        foreach ($run as $event) {
            if ($event['method'] === 'tools' && $event['params']['data']['event'] === 'tool-started') {
                $started = $event['params']['data'];
            }
        }

        self::assertSame('get_weather', $started['tool_name']);
        self::assertSame('call_1', $started['tool_call_id']);
    }

    public function testTransformersRegisterMiddlewareThenOptionThenCallSite(): void
    {
        $order = [];
        $middleware = Middleware::create(['name' => 'M', 'streamTransformers' => [self::labelled('middleware', $order)]]);
        $agent = self::weatherAgent(['middleware' => [$middleware], 'streamTransformers' => [self::labelled('option', $order)]]);

        $run = $agent->streamEvents(self::input(), ['transformers' => [self::labelled('callSite', $order)]], 'v3');
        $run->output();

        self::assertSame(['middleware', 'option', 'callSite'], $order);
        self::assertSame(['middleware', 'option', 'callSite'], array_keys($run->extensions()));
    }

    public function testATransformerInstanceIsAcceptedAsWellAsAFactory(): void
    {
        $order = [];
        $instance = (self::labelled('instance', $order))();
        $agent = self::weatherAgent(['streamTransformers' => [$instance]]);

        $run = $agent->streamEvents(self::input(), null, 'v3');
        $run->output();

        self::assertSame(['instance'], $order);
    }

    public function testEveryRunBuildsItsOwnTransformers(): void
    {
        $order = [];
        $agent = self::weatherAgent(['streamTransformers' => [self::labelled('option', $order)]]);

        $agent->streamEvents(self::input(), null, 'v3')->output();
        $agent->streamEvents(self::input(), null, 'v3')->output();

        // A shared instance would only see its first event once.
        self::assertSame(['option', 'option'], $order);
    }

    public function testAFailingRunRejectsOutputAndTheProjections(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('unused')]),
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'boom',
                'beforeModel' => static function (): never {
                    throw new \RuntimeException('boom');
                },
            ])],
        ]);
        $run = $agent->streamEvents(self::input(), null, 'v3');

        $this->expectExceptionMessage('boom');
        $run->output();
    }

    public function testMiddlewareStateIsInitialisedForTheRunStream(): void
    {
        $seen = [];
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('ok')]),
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'defaults',
                'stateSchema' => ['type' => 'object', 'properties' => ['count' => ['type' => 'number', 'default' => 7]]],
                'beforeModel' => static function (array $state) use (&$seen): ?array {
                    $seen[] = $state['count'];

                    return null;
                },
            ])],
        ]);

        $agent->streamEvents(self::input(), null, 'v3')->output();

        self::assertSame([7], $seen);
    }

    public function testVersionsOneAndTwoStillYieldTheGraphsEventStream(): void
    {
        foreach (['v1', 'v2'] as $version) {
            $events = self::weatherAgent()->streamEvents(self::input(), null, $version);

            self::assertInstanceOf(\Generator::class, $events);
            self::assertNotEmpty(iterator_to_array($events, false), $version);
        }
    }

    public function testStreamOptionsKeepAV3RequestOnTheEventStream(): void
    {
        $events = self::weatherAgent()->streamEvents(self::input(), null, 'v3', ['includeNames' => ['get_weather']]);

        self::assertInstanceOf(\Generator::class, $events);
        self::assertNotEmpty(iterator_to_array($events, false));
    }

    public function testCheckpointedRunsStreamV3OnAThread(): void
    {
        $agent = self::weatherAgent(['checkpointer' => new \LangGraph\Checkpoint\MemorySaver()]);
        $config = ['configurable' => ['thread_id' => 't-1']];

        $agent->streamEvents(self::input(), $config, 'v3')->output();

        $state = $agent->getState($config);
        self::assertSame(4, \count($state->values['messages']));
    }
}
