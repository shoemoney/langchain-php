<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangGraph\Agents\Utils as AgentUtils;
use LangGraph\Pregel\Constants;
use LangGraph\State\AnnotationRoot;

/**
 * Helpers for the agent's graph nodes.
 *
 * Port of `langchain/src/agents/nodes/utils.ts`.
 *
 * Upstream manipulates Zod objects; this port is JSON-Schema-native, so a "state schema" is an
 * {@see AnnotationRoot} or a JSON Schema array, and the functions that return a Zod object return a JSON
 * Schema array instead (`interopZodObjectPartial` and friends have no PHP counterpart). A middleware is
 * an array or object with `name` and optionally `stateSchema`.
 */
final class Utils
{
    private function __construct()
    {
    }

    /**
     * Initialize the state of every middleware that declares a state schema.
     *
     * Property defaults (`default` in the JSON Schema) are applied, unknown keys are stripped, and
     * underscore-prefixed (private) properties are made optional since a caller cannot provide them when
     * invoking the agent. Middleware without a schema is skipped. The merged result is returned.
     *
     * @param iterable<array<string, mixed>|object> $middlewareList
     * @return array<string, mixed>
     */
    public static function initializeMiddlewareStates(iterable $middlewareList, mixed $state): array
    {
        $input = \is_array($state) ? $state : [];
        $middlewareStates = [];

        foreach ($middlewareList as $middleware) {
            $stateSchema = AgentUtils::middlewareValue($middleware, 'stateSchema');
            // Skip middleware without a usable state schema.
            if ($stateSchema === null || AgentUtils::schemaKeys($stateSchema) === null) {
                continue;
            }

            $schema = self::toJsonSchema($stateSchema);
            $properties = (array) ($schema['properties'] ?? []);
            $required = array_values(array_filter(
                array_map('strval', (array) ($schema['required'] ?? [])),
                static fn (string $key): bool => !str_starts_with($key, '_'),
            ));

            $parsed = [];
            $missing = [];
            foreach ($properties as $key => $property) {
                $key = (string) $key;
                if (\array_key_exists($key, $input)) {
                    $parsed[$key] = $input[$key];
                } elseif (\is_array($property) && \array_key_exists('default', $property)) {
                    $parsed[$key] = $property['default'];
                } elseif (\in_array($key, $required, true)) {
                    $missing[] = $key;
                }
            }

            if ($missing === []) {
                $middlewareStates = [...$middlewareStates, ...$parsed];

                continue;
            }

            // Required public fields are missing.
            $requiredFields = implode("\n", array_map(static fn (string $key): string => '  - ' . $key . ': Required', $missing));

            throw new \Exception(
                'Middleware "' . AgentUtils::middlewareName($middleware) . "\" has required state fields that must be initialized:\n"
                . $requiredFields . "\n\n"
                . "To fix this, either:\n"
                . "1. Provide default values in your middleware's state schema using \"default\":\n"
                . "   ['properties' => ['myField' => ['type' => 'string', 'default' => 'default value']]]\n\n"
                . "2. Or leave the fields out of \"required\" so they are optional\n\n"
                . "3. Or ensure you pass these values when invoking the agent:\n"
                . "   \$agent->invoke(['messages' => [...], '" . $missing[0] . "' => 'value'])",
            );
        }

        return $middlewareStates;
    }

    /**
     * The schema of a middleware's private state: its underscore-prefixed properties made optional,
     * alongside the built-in `messages` and `structuredResponse` (so after-agent hooks can read and
     * modify it).
     *
     * Port of `derivePrivateState`. Like upstream, every property of the given schema is kept, and only
     * the private ones are made optional.
     *
     * @return array<string, mixed> a JSON Schema
     */
    public static function derivePrivateState(AnnotationRoot|array|null $stateSchema = null): array
    {
        $properties = ['messages' => [], 'structuredResponse' => []];
        $required = ['messages'];

        $keys = $stateSchema === null ? null : AgentUtils::schemaKeys($stateSchema);
        if ($keys === null) {
            return ['type' => 'object', 'properties' => $properties, 'required' => $required];
        }

        $shape = self::getSchemaShape($stateSchema);
        $originalRequired = \is_array($stateSchema) ? array_map('strval', (array) ($stateSchema['required'] ?? [])) : [];

        foreach ($shape as $key => $property) {
            $properties[$key] = $property;
            if (!str_starts_with($key, '_') && \in_array($key, $originalRequired, true)) {
                $required[] = $key;
            }
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_values(array_unique($required))];
    }

