<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Connection;

use LangGraph\Mcp\Client\Transport\TransportInterface;

/**
 * The `transportFactory` seam of the connection manager and the multi server client: builds a
 * {@see FakeServerTransport} per connection and remembers each one with the options it was built
 * for, so a test can count constructions, inspect what a transport was asked to do and script the
 * next one.
 */
final class FakeTransportFactory
{
    /** @var list<FakeServerTransport> */
    public array $transports = [];

    /** Every call, including the ones that were made to fail. */
    public int $attempts = 0;

    /** @var list<callable(FakeServerTransport): void> */
    private array $scripts = [];

    /** @var list<\Throwable> */
    private array $failures = [];

    /** Script the next transport built (called with it before it is returned). */
    public function script(callable $script): self
    {
        $this->scripts[] = $script;

        return $this;
    }

    /** Make the next factory call throw `$error` instead of building a transport. */
    public function failNext(\Throwable $error): self
    {
        $this->failures[] = $error;

        return $this;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $type, array $options): TransportInterface
    {
        ++$this->attempts;
        $failure = array_shift($this->failures);
        if ($failure !== null) {
            throw $failure;
        }

        $transport = new FakeServerTransport($type, $options);
        $script = array_shift($this->scripts);
        if ($script !== null) {
            $script($transport);
        }
        $this->transports[] = $transport;

        return $transport;
    }

    public function count(): int
    {
        return count($this->transports);
    }
}
