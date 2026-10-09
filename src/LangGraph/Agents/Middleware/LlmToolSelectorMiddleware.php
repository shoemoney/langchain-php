<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Middleware;

/**
 * Selects the tools for a model call with an LLM: when an agent has many tools, a (usually small) model first
 * picks the ones relevant to the user's query, which cuts token usage and helps the main model focus.
 *
 * Port of `llmToolSelectorMiddleware` from `langchain/src/agents/middleware/llmToolSelector.ts`.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'tools' => [$tool1, $tool2, $tool3, $tool4, $tool5],
 *     'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 3, 'alwaysInclude' => ['search']])],
 * ]);
 * ```
 *
 * Options (the run context can carry the same four and wins for `model`, `maxTools`, `alwaysInclude` and
 * `systemPrompt` when the request is prepared):
 *  - `model`: the selection model, a chat model or a "provider:model" string (resolved through
 *    {@see \LangChain\LanguageModels\Chat\Universal\InitChatModel::init()}). Defaults to the agent's model;
 *  - `systemPrompt`: instructions for the selection model;
 *  - `maxTools`: how many tools to keep; the model is told to list them most relevant first and only the first
 *    `maxTools` are used. No limit when absent;
 *  - `alwaysInclude`: names of tools that are kept whatever the model picks and do not count against `maxTools`.
 *
 * The selection call uses the model's `withStructuredOutput()` with a JSON Schema whose `tools` items are the
 * available tool names (an `enum`), and runs with the {@see Constants::INTERNAL_CALL_TAG} tag so LangGraph's
 * messages handlers keep it out of the agent's message stream. Tools that are not named (provider tool
 * definitions such as a hosted web search) are never offered to the selector and are always kept.
 *
 * Differences from upstream: the selection call's config carries the run's `signal` and `configurable` only. A
 * {@see \LangGraph\Agents\Runtime} holds neither the parent callbacks nor the tags (upstream's
 * `pickRunnableConfigKeys(request.runtime)` reads them off the runtime), so the call is not nested under the
 * agent's trace. As upstream does, the response is truncated to the `maxTools` of the OPTIONS, not of the context.
 */
final class LlmToolSelectorMiddleware
{
    private const DEFAULT_SYSTEM_PROMPT = "Your goal is to select the most relevant tools for answering the user's query.";

    private function __construct()
    {
    }

