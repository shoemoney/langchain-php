<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Errors\RemoteException;
use LangGraph\Sdk\Client;

/**
 * A handle on a run started on a remote server, iterated as protocol events.
 *
 * Port of `RemoteGraphRunStream` from `pregel/remote-run-stream.ts`. Upstream adapts the SDK's
 * `ThreadStream` to the local `GraphRunStream`; neither exists in this port, so this class follows the
 * run over REST instead: {@see RemoteGraph::streamEventsV3()} creates the run and this class reads
 * `runs->joinStream()`, turning each SSE part into an event
 *
 * ```
 * ['type' => 'event', 'seq' => int, 'method' => 'values', 'params' => ['namespace' => [], 'timestamp' => ms, 'data' => ...]]
 * ```
 *
 * `metadata` and `end` parts are run bookkeeping, not graph events, and are skipped; an `error` part
 * throws.
 *
 * `abort()` marks the run aborted and asks the server to cancel it (best effort, as upstream). The
 * signal handed to the stream is polled between reads, so an abort stops iteration at the next part.
 *
 * Upstream's `values`/`messages`/`subgraphs`/`lifecycle` projections are not ported: they are views of
 * the `ThreadStream` subscription machinery. {@see self::output()} and {@see self::interrupts()} are the
 * two answers a caller needs from the event list.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class RemoteRunStream implements \IteratorAggregate
{
    /** The stream modes a v3 run asks the server for. */
    public const STREAM_MODES = ['values', 'updates', 'messages-tuple', 'custom', 'tasks', 'checkpoints'];

    private bool $aborted = false;

    private bool $interrupted = false;

    /** @var list<array{id: string|null, value: mixed}> */
    private array $interrupts = [];

    private mixed $output = null;

    /** @var (callable(): bool)|null */
    private $callerSignal;

    /**
     * @param (callable(): bool)|null $signal polled between reads; true stops the stream
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $threadId,
        private readonly ?string $runId = null,
        ?callable $signal = null,
    ) {
        $this->callerSignal = $signal;
    }

    public function threadId(): string
    {
        return $this->threadId;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    /**
     * The abort signal, in the SDK's form: `callable(): bool`.
     *
     * @return callable(): bool
     */
    public function signal(): callable
    {
        return fn (): bool => $this->aborted || ($this->callerSignal !== null && ($this->callerSignal)());
    }

    public function aborted(): bool
    {
        return $this->aborted;
    }

    /**
     * Stop following the run and ask the server to cancel it. A failed cancel is swallowed.
     */
    public function abort(): void
    {
        if ($this->aborted) {
            return;
        }
        $this->aborted = true;

        if ($this->runId === null) {
            return;
        }
        try {
            $this->client->runs->cancel($this->threadId, $this->runId, false);
        } catch (\Throwable) {
            // Best effort, as upstream: the caller already has what it needs from the abort.
        }
    }

    /**
     * @return \Generator<int, array{type: string, seq: int, method: string, params: array{namespace: list<string>, timestamp: int, data: mixed}}>
     */
    public function getIterator(): \Generator
    {
        if ($this->runId === null) {
            return;
        }

        $seq = 0;
        $parts = $this->client->runs->joinStream($this->threadId, $this->runId, [
            'signal' => $this->signal(),
            'streamMode' => self::STREAM_MODES,
        ]);

        foreach ($parts as $part) {
            $name = (string) $part['event'];
            [$method, $namespace] = self::split($name);
            $data = $part['data'];

            if ($method === 'error') {
                throw new RemoteException(is_string($data) ? $data : (string) json_encode($data), ['data' => $data]);
            }
            if ($method === 'metadata' || $method === 'end') {
                continue;
            }

            if ($method === 'values') {
                $this->output = $data;
            } elseif ($method === 'updates' && is_array($data) && ($data[Constants::INTERRUPT] ?? null) !== null) {
                $this->interrupted = true;
                $this->interrupts = array_values($data[Constants::INTERRUPT]);
            }

            yield [
                'type' => 'event',
                'seq' => $seq++,
                'method' => $method,
                'params' => ['namespace' => $namespace, 'timestamp' => (int) (microtime(true) * 1000), 'data' => $data],
            ];

            if ($this->aborted) {
                return;
            }
        }
    }

    /**
     * Drain the stream and return the last `values` payload.
     */
    public function output(): mixed
    {
        foreach ($this as $ignored) {
            // Drain; getIterator() records the last values event.
        }

        return $this->output;
    }

    /** Whether an interrupt has been seen so far. */
    public function interrupted(): bool
    {
        return $this->interrupted;
    }

    /**
     * The interrupts seen so far.
     *
     * @return list<array{id: string|null, value: mixed}>
     */
    public function interrupts(): array
    {
        return $this->interrupts;
    }

    /**
     * The events as `text/event-stream` frames: `event: <method>[|<namespace>...]` then `data: <json>`.
     *
     * Port of `protocolEventsToEventStream`. An exception while iterating becomes a final `error` frame.
     *
     * @return \Generator<int, string>
     */
    public function toEventStream(): \Generator
    {
        try {
            foreach ($this as $event) {
                $namespace = $event['params']['namespace'];
                $name = $namespace !== []
                    ? $event['method'] . Constants::CHECKPOINT_NAMESPACE_SEPARATOR . implode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $namespace)
                    : $event['method'];

                yield "event: {$name}\ndata: " . json_encode($event['params']['data'] ?? new \stdClass()) . "\n\n";
            }
        } catch (\Throwable $e) {
            yield "event: error\ndata: " . json_encode(['message' => (string) $e]) . "\n\n";
        }
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function split(string $event): array
    {
        $parts = explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $event);

        return [$parts[0], array_slice($parts, 1)];
    }
}
