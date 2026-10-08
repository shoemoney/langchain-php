<?php

declare(strict_types=1);

namespace LangGraph\Agents\Responses;

use LangChain\Tools\Schema;
use LangGraph\Agents\Model;

/**
 * Builders and helpers for the `responseFormat` of an agent.
 *
 * Port of the free functions in `langchain/src/agents/responses.ts`: `toolStrategy`, `providerStrategy`,
 * `transformResponseFormat` and `hasSupportForJsonSchemaOutput`. They are static methods here because PHP
 * autoloads classes, not functions.
 *
 * A schema is a JSON Schema array, a {@see Schema} (upstream's Zod arm), or a Standard JSON Schema. A list of them
 * (or of strategies) is a list; a JSON Schema is a map, which is how the two are told apart.
 */
final class ResponseFormats
{
    private function __construct()
    {
    }

    /**
     * Creates a tool strategy for structured output using function calling.
     *
     * Configures structured output by converting schemas into function tools the model calls. Unlike
     * {@see self::providerStrategy()}, which uses native JSON schema support, it works with any model that supports
     * function calling. Pass a list of schemas and the model can choose which one to use.
     *
     * @param mixed                                                        $responseFormat a schema, or a list of schemas
     * @param array{toolMessageContent?: string, handleError?: mixed}|null $options        `handleError` is `true` (retry, the default), `false` (throw),
     *                                                                                      a string (retry with that message) or a callable receiving the error
     *                                                                                      and returning the message
     * @return list<ToolStrategy|ProviderStrategy>
     */
    public static function toolStrategy(mixed $responseFormat, ?array $options = null): array
    {
        return self::transformResponseFormat($responseFormat, $options);
    }

    /**
     * Creates a provider strategy for structured output using native JSON schema support.
     *
     * @param mixed $responseFormat a schema, or `['schema' => <schema>, 'strict' => ?bool]`
     */
    public static function providerStrategy(mixed $responseFormat): ProviderStrategy
    {
        // The options array form.
        if (\is_array($responseFormat)
            && \array_key_exists('schema', $responseFormat)
            && !self::isSerializableSchema($responseFormat)
            && !\array_key_exists('type', $responseFormat)
        ) {
            return ProviderStrategy::fromSchema($responseFormat['schema'], $responseFormat['strict'] ?? null);
        }

        return ProviderStrategy::fromSchema($responseFormat);
    }

    /**
     * Handle user input for the `responseFormat` parameter of an agent.
     *
     *  - a schema defaults to structured output via tool calling, or via the provider when the model supports it;
     *  - a strategy is returned as is;
     *  - a list must hold only strategies, or only schemas (each becomes a {@see ToolStrategy}).
     *
     * @param array{toolMessageContent?: string, handleError?: mixed}|null $options options for the tool strategy
     * @param mixed                                                        $model   the model, to check whether it supports JSON schema output
     * @return list<ToolStrategy|ProviderStrategy>
     */
    public static function transformResponseFormat(mixed $responseFormat = null, ?array $options = null, mixed $model = null): array
    {
        if ($responseFormat === null || $responseFormat === false) {
            return [];
        }

        if ($responseFormat instanceof ResponseFormatUndefined
            || (\is_array($responseFormat) && \array_key_exists('__responseFormatUndefined', $responseFormat))
        ) {
            return [];
        }

        if ($responseFormat instanceof ToolStrategy || $responseFormat instanceof ProviderStrategy) {
            return [$responseFormat];
        }

        // A list may only hold raw schemas or strategies.
        if (\is_array($responseFormat) && array_is_list($responseFormat)) {
            $isStrategy = static fn (mixed $item): bool => $item instanceof ToolStrategy || $item instanceof ProviderStrategy;

            if (self::every($responseFormat, $isStrategy)) {
                return $responseFormat;
            }

            if (self::every($responseFormat, static fn (mixed $item): bool => self::isSerializableSchema($item) || $item instanceof Schema)) {
                return array_map(static fn (mixed $item): ToolStrategy => ToolStrategy::fromSchema($item, $options), $responseFormat);
            }

            // Plain JSON schema arrays.
            if (self::every($responseFormat, static fn (mixed $item): bool => \is_array($item))) {
                return array_map(static fn (mixed $item): ToolStrategy => ToolStrategy::fromSchema($item, $options), $responseFormat);
            }

            throw new \Exception(
                "Invalid response format: list contains mixed types.\n"
                . 'All items must be either Schema, Standard Schema, or plain JSON schema arrays.'
            );
        }

        $useProviderStrategy = self::hasSupportForJsonSchemaOutput($model);

        if (self::isSerializableSchema($responseFormat)
            || $responseFormat instanceof Schema
            || (\is_array($responseFormat) && \array_key_exists('properties', $responseFormat))
        ) {
            return $useProviderStrategy
                ? [ProviderStrategy::fromSchema($responseFormat)]
                : [ToolStrategy::fromSchema($responseFormat, $options)];
        }

        throw new \Exception('Invalid response format: ' . (\is_scalar($responseFormat) ? (string) $responseFormat : get_debug_type($responseFormat)));
    }

    /**
     * Identifies the models that support JSON schema output by reading the model's profile metadata.
     *
     * @param mixed $model a resolved model instance. Callers should resolve string model names and configurable
     *                     model wrappers before calling this.
     */
    public static function hasSupportForJsonSchemaOutput(mixed $model = null): bool
    {
        if ($model === null || !Model::isBaseChatModel($model)) {
            return false;
        }

        $profile = null;
        if (method_exists($model, 'profile')) {
            $profile = $model->profile();
        } elseif (property_exists($model, 'profile')) {
            $profile = $model->profile;
        }

        return \is_array($profile) && ($profile['structuredOutput'] ?? null) === true;
    }

    /**
     * Whether a value is a Standard JSON Schema: `['~standard' => ['jsonSchema' => ['input' => callable]]]`.
     */
    public static function isSerializableSchema(mixed $schema): bool
    {
        if (\is_array($schema)) {
            $standard = $schema['~standard'] ?? null;
        } elseif (\is_object($schema) && !$schema instanceof Schema) {
            $standard = $schema->{'~standard'} ?? null;
        } else {
            return false;
        }

        $standard = \is_object($standard) ? (array) $standard : $standard;
        $jsonSchema = \is_array($standard) ? ($standard['jsonSchema'] ?? null) : null;
        $jsonSchema = \is_object($jsonSchema) ? (array) $jsonSchema : $jsonSchema;

        return \is_array($jsonSchema) && \is_callable($jsonSchema['input'] ?? null);
    }

    /**
     * @param array<int, mixed>   $items
     * @param callable(mixed): bool $predicate
     */
    private static function every(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }

        return true;
    }
}
