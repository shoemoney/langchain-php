<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;

/**
 * Guards a routing writer so it does not fire for a {@see HandledOutcome}.
 *
 * @internal
 */
final class SkipWhenHandled implements RunnableInterface
{
    public function __construct(private readonly RunnableInterface $inner)
    {
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $input instanceof HandledOutcome ? $input : $this->inner->invoke($input, $config);
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield $this->invoke($input, $config);
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return \LangChain\Runnables\Runnable::batchEachFor($this, $inputs, $config, $options);
    }

    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($input as $item) {
            yield $this->invoke($item, $config);
        }
    }
}
