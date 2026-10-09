<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * A client that can open a sibling connection carrying extra request headers.
 *
 * Optional: upstream probes for `"fork" in client`. A `beforeToolCall` hook that returns
 * `headers` needs it; without it the call fails with a {@see ToolException}.
 */
interface ForkableMcpClientInterface extends McpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function fork(array $headers): McpClientInterface;
}
