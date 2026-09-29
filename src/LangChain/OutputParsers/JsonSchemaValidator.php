<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Utils\Js;

/**
 * Validates a decoded JSON value against a JSON Schema.
 *
 * PHP has no equivalent of the Zod schema object the TypeScript original takes,
 * so this port takes the JSON Schema itself — which is the form the parser's
 * prompt instructions were going to print anyway, and the form every model is
 * finally being asked to satisfy.
 *
 * The subset implemented is the subset a structured-output parser actually
 * needs: `type`, `properties`, `required`, `items`, `enum`, `const`,
 * `additionalProperties: false`, `anyOf`, and a `"null"` entry in `type` for
 * optionality. Formats (`date-time` and friends) are advisory and ignored, as
 * they are in JSON Schema itself.
 *
 * @internal
 */
final class JsonSchemaValidator
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null $schema
     * @return list<string> Human-readable problems; empty when the value is valid.
     */
    public static function validate(mixed $value, ?array $schema): array
    {
        if ($schema === null || $schema === []) {
            return [];
        }

        $errors = [];
        self::check($value, $schema, '$', $errors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (is_array($option) && self::validate($value, $option) === []) {
                    return;
                }
            }
            $errors[] = "{$path}: does not match any of the allowed schemas";

            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = "{$path}: expected the constant " . json_encode($schema['const']);

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if (!in_array($value, $schema['enum'], true)) {
                $errors[] = "{$path}: expected one of " . json_encode($schema['enum']);

                return;
            }
        }

        $type = $schema['type'] ?? null;
        if ($type === null) {
            return;
        }

        $types = is_array($type) ? $type : [$type];
        $nullable = false;
        $matched = null;
        foreach ($types as $t) {
            if ($t === 'null') {
                $nullable = true;
                continue;
            }
            if (self::matchesType($value, (string) $t)) {
                $matched = (string) $t;

                break;
            }
        }

        // A matching container type still has to be walked: `type: object` says
        // nothing about which properties are required or how they are typed.
        if ($matched !== null) {
            if ($matched === 'object') {
                self::checkObject($value, $schema, $path, $errors);
            } elseif ($matched === 'array') {
                self::checkArray($value, $schema, $path, $errors);
            }

            return;
        }

        if ($nullable && $value === null) {
            return;
        }

        $errors[] = "{$path}: expected type " . implode(' | ', $types) . ', got ' . Js::typeOf($value);
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && !Js::isList($value),
            'array' => is_array($value) && Js::isList($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function checkObject(mixed $value, array $schema, string $path, array &$errors): void
    {
        /** @var array<string, mixed> $object */
        $object = $value;

        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists((string) $required, $object)) {
                $errors[] = "{$path}: missing required property '{$required}'";
            }
        }

        $properties = $schema['properties'] ?? [];
        if (is_array($properties)) {
            foreach ($properties as $name => $sub) {
                if (array_key_exists((string) $name, $object) && is_array($sub)) {
                    self::check($object[$name], $sub, "{$path}.{$name}", $errors);
                }
            }
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            foreach (array_keys($object) as $name) {
                if (!is_array($properties) || !array_key_exists((string) $name, $properties)) {
                    $errors[] = "{$path}: unexpected property '{$name}'";
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $errors
     */
    private static function checkArray(mixed $value, array $schema, string $path, array &$errors): void
    {
        /** @var list<mixed> $list */
        $list = $value;
        $items = $schema['items'] ?? null;
        if (!is_array($items)) {
            return;
        }

        foreach ($list as $i => $item) {
            self::check($item, $items, "{$path}[{$i}]", $errors);
        }
    }
}
