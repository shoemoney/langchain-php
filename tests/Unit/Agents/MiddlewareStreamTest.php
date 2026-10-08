<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/middleware.stream.test.ts`.
 *
 * Only "should expose streamTransformers on the middleware instance" has a counterpart. The other four stream
 * `run.extensions` out of `streamEvents(..., { version: "v3" })`, which needs LangGraph's stream transformer
 * protocol (`StreamTransformer`, `StreamChannel`, the v3 run stream); this port has neither, so a transformer
 * factory is recorded on the middleware and never run.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareStreamTest extends TestCase
{
    public function testShouldExposeStreamTransformersOnTheMiddlewareInstance(): void
    {
        $eventCounter = static fn (): array => [
            'init' => static fn (): array => ['eventCount' => 'remote:eventCount'],
            'process' => static fn (): bool => true,
        ];

        $middleware = Middleware::create(['name' => 'StreamMiddleware', 'streamTransformers' => [$eventCounter]]);

        self::assertSame([$eventCounter], $middleware['streamTransformers']);
    }

    public function testAnAgentWithAStreamTransformerMiddlewareStillStreamsAsUsual(): void
    {
        $middleware = Middleware::create([
            'name' => 'StreamMiddleware',
            'streamTransformers' => [static fn (): array => ['process' => static fn (): bool => true]],
        ]);
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('ok')]),
            'tools' => [],
            'middleware' => [$middleware],
        ]);

        $chunks = iterator_to_array($agent->stream(['messages' => [new HumanMessage('hi')]]), false);

        $updates = array_values(array_filter($chunks, static fn (array $chunk): bool => $chunk[0] === 'updates'));
        self::assertNotEmpty($updates);
        self::assertArrayHasKey('model_request', $updates[0][1]);
    }

    public function testStreamEventsV3IsRefusedClearly(): void
    {
        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('ok')]), 'tools' => []]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version "v3" is not available');

        $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');
    }
}
