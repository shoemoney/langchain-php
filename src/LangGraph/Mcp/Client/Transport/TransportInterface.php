<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client\Transport;

/**
 * A bidirectional channel of JSON-RPC messages to one MCP server.
 *
 * Pull-based: the client sends, then repeatedly calls {@see self::receive()} until its response
 * arrives, handling any server requests and notifications that come first.
 */
interface TransportInterface
{
    /**
     * @throws \LangGraph\Mcp\McpClientError when the channel cannot be opened
     */
    public function start(): void;

    /**
     * @param array<string, mixed> $message
     *
     * @throws \LangGraph\Mcp\McpClientError
     */
    public function send(array $message): void;

    /**
     * The next message from the server, or null when none arrived within `$timeoutSeconds`.
     *
     * @return array<string, mixed>|null
     *
     * @throws \LangGraph\Mcp\McpClientError when the channel is closed or broken
     */
    public function receive(float $timeoutSeconds): ?array;

    /** Called once the handshake settles on a protocol revision. */
    public function setProtocolVersion(string $version): void;

    /**
     * An unstarted sibling channel that adds `$headers` to every request.
     *
     * @param array<string, string> $headers
     *
     * @throws \LangGraph\Mcp\McpClientError when the transport carries no headers
     */
    public function withHeaders(array $headers): self;

    /** Release the channel. Safe to call twice. */
    public function close(): void;
}
