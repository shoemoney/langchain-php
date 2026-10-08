<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;

/**
 * A do-nothing runnable with a chosen name, for nodes whose only job is to be drawn.
 */
final class NamedRunnable extends Runnable
{
    public function __construct(private readonly string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $input;
    }
}
