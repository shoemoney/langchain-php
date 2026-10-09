<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream\Support;

use LangGraph\Stream\NativeStreamTransformer;

/**
 * A native transformer assembled from closures, the `{ __native: true, init, process }` literals upstream's
 * run-stream tests build.
 */
final class NativeCallbackTransformer implements NativeStreamTransformer
{
    /**
     * @param (callable(): mixed)|null $init
     * @param (callable(array<string, mixed>): bool)|null $process
     */
    public function __construct(private $init = null, private $process = null)
    {
    }

    public function init(): mixed
    {
        return $this->init === null ? [] : ($this->init)();
    }

    public function process(array $event): bool
    {
        return $this->process === null ? true : ($this->process)($event);
    }
}
