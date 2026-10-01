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
    /**
     * The one batch loop, shared by every implementation.
     *
     * Upstream's `Runnable.batch` is `inputs.map(...)` followed by `Promise.all(...)`, which ALWAYS
     * yields a LIST — string keys on `$inputs` are discarded. Every implementation here delegates here
     * rather than hand-rolling its own loop, because two things had already drifted apart by hand:
     *
     *  - `returnExceptions` (`batchOptions`, `base.ts:281`) was honoured by `Runnable::batch()` and
     *    ignored by `RunnableSequence`, `RunnableWithFallbacks`, `RunnableParallel` and
     *    `RunnableAssign`, so the option was real, documented, tested — and inert on every composition
     *    type, which is where a caller would actually reach for it;
     *  - the loop form KEPT string keys where upstream drops them, so a string-keyed batch returned a
     *    string-keyed array instead of a list. Nothing errored; the shape was simply different.
     *
     * @param list<mixed> $inputs
     * @param array<string, mixed>|null $options
     *
     * @return list<mixed>
     */
    /**
     * The one implementation of `batchOptions.returnExceptions` in this port.
     *
     * Exposed as a static entry point because not every `RunnableInterface` implementation extends
     * `Runnable`: `LangGraph\Pregel\ChannelWrite` and `LangGraph\Pregel\RunnableBranchWriter` declare
     * `batch()` themselves and previously hand-rolled it with `array_map`, which reads neither `$options`
     * nor `array_values($inputs)` — so `returnExceptions` was silently discarded and a string-keyed batch
     * came back as a string-keyed array. Upstream's contract is `Runnable.batch` (`base.ts:281` and
     * `:3081`), which every implementation inherits; keeping the logic in one place is what stops the
     * next hand-rolled copy from diverging again.
     */
    public static function batchEachFor(RunnableInterface $r, array $inputs, ?RunnableConfig $config, ?array $options): array
    {
        return self::runBatch($r, $inputs, $config, $options);
    }

    protected function batchEach(array $inputs, ?RunnableConfig $config, ?array $options): array
    {
        return self::runBatch($this, $inputs, $config, $options);
    }

    private static function runBatch(RunnableInterface $r, array $inputs, ?RunnableConfig $config, ?array $options): array
    {
        $inputs = array_values($inputs);

        if (!(bool) ($options['returnExceptions'] ?? false)) {
            return array_map(fn (mixed $input): mixed => $r->invoke($input, $config), $inputs);
        }

        $out = [];
        foreach ($inputs as $position => $input) {
            try {
                $out[$position] = $r->invoke($input, $config);
            } catch (\Throwable $e) {
                // Upstream returns "mixed RunOutputs and errors": the Throwable takes the failed item's
                // SLOT and the remaining items still run.
                $out[$position] = $e;
            }
        }

        return $out;
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return $this->batchEach($inputs, $config, $options);
    }

    /**
     * Upstream's `concat` dispatch, transcribed from `libs/langchain-core/src/utils/stream.ts`.
     *
     * Order matters and is upstream's: lists append, strings concatenate, numbers add, an object with a
     * `concat` method delegates, and two plain objects merge RECURSIVELY for keys that already exist and
     * are not lists. Anything else throws. `_concatOutputChunks` (`base.ts:460`) is a one-line delegate to
     * this function, and `RunnableBinding` (`base.ts:1425`) and `RunnableSequence` (`base.ts:2084`)
     * re-delegate to their wrapped runnable, so one implementation serves the whole hierarchy.
     */
    public static function concatOutputs(mixed $first, mixed $second): mixed
    {
        $firstIsList = \is_array($first) && array_is_list($first);
        $secondIsList = \is_array($second) && array_is_list($second);

        if ($firstIsList && $secondIsList) {
            return array_merge($first, $second);
        }

        if (\is_string($first) && \is_string($second)) {
            return $first . $second;
        }

        if ((\is_int($first) || \is_float($first)) && (\is_int($second) || \is_float($second))) {
            return $first + $second;
        }

        if (\is_object($first) && method_exists($first, 'concat')) {
            return $first->concat($second);
        }

        if (\is_array($first) && \is_array($second)) {
            $chunk = $first;
            foreach ($second as $key => $value) {
                if (\array_key_exists($key, $chunk) && !\is_array($chunk[$key])) {
                    $chunk[$key] = self::concatOutputs($chunk[$key], $value);
                } else {
                    $chunk[$key] = $value;
                }
            }

            return $chunk;
        }

        throw new \InvalidArgumentException(\sprintf(
            'Cannot concat %s and %s',
            get_debug_type($first),
            get_debug_type($second),
        ));
    }

    /**
     * Gathers the incoming CHUNKS into one, then invokes the runnable on the result and yields it RAW.
     *
     * Upstream `base.ts:655-671`:
     *
     *     let finalChunk;
     *     for await (const chunk of generator) {
     *       if (finalChunk === undefined) finalChunk = chunk;
     *       else finalChunk = this._concatOutputChunks(finalChunk, chunk);
     *     }
     *     yield* this._streamIterator(finalChunk, ensureConfig(options));
     *
     * and the default `_streamIterator` (`base.ts:297-302`) is `yield this.invoke(input, options)`.
     *
     * **The argument is therefore a stream of chunks to GATHER, not a list of inputs to stream over** —
     * the port previously read it the other way round and yielded one `[channel, value]` pair per input,
     * which is the opposite contract on both counts. It also did not call `stream()` at all here, so
     * nothing in `src/` depended on the old shape; measured before changing anything.
     *
     * `$seen` is a separate flag rather than a `null` check because upstream's sentinel is `undefined`:
     * a legitimately null first chunk must still be concatenated WITH the next one, which a null check
     * would silently discard.
     */
    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        $final = null;
        $seen = false;

        foreach ($input as $chunk) {
            $final = $seen ? self::concatOutputs($final, $chunk) : $chunk;
            $seen = true;
        }

        yield $this->invoke($final, $config);
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
