<?php

declare(strict_types=1);

namespace LangGraph\Stream;

use LangGraph\Pregel\Constants;

/**
 * Central dispatcher: routes protocol events through a pipeline of transformers, appends the survivors to
 * the main event log, and manages final-value projections and stream handles.
 *
 * Port of `StreamMux` and `pump` from `stream/mux.ts`.
 *
 * ```
 * run stream
 *   Mux pulls chunks from the engine stream (subgraphs included)
 *     for each protocol event:
 *       transformer_1.process(event) ... transformer_n.process(event)
 *       event is appended to the log unless a transformer dropped it
 *     on close: transformer_n.finalize() in registration order
 * ```
 *
 * ORDERING (known non-exact): upstream's `pump` is a background async task feeding the mux while consumers
 * iterate concurrently. Here the run is PULL-DRIVEN: {@see self::pull()} advances the engine by one chunk,
 * and every channel the mux owns calls it when a cursor runs dry. Event order is the engine's order, as
 * upstream; what differs is only that nothing runs until a consumer asks for more. `finalize()` results and
 * `Deferred`s are settled synchronously, so upstream's "defer closing the log until async finalize work
 * resolves" collapses to "close after the finalizers returned".
 */
final class Mux
{
    /** Wire prefix for user-defined {@see StreamChannel} auto-forwards. */
    public const EXTENSION_CHANNEL_PREFIX = 'custom:';

    /** All protocol events in arrival order, after the transformer pipeline. */
    public readonly StreamChannel $events;

    /** New-namespace discovery notifications, each `['ns' => list<string>, 'stream' => StreamHandle]`. */
    public readonly StreamChannel $discoveries;

    private int $nextEmitSeq = 0;

    private bool $closed = false;

    private ?\Throwable $error = null;

    private bool $interrupted = false;

    /** @var list<string> */
    private array $currentNamespace = [];

    /** @var list<object> */
    private array $transformers = [];

    /** @var list<object> */
    private array $channels = [];

    /** @var array<string, StreamHandle> */
    private array $streamMap = [];

    /** @var array<string, mixed> */
    private array $latestValues = [];

    /** @var list<array{interruptId: string, payload: mixed}> */
    private array $interrupts = [];

    /** @var list<array{name: string, deferred: Deferred}> */
    private array $finalValues = [];

    /** @var (\Closure(): bool)|null */
    private ?\Closure $puller = null;

    private bool $pulling = false;

    public function __construct()
    {
        $this->events = StreamChannel::local();
        $this->discoveries = StreamChannel::local();
        $this->attach($this->events);
        $this->attach($this->discoveries);
    }

    /**
     * Install the engine feed: a callable advancing the run by one chunk and returning whether it made
     * progress. Channels attached with {@see self::attach()} call it when a cursor runs dry.
     *
     * @param (callable(): bool)|null $puller
     */
    public function setPuller(?callable $puller): void
    {
        $this->puller = $puller === null ? null : \Closure::fromCallable($puller);
    }

    /**
     * Advance the run by one chunk. False when there is no feed, the feed is exhausted, or a pull is
     * already in progress (a transformer iterating a channel from inside `process()`).
     */
    public function pull(): bool
    {
        if ($this->puller === null || $this->pulling) {
            return false;
        }
        $this->pulling = true;
        try {
            return ($this->puller)();
        } finally {
            $this->pulling = false;
        }
    }

    /**
     * Point a channel's driver at this mux's feed, so iterating it advances the run.
     */
    public function attach(StreamChannel $channel): StreamChannel
    {
        $channel->setDriver($this->pull(...));

        return $channel;
    }

    /**
     * Associate a stream handle with a namespace so {@see self::close()} can settle its output.
     *
     * @param list<string> $path
     */
    public function register(array $path, StreamHandle $stream): void
    {
        $this->streamMap[Types::nsKey($path)] = $stream;
    }