    /**
     * Convert any supported schema (JSON Schema array, AnnotationRoot) to a partial JSON Schema in which
     * every field is optional. Useful for parsing state loosely.
     *
     * Port of `toPartialZodObject`; anything that is not a schema yields an empty object schema.
     *
     * @return array<string, mixed>
     */
    public static function toPartialSchema(mixed $schema): array
    {
        $properties = AgentUtils::schemaKeys($schema) === null ? [] : self::getSchemaShape($schema);

        return ['type' => 'object', 'properties' => $properties, 'required' => []];
    }

    /**
     * The property map of a schema (upstream: `getInteropZodObjectShape`).
     *
     * @return array<string, mixed>
     */
    public static function getSchemaShape(mixed $schema): array
    {
        if (AnnotationRoot::isInstance($schema)) {
            return array_map(static fn (): array => [], $schema->spec);
        }
        if (\is_array($schema) && \is_array($schema['properties'] ?? null)) {
            $shape = [];
            foreach ($schema['properties'] as $key => $property) {
                $shape[(string) $key] = $property;
            }

            return $shape;
        }

        return [];
    }

    /**
     * Parse a `jumpTo` target from user facing labels to a LangGraph node name.
     *
     * @throws \InvalidArgumentException for anything but `model`, `tools`, `end` or a node name
     */
    public static function parseJumpToTarget(?string $target = null): ?string
    {
        if ($target === null || $target === '') {
            return null;
        }

        // Already a valid jump target.
        if (\in_array($target, ['model_request', 'tools', Constants::END], true)) {
            return $target;
        }

        return match ($target) {
            'model' => 'model_request',
            'end' => Constants::END,
            default => throw new \InvalidArgumentException(
                sprintf('Invalid jump target: %s, must be "model", "tools" or "end".', $target),
            ),
        };
    }

    /**
     * Merge abort signals into one.
     *
     * Port of `mergeAbortSignals`, with the port's abort convention (see `AsyncCaller`): a signal is a
     * callable that returns `true` (or a `\Throwable`) once aborted, or an object with a boolean
     * `aborted` property. Anything else is ignored, as upstream ignores non-signals. The result is a
     * callable that reports the first aborted signal, so it can be handed straight to `RunnableConfig`.
     *
     * @return callable(): mixed
     */
    public static function mergeAbortSignals(mixed ...$signals): callable
    {
        $live = array_values(array_filter(
            $signals,
            static fn (mixed $signal): bool => self::isSignal($signal),
        ));

        return static function () use ($live): mixed {
            foreach ($live as $signal) {
                $reason = self::signalReason($signal);
                if ($reason !== false) {
                    return $reason;
                }
            }

            return false;
        };
    }

    /** Whether a signal (callable or `aborted` object) has fired. */
    public static function isAborted(mixed $signal): bool
    {
        return self::isSignal($signal) && self::signalReason($signal) !== false;
    }

    private static function isSignal(mixed $signal): bool
    {
        return \is_callable($signal) || (\is_object($signal) && property_exists($signal, 'aborted') && \is_bool($signal->aborted));
    }

    /** `false` while live; `true` or the throwable reason once aborted. */
    private static function signalReason(mixed $signal): mixed
    {
        if (\is_callable($signal)) {
            $reason = $signal();

            return $reason instanceof \Throwable || $reason === true ? $reason : false;
        }

        return $signal->aborted ? true : false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toJsonSchema(mixed $schema): array
    {
        if (AnnotationRoot::isInstance($schema)) {
            return ['type' => 'object', 'properties' => self::getSchemaShape($schema), 'required' => []];
        }

        return (array) $schema;
    }
}
