<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * A {@see StreamTransformer} with every optional hook defaulted to a no-op.
 */
abstract class AbstractStreamTransformer implements StreamTransformer
{
    public function init(): mixed
    {
        return [];
    }

    public function process(array $event): bool
    {
        return true;
    }

    public function onRegister(StreamEmitter $emitter): void
    {
    }

    public function finalize(): mixed
    {
        return null;
    }

    public function fail(mixed $error): void
    {
    }
}
