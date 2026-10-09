<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * An MCP tool call failed.
 *
 * Port of `ToolException` from `langchain-mcp-adapters/src/utils/errors.ts`. Distinct from
 * {@see \LangChain\Tools\ToolException}, which is core's "arguments did not match the schema":
 * this one wraps everything that can go wrong around an MCP round trip, and may carry the
 * server's own `isError` result.
 *
 * `$cause` is untyped on purpose, as upstream's `cause` is: a caller may hand in a non-Error
 * (`false`, `null`) and it must be preserved verbatim. A `Throwable` cause is also chained as the
 * PHP `previous`.
 */
class ToolException extends \RuntimeException
{
    /** The original failure, whatever it was. */
    public mixed $cause = null;

    /**
     * @param array<string, mixed>|null $result the server's `CallToolResult` when it reported `isError`
     */
    public function __construct(string $message, mixed $cause = null, public readonly ?array $result = null)
    {
        parent::__construct($message, 0, $cause instanceof \Throwable ? $cause : null);
        $this->cause = $cause;
    }
}
