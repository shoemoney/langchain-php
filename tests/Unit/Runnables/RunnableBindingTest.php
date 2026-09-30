<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tracers\RunCollectorCallbackHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A minimal runnable that reports the config it received.
 */
final class ConfigRecordingRunnable extends Runnable
{
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $config ?? new RunnableConfig();
    }

    public function getName(): string
    {
        return 'ConfigRecordingRunnable';
    }
}

#[CoversClass(RunnableBinding::class)]
final class RunnableBindingTest extends TestCase
{
    /**
     * A runnable that hands back the config it was invoked with.
     *
     * Asserting on the *arriving config* is what makes the kwargs assertions
     * below discriminating. A lambda cannot be used for this: `RunnableLambda`
     * passes only the input to its callable, never the config.
     */
    private static function configProbe(): ConfigRecordingRunnable
    {
        return new ConfigRecordingRunnable();
    }

    /**
     * The bound kwargs must reach the wrapped runnable's config.
     *
     * This is the whole point of `bind()`, and it used to do nothing: the kwargs
     * were stored on the binding and never read by anything. No test caught it
     * because no test in the suite called `bind()` at all.
     */
    public function testBoundKwargsReachTheWrappedRunnable(): void
    {
        $config = self::configProbe()
            ->bind(['temperature' => 0.0])
            ->invoke('x');

        self::assertSame(0.0, $config->options['temperature'] ?? null);
    }

    public function testBoundKwargsSurviveStreaming(): void
    {
        $config = null;
        foreach (self::configProbe()->bind(['stop' => ['END']])->stream('x') as [, $value]) {
            $config = $value;
        }

        self::assertSame(['END'], $config->options['stop'] ?? null);
    }

    public function testBoundKwargsSurviveBatch(): void
    {
        $configs = self::configProbe()
            ->bind(['temperature' => 0.25])
            ->batch(['a', 'b']);

        self::assertCount(2, $configs);
        foreach ($configs as $config) {
            self::assertSame(0.25, $config->options['temperature'] ?? null);
        }
    }

    /**
     * A bound kwarg beats a call-time option of the same name.
     *
     * Upstream passes the kwargs *last* to `mergeConfigs`, which lets a later
     * config overwrite an earlier one, so the binding wins. That is upstream's
     * behaviour and is pinned here so it is not "tidied" into the opposite.
     */
    public function testBoundKwargsWinOverCallTimeOptions(): void
    {
        $config = self::configProbe()
            ->bind(['temperature' => 0.0])
            ->invoke('x', new RunnableConfig(options: ['temperature' => 0.5]));

        self::assertSame(0.0, $config->options['temperature'] ?? null);
    }

    /**
     * Unrelated call-time options are not clobbered by the binding.
     */
    public function testCallTimeOptionsSurviveAlongsideBoundKwargs(): void
    {
        $config = self::configProbe()
            ->bind(['temperature' => 0.0])
            ->invoke('x', new RunnableConfig(options: ['topP' => 0.1]));

        self::assertSame(0.0, $config->options['temperature'] ?? null);
        self::assertSame(0.1, $config->options['topP'] ?? null);
    }

    /**
     * Binding nothing must produce an empty options bag, not a null config.
     */
    public function testBindingNoKwargsYieldsAnEmptyOptionsBag(): void
    {
        $config = self::configProbe()->bind()->invoke('x');

        self::assertSame([], $config->options);
    }

    /**
     * The bound *config bag* can carry options too.
     *
     * `bind([], ['options' => [...]])` is the documented way to attach call
     * options without binding a runnable, and it was dropped entirely — the
     * merge only ever looked at `$this->kwargs`.
     */
    public function testBoundConfigOptionsAreApplied(): void
    {
        $config = self::configProbe()->bind([], ['options' => ['temperature' => 0.1]])->invoke('x');

        self::assertSame(0.1, $config->options['temperature'] ?? null);
    }

    /**
     * For metadata and configurable the call-time value wins.
     *
     * Upstream merges `this.config` first and the call-time config after, and
     * `mergeConfigs` lets a later config overwrite an earlier one. These two had
     * it backwards, so a bound default could not be overridden per call.
     */
    public function testCallTimeMetadataWinsOverBound(): void
    {
        $config = self::configProbe()
            ->bind([], ['metadata' => ['k' => 'bound']])
            ->invoke('x', new RunnableConfig(metadata: ['k' => 'call']));

        self::assertSame('call', $config->metadata['k'] ?? null);
    }

    public function testCallTimeConfigurableWinsOverBound(): void
    {
        $config = self::configProbe()
            ->bind([], ['configurable' => ['k' => 'bound']])
            ->invoke('x', new RunnableConfig(configurable: ['k' => 'call']));

        self::assertSame('call', $config->configurable['k'] ?? null);
    }

    /**
     * Bound kwargs outrank a bound config bag's options.
     */
    public function testKwargsOutrankBoundConfigOptions(): void
    {
        $config = self::configProbe()
            ->bind(['temperature' => 0.0], ['options' => ['temperature' => 0.9]])
            ->invoke('x');

        self::assertSame(0.0, $config->options['temperature'] ?? null);
    }

    public function testBindWithoutACallConfigStillAppliesKwargs(): void
    {
        $config = self::configProbe()->bind(['temperature' => 0.0])->invoke('x');

        self::assertSame(0.0, $config->options['temperature'] ?? null);
    }

    // ---- the config half, which did work ---------------------------------

    public function testBoundConfigAppliesWhenNoCallConfigIsGiven(): void
    {
        $config = self::configProbe()
            ->bind([], ['run_name' => 'my-step'])
            ->invoke('x');

        self::assertSame('my-step', $config->runName);
    }

    public function testCallTimeConfigWinsForRunName(): void
    {
        $config = self::configProbe()
            ->bind([], ['run_name' => 'bound'])
            ->invoke('x', new RunnableConfig(runName: 'call'));

        self::assertSame('call', $config->runName);
    }

    public function testTagsFromBothSidesAreUnioned(): void
    {
        $config = self::configProbe()
            ->bind([], ['tags' => ['a']])
            ->invoke('x', new RunnableConfig(tags: ['b']));

        self::assertSame(['a', 'b'], $config->tags);
    }

    public function testCallbacksFromBothSidesAreConcatenated(): void
    {
        $bound = new RunCollectorCallbackHandler();
        $call = new RunCollectorCallbackHandler();

        $config = self::configProbe()
            ->bind([], ['callbacks' => [$bound]])
            ->invoke('x', new RunnableConfig(callbacks: [$call]));

        self::assertSame([$bound, $call], $config->callbacks);
    }

    public function testPipeThroughABindingStillComposes(): void
    {
        $result = RunnableLambda::from(static fn (mixed $i): string => strtoupper((string) $i))
            ->bind(['temperature' => 0.0])
            ->pipe(new RunnableLambda(static fn (mixed $i): string => $i . '!'))
            ->invoke('hi');

        self::assertSame('HI!', $result);
    }

    public function testGetName(): void
    {
        $binding = new RunnableBinding(RunnableLambda::from(static fn (mixed $i): mixed => $i));

        self::assertSame('RunnableBinding', $binding->getName());
    }
}