    /**
     * Attach a transformer and replay the events already in the log through it.
     *
     * The transformer must already be initialised (`init()` called, projection wired). If the mux is
     * already closed, `finalize()` (or `fail()`) runs immediately.
     */
    public function addTransformer(StreamTransformer $transformer): void
    {
        $snapshot = $this->events->size();
        $this->transformers[] = $transformer;

        if (method_exists($transformer, 'onRegister')) {
            // Transformer-originated events carry a placeholder seq: push() is the single authority.
            $transformer->onRegister(new class($this) implements StreamEmitter {
                public function __construct(private readonly Mux $mux)
                {
                }

                public function push(array $ns, array $event): void
                {
                    $this->mux->push($ns, $event);
                }
            });
        }

        for ($i = 0; $i < $snapshot; ++$i) {
            $transformer->process($this->events->get($i));
        }

        if ($this->closed) {
            if ($this->error !== null) {
                if (method_exists($transformer, 'fail')) {
                    $transformer->fail($this->error);
                }
            } elseif (method_exists($transformer, 'finalize')) {
                $transformer->finalize();
            }
        }
    }

    /**
     * Scan a transformer projection for streaming and final-value primitives.
     *
     * Named {@see StreamChannel}s forward every push as a `custom:<name>` event; unnamed ones are tracked
     * for lifecycle only. {@see Deferred}s are flushed on close as a single `custom` event carrying
     * `['name' => key, 'payload' => value]`. Anything else is ignored.
     *
     * @param array<string, mixed> $projection
     */
    public function wireChannels(array $projection): void
    {
        foreach ($projection as $key => $value) {
            if (StreamChannel::isInstance($value)) {
                $this->channels[] = $value;
                if ($value instanceof StreamChannel) {
                    $this->attach($value);
                }
                if (!\is_string($value->channelName)) {
                    continue;
                }
                $method = self::EXTENSION_CHANNEL_PREFIX . $value->channelName;
                $value->_wire(function (mixed $item) use ($method): void {
                    $this->events->push(Types::protocolEvent($method, $this->currentNamespace, $item, null, $this->nextEmitSeq++));
                });

                continue;
            }
            if ($value instanceof Deferred) {
                $this->finalValues[] = ['name' => (string) $key, 'deferred' => $value];
            }
        }
    }

    /**
     * Run an event through the transformer pipeline, then append it to the log unless a transformer dropped it.
     *
     * @param list<string> $ns
     * @param array<string, mixed> $event
     */
    public function push(array $ns, array $event): void
    {
        if (($event['method'] ?? null) === 'values') {
            $this->latestValues[Types::nsKey($ns)] = $event['params']['data'] ?? null;
        }

        // Re-entrant pushes (an onRegister emitter inside process()) set their own namespace without
        // clobbering the outer scope's StreamChannel routing.
        $outerNamespace = $this->currentNamespace;
        $this->currentNamespace = $ns;

        $keep = true;
        foreach ($this->transformers as $transformer) {
            if (!$transformer->process($event)) {
                $keep = false;
            }
        }

        $this->currentNamespace = $outerNamespace;

        if ($keep) {
            // Stamped AFTER process(), so channel-forwarded events pushed while processing get earlier
            // sequence numbers than the event that triggered them, matching their order in the log.
            $event['seq'] = $this->nextEmitSeq++;
            $this->events->push($event);
        }
    }

    /**
     * End the stream: settle values on every known stream, finalize transformers, close channels, flush
     * final-value projections as `custom` events, and close both logs.
     */
    public function close(): void
    {
        $this->closed = true;
        foreach ($this->latestValues as $key => $values) {
            $ns = $key === '' ? [] : explode("\x00", (string) $key);
            $this->streamMap[Types::nsKey($ns)]?->resolveValues($values);
        }

        foreach ($this->transformers as $transformer) {
            if (method_exists($transformer, 'finalize')) {
                $transformer->finalize();
            }
        }

        foreach ($this->channels as $channel) {
            $channel->_close();
        }

        foreach ($this->finalValues as ['name' => $name, 'deferred' => $deferred]) {
            // A rejected (or never settled) projection is dropped so one failing extension cannot poison
            // the protocol stream; its direct awaiters still see the rejection.
            if (!$deferred->isSettled() || $deferred->isRejected()) {
                continue;
            }
            if (!$this->events->done()) {
                $this->events->push(Types::protocolEvent('custom', [], ['name' => $name, 'payload' => $deferred->value()], null, $this->nextEmitSeq++));
            }
        }

        $this->events->close();
        $this->discoveries->close();

        foreach ($this->streamMap as $stream) {
            $stream->resolveValues(null);
        }
    }

