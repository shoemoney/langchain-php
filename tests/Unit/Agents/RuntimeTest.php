<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Runtime;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The write-protection cases of `langchain/src/agents/tests/runtime.test.ts`. Upstream runs them through
 * `createAgent` middleware hooks (`beforeModel`, `afterModel`, `wrapModelCall`) and expects "Cannot assign
 * to read only property"; those three are deferred to WP-21b. What they prove is that a runtime cannot be
 * written to, which is asserted here directly.
 */
#[CoversClass(Runtime::class)]
final class RuntimeTest extends TestCase
{
    public function testShouldThrowOnTheAttemptToWriteToTheRuntimeContext(): void
    {
        $runtime = new Runtime(context: ['user' => 'a']);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');

        /** @phpstan-ignore-next-line */
        $runtime->context = 123;
    }

    public function testEveryPropertyIsReadonly(): void
    {
        $runtime = new Runtime();

        foreach (['store', 'writer', 'interrupt', 'signal', 'toolCallId', 'configurable'] as $property) {
            try {
                $runtime->{$property} = 'x';
                self::fail("$property should be readonly");
            } catch (\Error $e) {
                self::assertStringContainsString('Cannot modify readonly property', $e->getMessage());
            }
        }
    }

    public function testFromConfigReadsTheRunConfig(): void
    {
        $store = new InMemoryStore();
        $signal = static fn (): bool => false;
        $config = new RunnableConfig(
            signal: $signal,
            configurable: ['thread_id' => 't1', '__pregel_store' => $store],
            toolCall: ['id' => 'call_1', 'name' => 'x', 'args' => []],
            context: ['tenant' => 'acme'],
        );

        $runtime = Runtime::fromConfig($config);

        self::assertSame(['tenant' => 'acme'], $runtime->context);
        self::assertSame($store, $runtime->store);
        self::assertSame($signal, $runtime->signal);
        self::assertSame('call_1', $runtime->toolCallId);
        self::assertSame('t1', $runtime->configurable['thread_id']);
    }

    public function testFromNothingIsEmpty(): void
    {
        $runtime = Runtime::fromConfig(null);

        self::assertNull($runtime->context);
        self::assertNull($runtime->store);
        self::assertSame([], $runtime->configurable);
    }

    public function testWithReturnsAChangedCopy(): void
    {
        $runtime = new Runtime(context: 1, configurable: ['thread_id' => 't']);

        $copy = $runtime->with(['context' => null, 'toolCallId' => 'c']);

        self::assertNotSame($runtime, $copy);
        self::assertNull($copy->context, 'an explicit null replaces the value');
        self::assertSame('c', $copy->toolCallId);
        self::assertSame(['thread_id' => 't'], $copy->configurable);
        self::assertSame(1, $runtime->context, 'the original is untouched');
    }
}
