<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Projection channel for local or remote streaming.
 *
 * Port of `StreamChannel` from `stream/stream-channel.ts`: an append-only log with independent cursors.
 * Local channels stay in process. Remote channels carry a protocol name; when the {@see Mux} wires them,
 * every {@see self::push()} is forwarded as a `custom:<name>` protocol event. Lifecycle (`close` / `fail`) is
 * managed by the mux.
 *
 * ORDERING (known non-exact): upstream cursors are async iterators that suspend until the producer pushes.
 * PHP has no event loop, so a cursor that runs out of buffered items asks the channel's DRIVER (set by the
 * mux, see {@see Mux::pull()}) to advance the run by one chunk and re-checks; with no driver, or when the
 * driver has nothing left, iteration ends. A channel never wired to a driver therefore yields what has been
 * pushed so far.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final class StreamChannel implements \IteratorAggregate
{
    /** The brand every channel carries; upstream's `Symbol.for("langgraph.stream_channel")`. */
    public const BRAND = 'langgraph.stream_channel';

    /** @var list<T> */
    private array $items = [];

    private bool $done = false;

    private ?\Throwable $error = null;

    /** @var (\Closure(T): void)|null */
    private ?\Closure $onPush = null;

    /** @var (\Closure(): bool)|null */
    private ?\Closure $driver = null;

    public function __construct(public readonly ?string $channelName = null)
    {
    }

    /**
     * The brand marker {@see self::isInstance()} looks for. A look-alike class from another copy of this
     * package is recognised by having this method return {@see self::BRAND}.
     */
    public function streamChannelBrand(): string
    {
        return self::BRAND;
    }

    /** An in-process-only channel. */
    public static function local(): self
    {
        return new self();
    }

    /** A channel whose pushes are forwarded to remote clients under `$name`. */
    public static function remote(string $name): self
    {
        return new self($name);
    }

    /**
     * Brand-based type guard: recognises any channel, even one built against another copy of this package.
     */
    public static function isInstance(mixed $value): bool
    {
        return \is_object($value)
            && method_exists($value, 'streamChannelBrand')
            && $value->streamChannelBrand() === self::BRAND;
    }

    /**
     * @param T $item
     */
    public function push(mixed $item): void
    {
        $this->items[] = $item;
        if ($this->onPush !== null) {
            ($this->onPush)($item);
        }
    }

    /**
     * A cursor starting at `$startAt`. Each call returns an independent cursor.
     *
     * @return \Generator<int, T>
     */
    public function iterate(int $startAt = 0): \Generator
    {
        $cursor = $startAt;
        while (true) {
            if ($cursor < \count($this->items)) {
                yield $this->items[$cursor++];

                continue;
            }
            if ($this->done) {
                if ($this->error !== null) {
                    throw $this->error;
                }

                return;
            }
            $progressed = $this->driver !== null && ($this->driver)();
            if (!$progressed && $cursor >= \count($this->items) && !$this->done) {
                return;
            }
        }
    }

    /**
     * A re-iterable view backed by this channel, starting at `$startAt`.
     *
     * @return \IteratorAggregate<int, T>
     */
    public function toAsyncIterable(int $startAt = 0): \IteratorAggregate
    {
        return new ReplayableIterable(fn (): \Generator => $this->iterate($startAt));
    }

    /**
     * The channel as Server-Sent Events: each yielded string is one complete event frame.
     *
     * Upstream returns a web `ReadableStream<Uint8Array>`; the PHP form is a generator of frames the
     * caller echoes or flushes.
     *
     * @param array{event?: string, startAt?: int, serialize?: callable(T): string} $options
     * @return \Generator<int, string>
     */
    public function toEventStream(array $options = []): \Generator
    {
        $event = $options['event'] ?? $this->channelName;
        $serialize = $options['serialize']
            ?? static fn (mixed $item): string => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';

        foreach ($this->iterate($options['startAt'] ?? 0) as $item) {
            $lines = [];
            if ($event !== null) {
                $lines[] = 'event: ' . $event;
            }
            foreach (preg_split('/\r\n|\r|\n/', (string) $serialize($item)) ?: [] as $line) {
                $lines[] = 'data: ' . $line;
            }

            yield implode("\n", $lines) . "\n\n";
        }
    }

    /**
     * @return T
     * @throws \OutOfRangeException when `$index` is out of bounds
     */
    public function get(int $index): mixed
    {
        if ($index < 0 || $index >= \count($this->items)) {
            throw new \OutOfRangeException(sprintf('StreamChannel index %d out of bounds (size=%d)', $index, \count($this->items)));
        }

        return $this->items[$index];
    }

    /** The number of buffered items. */
    public function size(): int
    {
        return \count($this->items);
    }

    /** Whether the channel has been closed or failed. */
    public function done(): bool
    {
        return $this->done;
    }

    /** Mark the channel complete after all buffered items are consumed. */
    public function close(): void
    {
        $this->done = true;
    }

    /** Mark the channel failed after all buffered items are consumed. */
    public function fail(mixed $error): void
    {
        $this->error = Types::toThrowable($error);
        $this->done = true;
    }

    /**
     * Called by the mux to wire auto-forwarding.
     *
     * @param callable(T): void $fn
     */
    public function _wire(callable $fn): void
    {
        $this->onPush = \Closure::fromCallable($fn);
    }

    /** Called by the mux on normal completion. */
    public function _close(): void
    {
        $this->close();
    }

    /** Called by the mux on failure. */
    public function _fail(mixed $error): void
    {
        $this->fail($error);
    }

    /**
     * Give the channel something to call when a cursor runs dry (see the class doc).
     *
     * @param (callable(): bool)|null $driver advances the run by one chunk; false when it cannot
     */
    public function setDriver(?callable $driver): void
    {
        $this->driver = $driver === null ? null : \Closure::fromCallable($driver);
    }

    /**
     * @return \Generator<int, T>
     */
    public function getIterator(): \Generator
    {
        return $this->iterate();
    }
}
