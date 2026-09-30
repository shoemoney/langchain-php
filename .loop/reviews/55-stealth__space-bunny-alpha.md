# Review 55 - stealth/space-bunny-alpha
_asked 2026-09-29T14:03:17 - served by stealth/space-bunny-alpha - 26s_

## 1. `allOf` schemas are never validated
**Severity:** MAJOR  
**Evidence:** `Schema::check()` handles `anyOf`, `const`, `enum`, and `type`, but has no `allOf` branch; `Schema::validatesOnlyStrings()` explicitly supports it.  
**Why it matters:** A schema such as `{"allOf":[{"type":"object"},{"required":["id"]}]}` accepts every value, so invalid tool calls execute side effects.  
**Suggested fix:** For every branch in `allOf`, run the full validator and aggregate all branch errors before accepting the value; add strict, mixed-type, and nested-object tests.

## 2. Property constraints are skipped when `type` is omitted
**Severity:** MAJOR  
**Evidence:** `Schema::check()` returns immediately when `$type === null`, so `checkSub()` never examines `properties`, `required`, or `additionalProperties`.  
**Why it matters:** Valid JSON Schema such as `{"properties":{"id":{"type":"string"}},"required":["id"]}` is exported to the model but does not validate arguments, allowing malformed calls into the tool body.  
**Suggested fix:** Apply object, array, and other subschema constraints independently of `type`; test typeless schemas containing `required`, nested properties, `items`, and `additionalProperties:false`.

## 3. Unknown schema types silently disable rejection
**Severity:** MAJOR  
**Evidence:** `Schema::matchesType()` has `default => true` in `Schema.php`.  
**Why it matters:** A typo such as `"type":"objec"` or `"type":"strng"` validates successfully, contradicting the promise that bad arguments throw `ToolException` and allowing unintended calls.  
**Suggested fix:** Reject unsupported `type` values during validation, and preferably validate the supplied JSON Schema when it is constructed; add tests for unknown scalar types and type arrays containing one.

## 4. Result-format errors leave tool traces open
**Severity:** MINOR  
**Evidence:** `StructuredTool::callToolWithValidation()` catches errors only around `execute()`; `splitResult()` and `ToolOutput::format()` run afterward outside that `try`.  
**Why it matters:** An invalid `content_and_artifact` result throws after `handleToolStart()` but never reaches `handleToolError()` or `handleToolEnd()`, leaving collectors with permanently unfinished runs.  
**Suggested fix:** Include `splitResult()` and `ToolOutput::format()` in the error-reporting `try`, report formatting failures through `handleToolError()`, and preserve the original exception while rethrowing.

## 5. Empty per-call bags cannot override tool defaults
**Severity:** MAJOR  
**Evidence:** `StructuredTool::mergeConfig()` replaces `tags`, `metadata`, `callbacks`, `options`, and `configurable` from defaults whenever the call-time value is `[]`; this is an inference from the shown `RunnableConfig` representation.  
**Why it matters:** A caller cannot explicitly clear default callbacks or per-call options, so invoking with `options: []` unexpectedly retains the tool’s default options.  
**Suggested fix:** Make unset versus explicitly empty config bags distinguishable, merge at field level as upstream does, and test default options/callbacks with both omitted and explicitly emptied call-time values.