    /**
     * @param array{model?: mixed, systemPrompt?: string|null, maxTools?: int|float|null, alwaysInclude?: list<string>|null} $options
     * @return array<string, mixed> the middleware
     */
    public static function create(array $options = []): array
    {
        return Middleware::create([
            'name' => 'LLMToolSelector',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'model' => ['description' => 'The language model to use for tool selection (a model or a "provider:model" string)'],
                    'systemPrompt' => ['type' => 'string', 'description' => 'System prompt for the tool selection model'],
                    'maxTools' => ['type' => 'number', 'description' => 'Maximum number of tools to select'],
                    'alwaysInclude' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tool names to always include'],
                ],
            ],
            'wrapModelCall' => static function (array $request, callable $handler) use ($options): mixed {
                $runtime = $request['runtime'] ?? null;
                $selection = self::prepareSelectionRequest($request, $options, $runtime);
                if ($selection === null) {
                    return $handler($request);
                }

                // The structured output schema: an enum of the tool names that can be selected.
                $schema = self::createToolSelectionResponse($selection['availableTools']);
                $model = $selection['model'];
                $structuredModel = method_exists($model, 'withStructuredOutput') ? $model->withStructuredOutput($schema) : null;

                $config = new RunnableConfig(
                    tags: [Constants::INTERNAL_CALL_TAG],
                    metadata: ['lc_source' => 'llmToolSelector'],
                    signal: \is_object($runtime) ? ($runtime->signal ?? null) : null,
                    configurable: \is_object($runtime) ? (array) ($runtime->configurable ?? []) : [],
                );

                $response = $structuredModel?->invoke(
                    [new SystemMessage($selection['systemMessage']), $selection['lastUserMessage']],
                    $config,
                );

                // The response should be an object with a tools array.
                if (\is_object($response)) {
                    $response = get_object_vars($response);
                }
                if (!\is_array($response) || !\array_key_exists('tools', $response)) {
                    throw new \RuntimeException('Expected object response with tools array, got ' . Utils::typeOf($response));
                }

                return $handler(self::processSelectionResponse(
                    array_values((array) $response['tools']),
                    $selection['availableTools'],
                    $selection['validToolNames'],
                    $request,
                    $options,
                ));
            },
        ]);
    }

    /**
     * The JSON Schema of the selection: a `tools` list whose items are the names of the tools that can be picked.
     *
     * @param list<mixed> $tools
     * @return array<string, mixed>
     */
    private static function createToolSelectionResponse(array $tools): array
    {
        if ($tools === []) {
            throw new \InvalidArgumentException('Invalid usage: tools must be non-empty');
        }

        return [
            'type' => 'object',
            'properties' => [
                'tools' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => array_map(self::nameOf(...), $tools)],
                    'description' => 'Tools to use. Place the most relevant tools first.',
                ],
            ],
            'required' => ['tools'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Prepare the inputs of the selection call.
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options
     * @return array{availableTools: list<mixed>, systemMessage: string, lastUserMessage: HumanMessage, model: object, validToolNames: list<string>}|null null when no selection is needed
     */
    private static function prepareSelectionRequest(array $request, array $options, mixed $runtime): ?array
    {
        $context = self::contextOf($runtime);
        $model = $context['model'] ?? $options['model'] ?? null;
        $maxTools = $context['maxTools'] ?? $options['maxTools'] ?? null;
        $alwaysInclude = $context['alwaysInclude'] ?? $options['alwaysInclude'] ?? [];
        $systemPrompt = $context['systemPrompt'] ?? $options['systemPrompt'] ?? self::DEFAULT_SYSTEM_PROMPT;

        // If no tools are available there is nothing to select.
        $tools = array_values((array) ($request['tools'] ?? []));
        if ($tools === []) {
            return null;
        }

        // Only named tools: provider-specific tool definitions are left out.
        $baseTools = array_values(array_filter($tools, self::isNamedTool(...)));

        // Validate that the alwaysInclude tools exist.
        if ($alwaysInclude !== []) {
            $availableToolNames = array_map(self::nameOf(...), $baseTools);
            $missingTools = array_values(array_filter($alwaysInclude, static fn (string $name): bool => !\in_array($name, $availableToolNames, true)));
            if ($missingTools !== []) {
                $sorted = array_values(array_unique($availableToolNames));
                sort($sorted, \SORT_STRING);

                throw new \RuntimeException(\sprintf(
                    'Tools in alwaysInclude not found in request: %s. Available tools: %s',
                    implode(', ', $missingTools),
                    implode(', ', $sorted),
                ));
            }
        }

        // Separate the tools that are always included from the ones available for selection.
        $availableTools = array_values(array_filter($baseTools, static fn (mixed $tool): bool => !\in_array(self::nameOf($tool), $alwaysInclude, true)));

        // If no tools are available for selection there is nothing to select.
        if ($availableTools === []) {
            return null;
        }

        $systemMessage = $systemPrompt;
        // With a maxTools limit, tell the model to put the most relevant first.
        if ($maxTools !== null) {
            $systemMessage .= "\nIMPORTANT: List the tool names in order of relevance, "
                . 'with the most relevant first. '
                . 'If you exceed the maximum number of tools, '
                . "only the first {$maxTools} will be used.";
        }

        // The last user message of the conversation.
        $lastUserMessage = null;
        foreach ((array) ($request['messages'] ?? []) as $message) {
            if ($message instanceof HumanMessage) {
                $lastUserMessage = $message;
            }
        }
        if ($lastUserMessage === null) {
            throw new \RuntimeException('No user message found in request messages');
        }

        return [
            'availableTools' => $availableTools,
            'systemMessage' => $systemMessage,
            'lastUserMessage' => $lastUserMessage,
            'model' => self::resolveModel($model, $request['model'] ?? null),
            'validToolNames' => array_map(self::nameOf(...), $availableTools),
        ];
    }

    /**
     * Turn the selection into the request to hand on: the selected tools, the always-included ones, and every
     * provider tool definition of the original request.
     *
     * @param list<mixed>          $selected
     * @param list<mixed>          $availableTools
     * @param list<string>         $validToolNames
     * @param array<string, mixed> $request
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function processSelectionResponse(array $selected, array $availableTools, array $validToolNames, array $request, array $options): array
    {
        $maxTools = $options['maxTools'] ?? null;
        $alwaysInclude = $options['alwaysInclude'] ?? [];

        $selectedToolNames = [];
        $invalidToolSelections = [];

        foreach ($selected as $toolName) {
            if (!\in_array($toolName, $validToolNames, true)) {
                $invalidToolSelections[] = (string) (\is_scalar($toolName) ? $toolName : json_encode($toolName));
                continue;
            }

            // Only add it if it is not already selected and within the maxTools limit.
            if (!\in_array($toolName, $selectedToolNames, true) && ($maxTools === null || \count($selectedToolNames) < $maxTools)) {
                $selectedToolNames[] = $toolName;
            }
        }

        if ($invalidToolSelections !== []) {
            throw new \RuntimeException('Model selected invalid tools: ' . implode(', ', $invalidToolSelections));
        }

        $requestTools = array_values((array) ($request['tools'] ?? []));
        $selectedTools = array_values(array_filter($availableTools, static fn (mixed $tool): bool => \in_array(self::nameOf($tool), $selectedToolNames, true)));

        // Append the always-included tools.
        $alwaysIncludedTools = array_filter(
            $requestTools,
            static fn (mixed $tool): bool => self::nameOf($tool) !== null && \in_array(self::nameOf($tool), $alwaysInclude, true),
        );
        // Also keep every provider-specific tool definition of the original request.
        $providerTools = array_filter($requestTools, static fn (mixed $tool): bool => !self::isNamedTool($tool));

        return [...$request, 'tools' => [...$selectedTools, ...array_values($alwaysIncludedTools), ...array_values($providerTools)]];
    }

    private static function resolveModel(mixed $model, mixed $requestModel): object
    {
        if ($model === null) {
            return $requestModel;
        }
        if (!\is_string($model)) {
            return $model;
        }

        return \LangChain\LanguageModels\Chat\Universal\InitChatModel::init($model);
    }

    /** A tool with a string `name` and a `description` (a tool object or a tool definition array). */
    private static function isNamedTool(mixed $tool): bool
    {
        if (\is_array($tool)) {
            return \is_string($tool['name'] ?? null) && \array_key_exists('description', $tool);
        }

        return \is_object($tool) && property_exists($tool, 'name') && \is_string($tool->name) && property_exists($tool, 'description');
    }

    /** The name of a tool, or null for a definition that has none. */
    private static function nameOf(mixed $tool): ?string
    {
        if (\is_array($tool)) {
            return \is_string($tool['name'] ?? null) ? $tool['name'] : null;
        }

        return \is_object($tool) && property_exists($tool, 'name') && \is_string($tool->name) ? $tool->name : null;
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = \is_object($runtime) ? ($runtime->context ?? null) : (\is_array($runtime) ? ($runtime['context'] ?? null) : null);

        return \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
    }
}
