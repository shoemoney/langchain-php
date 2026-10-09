<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * A JSON Schema (2020-12 / draft-07) validator for MCP tool schemas.
 *
 * Stands in for the SDK's `fromJsonSchema` + `DefaultJsonSchemaValidator`, which the adapter uses
 * to validate arguments, structured output and elicitation answers against the schema the SERVER
 * sent. It validates the schema as written: nothing is rewritten, so `allOf`, `oneOf`, `if/then/else`,
 * `$ref`/`$defs` and `unevaluatedProperties` mean what the server meant them to mean.
 *
 * PHP has one array type, so `[]` is ambiguous between `{}` and `[]`: an empty array satisfies both
 * `object` and `array`, and any non-list array is an object.
 *
 * Messages follow Ajv's wording (`data/confirm must be boolean`), which is what the upstream tests
 * assert on. `format` is advisory, as in the spec, and ignored.
 */
final class JsonSchemaValidator
{
    private const MAX_DEPTH = 64;

    private function __construct()
    {
    }

    /**
     * @return list<array{path: list<int|string>, message: string}> empty when valid
     */
    public static function validate(mixed $value, mixed $schema): array
    {
        $issues = [];
        self::apply($value, $schema, $schema, [], $issues, 0);

        return $issues;
    }

    /**
     * @param list<int|string>                                              $path
     * @param list<array{path: list<int|string>, message: string}>         $issues
     *
     * @return list<string> the object keys this schema evaluated, for `unevaluatedProperties`
     */
    private static function apply(mixed $value, mixed $schema, mixed $root, array $path, array &$issues, int $depth): array
    {
        if ($schema === true || $schema === null || $schema === []) {
            return self::allKeys($value);
        }
        if ($schema === false) {
            self::fail($issues, $path, 'boolean schema is false');

            return [];
        }
        if (!is_array($schema) || $depth > self::MAX_DEPTH) {
            return [];
        }

        $evaluated = [];
        $apply = static function (mixed $v, mixed $s, array $p, array &$into) use ($root, $depth): array {
            return self::apply($v, $s, $root, $p, $into, $depth + 1);
        };

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $target = self::resolve($root, $schema['$ref']);
            if ($target === null) {
                self::fail($issues, $path, "can't resolve reference " . $schema['$ref']);
            } else {
                $evaluated = [...$evaluated, ...$apply($value, $target, $path, $issues)];
            }
        }

        if (array_key_exists('type', $schema)) {
            $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];
            $ok = false;
            foreach ($types as $type) {
                if (is_string($type) && self::isType($value, $type)) {
                    $ok = true;

                    break;
                }
            }
            if (!$ok) {
                self::fail($issues, $path, 'must be ' . implode(',', array_map('strval', $types)));

                return $evaluated;
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $hit = false;
            foreach ($schema['enum'] as $candidate) {
                if (self::equals($value, $candidate)) {
                    $hit = true;

                    break;
                }
            }
            if (!$hit) {
                self::fail($issues, $path, 'must be equal to one of the allowed values');
            }
        }
        if (array_key_exists('const', $schema) && !self::equals($value, $schema['const'])) {
            self::fail($issues, $path, 'must be equal to constant');
        }

