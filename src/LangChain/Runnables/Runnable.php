<?php

declare(strict_types=1);

namespace LangChain\Runnables;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\OutputParsers\BaseLLMOutputParser;
use LangChain\Prompts\BasePromptTemplate;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\CallbackManager;
use LangChain\Tracers\EventStreamCallbackHandler;
use LangChain\Tracers\LogStreamCallbackHandler;
use LangChain\Tracers\RootEventFilter;
use LangChain\Tracers\RunId;
use LangChain\Tracers\RunLog;
use LangChain\Tracers\RunLogPatch;
use LangChain\Tracers\Serialized;
use LangChain\Tracers\StreamEvent;

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
        if ($this->emitsOwnRunEvents() || self::chainCallbackManager($config) === null) {
            yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];

            return;
        }

        // Upstream's default `_streamIterator` is `yield this.invoke(...)`, and `invoke` is what opens
        // the chain run. This port's `invoke` opens nothing, so when a handler is listening the run is
        // opened here, around the same single chunk. With no handler the path above is byte-identical to
        // what this method always did.
        yield from self::traceStream(
            $this,
            $input,
            $config,
            function (RunnableConfig $child) use ($input): \Generator {
                yield [self::CHANNEL_DEFAULT, $this->invoke($input, $child)];
            },
            $this->usesTransformStreaming(),
        );
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

    // ---- streamLog / streamEvents ---------------------------------------

    /**
     * Stream all output from a runnable, as reported to the callback system.
     *
     * Port of `Runnable.streamLog`. This includes all inner runs of LLMs, Tools, etc. Output is a stream of
     * {@see RunLogPatch} objects: JSON Patch operations that describe how the state of the run changed in
     * each step. Applying them in order (`$log = $log === null ? RunLog::fromRunLogPatch($p) : $log->concat($p)`)
     * reconstructs the state, whose `final_output` is the run's result.
     *
     * Upstream marks this `@deprecated` in favour of `stream()`; it is ported because `streamEvents()` v1
     * is built on it.
     *
     * @param array{includeNames?: list<string>, includeTypes?: list<string>, includeTags?: list<string>, excludeNames?: list<string>, excludeTypes?: list<string>, excludeTags?: list<string>} $streamOptions
     *
     * @return \Generator<int, RunLogPatch>
     */
    public function streamLog(mixed $input, ?RunnableConfig $config = null, array $streamOptions = []): \Generator
    {
        $handler = new LogStreamCallbackHandler(array_merge($streamOptions, [
            'autoClose' => false,
            'schemaFormat' => LogStreamCallbackHandler::SCHEMA_ORIGINAL,
        ]));

        yield from $this->runStreamLog($input, $handler, $config ?? new RunnableConfig());
    }

    /**
     * @return \Generator<int, RunLogPatch>
     */
    private function runStreamLog(mixed $input, LogStreamCallbackHandler $handler, RunnableConfig $config): \Generator
    {
        $config = clone $config;
        $config->callbacks = [...$config->callbacks, $handler];

        // Upstream consumes the runnable's stream in a second task while this one drains the handler.
        // One thread cannot do both at once, so the handler is drained after every step of the stream.
        $error = null;
        try {
            foreach (self::eventSource($this, $input, $config) as $pair) {
                $handler->write(new RunLogPatch(['ops' => [[
                    'op' => 'add',
                    'path' => '/streamed_output/-',
                    'value' => self::chunkOf($pair),
                ]]]));
                yield from $handler->drain();
            }
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            $handler->close();
        }

        yield from $handler->drain();

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * Generate a stream of events emitted by the internal steps of the runnable.
     *
     * Port of `Runnable.streamEvents`. Each {@see StreamEvent} has the shape
     * `on_[runnable_type]_(start|stream|end)` with `name`, `run_id`, `tags`, `metadata` and a `data`
     * payload (`input`, `chunk`, `output` or `error`). The runnable types are `llm`, `chat_model`,
     * `prompt`, `tool`, `retriever`, `parser` and `chain`.
     *
     * With `$version = 'v2'` (the maintained schema) events come straight from the callback system; `'v1'`
     * is the legacy schema built on {@see self::streamLog()}. Anything else throws.
     *
     * `$encoding = 'text/event-stream'` yields server-sent-event strings instead of events: one
     * `event: data\ndata: <json>\n\n` per event and a final `event: end\n\n`.
     *
     * Not ported: `config.signal` and `timeout` are not honoured, because PHP has no way to interrupt a
     * running step from outside it.
     *
     * Known divergence: events are emitted as each step returns rather than as an event loop interleaves
     * concurrent tasks, so the relative ORDER of events from different runs can differ from upstream's
     * (for example `on_chat_model_stream` precedes the enclosing chain's `on_chain_stream` here). Every
     * run still gets its start, stream and end events.
     *
     * @param array{includeNames?: list<string>, includeTypes?: list<string>, includeTags?: list<string>, excludeNames?: list<string>, excludeTypes?: list<string>, excludeTags?: list<string>} $streamOptions
     *
     * @return \Generator<int, StreamEvent|string>
     */
    public function streamEvents(
        mixed $input,
        ?RunnableConfig $config = null,
        string $version = 'v2',
        array $streamOptions = [],
        ?string $encoding = null,
    ): \Generator {
        // Validated eagerly, as upstream does: an unsupported version throws at the call, not at the
        // first iteration.
        $stream = match ($version) {
            'v1' => $this->streamEventsV1($input, $config ?? new RunnableConfig(), $streamOptions),
            'v2' => $this->streamEventsV2($input, $config ?? new RunnableConfig(), $streamOptions),
            default => throw new \InvalidArgumentException(
                'Only versions "v1" and "v2" of the schema are currently supported.',
            ),
        };

        if ($encoding === null) {
            return $stream;
        }

        if ($encoding !== 'text/event-stream') {
            throw new \InvalidArgumentException(\sprintf('Unsupported encoding "%s".', $encoding));
        }

        return self::encodeAsEventStream($stream);
    }

    /**
     * @param \Generator<int, StreamEvent> $events
     *
     * @return \Generator<int, string>
     */
    private static function encodeAsEventStream(\Generator $events): \Generator
    {
        foreach ($events as $event) {
            $payload = $event->toArray();
            // An empty PHP array is a JSON list; these two are objects on the wire.
            foreach (['metadata', 'data'] as $key) {
                if ($payload[$key] === []) {
                    $payload[$key] = new \stdClass();
                }
            }
            yield "event: data\ndata: " . json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n\n";
        }
        yield "event: end\n\n";
    }

    /**
     * @param array<string, mixed> $streamOptions
     *
     * @return \Generator<int, StreamEvent>
     */
    private function streamEventsV2(mixed $input, RunnableConfig $config, array $streamOptions): \Generator
    {
        unset($streamOptions['autoClose']);
        $eventStreamer = new EventStreamCallbackHandler(array_merge($streamOptions, ['autoClose' => false]));

        $config = clone $config;
        $runId = $config->runId[0] ?? RunId::v7();
        $config->runId = [$runId];
        $config->callbacks = [...$config->callbacks, $eventStreamer];

        $firstEventSent = false;
        $firstEventRunId = null;
        $process = static function (StreamEvent $event) use (&$firstEventSent, &$firstEventRunId, $input): StreamEvent {
            // The inputs into the chain are not available until the entire input is consumed, so the
            // first event reports the input that was passed in.
            if (!$firstEventSent) {
                $firstEventSent = true;
                $firstEventRunId = $event->runId;
                $data = \is_array($event->data) ? $event->data : [];
                $data['input'] = $input;

                return $event->withData($data);
            }
            // The end event of the root runnable does not repeat the input; it is in the first event.
            if ($event->runId === $firstEventRunId && str_ends_with($event->event, '_end')
                && \is_array($event->data) && !empty($event->data['input'])) {
                $data = $event->data;
                unset($data['input']);

                return $event->withData($data);
            }

            return $event;
        };

        $error = null;
        try {
            $tapped = $eventStreamer->tapOutputIterable($runId, self::eventSource($this, $input, $config));
            foreach ($tapped as $_) {
                foreach ($eventStreamer->drain() as $event) {
                    yield $process($event);
                }
            }
        } catch (\Throwable $e) {
            $error = $e;
        } finally {
            $eventStreamer->finish();
        }

        foreach ($eventStreamer->drain() as $event) {
            yield $process($event);
        }

        if ($error !== null) {
            throw $error;
        }
    }

    /**
     * The "v1" schema: rebuilt from the run log rather than read from the callbacks.
     *
     * @param array<string, mixed> $streamOptions
     *
     * @return \Generator<int, StreamEvent>
     */
    private function streamEventsV1(mixed $input, RunnableConfig $config, array $streamOptions): \Generator
    {
        $runLog = null;
        $hasEncounteredStartEvent = false;
        $rootTags = $config->tags;
        $rootMetadata = $config->metadata;
        $rootName = $config->runName ?? $this->getName();
        unset($streamOptions['autoClose']);

        $logStreamCallbackHandler = new LogStreamCallbackHandler(array_merge($streamOptions, [
            'autoClose' => false,
            'schemaFormat' => LogStreamCallbackHandler::SCHEMA_STREAMING_EVENTS,
        ]));
        $rootEventFilter = new RootEventFilter($streamOptions);

        foreach ($this->runStreamLog($input, $logStreamCallbackHandler, $config) as $log) {
            $runLog = $runLog === null ? RunLog::fromRunLogPatch($log) : $runLog->concat($log);
            $state = $runLog->state;

            // Yield the start event for the root runnable if it hasn't been seen. The root run is never
            // filtered out of the log, only out of the events.
            if (!$hasEncounteredStartEvent) {
                $hasEncounteredStartEvent = true;
                $event = new StreamEvent("on_{$state['type']}_start", $rootName, $state['id'], $rootTags, $rootMetadata, ['input' => $input]);
                if ($rootEventFilter->includeEvent($event, $state['type'])) {
                    yield $event;
                }
            }

            $paths = [];
            foreach ($log->ops as $op) {
                if (str_starts_with($op['path'], '/logs/')) {
                    $paths[] = explode('/', $op['path'])[2];
                }
            }

            foreach (array_values(array_unique($paths)) as $path) {
                $logEntry = $runLog->state['logs'][$path];
                $data = [];

                if ($logEntry['end_time'] === null) {
                    $eventType = $logEntry['streamed_output'] !== [] ? 'stream' : 'start';
                } else {
                    $eventType = 'end';
                }

                if ($eventType === 'start') {
                    // Inputs are usually NOT available at the start of a component that operates on
                    // streams: it does not know its final input until the stream ends.
                    if (($logEntry['inputs'] ?? null) !== null) {
                        $data['input'] = $logEntry['inputs'];
                    }
                } elseif ($eventType === 'end') {
                    if (($logEntry['inputs'] ?? null) !== null) {
                        $data['input'] = $logEntry['inputs'];
                    }
                    $data['output'] = $logEntry['final_output'];
                } else {
                    $chunkCount = \count($logEntry['streamed_output']);
                    if ($chunkCount !== 1) {
                        throw new \RuntimeException(\sprintf(
                            'Expected exactly one chunk of streamed output, got %d instead. Encountered in: "%s"',
                            $chunkCount,
                            $logEntry['name'],
                        ));
                    }
                    $data = ['chunk' => $logEntry['streamed_output'][0]];
                    // Clean up the stream, we don't need it anymore. And this avoids duplicates as well!
                    $runLog->state['logs'][$path]['streamed_output'] = [];
                }

                yield new StreamEvent(
                    "on_{$logEntry['type']}_{$eventType}",
                    $logEntry['name'],
                    $logEntry['id'],
                    $logEntry['tags'],
                    $logEntry['metadata'],
                    $data,
                );
            }

            // Finally, the streaming output from the root chain, if there is any.
            $rootState = $runLog->state;
            if ($rootState['streamed_output'] !== []) {
                $chunkCount = \count($rootState['streamed_output']);
                if ($chunkCount !== 1) {
                    throw new \RuntimeException(\sprintf(
                        'Expected exactly one chunk of streamed output, got %d instead. Encountered in: "%s"',
                        $chunkCount,
                        $rootState['name'],
                    ));
                }
                $runLog->state['streamed_output'] = [];
                $event = new StreamEvent(
                    "on_{$rootState['type']}_stream",
                    $rootName,
                    $rootState['id'],
                    $rootTags,
                    $rootMetadata,
                    ['chunk' => $rootState['streamed_output'][0]],
                );
                if ($rootEventFilter->includeEvent($event, $rootState['type'])) {
                    yield $event;
                }
            }
        }

        if ($runLog !== null) {
            // Finally, the end event for the root runnable.
            $state = $runLog->state;
            $event = new StreamEvent("on_{$state['type']}_end", $rootName, $state['id'], $rootTags, $rootMetadata, [
                'output' => $state['final_output'],
            ]);
            if ($rootEventFilter->includeEvent($event, $state['type'])) {
                yield $event;
            }
        }
    }

    // ---- run tracing ----------------------------------------------------
    //
    // Upstream opens a chain run inside every runnable's own `invoke` / `_transformStreamWithConfig`.
    // Models and tools in this port already do that for themselves; the composition runnables
    // (`RunnableSequence`, `RunnableLambda`, `RunnableParallel`, ...) do not, and are not owned by this
    // work package. So the runs that `streamEvents()` / `streamLog()` need are opened here: by the base
    // `stream()` for runnables that inherit it, and by the entry point for the root of everything else.
    //
    // Consequence: a runnable that overrides `stream()` and is NOT the root (a nested
    // `RunnableSequence`, say) opens no chain run of its own, and a step reached through `invoke()` rather
    // than `stream()` is not traced. Listed in PORT_STATUS "Known non-exact behaviours".

    /**
     * Whether this runnable reports its own typed run (`llm`, `chat_model`, `tool`), or is a transparent
     * wrapper whose inner runnable does. Either way no chain run should be opened around it.
     */
    private function emitsOwnRunEvents(): bool
    {
        return $this instanceof BaseLangChain || $this instanceof RunnableBinding;
    }

    /**
     * Whether `stream()` on this runnable already opens its run (so the entry point must not).
     */
    private function tracesItsOwnStream(): bool
    {
        if ($this->emitsOwnRunEvents()) {
            return true;
        }

        return (new \ReflectionMethod($this, 'stream'))->getDeclaringClass()->getName() === self::class;
    }

    /**
     * Upstream runs lambdas and passthroughs (and sequences, maps, ...) through
     * `_transformStreamWithConfig`, which cannot know the input when the run starts (it may itself be a
     * stream) and whose output stream is tapped; everything else goes through `_callWithConfig`, which
     * starts with the input and is not tapped.
     */
    private function usesTransformStreaming(): bool
    {
        return $this instanceof RunnableLambda || $this instanceof RunnablePassthrough;
    }

    private static function runTypeOf(RunnableInterface $runnable): string
    {
        return match (true) {
            $runnable instanceof BasePromptTemplate => 'prompt',
            $runnable instanceof BaseLLMOutputParser => 'parser',
            default => 'chain',
        };
    }

    /**
     * A manager over the handlers carried by the config, or null when nobody is listening.
     *
     * The environment-driven handlers (`LANGCHAIN_VERBOSE`, tracing) are deliberately NOT consulted: the
     * models and tools already attach those, and a chain run is only needed when a caller handed
     * handlers in.
     */
    private static function chainCallbackManager(?RunnableConfig $config): ?CallbackManager
    {
        if ($config === null) {
            return null;
        }

        $handlers = array_values(array_filter(
            $config->callbacks,
            static fn (mixed $callback): bool => $callback instanceof BaseCallbackHandler,
        ));
        if ($handlers === []) {
            return null;
        }

        $manager = CallbackManager::configure($handlers, null, $config->tags, null, $config->metadata, null);
        if ($manager !== null) {
            $manager->parentRunId = $config->runIdParent;
        }

        return $manager;
    }

    /**
     * Follow transparent bindings to the runnable that actually does the work, merging their configs.
     *
     * `RunnableBinding::stream()` forwards to its bound runnable, so the binding opens no run and the
     * bound one must. `mergeConfig` is private to the binding and has exactly the semantics wanted, so it
     * is called rather than copied.
     *
     * @return array{0: RunnableInterface, 1: ?RunnableConfig}
     */
    private static function unwrapBindings(RunnableInterface $runnable, ?RunnableConfig $config): array
    {
        while ($runnable instanceof RunnableBinding) {
            $config = (new \ReflectionMethod(RunnableBinding::class, 'mergeConfig'))->invoke($runnable, $config);
            $runnable = $runnable->bound;
        }

        return [$runnable, $config];
    }

    /**
     * The `[channel, chunk]` stream of the root runnable with its chain run opened around it where the
     * runnable would not open one itself.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private static function eventSource(RunnableInterface $root, mixed $input, RunnableConfig $config): \Generator
    {
        [$target, $config] = self::unwrapBindings($root, $config);
        $config ??= new RunnableConfig();

        if ($target instanceof Runnable && $target->tracesItsOwnStream()) {
            yield from $target->stream($input, $config);

            return;
        }

        yield from self::traceStream(
            $target,
            $input,
            $config,
            static fn (RunnableConfig $child): \Generator => self::untracedStream($target, $input, $child),
            true,
        );
    }

    /**
     * What a composition runnable streams, shaped the way upstream's pipes shape it.
     *
     * A `RunnableSequence` pipes each step's output into the next, so only the LAST step's chunks leave the
     * sequence; its own `stream()` re-yields every step's chunks, which is not what a root run's stream
     * (or final output) should contain. A `RunnableParallel` yields one `[key, value]` pair per key, which
     * upstream reports as one `{key: value}` dict chunk per key.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private static function untracedStream(RunnableInterface $target, mixed $input, RunnableConfig $config): \Generator
    {
        if ($target instanceof RunnableSequence) {
            yield from self::lastStepStream($target, $input, $config);

            return;
        }

        if ($target instanceof RunnableParallel) {
            foreach ($target->stream($input, $config) as [$key, $value]) {
                yield [self::CHANNEL_DEFAULT, [$key => $value]];
            }

            return;
        }

        yield from $target->stream($input, $config);
    }

    /**
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private static function lastStepStream(RunnableSequence $sequence, mixed $input, RunnableConfig $config): \Generator
    {
        $stepConfigFor = new \ReflectionMethod(RunnableSequence::class, 'stepConfig');
        $last = \count($sequence->steps) - 1;
        $stepInput = $input;

        foreach ($sequence->steps as $i => $step) {
            $stepConfig = $stepConfigFor->invoke($sequence, $config, $i);

            if ($i === $last) {
                yield from $step->stream($stepInput, $stepConfig);

                return;
            }

            // Upstream feeds a step's whole stream into the next step, which gathers it with `concat`.
            $gathered = null;
            $sawChunk = false;
            foreach ($step->stream($stepInput, $stepConfig) as [$channel, $chunk]) {
                if ($channel !== self::CHANNEL_DEFAULT) {
                    continue;
                }
                $gathered = $sawChunk ? self::concatOutputs($gathered, $chunk) : $chunk;
                $sawChunk = true;
            }
            $stepInput = $sawChunk ? $gathered : $step->invoke($stepInput, $stepConfig);
        }
    }

    /**
     * Run `$source` inside a chain run reported to the handlers on `$config`.
     *
     * Port of `_transformStreamWithConfig` (`base.ts:470-577`): open the run, tap the output stream for
     * the event/log handlers, gather the chunks into the run's output, close the run. With no handler
     * listening it is a plain pass-through.
     *
     * @param \Closure(RunnableConfig): \Generator<int, array{0: string, 1: mixed}> $source Receives the
     *        CHILD config: this run's id as parent, the caller's run name cleared.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private static function traceStream(
        RunnableInterface $runnable,
        mixed $input,
        ?RunnableConfig $config,
        \Closure $source,
        bool $transformStyle,
        ?string $runType = null,
    ): \Generator {
        $config ??= new RunnableConfig();
        $manager = self::chainCallbackManager($config);

        $runId = $config->runId[0] ?? RunId::v7();
        $child = clone $config;
        $child->runId = [];
        $child->runIdParent = $runId;
        $child->runName = null;

        if ($manager === null) {
            yield from $source($child);

            return;
        }

        $inputs = self::coerceToDict($input);
        $runManager = $manager->handleChainStart(
            new Serialized(['langchain_core', 'runnables', $runnable->getName()]),
            $transformStyle ? ['input' => ''] : $inputs,
            $runId,
            $runType ?? self::runTypeOf($runnable),
            [],
            [],
            $config->runName ?? $runnable->getName(),
            $transformStyle ? ['lc_defers_inputs' => true] : [],
        );

        $stream = $source($child);
        // Only a run driven through `_transformStreamWithConfig` is tapped. One driven through
        // `_callWithConfig` (a prompt, a parser) streams nothing: its single chunk exists only after the
        // run has already ended.
        if ($transformStyle) {
            foreach ($runManager->handlers as $handler) {
                if ($handler instanceof EventStreamCallbackHandler) {
                    $stream = $handler->tapOutputIterable($runId, $stream);
                    break;
                }
            }
            foreach ($runManager->handlers as $handler) {
                if ($handler instanceof LogStreamCallbackHandler) {
                    $stream = $handler->tapOutputIterable($runId, $stream);
                    break;
                }
            }
        }

        $finalOutput = null;
        $sawOutput = false;
        $outputSupported = true;
        try {
            foreach ($stream as $pair) {
                yield $pair;

                if ($outputSupported && $pair[0] === self::CHANNEL_DEFAULT) {
                    try {
                        $finalOutput = $sawOutput ? self::concatOutputs($finalOutput, $pair[1]) : $pair[1];
                        $sawOutput = true;
                    } catch (\InvalidArgumentException) {
                        $finalOutput = null;
                        $sawOutput = false;
                        $outputSupported = false;
                    }
                }
            }
        } catch (\Throwable $e) {
            $runManager->handleChainError($e, ['inputs' => $inputs]);

            throw $e;
        }

        $runManager->handleChainEnd(
            $sawOutput ? self::coerceToDict($finalOutput, 'output') : [],
            ['inputs' => $inputs],
        );
    }

    /**
     * Upstream's `_coerceToDict`: a dict passes through, anything else is filed under `$key`.
     *
     * @return array<string, mixed>
     */
    private static function coerceToDict(mixed $value, string $key = 'input'): array
    {
        if (\is_array($value) && !array_is_list($value)) {
            return $value;
        }

        return [$key => $value];
    }

    private static function chunkOf(mixed $pair): mixed
    {
        return \is_array($pair) && \array_key_exists(1, $pair) ? $pair[1] : $pair;
    }
}
