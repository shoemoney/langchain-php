# Review 39 - x-ai/grok-4.20-multi-agent
_asked 2026-09-29T12:04:08 - served by x-ai/grok-4.20-multi-agent - 210s_

## 1. Dead `$input` property in StructuredTool
**Severity:** MINOR
**Evidence:** `src/LangChain/Tools/StructuredTool.php:58` (`protected array $input = [];` — declared, defaulted, but never assigned or read in class or provided source)
**Why it matters:** Written-but-never-read value (the exact class of latent defect that survived 156 tests in `StateGraph` and the `uuid6` sorter per HANDOFF.md); bloats serialized state and risks confusion on future changes.
**Suggested fix:** Delete the property and its docblock.

## 2. Pointless assignment to unused `$configurableState` in ToolRuntime
**Severity:** MINOR
**Evidence:** `src/LangChain/Tools/ToolRuntime.php:34` (`state: (array) ($configurableState = $config->configurable['__state'] ?? []),` — variable assigned but never read)
**Why it matters:** Matches the "wrong values written but never read" pattern that dominated the project's defect history (see HANDOFF.md table); remnant that obscures the real `__state` extraction logic.
**Suggested fix:** Simplify to `state: (array) ($config->configurable['__state'] ?? []),` (remove the dead variable).

## 3. `validatesOnlyStrings()` lacks `anyOf` handling (unlike `check()`)
**Severity:** MAJOR
**Evidence:** `src/LangChain/Tools/Schema.php:148-175` (`validatesOnlyStrings` implements type/enum/const/allOf but skips `anyOf` present in `check():78-92` and the DynamicTool fork comment at line ~110)
**Why it matters:** A JSON-Schema-valid `anyOf: [{type:"string"}, ...]` (or string-only `anyOf`) returns false, routing through object-wrapper instead of bare-string `DynamicTool`; silently corrupts tool call input shape for models/agents.
**Suggested fix:** Add symmetric `anyOf` block before `allOf` (loop subschemas; return false on any non-string-only option; return true if all are). Pin with test from `tools.test.ts`.

## 4. Duplicate/outdated docblocks on `RunnableInterface::batch()`
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php:45-75` (two consecutive `/**` blocks before `batch()`; first lists outdated `@param` types, second explains upstream `batchOptions` + ignore semantics matching PORT_STATUS.md known behaviour)
**Why it matters:** Docs contradict the implemented signature and the documented non-exact `batch()` contract; misleads anyone extending for agents or reviewing LCEL composition.
**Suggested fix:** Delete the first (legacy) block; retain and tighten the second to exactly match the three parameters and "base ignores `$options`" note.

## 5. `ToolRuntime::fromConfig()` incomplete vs constructor/docblock
**Severity:** MAJOR
**Evidence:** `src/LangChain/Tools/ToolRuntime.php:22-50` (docblock + `__construct` declare `$store`, `$writer`; `fromConfig` populates only 5/7 fields, leaving defaults; see PORT_STATUS.md "ToolRuntime::fromConfig() reads state from configurable['__state']" and "unreferenced" note)
**Why it matters:** Tools expecting full runtime (store for persistence, writer for progressive output) receive nulls via the only public factory; will break `createReactAgent`/`ToolNode` (top unported item per HANDOFF.md) with silent data loss exactly as calibration warns.
**Suggested fix:** Add `store: $config->configurable['store'] ?? null, writer: $config->configurable['writer'] ?? null` (or exact upstream injection keys); mirror `__state` pattern and add to graph integration test.