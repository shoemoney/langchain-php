<?php

declare(strict_types=1);

namespace LangGraph\Agents;

/**
 * Creates an agent graph that calls tools in a loop until a stopping condition is met.
 *
 * Port of `createAgent` from `langchain/src/agents/index.ts`.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $chatModel,
 *     'tools' => [$weatherTool],
 *     'systemPrompt' => 'You are a helpful assistant.',
 *     'middleware' => [$loggingMiddleware],
 * ]);
 *
 * $result = $agent->invoke(['messages' => [new HumanMessage('Weather in Tokyo?')]]);
 * ```
 *
 * The options are:
 *
 *  - `model` (required): a chat model without tools bound (the agent binds `tools` itself);
 *  - `tools`: the tools the agent can call;
 *  - `systemPrompt`: a string or a `SystemMessage`;
 *  - `stateSchema`, `contextSchema`: extra state channels and the run context shape (an `AnnotationRoot` or a
 *    JSON Schema array; a state schema with a reducer is an `AnnotationRoot`);
 *  - `middleware`: middleware made with {@see Middleware::create()}, outermost first;
 *  - `checkpointer`, `store`: persistence (a saver / `true` for a subgraph, and a `BaseStore`);
 *  - `responseFormat`: structured output (WP-21c);
 *  - `name`, `description`: the agent's name (stamped on its AI messages) and description;
 *  - `includeAgentName`: `"inline"` to put the agent name into the message text the model sees;
 *  - `signal`: an abort signal (a callable returning `true`/a throwable once aborted, or an object with `aborted`);
 *  - `version`: `"v2"` (default) sends each tool call to the tools node on its own; `"v1"` runs all of a message's calls in one task;
 *  - `streamTransformers`: accepted for parity; there is no transformer protocol to merge them into.
 */
final class Agent
{
    private function __construct()
    {
    }

    /**
     * Port of `createAgent`.
     *
     * @param array<string, mixed> $options
     */
    public static function create(array $options): ReactAgent
    {
        return new ReactAgent($options);
    }
}
