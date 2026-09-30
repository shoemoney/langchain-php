# Review 47 - perplexity/sonar-pro-search
_asked 2026-09-30T17:33:49 - served by perplexity/sonar-pro-search - 10s_

## 1. `StructuredTool` cannot inject `ToolRuntime`
**Severity:** MAJOR  
**Evidence:** `StructuredTool::callTool()` always receives only `$toolInput, $runManager, $parentConfig`; `ToolRuntime::fromConfig()` is never used in the shown source.  
**Why it matters:** Tools declaring the upstream runtime parameter cannot receive graph state, context, store, writer, or `toolCallId`; they instead see no injected runtime and cannot implement stateful agent behavior.  
**Suggested fix:** Detect/inject a `ToolRuntime` parameter in the tool factory/execution path, construct it from the invocation config, and pass it while preserving existing `(input, runManager, config)` callables.

## 2. `ToolRuntime` does not expose the invocation config
**Severity:** MAJOR  
**Evidence:** `ToolRuntime` constructor fields omit `RunnableConfig`; `ToolRuntime::fromConfig()` copies only `state`, `toolCallId`, `toolCall`, `configurable`, and `context`.  
**Why it matters:** Upstream runtime consumers can inspect the current runnable configuration, but PHP tools cannot access callbacks, metadata, options, or run identifiers through `ToolRuntime`.  
**Suggested fix:** Add a `RunnableConfig $config` field and populate it in `fromConfig()`; retain the existing convenience fields as derived values.

## 3. `ToolRuntime::fromConfig()` silently discards `store` and `writer`
**Severity:** MAJOR  
**Evidence:** `ToolRuntime::fromConfig()` explicitly leaves `store` and `writer` at constructor defaults because “the config carries no field for either.”  
**Why it matters:** A graph/tool integration that supplies persistent storage or a stream writer cannot deliver either capability to tools; calls succeed while stateful persistence and progressive output silently do nothing.  
**Suggested fix:** Define documented config keys or an explicit runtime-injection object for `store` and `writer`, validate their types, and copy them into `ToolRuntime`; add an invocation test asserting both reach the tool.

## 4. `Schema::checkSub()` ignores `Schema` property nodes during validation
**Severity:** MAJOR  
**Evidence:** In `checkSub()`, property validation is gated by `!is_array($subSchema)`, so a property created with `Schema::string()` is skipped even though `unwrap()` only runs for the top-level schema before `check()`.  
**Why it matters:** `Schema::object(['name' => Schema::string()])` can serialize correctly through `toJsonSchema()`, but validation can accept an invalid non-string `name`, allowing malformed tool arguments into the body.  
**Suggested fix:** Recursively unwrap each `$subSchema` before calling `check()`, or normalize the complete schema once in the constructor and validate only normalized arrays; add a nested-schema rejection test.

## 5. Generator cleanup can throw after normal completion
**Severity:** MINOR  
**Evidence:** `StructuredTool::execute()` calls `$raw->getReturn()` and then its `finally` checks `$raw->valid()`; after normal exhaustion this is false, but after `getReturn()`/iterator state changes or unusual generator cleanup the code can call `$raw->throw(...)` during teardown.  
**Why it matters:** A successfully completed tool stream can be converted into an exception during cleanup, potentially reporting a successful tool as failed and emitting duplicate error callbacks.  
**Suggested fix:** Track an explicit `$completed` flag set immediately after `getReturn()`; only inject the early-close exception when the generator was abandoned before completion.