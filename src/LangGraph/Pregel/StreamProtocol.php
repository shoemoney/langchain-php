<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/** Synchronous sink adaptation of the upstream writable stream and duplex stream. */
final class StreamProtocol
{
    private bool $closed = false;
    private readonly \Closure $sink;

    /** @param list<string> $modes */
    public function __construct(public readonly array $modes, callable $sink)
    {
        $this->sink = \Closure::fromCallable($sink);
    }

    /** @param array{0: list<string>, 1: string, 2: mixed} $chunk */
    public function push(array $chunk): void
    {
        if (!$this->closed && in_array($chunk[1], $this->modes, true)) {
            ($this->sink)($chunk);
        }
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public static function duplex(self ...$streams): self
    {
        $modes = array_values(array_unique(array_merge(...array_map(static fn (self $s): array => $s->modes, $streams))));

        return new self($modes, static function (array $chunk) use ($streams): void {
            foreach ($streams as $stream) {
                $stream->push($chunk);
            }
        });
    }
}
