<?php

declare(strict_types=1);

namespace LangGraph\Stream\Transformers;

use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\Types;

/**
 * Captures `values` events into a local {@see StreamChannel}. Only events whose namespace exactly matches
 * `$path` are recorded; child and sibling namespaces are ignored.
 *
 * Port of `createValuesTransformer` from `stream/transformers/values.ts`. The final snapshot is resolved by
 * the mux directly; this transformer only accumulates the intermediate ones. The projection is
 * `['_valuesLog' => StreamChannel]`.
 */
final class ValuesTransformer extends AbstractStreamTransformer
{
    private readonly StreamChannel $valuesLog;

    /**
     * @param list<string> $path namespace to match
     */
    public function __construct(private readonly array $path = [])
    {
        $this->valuesLog = StreamChannel::local();
    }

    public function init(): array
    {
        return ['_valuesLog' => $this->valuesLog];
    }

    public function process(array $event): bool
    {
        if (($event['method'] ?? null) !== 'values') {
            return true;
        }
        $namespace = $event['params']['namespace'];
        if (\count($namespace) !== \count($this->path) || !Types::hasPrefix($namespace, $this->path)) {
            return true;
        }
        $this->valuesLog->push($event['params']['data']);

        return true;
    }

    public function finalize(): mixed
    {
        $this->valuesLog->close();

        return null;
    }

    public function fail(mixed $error): void
    {
        $this->valuesLog->fail($error);
    }
}
