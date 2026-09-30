# Review 19 - anthropic/claude-fable-5.1
_asked 2026-09-30T11:03:30 - served by anthropic/claude-fable-5.1 - 92s_

## 1. `anyOf` match returns early and skips every sibling constraint
**Severity:** MAJOR
**Evidence:** `Schema::check()`: `if (is_array($option) && (new self($option))->errors($value) === []) { return; }` — the comment says "once one branch holds there is nothing left to decide".
**Why it matters:** `anyOf` is a disjunction over its own branches but a conjunction with siblings. The idiomatic "one of a/b is required" schema `{type:object, properties:{a:{type:string}}, anyOf:[{required:[a]},{required:[b]}]}` accepts `{"a": 1}` — `properties`/`type` never run. Same fail-open shape as the `allOf` bug already fixed.
**Suggested fix:** Only append the "does not match any" error and `return` on failure; on a match, fall through to `const`/`enum`/`type`/`checkSub` exactly as the `allOf` branch now does. Pin with the schema above and input `{"a": 1}`.

## 2. `invoke()` writes `toolCall` onto the caller's own `RunnableConfig`
**Severity:** MAJOR
**Evidence:** `StructuredTool::mergeConfig()`: `$merged = $config ?? new RunnableConfig(); if ($this->defaultConfig === null) { return $merged; }` (no clone) then `invoke()`: `$enrichedConfig->toolCall = $input;`.
**Why it matters:** A config reused across calls (an agent loop passes one config) keeps the previous tool call. The next `invoke()` with plain args then hits `ToolUtils::configHasToolCallId(['toolCall' => $config->toolCall])`, wraps the result in a `ToolMessage` with a stale `tool_call_id`, and `ToolRuntime::fromConfig()` returns the wrong runtime. Same class as the Anthropic "never mutates a caller-owned message" fix.
**Suggested fix:** Always `clone` in `mergeConfig()` (or `$enrichedConfig = clone $enrichedConfig` before assigning `toolCall`), and add a test invoking twice with one config: first a tool-call envelope, then raw args, asserting the second result is unwrapped.

## 3. A bare `{"type":"object"}` schema rejects `{}`
**Severity:** MAJOR
**Evidence:** `Schema::check()`: `$emptyIsObject = $value === [] && (array_key_exists('properties', $schema) || array_key_exists('required', $schema) || array_key_exists('additionalProperties', $schema));` and `matchesType`: `'object' => is_array($value) && !Js::isList($value)`.
**Why it matters:** `['type' => 'object']` with no keywords — the minimal shape OpenAI requires for a no-arg tool — gets `"arguments":"{}"` → `[]` → `emptyIsObject` false → "expected object, got array". The earlier `{}` fix covered only schemas with object keywords; a `type` of exactly `object` is itself unambiguous.
**Suggested fix:** In `$emptyIsObject`, also accept when the declared type set is `['object']` alone (no `array` member). Keep `type: ['object','array']` and bare `type: array` unchanged; pin all three.

## 4. `toJsonSchema()` emits `"properties": []` for an empty property map
**Severity:** MAJOR (partly inference — I cannot see `Tools::convert()`)
**Evidence:** `Schema::toJsonSchema()`: `? ['type' => 'object', 'properties' => []]`; also `Schema::object([])` passes `$properties = []` straight through. The docblock says "reported as an empty object rather than as `{}`", which contradicts itself.
**Why it matters:** `json_encode` writes `"properties":[]`, a JSON array where providers require an object; OpenAI rejects the function definition. Same one-array-type class as the `{"arguments":"[]"}` bug in HANDOFF. If a downstream converter already `(object)`-casts this, the finding reduces to the wrong docblock.
**Suggested fix:** Return `'properties' => (object) []` (or `new \stdClass()`) in both places, assert on the encoded bytes `"properties":{}` in a test, and fix the docblock.

## 5. `enum`/`const` use strict comparison while `integer` accepts `3.0`
**Severity:** MINOR
**Evidence:** `Schema::check()`: `!in_array($value, $schema['enum'], true)` and `$value !== $schema['const']`; versus `matchesType`: `'integer' => is_int($value) || (is_float($value) && ... $value === floor($value))`.
**Why it matters:** The ledger fixed "a model that writes 3.0 for an integer field has its whole tool call refused", but `enum: [1,2,3]` or `const: 3` still refuse `3.0` (`json_decode('3.0')` is float) with "expected one of [1,2,3]". Inconsistent handling of the same input inside one validator.
**Suggested fix:** Compare enum/const members with a JSON-equality helper that treats an integral float and int as equal (and recurses for arrays), keeping `"3"` vs `3` distinct. Pin `enum:[3]` with `3.0` accepted and `"3"` rejected.