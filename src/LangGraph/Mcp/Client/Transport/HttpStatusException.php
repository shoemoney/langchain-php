<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client\Transport;

use LangGraph\Mcp\McpClientError;

/**
 * The MCP endpoint answered with an HTTP error status. `Errors::getHttpErrorCode()` reads `$status`.
 */
final class HttpStatusException extends McpClientError
{
    public function __construct(public readonly int $status, string $detail)
    {
        parent::__construct("{$detail} (HTTP {$status})");
    }
}
