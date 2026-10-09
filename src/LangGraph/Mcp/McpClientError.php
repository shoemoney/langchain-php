<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * An operational failure while connecting to or using an MCP server.
 *
 * Port of `MCPClientError` from `utils/errors.ts`.
 */
class McpClientError extends \RuntimeException
{
    public mixed $cause = null;

    public function __construct(string $message, public readonly ?string $serverName = null, mixed $cause = null)
    {
        parent::__construct($message, 0, $cause instanceof \Throwable ? $cause : null);
        $this->cause = $cause;
    }
}
