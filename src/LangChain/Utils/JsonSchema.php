<?php

declare(strict_types=1);

namespace LangChain\Utils;

use LangChain\Tools\Schema;

/**
 * Normalising schemas to JSON Schema, and inspecting JSON Schemas.
 *
 * Port of `@langchain/core/utils/json_schema`.
 *
 * Upstream's `toJsonSchema` has three arms: Zod v4, Zod v3 and "a Standard JSON
 * Schema". The Zod arms are out of scope (this port is JSON-Schema-native, see
 * {@see Schema}), so what remains is the arm that matters in PHP:
 *
 *   - a {@see Schema} -> its JSON Schema,
 *   - a Standard JSON Schema (`['~standard' => ['jsonSchema' => ['input' => fn]]]`,
 *     as an array or an object with a `~standard` property) -> `input(['target' => 'draft-07'])`,
 *   - anything else -> assumed to already be JSON Schema and returned as is.
 *
 * The `@cfworker/json-schema` re-exports (`Validator`, `deepCompareStrict`) are
 * not ported: validation lives in `OutputParsers\JsonSchemaValidator`.
 */
final class JsonSchema
{
    /** @var \WeakMap<object, array<string, mixed>>|null */
    private static ?\WeakMap $cache = null;

    private function __construct()
    {
    }

    /**
     * Convert a schema to JSON Schema.
     *
     * Results are cached per schema *object* when no `$params` are passed, which
     * is the tool-binding hot path: the same `tool.schema` is converted on every
     * model call, and a conversion can be an arbitrary callback. Arrays have no
     * identity in PHP and are already JSON Schema, so they need no cache.
     * `$params` can change the output (a different draft), so passing any
     * bypasses the cache.
     *
     * @param Schema|array<string, mixed>|object $schema
     * @param array<string, mixed>|null          $params
     *
     * @return array<string, mixed>
     */
    public static function toJsonSchema(mixed $schema, ?array $params = null): array
    {
        $cacheable = $params === null && is_object($schema);

        if ($cacheable) {
            self::$cache ??= new \WeakMap();
            if (isset(self::$cache[$schema])) {
                return self::$cache[$schema];
            }
        }

        $standard = self::standardJsonSchemaInput($schema);
        if ($standard !== null) {
            $result = $standard(['target' => 'draft-07']);
        } elseif ($schema instanceof Schema) {
            $result = $schema->toJsonSchema();
        } else {
            $result = $schema;
        }

        if ($cacheable) {
            self::$cache[$schema] = $result;
        }

        return $result;
    }

    /**
     * Whether a JSON Schema validates only strings.
     *
     * May return false negatives in edge cases (recursive or unresolvable refs).
     */
    public static function validatesOnlyStrings(mixed $schema): bool
    {
        if (!is_array($schema) || $schema === [] || Js::isList($schema)) {
            return false;
        }

        if (array_key_exists('type', $schema)) {
            if (is_string($schema['type'])) {
                return $schema['type'] === 'string';
            }
            if (is_array($schema['type'])) {
                foreach ($schema['type'] as $t) {
                    if ($t !== 'string') {
                        return false;
                    }
                }

                return true;
            }

            return false;
        }

        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum']) || $schema['enum'] === []) {
                return false;
            }
            foreach ($schema['enum'] as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }

            return true;
        }

        if (array_key_exists('const', $schema)) {
            return is_string($schema['const']);
        }

        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $subschema) {
                if (self::validatesOnlyStrings($subschema)) {
                    return true;
                }
            }

            return false;
        }

        if ((isset($schema['anyOf']) && is_array($schema['anyOf'])) || (isset($schema['oneOf']) && is_array($schema['oneOf']))) {
            $subschemas = isset($schema['anyOf']) ? $schema['anyOf'] : $schema['oneOf'];
            if (!is_array($subschemas) || $subschemas === []) {
                return false;
            }
            foreach ($subschemas as $subschema) {
                if (!self::validatesOnlyStrings($subschema)) {
                    return false;
                }
            }

            return true;
        }

        // Too complex to reason about: a `not` can admit non-strings.
        if (array_key_exists('not', $schema)) {
            return false;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = self::resolveLocalRef($schema, $schema['$ref']);

            return $resolved !== null && self::validatesOnlyStrings($resolved);
        }

        return false;
    }

    /**
     * @return (callable(array<string, mixed>): array<string, mixed>)|null
     */
    private static function standardJsonSchemaInput(mixed $schema): ?callable
    {
        $standard = null;
        if (is_array($schema)) {
            $standard = $schema['~standard'] ?? null;
        } elseif (is_object($schema) && !($schema instanceof Schema)) {
            $standard = $schema->{'~standard'} ?? null;
        }

        if (is_object($standard)) {
            $standard = (array) $standard;
        }
        if (!is_array($standard)) {
            return null;
        }

        $jsonSchema = $standard['jsonSchema'] ?? null;
        if (is_object($jsonSchema)) {
            $jsonSchema = (array) $jsonSchema;
        }
        $input = is_array($jsonSchema) ? ($jsonSchema['input'] ?? null) : null;

        return is_callable($input) ? $input : null;
    }

    /**
     * Resolve a `#/...` JSON pointer against the schema that contains the ref.
     *
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>|null
     */
    private static function resolveLocalRef(array $root, string $ref): ?array
    {
        if ($ref === '#') {
            return $root;
        }
        if (!str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_array($node) ? $node : null;
    }
}
