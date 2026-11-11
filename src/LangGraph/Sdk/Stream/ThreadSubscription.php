<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

use LangGraph\Stream\StreamChannel;

/** A consumptive raw subscription; unlike projections it does not replay. */
final class ThreadSubscription implements \IteratorAggregate
{
    private readonly StreamChannel $buffer;
    private int $cursor = 0;
    private bool $closed = false;
    public bool $paused = false;
    public bool $endOnRootTerminal = false;
    private array $seen = [];
    private ?\Closure $pushObserver = null;

    public function __construct(
        public readonly string $subscriptionId,
        public readonly array $params,
        private readonly \Closure $unsubscribeCallback,
        callable $driver,
        private readonly bool $unwrap = false,
        public readonly ?int $registeredAfterSeq = null,
    ) {
        $this->buffer = StreamChannel::local();
        $this->buffer->setDriver(fn (): bool => !$this->paused && !$this->closed && $driver());
    }

    public function push(array $event): bool
    {
        if ($this->closed || !self::matches($event, $this->params)) {
            return false;
        }
        $id = $event['event_id'] ?? null;
        if ($id !== null && isset($this->seen[$id])) {
            return false;
        }
        if ($id !== null) {
            $this->seen[$id] = true;
        }
        $this->buffer->push($this->unwrap ? ($event['params']['data']['payload'] ?? $event) : $event);
        if ($this->pushObserver !== null) {
            ($this->pushObserver)($event);
        }

        return true;
    }

    /** @internal Capture a projection's event identity at delivery, before late reads. */
    public function setPushObserver(\Closure $observer): void
    {
        $this->pushObserver = $observer;
    }

    public function getIterator(): \Generator
    {
        while (true) {
            // Claim one shared queue slot before yielding. A suspended iterator
            // must re-read the global cursor when another consumer has advanced.
            $item = $this->buffer->iterate($this->cursor);
            $item->rewind();
            if (!$item->valid()) {
                return;
            }
            ++$this->cursor;
            yield $item->current();
        }
    }

    public function unsubscribe(): void
    {
        if ($this->closed) {
            return;
        }
        $this->close();
        ($this->unsubscribeCallback)($this->subscriptionId);
    }

    public function close(?\Throwable $error = null): void
    {
        $this->closed = true;
        $error === null ? $this->buffer->close() : $this->buffer->fail($error);
    }

    /** Source subscription.ts channel and normalized namespace-prefix rules. */
    public static function matches(array $event, array $params): bool
    {
        $method = $event['method'] ?? '';
        $data = $event['params']['data'] ?? [];
        $channel = match ($method) {
            'custom' => isset($data['name']) ? 'custom:' . $data['name'] : 'custom',
            'input.requested' => 'input',
            'values', 'updates', 'messages', 'tools', 'lifecycle', 'checkpoints', 'tasks' => $method,
            default => null,
        };
        if ($channel === null || (!in_array($channel, $params['channels'], true)
            && !(str_starts_with($channel, 'custom:') && in_array('custom', $params['channels'], true)))) {
            return false;
        }
        $prefixes = $params['namespaces'] ?? [];
        if ($prefixes === []) {
            return true;
        }
        $namespace = $event['params']['namespace'] ?? [];
        foreach ($prefixes as $prefix) {
            if (count($prefix) > count($namespace)) {
                continue;
            }
            $matches = true;
            foreach ($prefix as $i => $segment) {
                $candidate = $namespace[$i];
                if ($candidate !== $segment && (str_contains($segment, ':') || explode(':', $candidate, 2)[0] !== $segment)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches && (!isset($params['depth']) || count($namespace) - count($prefix) <= $params['depth'])) {
                return true;
            }
        }

        return false;
    }
}
