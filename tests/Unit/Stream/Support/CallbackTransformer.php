<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream\Support;

use LangGraph\Stream\StreamEmitter;
use LangGraph\Stream\StreamTransformer;

/**
 * A transformer assembled from closures, the PHP form of the object literals the upstream tests build
 * (`{ init: () => ..., process: ..., finalize: ... }`).
 */
final class CallbackTransformer implements StreamTransformer
{
    /**
     * @param (callable(): mixed)|null $init
     * @param (callable(array<string, mixed>): bool)|null $process
     * @param (callable(): mixed)|null $finalize
     * @param (callable(mixed): void)|null $fail
     * @param (callable(StreamEmitter): void)|null $onRegister
     */
    public function __construct(
        private $init = null,
        private $process = null,
        private $finalize = null,
        private $fail = null,
        private $onRegister = null,
    ) {
    }

    public function init(): mixed
    {
        return $this->init === null ? [] : ($this->init)();
    }

    public function process(array $event): bool
    {
        return $this->process === null ? true : ($this->process)($event);
    }

    public function finalize(): mixed
    {
        return $this->finalize === null ? null : ($this->finalize)();
    }

    public function fail(mixed $error): void
    {
        if ($this->fail !== null) {
            ($this->fail)($error);
        }
    }

    public function onRegister(StreamEmitter $emitter): void
    {
        if ($this->onRegister !== null) {
            ($this->onRegister)($emitter);
        }
    }
}
