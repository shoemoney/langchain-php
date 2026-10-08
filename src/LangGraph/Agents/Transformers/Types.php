<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * The shared vocabulary of the agent stream transformers.
 *
 * Port of `langchain/src/agents/transformers/types.ts`. That file is TypeScript types (`AgentRunStream`,
 * `ToolCallStreamUnion`, `InferStreamExtensions`) with no runtime form; the runtime shapes live in
 * {@see ToolCallStream} and {@see SubagentRunStream}. What remains here are the protocol constants and the
 * event/cause builders the transformers and their callers share.
 */
final class Types
{
    public const STATUS_FINISHED = 'finished';
    public const STATUS_ERROR = 'error';

    private function __construct()
    {
    }

    /**
     * A protocol event.
     *
     * @param list<string> $namespace
     * @return array{method: string, params: array{namespace: list<string>, data: mixed}}
     */
    public static function protocolEvent(string $method, array $namespace, mixed $data): array
    {
        return ['method' => $method, 'params' => ['namespace' => $namespace, 'data' => $data]];
    }

    /**
     * The lifecycle cause for a subagent dispatched by a tool call.
     *
     * @return array{type: 'toolCall', tool_call_id: string}
     */
    public static function toolCallCause(string $toolCallId): array
    {
        return ['type' => 'toolCall', 'tool_call_id' => $toolCallId];
    }

    /**
     * Whether `$namespace` starts with every segment of `$prefix`.
     *
     * @param list<string> $namespace
     * @param list<string> $prefix
     */
    public static function hasPrefix(array $namespace, array $prefix): bool
    {
        if (\count($prefix) > \count($namespace)) {
            return false;
        }
        foreach ($prefix as $i => $segment) {
            if ($namespace[$i] !== $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * A stable string key for a namespace.
     *
     * @param list<string> $namespace
     */
    public static function namespaceKey(array $namespace): string
    {
        return implode("\0", $namespace);
    }
}
