# Review 58 - perplexity/sonar-reasoning-pro (top-up)
_asked 2026-10-01T00:46:30 - served by perplexity/sonar-reasoning-pro - top-up for 3 finding(s)_

Only three concrete, grounded improvements surfaced from this packet; they are below.

## 1. Duplicate docblocks on `RunnableInterface::batch`
**Severity:** MINOR  
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php`: two consecutive `/** ... */` blocks immediately above `public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array`.  
**Why it matters:** Static analyzers and readers typically consume only the first phpdoc, which lacks the detailed explanation about `$options` (batchOptions) being intentionally ignored, so the stronger behavioural contract in the second block is easy to miss or contradict later.  
**Suggested fix:** Collapse both comments into a single docblock that includes the full description of `batch` semantics (including the non-use of `batchOptions`) and the correct `@param`/`@return` tags, then remove the extra block.  

## 2. `Schema` class doc understates supported keywords
**Severity:** MINOR  
**Evidence:** `src/LangChain/Tools/Schema.php`: class-level comment says “The subset implemented is … `type`, `properties`, `required`, `items`, `enum`, `const`, `anyOf`, and `additionalProperties: false`,” while the implementation also handles `allOf`, `integer`/`number`/`null`/`boolean` types, empty-object vs array ambiguity, and JSON instance equality via `jsonEquals()`.  
**Why it matters:** A maintainer reading the doc as the contract can easily conclude `allOf` and richer type handling are unsupported, avoid using them in schemas, or misdiagnose tests that rely on them, reducing trust in the docs as a specification of what the validator actually does.  
**Suggested fix:** Update the class-level documentation to enumerate the full set of implemented behaviours (including `allOf`, union types, numeric/boolean rules, and the `{}` vs `[]` handling), and explicitly state which JSON Schema features are not supported, so the doc matches the validator’s real surface.  

## 3. `ToolRuntime::$writer` type/docs mismatch and dormant field
**Severity:** MINOR  
**Evidence:** `src/LangChain/Tools/ToolRuntime.php`: constructor doc describes `$writer` as “Stream writer for progressive output” and suggests a callable, but the property is declared `public mixed $writer = null`, and `fromConfig()` never populates it (only `state`, `toolCallId`, `toolCall`, `configurable`, `context`).  
**Why it matters:** Tool code written against the doc will reasonably treat `$writer` as a callable and may call it, but the type system cannot protect that assumption and the field is currently never set, so future LangGraph wiring could hand arbitrary values here without tests noticing until runtime errors. The dormant field also invites accidental use before the runtime integration is in place.  
**Suggested fix:** Either narrow `$writer` to `callable|null` (or a dedicated interface) and ensure the eventual LangGraph integration populates it via `fromConfig()`, or remove the parameter and property until the streaming runtime exists, so the public API surface reflects only capabilities that are actually wired.

---

## 4. ToolRuntime lacks a `RunnableConfig` field
**Severity:** MINOR  
**Evidence:** `LangChain\Tools\ToolRuntime` defines constructor parameters for `state`, `toolCallId`, `toolCall`, `configurable`, `context`, `store`, `writer`, but has no `config`/`RunnableConfig` property or parameter; `ToolRuntime::fromConfig()` only threads `configurable` and `context` from the `RunnableConfig`.  
**Why it matters:** Tools that expect `ToolRuntime` to expose the full `RunnableConfig` (run name, tags, metadata, options) cannot see those values via runtime, diverging from upstream’s ToolRuntime contract and limiting future agent/tool-node fidelity.  
**Suggested fix:** Add a `public ?RunnableConfig $config` property to `ToolRuntime`, include it in the constructor, and set it in `fromConfig()` from the `$config` argument; update the docblock to mention `config` alongside `state`, `context`, `store`, and `writer`.

## 5. `Schema::validatesOnlyStrings()` semantics contradict its name
**Severity:** MINOR  
**Evidence:** `LangChain\Tools\Schema::validatesOnlyStrings()` doc says “constrains the value to a string”, but the `anyOf` branch returns `true` if *any* subschema is string-only, even when other `anyOf` branches allow non-string types; the comment references routing in `tool()` based on this method.  
**Why it matters:** A schema like `{ anyOf: [{type: "string"}, {type: "number"}] }` is treated as “string-only” for routing, even though validation accepts numbers, which is surprising given the method’s name and doc and can misclassify tools between the string and structured paths.  
**Suggested fix:** Either tighten `validatesOnlyStrings()` to require that *all* possible branches accept only strings (including `anyOf`), or rename the method and adjust its docblock to reflect the actual “string-accepting” semantics, then ensure callers (e.g. `tool()`) are using the intended behaviour.