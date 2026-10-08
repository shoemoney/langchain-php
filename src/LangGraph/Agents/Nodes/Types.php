<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangGraph\Agents\Runtime;

/**
 * Request shapes the agent nodes hand to middleware.
 *
 * Port of `langchain/src/agents/nodes/types.ts`, whose only runtime-relevant export is the
 * `ModelRequest` interface. Upstream middleware overrides a request with a spread
 * (`{ ...request, systemMessage }`); a request here is therefore a plain array, and an override is
 * `array_merge($request, $changes)`.
 *
 * A `ModelRequest` carries:
 *
 *  - `model`: the language model (any runnable) to use for this step;
 *  - `messages`: the messages to send;
 *  - `systemPrompt`: the system message string (deprecated upstream in favour of `systemMessage`);
 *  - `systemMessage`: the `SystemMessage` for this step (an empty one when no prompt was given);
 *  - `toolChoice`: `"auto"`, `"none"`, `"required"`, or `['type' => 'function', 'function' => ['name' => …]]`;
 *  - `tools`: the tools available for this step;
 *  - `state`: the agent state (middleware state plus the built-ins `messages` and `structuredResponse`);
 *  - `responseFormat`: the structured output configuration for this step;
 *  - `runtime`: the {@see Runtime};
 *  - `modelSettings`: extra settings applied when the model is re-bound for each request.
 */
final class Types
{
    /** Every key a model request may carry. */
    public const MODEL_REQUEST_KEYS = [
        'model',
        'messages',
        'systemPrompt',
        'systemMessage',
        'toolChoice',
        'tools',
        'state',
        'responseFormat',
        'runtime',
        'modelSettings',
    ];

    public const TOOL_CHOICE_AUTO = 'auto';
    public const TOOL_CHOICE_NONE = 'none';
    public const TOOL_CHOICE_REQUIRED = 'required';

    private function __construct()
    {
    }

    /**
     * Build a model request, filling the fields upstream marks required with their defaults
     * (`systemPrompt` is `""`, `tools` and `messages` are empty).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws \InvalidArgumentException for a key a model request does not have
     */
    public static function modelRequest(array $fields = []): array
    {
        $unknown = array_diff(array_keys($fields), self::MODEL_REQUEST_KEYS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown ModelRequest field(s): ' . implode(', ', $unknown));
        }

        return $fields + [
            'messages' => [],
            'systemPrompt' => '',
            'tools' => [],
            'state' => [],
            'runtime' => new Runtime(),
        ];
    }

    /**
     * The request with some fields overridden: upstream's `{ ...request, ...changes }`.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function overrideModelRequest(array $request, array $changes): array
    {
        return self::modelRequest([...$request, ...$changes]);
    }
}
