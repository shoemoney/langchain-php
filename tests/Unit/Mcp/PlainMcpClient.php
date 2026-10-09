<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\McpClientInterface;

/**
 * A client that implements ONLY the base interface: no fork, no elicitation handler.
 *
 * Upstream probes `"fork" in client`; this is the object that probe fails on.
 */
final class PlainMcpClient implements McpClientInterface
{
    public function __construct(private readonly FakeMcpClient $inner)
    {
    }

    public function listTools(): array
    {
        return $this->inner->listTools();
    }

    public function callTool(string $name, array $arguments, array $options = []): array
    {
        return $this->inner->callTool($name, $arguments, $options);
    }

    public function getServerCapabilities(): ?array
    {
        return $this->inner->getServerCapabilities();
    }

    public function getProtocolEra(): string
    {
        return $this->inner->getProtocolEra();
    }
}
