<?php

declare(strict_types=1);

namespace LangChain\Runnables;


/**
 * Base implementation of {@see RunnableInterface}.
 *
 * Port of `Runnable` (the abstract base class) from `@langchain_core/runnables`.
 *
 * Subclasses override `invoke()` and, if they can produce output incrementally,
 * `stream()`. The `stream` default yields the whole `invoke` result as one
 * `default`-channel chunk, which is the correct — if not especially lively —
 * behaviour for a component that computes atomically.
 */
abstract class Runnable implements RunnableInterface
{
    /** The default output channel name. */
    public const CHANNEL_DEFAULT = 'default';

    /** The channel carrying retriever results. */
    public const CHANNEL_RETRIEVER = 'retriever';

    /** The channel carrying tool output. */
    public const CHANNEL_TOOL = 'tool';

    /** The channel carrying model output. */
    public const CHANNEL_MODEL = 'model';

    /** The channel carrying prompt output. */
    public const CHANNEL_PROMPT = 'prompt';

    public function getName(): string
    {
        $parts = explode('\\', static::class);

        return end($parts);
    }

    abstract public function invoke(mixed $input, ?RunnableConfig $config = null): mixed;

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];
    }

    /**
     * `$options` (upstream `batchOptions`) is accepted and ignored — see
     * {@see RunnableInterface::batch()} for why, and what a subclass would have
     * to do to honour it.
     *
     * @param list<mixed>              $inputs
     * @param array<string, mixed>|null $options Unused.
     */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        // `batchOptions.returnExceptions` (`runnables/base.ts:240-241`, honoured at `:281`).
        //
        // It was accepted and IGNORED here, and three reviewers reported that across cycles 3, 4 and 5.
        // This loop refused all three on the grounds that the port's own docblock said the option was
        // ignored — which is this same mistake a second time: A DOCBLOCK SAYING AN OPTION IS IGNORED IS
        // A DESCRIPTION OF A GAP, NOT A JUSTIFICATION FOR IT. Upstream implements the option, so the
        // port was simply missing it.
        $returnExceptions = (bool) ($options['returnExceptions'] ?? false);

        if (!$returnExceptions) {
            return array_map(
                fn (mixed $input): mixed => $this->invoke($input, $config),
                array_values($inputs)
            );
        }

        $out = [];
        foreach (array_values($inputs) as $position => $input) {
            try {
                $out[$position] = $this->invoke($input, $config);
            } catch (\Throwable $e) {
                // Upstream returns "mixed RunOutputs and errors" — the Throwable takes the failed
                // item's SLOT, and the remaining items still run.
                $out[$position] = $e;
            }
        }

        return $out;
    }

    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($input as $item) {
            foreach ($this->stream($item, $config) as $pair) {
                yield $pair;
            }
        }
    }

    // ---- composition ----------------------------------------------------

    /**
     * Retry this runnable on failure.
     *
     * Port of `Runnable.withRetry`, which upstream defines as
     * `new RunnableRetry({ bound: this, kwargs: {}, config: {}, maxAttemptNumber: fields?.stopAfterAttempt, ...fields })`
     * — and upstream's `RunnableRetry` extends `RunnableBinding`, which this port already has, so this
     * is a subclass of existing machinery rather than a new mechanism.
     *
     * Upstream's default is three attempts. Only `stopAfterAttempt` and `onFailedAttempt` are
     * accepted; `stopAfterAttempt` is upstream's name for the attempt ceiling and maps to this port's
     * `maxAttemptNumber`.
     *
     * @param array{stopAfterAttempt?: int, onFailedAttempt?: callable} $fields
     */
    public function withRetry(array $fields = []): RunnableRetry
    {
        return new RunnableRetry(
            $this,
            [],
            null,
            (int) ($fields['stopAfterAttempt'] ?? RunnableRetry::DEFAULT_MAX_ATTEMPTS),
            isset($fields['onFailedAttempt']) && \is_callable($fields['onFailedAttempt'])
                ? $fields['onFailedAttempt']
                : null,
        );
    }

    /**
     * Select one or more named fields from this runnable's output.
     *
     * Port of `Runnable.pick` (`base.ts:628`), which upstream defines as
     * `this.pipe(new RunnablePick(keys))` — so this is that exact wiring, and `RunnablePick` carries
     * upstream's `_pick` semantics (string key → the field; array of keys → only the keys present; no
     * surviving key → nothing yielded).
     *
     * One of five upstream `Runnable` methods this port was missing (see PORT_STATUS); `pick` is the
     * smallest, needing only the `pipe()` this class already has.
     *
     * @param string|list<string> $keys
     */
    public function pick(string|array $keys): RunnableInterface
    {
        return $this->pipe(new RunnablePick($keys));
    }

    /**
     * Compose: run `$next` on this runnable's output.
     */
    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }

    // `pipeTo()` was removed here.
    //
    // It did nothing. The docblock promised "feed this runnable's output into a
    // callback", and the body did neither half of that: it built a RunnableLambda
    // around an IDENTITY callable and passed `$fn` as `['func' => ...]`, which is
    // a BOUND TEMPLATE VARIABLE, not a callback override
    // (`RunnableLambda::__construct(callable $func, array $bound)`).
    //
    // Measured: `(new Runnable) ->pipeTo($fn))->invoke('A')` returned 'A'
    // unchanged and `$fn` was never invoked. `$this` was never run either, so the
    // receiver produced no output to feed anything.
    //
    // It had no callers anywhere in src/ or tests/, and upstream has no
    // counterpart at all — `runnables/base.ts` defines no `pipeTo`, only `pipe`
    // and `then`. So it was invented API in a port whose rule is fidelity, and
    // the invented API was broken. Removed rather than repaired: a caller who
    // wants this writes `$this->pipe(new RunnableLambda($fn))`, which is the
    // upstream-shaped way and actually runs.

    /**
     * Bind kwargs/config that are applied to every invocation of this runnable.
     *
     * @param array<string, mixed> $kwargs
     */
    public function bind(array $kwargs = [], ?array $config = null): RunnableBinding
    {
        return new RunnableBinding($this, $kwargs, $config);
    }

    /**
     * Bind a config that every subsequent call on the returned runnable carries.
     *
     * Port of `Runnable.withConfig` (`libs/langchain-core/src/runnables/base.ts:175-183`), which is
     * literally `new RunnableBinding({ bound: this, config, kwargs: {} })` — that is, `bind([], $config)`.
     *
     * It was MISSING here, and the absence had consequences beyond the missing method: three separate
     * reviews reported that a bound `runName` never reached the traced run, because they reached for a
     * way to set config on a runnable, found none, and concluded the value was being silently dropped.
     * The premise was right — there was no way to do it — and the conclusion was wrong; the value was
     * not being dropped, there was simply no method to bind it with. (`withStructuredOutput(['name' =>
     * ...])` is a different thing: `BaseChatModel::withStructuredOutput()` reads `$config['name']` as the
     * PARSER'S LOOKUP KEY, which is correct upstream behaviour.)
     *
     * Declared here rather than on `RunnableInterface` because `bind()` is declared here, and a binding
     * that persists config is a property of `RunnableBinding`, not of every runnable.
     *
     * @param array<string, mixed> $config
     */
    public function withConfig(array $config): RunnableBinding
    {
        return $this->bind([], $config);
    }

    /**
     * Try each runnable in turn, returning the first that succeeds.
     *
     * @param list<RunnableInterface> $fallbacks
     */
    public function withFallbacks(array $fallbacks): RunnableWithFallbacks
    {
        return new RunnableWithFallbacks($this, $fallbacks);
    }
}
