<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Connection;

use LangGraph\Mcp\Client\McpClient;
use LangGraph\Mcp\ElicitationCapableClientInterface;
use LangGraph\Mcp\ForkableMcpClientInterface;
use LangGraph\Mcp\McpClientInterface;

/**
 * A connected {@see McpClient} owned by a {@see ConnectionManager}: the `Client` type of
 * `connection.ts`, an SDK client extended with `fork`.
 *
 * Everything delegates to the wrapped client except {@see self::fork()}, which goes back through
 * the manager so a fork with headers the connection already carries is the same client, and a
 * new header set is pooled like any other connection.
 */
final class ManagedClient implements ForkableMcpClientInterface, ElicitationCapableClientInterface
{
    /**
     * @param \Closure(array<string, string>): ManagedClient $forker
     */
    public function __construct(private readonly McpClient $client, private readonly \Closure $forker)
    {
    }

    /** The wrapped protocol client. */
    public function inner(): McpClient
    {
        return $this->client;
    }

    public function listTools(): array
    {
        return $this->client->listTools();
    }

    public function callTool(string $name, array $arguments, array $options = []): array
    {
        return $this->client->callTool($name, $arguments, $options);
    }

    /** @return array{resources: list<array<string, mixed>>} */
    public function listResources(): array
    {
        return $this->client->listResources();
    }

    /** @return array<string, mixed> */
    public function readResource(string $uri): array
    {
        return $this->client->readResource($uri);
    }

    public function setLoggingLevel(string $level): void
    {
        $this->client->setLoggingLevel($level);
    }

    public function getServerCapabilities(): ?array
    {
        return $this->client->getServerCapabilities();
    }

    /** @return array<string, mixed>|null */
    public function getServerVersion(): ?array
    {
        return $this->client->getServerVersion();
    }

    public function getInstructions(): ?string
    {
        return $this->client->getInstructions();
    }

    public function getProtocolEra(): string
    {
        return $this->client->getProtocolEra();
    }

    public function getProtocolVersion(): ?string
    {
        return $this->client->getProtocolVersion();
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }

    public function setElicitationHandler(callable $handler): void
    {
        $this->client->setElicitationHandler($handler);
    }

    public function fork(array $headers): McpClientInterface
    {
        return ($this->forker)($headers);
    }

    public function close(): void
    {
        $this->client->close();
    }
}
