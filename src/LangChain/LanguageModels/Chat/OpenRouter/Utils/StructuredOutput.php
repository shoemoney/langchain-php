<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

/**
 * Picks the structured-output strategy for an OpenRouter model.
 *
 * Port of `utils/structured_output.ts` from `@langchain/openrouter`.
 *
 * The three strategies:
 *
 *  - `jsonSchema`: native JSON Schema response format (only models that
 *    advertise `structuredOutput` in their profile support this).
 *  - `functionCalling`: wraps the schema as a tool/function call and parses
 *    the tool output. Works on any model that supports tools.
 *  - `jsonMode`: asks the model to respond in JSON without a strict schema
 *    constraint (`response_format: {type: "json_object"}`).
 */
final class StructuredOutput
{
    public const SUPPORTED_METHODS = ['jsonSchema', 'functionCalling', 'jsonMode'];

    private function __construct()
    {
    }

    /**
     * Determines which structured-output strategy to use for a given model and
     * caller configuration.
     *
     * Resolution order:
     *  1. An explicit method is validated and returned (throws if unsupported
     *     or incompatible with the model).
     *  2. If OpenRouter routing is active (a multi-model `models` list or
     *     `route: "fallback"`), `functionCalling`, because the backend model,
     *     and so its capabilities, is unknown at request time.
     *  3. Otherwise `jsonSchema` when the profile advertises native structured
     *     output, else `functionCalling`.
     *
     * @param array{model: string, method?: mixed, profile?: array<string, mixed>, models?: list<string>|null, route?: string|null} $params
     */
    public static function resolveOpenRouterStructuredOutputMethod(array $params): string
    {
        $model = $params['model'];
        $method = $params['method'] ?? null;
        $profile = $params['profile'] ?? [];
        $models = $params['models'] ?? null;
        $route = $params['route'] ?? null;

        if ($method !== null && !in_array($method, self::SUPPORTED_METHODS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid structured output method: %s. Supported methods are: %s',
                is_scalar($method) ? (string) $method : get_debug_type($method),
                implode(', ', self::SUPPORTED_METHODS),
            ));
        }

        $supportsStructuredOutput = ($profile['structuredOutput'] ?? null) === true;

        if ($method === 'jsonSchema' && !$supportsStructuredOutput) {
            throw new \InvalidArgumentException(
                "Structured output method \"jsonSchema\" is not supported for model \"{$model}\". Use \"functionCalling\" or \"jsonMode\" instead."
            );
        }

        if ($method !== null) {
            return $method;
        }

        $hasRoutedModelSelection = $route === 'fallback' || count($models ?? []) > 0;

        if ($hasRoutedModelSelection) {
            return 'functionCalling';
        }

        return $supportsStructuredOutput ? 'jsonSchema' : 'functionCalling';
    }
}
