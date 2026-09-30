<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Attach fixed kwargs and/or config to a runnable.
 *
 * Port of `RunnableBinding` from `@langchain_core/runnables`.
 *
 * A binding is transparent: it forwards `invoke` to the wrapped runnable with
 * the bound config merged in. This is how per-call settings (a different model,
 * a temperature override, extra tags) are applied to one branch of a chain
 * without rebuilding the chain.
 */
class RunnableBinding extends Runnable
{
    public function __construct(
        public RunnableInterface $bound,
        public array $kwargs = [],
        public ?array $config = null,
    ) {
    }

    public function getName(): string
    {
        return 'RunnableBinding';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $merged = $this->mergeConfig($config);

        return $this->bound->invoke($input, $merged);
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->bound->stream($input, $this->mergeConfig($config));
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        $merged = $this->mergeConfig($config);

        return $this->bound->batch($inputs, $merged, $options);
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }

    /**
     * Merge the bound config *under* the call-time config, so an explicit
     * caller setting always wins over a bound default.
     *
     * The bound **kwargs** are merged in too — this is the whole point of
     * `bind()`. They land in `config->options`, which is where a runnable reads
     * its per-call parameters (`BaseChatModel` reads `$config->options` and
     * passes it to the provider's `invocationParams()`).
     *
     * HISTORY — none of this describes the behaviour above, which is current:
     *
     *     They used to be stored and never read, which made `->bind(['temperature' =>
     *     0])` a silent no-op on every runnable in the SDK. No test caught it
     *     because no test called `bind()` at all.
     *
     * Kept because the precedence rule below reads as arbitrary without it. It is
     * fenced deliberately: a comment narrating a FIXED bug reads as present tense to
     * anyone — human or model — skimming the source, and this project has now been
     * bitten by that three times.
     *
     * Precedence, stated plainly because a reviewer read this the wrong way once:
     * **the bound kwargs win.** Upstream calls `this._mergeConfig(options,
     * this.kwargs)` — the kwargs go last — and `mergeConfigs` lets a later
     * config overwrite an earlier one. So
     * `bind(['temperature' => 0])->invoke($x, ['temperature' => 0.5])` sends
     * 0. The bound value is the model's standing intent; the call-site option
     * is the anomaly. `$a + $b` keeping the left operand is what expresses
     * that, which is why `kwargs` is on the left.
     */
    private function mergeConfig(?RunnableConfig $config): ?RunnableConfig
    {
        if ($this->config === null && $this->kwargs === []) {
            return $config;
        }

        $bound = $this->config === null ? new RunnableConfig() : RunnableConfig::fromArray($this->config);
        $merged = $config === null ? new RunnableConfig() : clone $config;

        if ($bound->tags !== []) {
            $merged->tags = array_merge($bound->tags, $merged->tags);
        }
        // Upstream `mergeConfigs` does `{...copy, ...options}` for metadata and
        // configurable, and the call-time config is merged *after* the bound
        // one — so a key set at the call site wins. These two had it backwards,
        // which made a bound default impossible to override per call.
        if ($bound->metadata !== []) {
            $merged->metadata = $merged->metadata + $bound->metadata;
        }
        if ($bound->callbacks !== []) {
            $merged->callbacks = array_merge($bound->callbacks, $merged->callbacks);
        }
        if ($bound->configurable !== []) {
            $merged->configurable = $merged->configurable + $bound->configurable;
        }
        if ($bound->maxConcurrency !== null) {
            $merged->maxConcurrency = $merged->maxConcurrency ?? $bound->maxConcurrency;
        }
        if ($bound->runName !== null) {
            $merged->runName = $merged->runName ?? $bound->runName;
        }

        // Options, in increasing specificity: the bound *config* bag, then the
        // bound kwargs, then the call-time options. The kwargs layer sits above
        // the config bag because upstream passes it last, and it sits above the
        // call-time options for the same reason.
        //
        // The bound config bag's own `options` used to be dropped entirely, so
        // `bind([], ['options' => [...]])` — the documented way to attach call
        // options without binding a runnable — did nothing.
        $merged->options = $this->kwargs + ($merged->options + $bound->options);

        return $merged;
    }
}
