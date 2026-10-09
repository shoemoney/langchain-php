<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\StreamTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/middleware.stream.test.ts`.
 *
 * Each case streams `run.extensions` out of `streamEvents(..., 'v3')`. The run stream is pull-driven (no event
 * loop), so iterating an extension channel advances the run until the channel is exhausted.
 */
#[CoversClass(Middleware::class)]
#[CoversClass(ReactAgent::class)]
final class MiddlewareStreamTest extends TestCase
{
    /** @return \Closure(): StreamTransformer a factory whose transformer pushes the running event count */
    private static function eventCounter(): \Closure
    {
        return static fn (): StreamTransformer => new class extends AbstractStreamTransformer {
            private readonly StreamChannel $eventCount;

            private int $count = 0;

            public function __construct()
            {
                $this->eventCount = StreamChannel::remote('eventCount');
            }

            public function init(): array
            {
                return ['eventCount' => $this->eventCount];
            }

            public function process(array $event): bool
            {
                $this->eventCount->push(++$this->count);

                return true;
            }
        };
    }

    /** @return \Closure(): StreamTransformer a factory whose transformer pushes every event's method */
    private static function methodTracker(): \Closure
    {
        return static fn (): StreamTransformer => new class extends AbstractStreamTransformer {
            private readonly StreamChannel $methods;

            public function __construct()
            {
                $this->methods = StreamChannel::remote('methods');
            }

            public function init(): array
            {
                return ['methods' => $this->methods];
            }

            public function process(array $event): bool
            {
                $this->methods->push($event['method']);

                return true;
            }
        };
    }

    /** @param array<string, mixed> $options */
    private static function agent(array $options): ReactAgent
    {
        return Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('ok')]), 'tools' => [], ...$options]);
    }

    /**
     * @param array<string, mixed> $extensions
     * @return list<mixed>
     */
    private static function drain(array $extensions, string $name): array
    {
        self::assertArrayHasKey($name, $extensions);

        return iterator_to_array($extensions[$name], false);
    }

    public function testShouldExposeStreamTransformersOnTheMiddlewareInstance(): void
    {
        $eventCounter = self::eventCounter();

        $middleware = Middleware::create(['name' => 'StreamMiddleware', 'streamTransformers' => [$eventCounter]]);

        self::assertSame([$eventCounter], $middleware['streamTransformers']);
    }

    public function testAnAgentWithAStreamTransformerMiddlewareStillStreamsAsUsual(): void
    {
        $middleware = Middleware::create(['name' => 'StreamMiddleware', 'streamTransformers' => [self::eventCounter()]]);
        $agent = self::agent(['middleware' => [$middleware]]);

        $chunks = iterator_to_array($agent->stream(['messages' => [new HumanMessage('hi')]]), false);

        $updates = array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[0] === 'updates'));
        self::assertNotEmpty($updates);
        self::assertArrayHasKey('model_request', $updates[0][1]);
    }

    public function testShouldStreamEventCountsFromAMiddlewareRegisteredTransformer(): void
    {
        $middleware = Middleware::create(['name' => 'StreamMiddleware', 'streamTransformers' => [self::eventCounter()]]);
        $agent = self::agent(['middleware' => [$middleware]]);

        $run = $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');
        $counts = self::drain($run->extensions(), 'eventCount');

        self::assertNotEmpty($counts);
        foreach ($counts as $i => $count) {
            self::assertSame($i + 1, $count);
        }
        self::assertSame(\count($counts), $counts[\count($counts) - 1]);
    }

    public function testShouldStreamProtocolMethodsFromAMiddlewareRegisteredTransformer(): void
    {
        $middleware = Middleware::create(['name' => 'MethodMiddleware', 'streamTransformers' => [self::methodTracker()]]);
        $agent = self::agent(['middleware' => [$middleware]]);

        $run = $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');
        $seenMethods = self::drain($run->extensions(), 'methods');

        self::assertNotEmpty($seenMethods);
        self::assertContains('values', $seenMethods);
    }

    public function testShouldMergeAgentAndMiddlewareStreamTransformers(): void
    {
        $middleware = Middleware::create(['name' => 'MethodMiddleware', 'streamTransformers' => [self::methodTracker()]]);
        $agent = self::agent(['middleware' => [$middleware], 'streamTransformers' => [self::eventCounter()]]);

        $run = $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');
        $counts = self::drain($run->extensions(), 'eventCount');
        $seenMethods = self::drain($run->extensions(), 'methods');

        self::assertNotEmpty($counts);
        self::assertNotEmpty($seenMethods);
        self::assertContains('values', $seenMethods);
    }

    public function testShouldMergeStreamTransformersFromMultipleMiddlewareInstances(): void
    {
        $counterMiddleware = Middleware::create(['name' => 'CounterMiddleware', 'streamTransformers' => [self::eventCounter()]]);
        $trackerMiddleware = Middleware::create(['name' => 'TrackerMiddleware', 'streamTransformers' => [self::methodTracker()]]);
        $agent = self::agent(['middleware' => [$counterMiddleware, $trackerMiddleware]]);

        $run = $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');
        $counts = self::drain($run->extensions(), 'eventCount');
        $seenMethods = self::drain($run->extensions(), 'methods');

        self::assertNotEmpty($counts);
        self::assertNotEmpty($seenMethods);
        self::assertContains('values', $seenMethods);
    }
}
