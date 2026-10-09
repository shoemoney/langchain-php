<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Tests\Unit\Mcp\Connection\FakeServerTransport;
use LangChain\Tests\Unit\Mcp\Connection\FakeTransportFactory;
use LangGraph\Mcp\MultiServerMcpClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for the converted `MultiServerMCPClient` suites: a client wired to a scripted
 * transport factory, with the restart backoff recorded instead of slept, and every client closed
 * when the test ends.
 */
abstract class MultiServerTestCase extends TestCase
{
    /** @var list<MultiServerMcpClient> */
    private array $clients = [];

    /** @var list<int> backoff delays the clients asked for, in milliseconds */
    protected array $sleeps = [];

    protected FakeTransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new FakeTransportFactory();
        $this->sleeps = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->close();
            } catch (\Throwable) {
                // A test that broke closing on purpose has already made its point.
            }
        }
        $this->clients = [];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function adapter(array $config, ?FakeTransportFactory $factory = null): MultiServerMcpClient
    {
        $client = new MultiServerMcpClient($config, [
            'transportFactory' => $factory ?? $this->factory,
            'sleep' => function (int $milliseconds): void {
                $this->sleeps[] = $milliseconds;
            },
        ]);
        $this->clients[] = $client;

        return $client;
    }

    /** A bare configuration: no fakes, for tests that never connect or that use a real server. */
    protected function plain(array $config): MultiServerMcpClient
    {
        $client = new MultiServerMcpClient($config);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected static function stdio(array $extra = []): array
    {
        return [...['mode' => 'legacy', 'transport' => 'stdio', 'command' => 'python', 'args' => ['./script.py']], ...$extra];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected static function http(array $extra = []): array
    {
        return [...['mode' => 'legacy', 'transport' => 'http', 'url' => 'http://localhost:8000/mcp', 'automaticSSEFallback' => false], ...$extra];
    }

    /** @return list<string> */
    protected static function names(array $tools): array
    {
        return array_values(array_map(static fn ($tool): string => $tool->name, $tools));
    }

    protected function transport(int $index = 0): FakeServerTransport
    {
        return $this->factory->transports[$index];
    }

    protected static function thrownBy(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected a throwable.');
    }
}
