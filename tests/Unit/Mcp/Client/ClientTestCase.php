<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\Client\Transport\StdioTransport;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing: spawn the stdio fixture server and make sure no child outlives its test.
 */
abstract class ClientTestCase extends TestCase
{
    /** @var list<McpClient|StdioTransport> */
    private array $toClose = [];

    protected function tearDown(): void
    {
        foreach ($this->toClose as $closable) {
            $closable->close();
        }
        $this->toClose = [];
    }

    /**
     * @param list<string>         $args  fixture server arguments (name, mode)
     * @param array<string, mixed> $extra transport config overrides
     */
    protected function stdioTransport(array $args = [], array $extra = []): StdioTransport
    {
        $transport = new StdioTransport($extra + [
            'command' => \PHP_BINARY,
            'args' => [__DIR__ . '/Fixtures/StdioServer.php', ...$args],
            'stderr' => 'ignore',
        ]);
        $this->toClose[] = $transport;

        return $transport;
    }

    /**
     * @param list<string>         $args
     * @param array<string, mixed> $options
     */
    protected function stdioClient(array $args = [], array $options = []): McpClient
    {
        $client = new McpClient($this->stdioTransport($args), $options);
        $this->toClose[] = $client;

        return $client;
    }

    protected function track(McpClient $client): McpClient
    {
        $this->toClose[] = $client;

        return $client;
    }

    /** @param array<string, mixed> $result */
    protected static function textOf(array $result): string
    {
        return (string) $result['content'][0]['text'];
    }
}
