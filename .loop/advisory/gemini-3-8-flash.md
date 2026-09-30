# Advisory review — google/gemini-3.8-flash

_Generated 2026-09-30T05:22:24.877600+00:00_

### Layering
**Verdict:** sound
**Evidence:** Section 2 & 3: `LangGraph -> LangChain: 15 files`; 0 references from `LangChain` to `LangGraph`; provider packages (`Chat\Anthropic`, `Chat\OpenAI`) isolated in `LangChain\LanguageModels\Chat`.
**Finding:** Namespace layering respects unidirectional dependency: `LangGraph` consumes core abstractions (`RunnableConfig`, `BaseMessage`, runnables) from `LangChain`, while `LangChain` contains zero references back to `LangGraph`. Low-level utilities (`Utils\Http`, `Channels`, `Checkpoint\Serde`) contain no imports of high-level graph engines or specific chat providers.
**Suggested change:** none

---

### Public API
**Verdict:** needs work
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` (`generatePrompt(..., array $options = [], ?array $callbacks = null)` vs `generateMessages(..., ?RunnableConfig $config = null, array $options = [])`); `src/LangGraph/Pregel/Pregel.php` (`getState(RunnableConfig|array $config): array`).
**Finding:** The public API exhibits convention drift between peer methods on `BaseChatModel`: `generatePrompt` retains a legacy pre-LCEL signature `($promptValues, $options, $callbacks)` while `generateMessages` uses `($messageLists, $config, $options)`. On `Pregel`, `getState()` and `getStateHistory()` return untyped `array` maps rather than typed `StateSnapshot` value objects, forcing callers into defensive array-key probing.
**Suggested change:** Normalize `BaseChatModel::generatePrompt` to accept `?RunnableConfig $config = null` as its second argument, and introduce a readonly `StateSnapshot` class for `Pregel::getState` and `Pregel::getStateHistory`.

---

### Composition
**Verdict:** needs work
**Evidence:** Section 4 `BaseChatModel::stream(mixed $input, ?RunnableConfig $config = null): \Generator` and `Pregel::stream(): \Generator` vs Section 6 row `RunnableBinding merges bound kwargs into config->options`.
**Finding:** Generator-based streaming across LCEL boundaries composes cleanly on happy paths, but unwinding and config propagation across `RunnableBinding -> BaseChatModel::stream` require bound kwargs to be translated into provider-specific invocation parameters inside `config->options`, creating an implicit convention that custom Runnables do not participate in.
**Suggested change:** Formalize an explicit parameter-resolution pass on `RunnableConfig` rather than relying on late inspection of `$config->options` inside provider clients.

---

### Error Handling
**Verdict:** needs work
**Evidence:** Section 2: `LangGraph.Errors` (13 src files, 389 lines, 0 test files); Section 1: `TransportExceptionContractTest`.
**Finding:** While transport exceptions for HTTP clients are strictly wrapped and tested across OpenAI and Anthropic, the 13 domain exception classes in `LangGraph\Errors` have 0 dedicated test files, leaving their inheritance hierarchies, serialization safety, and message-formatting contracts unverified by CI.
**Suggested change:** Add a test covering the `LangGraph\Errors` taxonomy asserting that all graph exceptions extend a common `LangGraphException` interface/base class and preserve cause chaining (`$previous`).

---

### Fidelity
**Verdict:** sound
**Evidence:** Section 4 & 6: Deliberate divergences documented in `PORT_STATUS.md` (e.g. PHP floor semantics, `RunnableParallel` scalar inputs, absence of JS Zod/StandardSchema branches in `StructuredOutput`, synchronous `batch()` ignoring concurrency).
**Finding:** Divergences from upstream TypeScript are intentional adaptations to PHP runtime realities (single array type, synchronous execution model, lack of union types/reflection on closures) and are pinned by regression tests rather than occurring through accidental omission.
**Suggested change:** none

---

### Test Strategy
**Verdict:** needs work
**Evidence:** Section 2 inventory: `LangGraph.Checkpoint` (11 src, 2079 lines, 0 test files); `LangGraph.Errors` (13 src, 389 lines, 0 test files); `LangChain.LanguageModels.Outputs` (6 src, 294 lines, 0 test files); `LangChain.Schema` (4 src, 187 lines, 0 test files).
**Finding:** Over 2,900 lines of source code across core subsystems (`Checkpoint`, `Errors`, `Outputs`, `Schema`) have zero dedicated direct test files; coverage for these subsystems is purely derivative through downstream execution (`Pregel.Checkpoint` has 11 test files exercising savers indirectly).
**Suggested change:** Add direct contract unit tests for `LangGraph\Checkpoint` interfaces and base implementations (memory saver, base saver serialization contracts) independent of the Pregel engine loop.

---

### Documentation
**Verdict:** needs work
**Evidence:** Section 6, `PORT_STATUS.md` excerpt: row 5 formatting corruption (`Chat\Anthropic\Utils\MessageOutputs::contentOf() | | A finally runs on the way out... | LanguageModels\BaseChatModel::stream()`).
**Finding:** `PORT_STATUS.md` contains a merged/corrupted Markdown table row where the Anthropic `contentOf()` entry was accidentally truncated and spliced into the `BaseChatModel::stream()` finally-block documentation.
**Suggested change:** Repair the Markdown table structure in `PORT_STATUS.md` by splitting the spliced table row into two distinct rows with their respective headers and columns intact.

---

### Dead Code
**Verdict:** sound
**Evidence:** Section 2 & 4: Inventory of classes and methods against test and export profiles; lack of dangling helper utilities outside tested namespaces.
**Finding:** Public methods on core classes (`BaseChatModel`, `Pregel`, `Schema`) map 1:1 to LCEL and Pregel execution surfaces, and previously verified helper methods (such as `Run::toArray()`) exist deliberately for caller serialization and downstream consumers.
**Suggested change:** none

---

## Cross-Cutting Questions

### A. Composition: End-to-End User Journeys

#### Journey 1: Bind tools to a ChatModel and stream responses inside a checkpointed StateGraph
1. **Tool Definition & Binding:** The caller defines a tool via `Schema::object(...)` and wraps it in a `StructuredTool`. Calling `$model->bindTools([$tool])` returns a `RunnableBinding` wrapping `$model` with `$tools` stored in bound `$kwargs`.
2. **Graph Node Execution:** Inside a `StateGraph` node, `$node = fn($state) => ['messages' => [$boundModel->invoke($state['messages'])]]`.
3. **Execution & Checkpointing:** When `Pregel::stream()` runs:
   - `PregelLoop` yields control per step.
   - At the node boundary, `RunnableBinding::invoke()` merges bound tool kwargs into `RunnableConfig->options`.
   - `BaseChatModel::generateMessages` receives `$config`, delegates to `ChatOpenAI`/`ChatAnthropic`, formats the tools into wire format (`toolCallToWire()`), and yields an `AIMessageChunk`.
   - When the step completes, `LangGraph\Pregel\Checkpoint` serializes channel state via `LangGraph\Checkpoint\Serde`.
4. **The Seam / Friction Point:** If streaming *through* the node rather than streaming the Pregel loop steps (`$boundModel->stream()` inside the node), the outer generator (`Pregel::stream()`) yields graph state values, not token chunks. Token streaming inside a graph node requires custom callback dispatching via `RunnableConfig['callbacks']`. If the caller attempts to yield inner model stream chunks directly out of the node closure, `PregelLoop` receives raw chunks rather than state channel updates, failing state application.

#### Journey 2: Structured Output Pipeline with Fallback and Tool Extraction
1. **Pipeline Construction:** `$chain = $model->withStructuredOutput($jsonSchema)`.
2. **Execution:** Under the
