<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * A client that lets the adapter install an `elicitation/create` request handler (legacy,
 * callback-style elicitation). Optional: the in-band modern path never needs it.
 */
interface ElicitationCapableClientInterface extends McpClientInterface
{
    /**
     * @param callable(array<string, mixed> $params): array<string, mixed> $handler
     */
    public function setElicitationHandler(callable $handler): void;
}