        foreach (['allOf'] as $keyword) {
            if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
                foreach ($schema[$keyword] as $sub) {
                    $evaluated = [...$evaluated, ...$apply($value, $sub, $path, $issues)];
                }
            }
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $sub) {
                $probe = [];
                $keys = $apply($value, $sub, $path, $probe);
                if ($probe === []) {
                    $matched = true;
                    $evaluated = [...$evaluated, ...$keys];
                }
            }
            if (!$matched) {
                self::fail($issues, $path, 'must match a schema in anyOf');
            }
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $sub) {
                $probe = [];
                $keys = $apply($value, $sub, $path, $probe);
                if ($probe === []) {
                    ++$matches;
                    $evaluated = [...$evaluated, ...$keys];
                }
            }
            if ($matches !== 1) {
                self::fail($issues, $path, 'must match exactly one schema in oneOf');
            }
        }

        if (array_key_exists('not', $schema)) {
            $probe = [];
            $apply($value, $schema['not'], $path, $probe);
            if ($probe === []) {
                self::fail($issues, $path, 'must NOT be valid');
            }
        }

        if (array_key_exists('if', $schema)) {
            $probe = [];
            $keys = $apply($value, $schema['if'], $path, $probe);
            if ($probe === []) {
                $evaluated = [...$evaluated, ...$keys];
                if (array_key_exists('then', $schema)) {
                    $evaluated = [...$evaluated, ...$apply($value, $schema['then'], $path, $issues)];
                }
            } elseif (array_key_exists('else', $schema)) {
                $evaluated = [...$evaluated, ...$apply($value, $schema['else'], $path, $issues)];
            }
        }

        if (is_int($value) || is_float($value)) {
            self::numeric($value, $schema, $path, $issues);
        } elseif (is_string($value)) {
            self::string($value, $schema, $path, $issues);
        } elseif (is_array($value)) {
            if (self::isObject($value)) {
                $evaluated = [...$evaluated, ...self::object($value, $schema, $path, $issues, $apply)];
            }
            if (self::isArray($value)) {
                self::items($value, $schema, $path, $issues, $apply);
            }
        }

        // Needs every sibling and applicator to have reported what they evaluated, so it runs last.
        if (array_key_exists('unevaluatedProperties', $schema) && is_array($value) && self::isObject($value)) {
            $seen = array_flip(array_map('strval', $evaluated));
            foreach (array_keys($value) as $key) {
                if (isset($seen[(string) $key])) {
                    continue;
                }
                $sub = $schema['unevaluatedProperties'];
                if ($sub === false) {
                    self::fail($issues, $path, 'must NOT have unevaluated properties');
                } elseif ($sub !== true) {
                    $apply($value[$key], $sub, [...$path, $key], $issues);
                }
                $evaluated[] = (string) $key;
            }
        }

        return $evaluated;
    }

    /**
     * @param array<mixed>                                                  $value
     * @param array<string, mixed>                                          $schema
     * @param list<int|string>                                              $path
     * @param list<array{path: list<int|string>, message: string}>         $issues
     *
     * @return list<string>
     */
    private static function object(array $value, array $schema, array $path, array &$issues, \Closure $apply): array
    {
        $evaluated = [];

        if (isset($schema['required']) && is_array($schema['required'])) {
            foreach ($schema['required'] as $key) {
                if (!array_key_exists($key, $value) && !array_key_exists((string) $key, $value)) {
                    self::fail($issues, $path, "must have required property '" . (is_scalar($key) ? (string) $key : json_encode($key)) . "'");
                }
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $name => $sub) {
            if (array_key_exists($name, $value)) {
                $evaluated[] = (string) $name;
                $apply($value[$name], $sub, [...$path, $name], $issues);
            }
        }

        $patterns = is_array($schema['patternProperties'] ?? null) ? $schema['patternProperties'] : [];
        foreach ($patterns as $pattern => $sub) {
            foreach ($value as $key => $item) {
                if (self::matches((string) $pattern, (string) $key)) {
                    $evaluated[] = (string) $key;
                    $apply($item, $sub, [...$path, $key], $issues);
                }
            }
        }

        if (array_key_exists('additionalProperties', $schema)) {
            $extra = $schema['additionalProperties'];
            foreach ($value as $key => $item) {
                if (array_key_exists($key, $properties)) {
                    continue;
                }
                foreach (array_keys($patterns) as $pattern) {
                    if (self::matches((string) $pattern, (string) $key)) {
                        continue 2;
                    }
                }
                if ($extra === false) {
                    self::fail($issues, $path, 'must NOT have additional properties');
                } else {
                    $evaluated[] = (string) $key;
                    if ($extra !== true) {
                        $apply($item, $extra, [...$path, $key], $issues);
                    }
                }
            }
        }

        if (isset($schema['minProperties']) && count($value) < $schema['minProperties']) {
            self::fail($issues, $path, 'must NOT have fewer than ' . $schema['minProperties'] . ' properties');
        }
        if (isset($schema['maxProperties']) && count($value) > $schema['maxProperties']) {
            self::fail($issues, $path, 'must NOT have more than ' . $schema['maxProperties'] . ' properties');
        }

        return $evaluated;
    }

    /**
     * @param array<mixed>                                                  $value
     * @param array<string, mixed>                                          $schema
     * @param list<int|string>                                              $path
     * @param list<array{path: list<int|string>, message: string}>         $issues
     */
    private static function items(array $value, array $schema, array $path, array &$issues, \Closure $apply): void
    {
        if ($value === []) {
            if (isset($schema['minItems']) && $schema['minItems'] > 0) {
                self::fail($issues, $path, 'must NOT have fewer than ' . $schema['minItems'] . ' items');
            }

            return;
        }

        $hasPrefixItems = isset($schema['prefixItems']) && is_array($schema['prefixItems']);
        $itemsIsTuple = isset($schema['items']) && is_array($schema['items']) && array_is_list($schema['items']) && $schema['items'] !== [];
        $prefix = $hasPrefixItems ? $schema['prefixItems'] : ($itemsIsTuple ? $schema['items'] : []);
        $rest = $hasPrefixItems || !$itemsIsTuple ? ($schema['items'] ?? null) : ($schema['additionalItems'] ?? null);

        foreach ($value as $index => $item) {
            if (array_key_exists($index, $prefix)) {
                $apply($item, $prefix[$index], [...$path, $index], $issues);
            } elseif ($rest !== null) {
                $apply($item, $rest, [...$path, $index], $issues);
            }
        }

        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            self::fail($issues, $path, 'must NOT have fewer than ' . $schema['minItems'] . ' items');
        }
        if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            self::fail($issues, $path, 'must NOT have more than ' . $schema['maxItems'] . ' items');
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $seen = [];
            foreach ($value as $item) {
                $key = json_encode($item);
                if (isset($seen[$key])) {
                    self::fail($issues, $path, 'must NOT have duplicate items');

                    break;
                }
                $seen[$key] = true;
            }
        }
        if (array_key_exists('contains', $schema)) {
            $any = false;
            foreach ($value as $item) {
                $probe = [];
                $apply($item, $schema['contains'], $path, $probe);
                if ($probe === []) {
                    $any = true;

                    break;
                }
            }
            if (!$any) {
                self::fail($issues, $path, 'must contain at least 1 valid item(s)');
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int|string>     $path
     * @param list<array{path: list<int|string>, message: string}> $issues
     */
    private static function numeric(int|float $value, array $schema, array $path, array &$issues): void
    {
        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
            self::fail($issues, $path, 'must be >= ' . $schema['minimum']);
        }
        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum']) {
            self::fail($issues, $path, 'must be <= ' . $schema['maximum']);
        }
        if (isset($schema['exclusiveMinimum']) && is_numeric($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            self::fail($issues, $path, 'must be > ' . $schema['exclusiveMinimum']);
        }
        if (isset($schema['exclusiveMaximum']) && is_numeric($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            self::fail($issues, $path, 'must be < ' . $schema['exclusiveMaximum']);
        }
        if (isset($schema['multipleOf']) && is_numeric($schema['multipleOf']) && $schema['multipleOf'] > 0) {
            $quotient = $value / $schema['multipleOf'];
            if (abs($quotient - round($quotient)) > 1e-9) {
                self::fail($issues, $path, 'must be multiple of ' . $schema['multipleOf']);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<int|string>     $path
     * @param list<array{path: list<int|string>, message: string}> $issues
     */
    private static function string(string $value, array $schema, array $path, array &$issues): void
    {
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && $length < $schema['minLength']) {
            self::fail($issues, $path, 'must NOT have fewer than ' . $schema['minLength'] . ' characters');
        }
        if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
            self::fail($issues, $path, 'must NOT have more than ' . $schema['maxLength'] . ' characters');
        }
        if (isset($schema['pattern']) && is_string($schema['pattern']) && !self::matches($schema['pattern'], $value)) {
            self::fail($issues, $path, 'must match pattern "' . $schema['pattern'] . '"');
        }
    }

    private static function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'string' => is_string($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'array' => is_array($value) && self::isArray($value),
            'object' => is_array($value) && self::isObject($value),
            default => false,
        };
    }

    /** @param array<mixed> $value */
    private static function isObject(array $value): bool
    {
        return $value === [] || !array_is_list($value);
    }

    /** @param array<mixed> $value */
    private static function isArray(array $value): bool
    {
        return array_is_list($value);
    }

    /** @return list<string> */
    private static function allKeys(mixed $value): array
    {
        return is_array($value) && self::isObject($value) ? array_map('strval', array_keys($value)) : [];
    }

    /** JSON instance equality: numbers numerically, booleans never numbers, objects unordered. */
    private static function equals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            $listA = array_is_list($a);
            if ($listA !== array_is_list($b)) {
                return false;
            }
            foreach ($a as $key => $item) {
                if (!array_key_exists($key, $b) || !self::equals($item, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    private static function matches(string $pattern, string $subject): bool
    {
        return @preg_match('~' . str_replace('~', '\~', $pattern) . '~u', $subject) === 1;
    }

    private static function resolve(mixed $root, string $ref): mixed
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

        return $node;
    }

    /**
     * @param list<array{path: list<int|string>, message: string}> $issues
     * @param list<int|string>                                     $path
     */
    private static function fail(array &$issues, array $path, string $message): void
    {
        $pointer = '';
        foreach ($path as $segment) {
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment);
        }
        $issues[] = ['path' => $path, 'message' => 'data' . $pointer . ' ' . $message];
    }
}
