<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\ElicitationCapableClientInterface;
use LangGraph\Mcp\ForkableMcpClientInterface;
use LangGraph\Mcp\McpClientInterface;

/**
 * A scripted MCP client: the stand-in for the `vi.fn()` mock client upstream's tests build.
 *
 * `callTool()` answers from, in order: a `respondWith()` handler, then the queue filled by
 * `willReturn()` (a queued `\Throwable` is thrown instead, and the LAST entry repeats), then an
 * empty `content` result. Every call is recorded with the request and the options the adapter
 * passed, which is what the converted tests assert on.
 */
final class FakeMcpClient implements ForkableMcpClientInterface, ElicitationCapableClientInterface
{
    /** @var list<array{name: string, arguments: array<string, mixed>, options: array<string, mixed>}> */
    public array $calls = [];

    public int $listCalls = 0;

    public int $eraCalls = 0;

    /** @var list<array<string, string>> */
    public array $forkedWith = [];

    /** @var (callable(array<string, mixed>): array<string, mixed>)|null */
    public $elicitationHandler = null;

    /** @var list<array<string, mixed>|\Throwable> */
    private array $queue = [];

    /** @var (callable(string, array<string, mixed>, array<string, mixed>): array<string, mixed>)|null */
    private $handler = null;

    /**
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>|null  $capabilities
     */
    public function __construct(
        public array $tools = [],
        public string $era = 'legacy',
        public ?array $capabilities = ['tools' => []],
        public ?FakeMcpClient $fork = null,
    ) {
    }

    /** @param array<string, mixed>|\Throwable ...$results */
    public function willReturn(array|\Throwable ...$results): self
    {
        $this->queue = array_values($results);

        return $this;
    }

    /** @param callable(string, array<string, mixed>, array<string, mixed>): array<string, mixed> $handler */
    public function respondWith(callable $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function listTools(): array
    {
        ++$this->listCalls;

        return ['tools' => $this->tools];
    }

    public function callTool(string $name, array $arguments, array $options = []): array
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments, 'options' => $options];

        if ($this->handler !== null) {
            return ($this->handler)($name, $arguments, $options);
        }

        $next = count($this->queue) > 1 ? array_shift($this->queue) : ($this->queue[0] ?? ['content' => []]);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function getServerCapabilities(): ?array
    {
        return $this->capabilities;
    }

    public function getProtocolEra(): string
    {
        ++$this->eraCalls;

        return $this->era;
    }

    public function fork(array $headers): McpClientInterface
    {
        $this->forkedWith[] = $headers;

        return $this->fork ?? new self($this->tools, $this->era);
    }

    public function setElicitationHandler(callable $handler): void
    {
        $this->elicitationHandler = $handler;
    }

    /** @return array<string, mixed> the options of call `$index` */
    public function optionsOf(int $index = 0): array
    {
        return $this->calls[$index]['options'];
    }
}
