<?php

declare(strict_types=1);

namespace LangChain\Tools;

/**
 * A tool's input schema, in the form the port can validate against.
 *
 * ## Why JSON Schema rather than Zod
 *
 * The TypeScript original accepts either a Zod schema or a raw JSON Schema, and
 * validates with Zod's `parse()` in the first case and `@cfworker/json-schema`
 * in the second. PHP has no Zod, and the semantics that actually matter here —
 * check the arguments, reject the call when they do not fit — are expressible in
 * JSON Schema alone.
 *
 * So this port takes JSON Schema as the single representation. That is not a
 * narrowing of behaviour so much as a relocation: the *observable* contract
 * (`invoke()` with bad args throws {@see ToolException}, with good args reaches
 * the body) holds identically. What is lost is Zod's transforms — a schema that
 * renamed a key or coerced `"3"` to `3` on the way through. A tool that needs
 * coercion should do it at the top of its body, where the intent is visible.
 *
 * The subset implemented is the subset a tool argument schema uses: `type`,
 * `properties`, `required`, `items`, `enum`, `const`, `anyOf`, and
 * `additionalProperties: false`. Formats are advisory and ignored, exactly as
 * JSON Schema itself specifies.
 */
final class Schema
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        public readonly array $schema,
    ) {
    }

    /**
     * A schema accepting an optional single string under `input`.
     *
     * The shape every {@see Tool} validates against. Exposed as a factory
     * because {@see Tool} has to rebuild it after its parent constructor has
     * already stored the caller's schema.
     */
    public static function stringInput(): self
    {
        return new self([
            'type' => 'object',
            'properties' => ['input' => ['type' => 'string']],
        ]);
    }

    /**
     * A schema that accepts anything — a tool with no declared arguments.
     */
    public static function any(): self
    {
        return new self([]);
    }

    /**
     * A single string argument.
     */
    public static function string(): self
    {
        return new self(['type' => 'string']);
    }

    /**
     * An object with the given required string properties.
     *
     * @param array<string, mixed> $properties
     * @param list<string>         $required
     */
    public static function object(array $properties, array $required = []): self
    {
        return new self([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ]);
    }

    /**
     * Coerce a caller-supplied schema into a {@see self}.
     *
     * Accepts an already-built schema, a bare JSON Schema array, or null (which
     * means "any"). Rejecting anything else is deliberate: a schema this class
     * cannot validate against would silently become a no-op, and a tool that
     * accepts anything because its schema was mistyped is worse than one that
     * fails loudly at construction.
     */
    public static function from(mixed $schema): self
    {
        if ($schema instanceof self) {
            return $schema;
        }
        if ($schema === null || $schema === []) {
            return self::any();
        }
        if (is_array($schema)) {
            return new self($schema);
        }

        throw new \InvalidArgumentException(
            'Tool schema must be a Schema, a JSON Schema array, or null; got ' . get_debug_type($schema) . '.',
        );
    }

    /**
     * Validate a value, throwing {@see ToolException} when it does not fit.
     *
     * The raw input is attached to the exception because the caller needs to
     * report *what the model sent* back to the model — a re-serialised version
     * of the same value is not the same thing to debug, and for a malformed
     * payload the difference is the whole point.
     *
     * `$verbose` appends the per-field problems to the message. It is off by
     * default because the detail is noise in a production context: the message
     * goes back into the model's context on a retry, and a wall of JSON about
     * its own mistake mostly just burns tokens.
     *
     * @throws ToolException
     */
    public function validate(mixed $value, mixed $rawArg = null, bool $verbose = false): void
    {
        $errors = $this->errors($value);
        if ($errors === []) {
            return;
        }

        $message = 'Received tool input did not match expected schema';
        if ($verbose) {
            $encoded = json_encode($errors, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
            $message .= "\nDetails: " . ($encoded === false ? print_r($errors, true) : $encoded);
        }

        throw new ToolException($message, self::describeRaw($rawArg ?? $value));
    }

    /**
     * The list of problems with a value; empty when it is valid.
     *
     * @return list<array<string, mixed>>
     */
    public function errors(mixed $value): array
    {
        if ($this->schema === []) {
            return [];
        }

        $errors = [];
        $this->check($value, (array) self::unwrap($this->schema), '$', $errors);

        return $errors;
    }

    /**
     * The JSON Schema this tool's arguments are described by.
     *
     * This is what gets sent to a model, so an empty schema is reported as an
     * empty object rather than as `{}` — a model asked to satisfy `{}` will
     * invent fields.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        return $this->schema === []
            ? ['type' => 'object', 'properties' => []]
            : (array) self::unwrap($this->schema);
    }

    /**
     * Replace every nested {@see self} with its underlying JSON Schema array.
     *
     * `Schema::string()` returns a Schema, so `Schema::object(['a' =>
     * Schema::string()])` is the natural spelling — but the property was then a
     * Schema OBJECT inside the array, and neither consumer noticed:
     *
     *   * `toJsonSchema()` emitted `{"a":{"schema":{"type":"string"}}}`, a shape
     *     no model can read, so the tool described no argument type at all.
     *   * `errors()` looked for `$prop['type']`, found nothing, and reported no
     *     error — so a string argument silently accepted `1` and `null`.
     *
     * That second one fails OPEN: the schema looked declared, the tool accepted
     * input it was supposed to reject, and the tests all passed because they
     * only ever built properties as plain arrays. Recursion is depth-agnostic
     * because `object()` accepts a whole subtree, not one leaf.
     *
     * Anything that is neither a Schema nor an array is passed through
     * untouched, so a type union like `['string', 'null']` survives.
     *
     * @return array<mixed>|mixed
     */
    private static function unwrap(mixed $node): mixed
    {
        if ($node instanceof self) {
            return self::unwrap($node->schema);
        }
        if (!is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = self::unwrap($value);
        }

        return $out;
    }

    /**
     * Whether this schema constrains the value to a string.
     *
     * Drives the one behavioural fork in {@see tool()}: a tool whose arguments
     * are a bare string gets the simpler `DynamicTool`, which skips the object
     * wrapping entirely. Getting this wrong is not cosmetic — a string-schema
     * tool routed through the structured path receives `{input: "..."}` when it
     * declared it wanted `"..."`.
     */
    public function validatesOnlyStrings(): bool
    {
        $schema = $this->schema;
        if ($schema === []) {
            return false;
        }

        if (isset($schema['type'])) {
            $type = $schema['type'];
            if (is_string($type)) {
                return $type === 'string';
            }
            if (is_array($type)) {
                // Written as a loop, not `array_all()`. That function landed in
                // PHP 8.4, and this package declares `php: >=8.2` with a CI
                // matrix of 8.2 / 8.3 / 8.4 — so it was a hard fatal on two of
                // the three supported runtimes, invisible locally because the
                // development box runs 8.5.
                if ($type === []) {
                    return false;
                }

                foreach ($type as $single) {
                    if ($single !== 'string') {
                        return false;
                    }
                }

                return true;
            }

            return false;
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            if ($schema['enum'] === []) {
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
            foreach ($schema['allOf'] as $option) {
                if (is_array($option) && !(new self($option))->validatesOnlyStrings()) {
                    return false;
                }
            }

            return true;
        }

        // `anyOf` is handled here for the same reason `allOf` is: the schema
        // VALIDATES a bare string through this spelling, so a tool declaring it
        // must be routed down the string path too. Without this, a tool whose
        // schema is `{"anyOf": [{"type": "string"}]}` passed validation for a
        // bare string and was then handed the structured path, where the value
        // arrives as `{"input": "..."}` instead — a silent shape change for the
        // tool body.
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            $options = array_values(array_filter($schema['anyOf'], 'is_array'));
            if ($options === []) {
                return false;
            }

            foreach ($options as $option) {
                if ((new self($option))->validatesOnlyStrings()) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * The one-line description passed to a model when a tool has none.
     */
    public function description(): ?string
    {
        return isset($this->schema['description']) && is_string($this->schema['description'])
            ? $this->schema['description']
            : null;
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<array<string, mixed>> $errors
     */
    private function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        // `allOf` is a conjunction: EVERY subschema must hold. It was absent
        // here altogether, so a tool declared `{"allOf": [{...required...}]}`
        // validated nothing at all — arguments violating it reached the body.
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $option) {
                if (is_array($option)) {
                    $this->check($value, $option, $path, $errors);
                }
            }

        // FALL THROUGH rather than returning. `allOf` is a conjunction WITH its
        // siblings, not a replacement for them, so once the clauses hold the
        // sibling keywords must still be applied.
        //
        // Measured with
        //   {type: object, properties: {a: {type: string}}, allOf: [{required: [b]}]}
        // on {"a": 1, "b": "y"}: ACCEPTED, with `a` declared a string and
        // holding an integer. The conjunction passed and the sibling
        // `properties` check never ran — a fail-open, where the schema looks
        // declared and the constraint is skipped entirely.
        //
        // Only allOf falls through. `anyOf` legitimately short-circuits: it is a
        // disjunction, so once one branch holds there is nothing left to decide.
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $option) {
                if (is_array($option) && (new self($option))->errors($value) === []) {
                    return;
                }
            }
            $errors[] = ['path' => $path, 'message' => 'does not match any of the allowed schemas'];

            return;
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = [
                'path' => $path,
                'message' => 'expected the constant ' . json_encode($schema['const']),
            ];

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            $errors[] = [
                'path' => $path,
                'message' => 'expected one of ' . json_encode($schema['enum']),
            ];

            return;
        }

        $type = $schema['type'] ?? null;
        if ($type === null) {
            // `properties`, `required`, `items` and `additionalProperties` are
            // independent of `type` in JSON Schema — `{"properties": {"a":
            // {"type": "string"}}}` constrains `a` with no `type` at the top.
            // Returning here skipped every one of them, so those constraints
            // were silently unenforced.
            $this->checkSub($value, $schema, $path, $errors);

            return;
        }

        $types = is_array($type) ? $type : [$type];

        // An EMPTY argument object is ambiguous in PHP and not in JSON.
        // `"arguments": "{}"` decodes to `[]`, which `Js::isList()` calls a
        // list, so `matchesType([], 'object')` was false and the type check
        // errored with "expected object, got array" — BEFORE the
        // `checkSub()` disambiguation below, which exists precisely to resolve
        // this and would have accepted it.
        //
        // Measured: a tool declared `Schema::object(['q' => Schema::string()])`
        // with nothing required, invoked with no arguments, threw
        // `ToolException: Received tool input did not match expected schema`. A
        // zero-argument tool could never be called, and models really do send
        // `{}`.
        //
        // So the ambiguity is resolved HERE as well as in `checkSub()`: when the
        // value is `[]` and the schema declares object keywords, `object` is
        // accepted on the same terms `checkSub()` already uses.
        $emptyIsObject = $value === [] && (
            array_key_exists('properties', $schema)
            || array_key_exists('required', $schema)
            || array_key_exists('additionalProperties', $schema)
        );

        foreach ($types as $candidate) {
            if ($candidate === 'object' && $emptyIsObject) {
                $this->checkSub($value, $schema, $path, $errors);

                return;
            }
            if (is_string($candidate) && self::matchesType($value, $candidate)) {
                $this->checkSub($value, $schema, $path, $errors);

                return;
            }
        }

        $errors[] = [
            'path' => $path,
            'message' => 'expected ' . implode(' or ', array_map(static fn (mixed $t): string => (string) $t, $types))
                . ', got ' . self::describeType($value),
        ];
    }

    /**
     * @param array<string, mixed>        $schema
     * @param list<array<string, mixed>>  $errors
     */
    private function checkSub(mixed $value, array $schema, string $path, array &$errors): void
    {
        // An EMPTY array is a list as far as PHP is concerned, but a schema that
        // declares `properties`, `required` or `additionalProperties` is talking
        // about an OBJECT — and `{}` arrives from json_decode as exactly this
        // same empty array. Taking the list reading there would skip `required`
        // entirely, so an empty argument list would satisfy a schema that
        // demands a key. A non-empty list is never an object, so this only
        // widens the genuinely ambiguous case.
        $declaresObject = array_key_exists('properties', $schema)
            || array_key_exists('required', $schema)
            || array_key_exists('additionalProperties', $schema);

        if (is_array($value) && (!\LangChain\Utils\Js::isList($value) || ($value === [] && $declaresObject))) {
            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];

            /** @var list<string> $required */
            foreach ($required as $key) {
                if (!array_key_exists($key, $value)) {
                    $errors[] = ['path' => $path, 'message' => "missing required property '{$key}'"];
                }
            }

            /** @var array<string, mixed> $properties */
            foreach ($properties as $key => $subSchema) {
                if (!array_key_exists($key, $value) || !is_array($subSchema)) {
                    continue;
                }
                $this->check($value[$key], $subSchema, $path . '.' . $key, $errors);
            }

            if (($schema['additionalProperties'] ?? null) === false) {
                $allowed = array_keys($properties);
                foreach (array_keys($value) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = ['path' => $path, 'message' => "unexpected property '{$key}'"];
                    }
                }
            }

            return;
        }

        if (is_array($value) && \LangChain\Utils\Js::isList($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $item) {
                $this->check($item, $schema['items'], $path . '[' . $i . ']', $errors);
            }
        }
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'object' => is_array($value) && !\LangChain\Utils\Js::isList($value),
            'array' => is_array($value) && \LangChain\Utils\Js::isList($value),
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            // JSON Schema 2019-09 and later define `integer` as a number with a
            // zero fractional part, and json_decode('3.0') yields a float.
            // Measured: an integer-typed argument holding 3.0 was rejected with
            // "expected integer, got number", though 3.0 IS an integer by the
            // spec, and a model that writes 3.0 for an integer field has its
            // whole tool call refused.
            'integer' => is_int($value)
                || (is_float($value) && is_finite($value) && $value === floor($value)),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }

    private static function describeType(mixed $value): string
    {
        return \LangChain\Utils\Js::typeOf($value);
    }

    /**
     * The raw input as a string for the exception.
     *
     * `null` becomes `'null'`, matching `JSON.stringify` — the caller needs to
     * see that the model sent null, not an empty string.
     */
    private static function describeRaw(mixed $value): string
    {
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? print_r($value, true) : $encoded;
    }
}
