<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client;

use LangGraph\Mcp\McpClientError;

/**
 * The server answered a request with a JSON-RPC `error` object.
 */
final class JsonRpcException extends McpClientError
{
    public function __construct(string $message, public readonly int $rpcCode, public readonly mixed $data = null)
    {
        parent::__construct($message);
    }
}
