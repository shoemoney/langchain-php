# Review 58 - perplexity/sonar-reasoning-pro
_asked 2026-10-01T00:46:30 - served by perplexity/sonar-reasoning-pro - 127s_

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