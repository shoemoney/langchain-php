# Review 73 - deepseek/deepseek-v4-flash-vision-exp
_asked 2026-09-30T00:48:51 - served by deepseek/deepseek-v4-flash-vision-exp - 73s_

## 1. Empty `{}` args rejected by an `object` tool schema
**Severity:** MAJOR
**Evidence:** `Schema::matchesType([], 'object')` returns `is_array($value) && !Js::isList($value)`; `array_is_list([])` is `true`, so it returns `false`. `Schema::check()` calls `matchesType` BEFORE the `checkSub` disambiguation that specifically handles the empty-array/empty-object ambiguity (`$value === [] && $declaresObject`).
**Why it matters:** A tool declared with `Schema::object([...optional...])` and invoked with no arguments (a model sends `"arguments": "{}"`, which decodes to `[]`) fails validation with `expected object, got array` and never reaches the body. The fix that `checkSub` documents is bypassed by the type check one step earlier.
**Suggested fix:** In `check()`, treat `$value === []` as matching `'object'` (and `'array'`) when the schema at this level declares any of `properties`/`required`/`additionalProperties`, or move the disambiguation into `matchesType`.

## 2. Post-`execute()` failures orphan the tool's trace run
**Severity:** MAJOR
**Evidence:** In `StructuredTool::callToolWithValidation()`, the `try { $result = $this->execute(...) } catch (\Throwable $e) { $runManager?->handleToolError($e); throw $e; }` block ends before `[$content, $artifact] = $this->splitResult($result);` and `ToolOutput::format(...)`.
**Why it matters:** A `content_and_artifact` tool whose body returns a non-two-tuple throws from `splitResult()`, and `ToolOutput::format()` can also throw — in either case `handleToolError` is never called and `handleToolEnd` never fires. The tool's run span stays open forever in any trace UI, exactly the failure mode the packet flags elsewhere ("a span hanging for a prompt never even sent").
**Suggested fix:** Move `splitResult()` and `ToolOutput::format()` inside the guarded `try`, or wrap them in their own `try/catch` that calls `handleToolError` and rethrows.

## 3. `Schema::check` short-circuits siblings when `allOf` is present
**Severity:** MINOR
**Evidence:** `if (isset($schema['allOf']) && is_array($schema['allOf'])) { foreach (...) $this->check(...); return; }` — the `return` exits before `$type`, `properties`, `required`, `const`, `enum`, and `anyOf` are examined.
**Why it matters:** JSON Schema's `allOf` is a conjunction with its siblings, not a replacement. A schema such as `{"type": "object", "allOf": [{"required": ["a"]}], "properties": {"a": {"type": "string"}}}` enforces only the `allOf` clauses; the sibling `properties` and `type` never run. The `anyOf` branch has the same return-on-match shape and skips sibling constraints when a subschema happens to validate.
**Suggested fix:** Replace the early `return`s with bounded recursion that appends `allOf`/`anyOf` results to `$errors` and then falls through to the sibling checks.

## 4. `integer` rejects the JSON spelling `3.0`
**Severity:** MINOR
**Evidence:** `matchesType` has `'integer' => is_int($value)`. PHP's `json_decode("3.0")` yields `float(3.0)`, and JSON Schema (2019-09 and later) defines `integer` as any number with zero fractional part.
**Why it matters:** A model that fills an integer-typed argument with `3.0` (a legitimate JSON encoding) has its call rejected with `expected integer, got number`, even though the value is an integer per spec. A caller invoking `->invoke(['n' => 3.0])` sees the same false negative.
**Suggested fix:** `'integer' => is_int($value) || (is_float($value) && $value === floor($value) && is_finite($value))`.

## 5. `StructuredTool::mergeConfig` drops a default `context`
**Severity:** MINOR
**Evidence:** `mergeConfig()` copies `tags`, `metadata`, `callbacks`, `options`, `runName`, `configurable` from `$defaults` when the corresponding slot on the call config is empty — but `ToolRuntime::fromConfig()` reads `$config->context`, and neither that field nor `toolCall` is in the copy list.
**Why it matters:** A tool whose `defaultConfig` sets a runtime `context` (agent id, tenant, an injected client) silently loses it whenever the caller does not pass context on the per-call config — the tool body sees `null` where the tool author configured a value. Same class as the `kwargs`-recorded-but-never-read defects already documented for the providers.
**Suggested fix:** Add a `context` branch mirroring the others: `if ($merged->context === null) { $combined->context = $defaults->context; }`.