    /**
     * Propagate a failure to every transformer, channel, log and stream handle.
     */
    public function fail(mixed $error): void
    {
        $error = Types::toThrowable($error);
        $this->closed = true;
        $this->error = $error;
        foreach ($this->transformers as $transformer) {
            if (method_exists($transformer, 'fail')) {
                $transformer->fail($error);
            }
        }
        foreach ($this->channels as $channel) {
            $channel->_fail($error);
        }
        $this->events->fail($error);
        $this->discoveries->fail($error);
        foreach ($this->streamMap as $stream) {
            $stream->rejectValues($error);
        }
    }

    /**
     * Record that the run was interrupted, keeping the payloads.
     *
     * @param list<array{interruptId: string, payload: mixed}> $interrupts
     */
    public function markInterrupted(array $interrupts): void
    {
        $this->interrupted = true;
        array_push($this->interrupts, ...$interrupts);
    }

    public function interrupted(): bool
    {
        return $this->interrupted;
    }

    /** @return list<array{interruptId: string, payload: mixed}> */
    public function interrupts(): array
    {
        return $this->interrupts;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Events whose namespace starts with `$path`, from log index `$startAt`.
     *
     * @param list<string> $path
     * @return \Generator<int, array<string, mixed>>
     */
    public function subscribeEvents(array $path, int $startAt = 0): \Generator
    {
        foreach ($this->events->iterate($startAt) as $event) {
            if (Types::hasPrefix($event['params']['namespace'], $path)) {
                yield $event;
            }
        }
    }

    /**
     * Drain a chunk source into a mux: convert each chunk, push the events, close on completion or fail on
     * error. A chunk is `[namespace, mode, payload]`; the engine's two-element `[mode, payload]` chunk (no
     * subgraph streaming) is taken as the root namespace.
     *
     * @param iterable<mixed> $source
     */
    public static function pump(iterable $source, self $mux): void
    {
        foreach (self::pumpSteps($source, $mux) as $ignored) {
            // Drain.
        }
    }

    /**
     * The same as {@see self::pump()}, yielding after every chunk so a caller can interleave consumers.
     *
     * @param iterable<mixed> $source
     * @param (callable(): mixed)|null $aborted returns a reason (non-null) to stop the run with a failure
     * @return \Generator<int, int>
     */
    public static function pumpSteps(iterable $source, self $mux, ?callable $aborted = null): \Generator
    {
        $seq = 0;
        $chunks = 0;
        try {
            foreach ($source as $chunk) {
                if ($aborted !== null && ($reason = $aborted()) !== null) {
                    throw $reason instanceof \Throwable ? $reason : new \RuntimeException(\is_string($reason) ? $reason : 'This operation was aborted');
                }

                [$ns, $mode, $payload] = \count($chunk) === 2 ? [[], $chunk[0], $chunk[1]] : $chunk;

                if ($mode === 'values' && \is_array($payload) && isset($payload[Constants::INTERRUPT])) {
                    $mux->markInterrupted(array_map(
                        static fn (mixed $i): array => [
                            'interruptId' => (string) (\is_array($i) ? ($i['id'] ?? '') : ''),
                            'payload' => \is_array($i) ? ($i['value'] ?? null) : $i,
                        ],
                        array_values((array) $payload[Constants::INTERRUPT]),
                    ));
                }

                $events = Convert::toProtocolEvent($ns, (string) $mode, $payload, $seq);
                $seq += \count($events);
                foreach ($events as $event) {
                    $mux->push($ns, $event);
                }

                yield ++$chunks;
            }
        } catch (\Throwable $e) {
            $mux->fail($e);

            return;
        }
        $mux->close();
    }
}
