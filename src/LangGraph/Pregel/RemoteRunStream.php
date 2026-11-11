<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Errors\RemoteException;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\Stream\ThreadProjection;
use LangGraph\Sdk\Stream\ThreadStream;

/**
 * Remote protocol handle. fromThread() adapts the SDK ThreadStream; the public direct constructor
 * retains the legacy REST joinStream path. SDK projections are delegated without wrappers.
 * Callable signals are checked at entry/exit and raw/lifecycle yield boundaries. They cannot
 * interrupt delegated projection pulls or blocked reads; those need the SDK common-pull seam.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class RemoteRunStream implements \IteratorAggregate
{
    /** Legacy REST stream modes preserved for the public direct constructor. */
    public const STREAM_MODES = ['values', 'updates', 'messages-tuple', 'custom', 'tasks', 'checkpoints'];

    public const PROTOCOL_CHANNELS = ['values', 'updates', 'messages', 'tools', 'custom', 'tasks', 'checkpoints', 'lifecycle', 'input'];

    private ?ThreadStream $thread = null;

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

    public static function fromThread(Client $client, ThreadStream $thread, ?string $runId = null, ?callable $signal = null): self
    {
        $stream = new self($client, $thread->threadId, $runId, $signal);
        $stream->thread = $thread;

        return $stream;
    }

    public function thread(): ?ThreadStream
    {
        return $this->thread;
    }

    private function requireThread(): ThreadStream
    {
        return $this->thread ?? throw new \LogicException('Thread projections require RemoteRunStream::fromThread().');
    }

    private function checkSignal(): void
    {
        if (!$this->aborted && $this->callerSignal !== null && ($this->callerSignal)()) {
            $this->abort();
        }
    }

    private function delegate(callable $operation): mixed
    {
        $this->checkSignal();
        try {
            return $operation($this->requireThread());
        } finally {
            $this->checkSignal();
        }
    }

    public function values(): ThreadProjection
    {
        return $this->delegate(static fn (ThreadStream $thread): ThreadProjection => $thread->values());
    }

    public function messages(): ThreadProjection
    {
        return $this->delegate(static fn (ThreadStream $thread): ThreadProjection => $thread->messages());
    }

    public function messagesFrom(string $node): ThreadProjection
    {
        return $this->delegate(static fn (ThreadStream $thread): ThreadProjection => $thread->messagesFrom($node));
    }

    public function subgraphs(): ThreadProjection
    {
        return $this->delegate(static fn (ThreadStream $thread): ThreadProjection => $thread->subgraphs());
    }

    /** Named lazy accessor replaces the source extensions Proxy without copying its cache. */
    public function extension(string $name): ThreadProjection
    {
        return $this->delegate(static fn (ThreadStream $thread): ThreadProjection => $thread->extension($name));
    }

    public function lifecycle(): \Generator
    {
        foreach ($this->iterateThread(['lifecycle']) as $event) {
            yield [...(array) $event['params']['data'],
                'namespace' => $event['params']['namespace'], 'timestamp' => $event['params']['timestamp']];
        }
    }

    private function iterateThread(array $channels): \Generator
    {
        $this->checkSignal();
        if ($this->aborted) {
            return;
        }
        $subscription = $this->requireThread()->subscribe(['channels' => $channels]);
        $cursor = $subscription->getIterator();
        try {
            while (true) {
                $this->checkSignal();
                if ($this->aborted || !$cursor->valid()) {
                    return;
                }
                $this->checkSignal();
                if ($this->aborted) {
                    return;
                }
                yield $cursor->current();
                $this->checkSignal();
                if ($this->aborted) {
                    return;
                }
                $cursor->next();
            }
        } finally {
            $subscription->unsubscribe();
        }
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
        return function (): bool {
            $this->checkSignal();

            return $this->aborted;
        };
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

        try {
            if ($this->runId !== null) {
                $this->client->runs->cancel($this->threadId, $this->runId, false);
            }
        } catch (\Throwable) {
            // Best effort cancellation must not prevent releasing the thread.
        }
        try {
            $this->thread?->close();
        } catch (\Throwable) {
            // Best effort, as upstream.
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>> Exact thread envelopes; legacy records use generated seq/time.
     */
    public function getIterator(): \Generator
    {
        if ($this->thread !== null) {
            yield from $this->iterateThread(self::PROTOCOL_CHANNELS);

            return;
        }
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
        if ($this->thread !== null) {
            return $this->delegate(static fn (ThreadStream $thread): mixed => $thread->output());
        }
        foreach ($this as $ignored) {
            // Drain; getIterator() records the last values event.
        }

        return $this->output;
    }

    /** Whether an interrupt has been seen so far. */
    public function interrupted(): bool
    {
        return $this->thread !== null
            ? $this->delegate(static fn (ThreadStream $thread): bool => $thread->interrupted()) : $this->interrupted;
    }

    /**
     * The interrupts seen so far.
     *
     * @return list<array<string, mixed>> SDK interruptId/payload/namespace; legacy id/value records.
     */
    public function interrupts(): array
    {
        return $this->thread !== null
            ? $this->delegate(static fn (ThreadStream $thread): array => $thread->interrupts()) : $this->interrupts;
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
            $message = $this->thread !== null
                ? (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage() : (string) $e;
            yield "event: error\ndata: " . json_encode(['message' => $message]) . "\n\n";
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
