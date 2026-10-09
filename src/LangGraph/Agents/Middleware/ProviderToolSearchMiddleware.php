<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Tools\ToolUtils;
use LangGraph\Agents\ConfigurableModelInterface;
use LangGraph\Agents\Middleware;

/**
 * Provider-side tool search.
 *
 * Port of `providerToolSearchMiddleware` from `langchain/src/agents/middleware/providerToolSearch.ts`.
 *
 * The full client tool catalog is forwarded to the provider with the deferred tools marked `defer_loading`,
 * so the provider discloses them on demand through its own tool search. A tool is deferred when it is named in
 * `searchableTools` (a `ToolIdentifier`: a name or a tool) or was built with `extras['defer_loading'] === true`.
 * Needs a model with server-side tool search (OpenAI gpt-5.4+, Anthropic Claude Sonnet 4+/Opus 4+/Haiku 4.5+);
 * any other provider throws, and an in-family model that is too old surfaces the provider's own API error.
 *
 * Upstream hands the model a minimal `{name, description, schema, extras: {defer_loading}}` binding spec and each
 * provider package renders it. This port's providers only render `defer_loading` for the tool shapes they
 * already know, so the spec is rendered here into the provider's own wire shape (the Anthropic tool with
 * `defer_loading`, the OpenAI function tool with `defer_loading`). The deferred tool is an array rather than a
 * tool instance on purpose: a replaced tool instance is rejected by the agent, as it is not the tool the tools
 * node executes.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $chatAnthropic,
 *     'tools' => [$getWeather, ...$nicheTools],
 *     'middleware' => [ProviderToolSearchMiddleware::create(['searchableTools' => $nicheTools])],
 * ]);
 * ```
 */
final class ProviderToolSearchMiddleware
{
    /** Providers with a server-side tool search, and the native search tool each one is offered. */
    private const SERVER_TOOL_SEARCH_TOOLS = [
        'anthropic' => ['type' => 'tool_search_tool_bm25_20251119', 'name' => 'tool_search_tool_bm25'],
        'openai' => ['type' => 'tool_search'],
    ];

    private function __construct()
    {
    }

    /**
     * @param array{searchableTools?: list<mixed>|null} $config `searchableTools`: tool names or tools to defer.
     * @return array<string, mixed> the middleware
     */
    public static function create(array $config = []): array
    {
        $deferNames = [];
        foreach ((array) ($config['searchableTools'] ?? []) as $tool) {
            $deferNames[self::nameOf($tool)] = true;
        }

        return Middleware::create([
            'name' => 'ProviderToolSearch',
            'wrapModelCall' => static function (array $request, callable $handler) use ($deferNames): mixed {
                $tools = array_values((array) ($request['tools'] ?? []));

                // Fail fast if we try to defer a tool that is not bound to the model.
                if ($deferNames !== []) {
                    $available = [];
                    foreach ($tools as $tool) {
                        if (ToolUtils::isLangChainTool($tool)) {
                            $available[] = self::nameOf($tool);
                        }
                    }
                    $unknown = array_values(array_filter(
                        array_keys($deferNames),
                        static fn (string $name): bool => !\in_array($name, $available, true),
                    ));
                    if ($unknown !== []) {
                        throw new \Exception('providerToolSearchMiddleware: searchableTools references tool(s) not bound to the model: ' . implode(', ', $unknown));
                    }
                }

                $provider = self::modelProvider($request['model'] ?? null);
                if (!isset(self::SERVER_TOOL_SEARCH_TOOLS[$provider])) {
                    throw new \Exception("providerToolSearchMiddleware requires a provider with server-side tool search, but got {$provider}");
                }

                // Nothing to defer -> pass through.
                $hasDeferred = false;
                foreach ($tools as $tool) {
                    if (self::isDeferred($tool, $deferNames)) {
                        $hasDeferred = true;
                        break;
                    }
                }
                if (!$hasDeferred) {
                    return $handler($request);
                }

                $boundTools = array_map(
                    static fn (mixed $tool): mixed => self::isDeferred($tool, $deferNames) ? self::deferredSpec($tool, $provider) : $tool,
                    $tools,
                );

                return $handler([...$request, 'tools' => [...$boundTools, self::SERVER_TOOL_SEARCH_TOOLS[$provider]]]);
            },
        ]);
    }

    /** @param array<string, true> $deferNames */
    private static function isDeferred(mixed $tool, array $deferNames): bool
    {
        return ToolUtils::isLangChainTool($tool)
            && ((self::extrasOf($tool)['defer_loading'] ?? null) === true || isset($deferNames[self::nameOf($tool)]));
    }

    /**
     * The tool as the provider takes a deferred tool: its name, description and schema, flagged `defer_loading`.
     *
     * @return array<string, mixed>
     */
    private static function deferredSpec(mixed $tool, string $provider): array
    {
        $name = self::nameOf($tool);
        $description = self::descriptionOf($tool);
        $schema = self::jsonSchemaOf($tool);

        if ($provider === 'anthropic') {
            return ['name' => $name, 'description' => $description, 'input_schema' => $schema, 'defer_loading' => true];
        }

        return [
            'type' => 'function',
            'function' => ['name' => $name, 'description' => $description, 'parameters' => $schema],
            'defer_loading' => true,
        ];
    }

    /**
     * The provider behind a model, by the name it reports (`ChatAnthropic`, `ChatOpenAI`) or, for a
     * configurable model, the model it resolves to.
     */
    private static function modelProvider(mixed $model): string
    {
        if (!\is_object($model) || !method_exists($model, 'getName')) {
            return 'other';
        }

        $name = $model->getName();
        if ($model instanceof ConfigurableModelInterface) {
            $name = $model->getModelInstance()->getName();
        }

        return match ($name) {
            'ChatAnthropic' => 'anthropic',
            'ChatOpenAI' => 'openai',
            default => $name,
        };
    }

    /** @return array<string, mixed> */
    private static function extrasOf(mixed $tool): array
    {
        if (\is_object($tool) && property_exists($tool, 'extras')) {
            return (array) $tool->extras;
        }

        return \is_array($tool) ? (array) ($tool['extras'] ?? []) : [];
    }

    private static function nameOf(mixed $tool): string
    {
        if (\is_string($tool)) {
            return $tool;
        }
        if (\is_array($tool)) {
            return (string) ($tool['name'] ?? '');
        }

        return \is_object($tool) && property_exists($tool, 'name') ? (string) $tool->name : '';
    }

    private static function descriptionOf(mixed $tool): string
    {
        if (\is_array($tool)) {
            return (string) ($tool['description'] ?? '');
        }

        return \is_object($tool) && property_exists($tool, 'description') ? (string) $tool->description : '';
    }

    /** @return array<string, mixed> */
    private static function jsonSchemaOf(mixed $tool): array
    {
        $schema = \is_array($tool) ? ($tool['schema'] ?? null) : (\is_object($tool) && property_exists($tool, 'schema') ? $tool->schema : null);
        if (\is_object($schema) && method_exists($schema, 'toJsonSchema')) {
            return $schema->toJsonSchema();
        }

        return \is_array($schema) ? $schema : ['type' => 'object', 'properties' => []];
    }
}
