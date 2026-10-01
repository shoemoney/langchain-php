<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `withConfig()` was missing from the port entirely, and its absence is why three separate reviews
 * reported a `runName` propagation bug: they reached for a way to set config on a runnable, found none,
 * and concluded the value was being dropped. The premise was right and the conclusion was wrong.
 *
 * Upstream is a one-liner (`base.ts:175-183`):
 *
 *     withConfig(config) { return new RunnableBinding({ bound: this, config, kwargs: {} }); }
 *
 * and `RunnableBinding` in this port already persists a bound config across `invoke`, `stream` and
 * `batch` — so this is a delegation to `bind([], $config)`, not new machinery.
 */
#[CoversClass(RunnableLambda::class)]
final class WithConfigTest extends TestCase
{
    /**
     * `withConfig` returns a binding, exactly as upstream's `new RunnableBinding({ bound, config, kwargs: {} })`.
     *
     * The original version of this test tried to prove the name reached a TRACED run by calling
     * `$config?->handleLLMStart(...)` from inside the lambda. That is a method on the run MANAGER, not on
     * `RunnableConfig` — the same class of mistake as 390's wrong-shape read, and the second time in two
     * iterations that a plausible-looking assertion was wrong about the API rather than about the code
     * under test. The config-reaches-the-lambda assertions below are the real check and they do not need
     * a tracer at all.
     */
    public function testWithConfigReturnsABindingAndStaysChainable(): void
    {
        $base = new RunnableLambda(static fn (mixed $x): string => 'ok');

        $bound = $base->withConfig(['runName' => 'pull_person']);

        self::assertInstanceOf(\LangChain\Runnables\RunnableBinding::class, $bound);
        self::assertSame('ok', $bound->invoke('hi'), 'the binding must delegate straight through');
    }

    /** `withConfig` must not consume a call-time config — the binding merges, it does not replace. */
    public function testACallTimeConfigStillReachesTheRunnable(): void
    {
        $seen = null;
        $base = new RunnableLambda(function (mixed $input, $config) use (&$seen): mixed {
            $seen = $config;

            return $input;
        });

        $base->withConfig(['runName' => 'bound'])->invoke('hi', new RunnableConfig(tags: ['call-time']));

        self::assertNotNull($seen, 'the lambda received no config');
        self::assertSame('bound', $seen->runName, 'the bound name must survive');
        self::assertContains('call-time', $seen->tags, 'the call-time tags must survive');
    }

    /** Control: a plain runnable is unaffected. */
    public function testPlainInvokeStillWorks(): void
    {
        self::assertSame('ok', (new RunnableLambda(static fn (mixed $x): string => 'ok'))->invoke('hi'));
    }
}
