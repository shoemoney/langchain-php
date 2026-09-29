# Port status

Live accounting of what has been ported, verified by the converted test suite.

**Rule:** a subsystem is only marked ported when its tests exist in this repo
and pass. Nothing is marked on the strength of the TypeScript source alone.

Run the evidence yourself:

```bash
composer test
```

---

## Legend

| | Meaning |
|---|---|
| ✅ | Ported, tests ported and passing |
| 🟡 | Partially ported — see notes |
| ⬜ | Not started |
| ⛔ | Deliberately out of scope, with the reason |

---

## langchain-core

| Subsystem | Status | Notes |
|---|---|---|
| `utils/promise` + event loop | ✅ | `Promise`, `Await` (fiber scheduler), 18 tests |
| `utils/stream` (Observable) | ✅ | `Observable` |
| `messages` | ✅ | Full hierarchy, content blocks, chunk fold, 47 tests |
| `messages/utils` (coercion, buffer string, stored form) | ✅ | incl. v1 checkpoint upgrade |
| `messages/format` | ✅ | |
| `load/serializable` | ✅ | Byte-compatible payload shape |
| `runnables` | ✅ | `Runnable`, Sequence, Lambda, Parallel, Branch, Binding, WithFallbacks, Each |
| `runnables` config | ✅ | `RunnableConfig` |
| `documents` (`Document`) | ✅ | |
| `prompt_values` | ✅ | `StringPromptValue`, `ChatPromptValue` |
| `text_splitters` | ✅ | Character, RecursiveCharacter, Markdown, Latex, 16 language tables |
| `language_models` | ✅ | `BaseLangChain`, `BaseLanguageModel`, `BaseChatModel`, `BaseLLM`, `LLM`, `SimpleChatModel`, `ChatResult`/`LLMResult`/`Generation{,Chunk}`/`ChatGeneration{,Chunk}`, fakes. No per-provider subfolders. |
| `prompts` | ✅ | |
| `output_parsers` | ✅ | |
| `tools` | ✅ | `StructuredTool`, `Tool`, `DynamicTool`, `DynamicStructuredTool`, `tool()`, `BaseToolkit`, `ToolRuntime`, `ToolException`. Schema is **JSON Schema**, not Zod — see below. |
| `tracers` / `callbacks` | ✅ | `BaseCallbackHandler` + method-bag handlers, `CallbackManager` and the four run managers, `BaseTracer`, `ConsoleCallbackHandler`, `RunCollectorCallbackHandler`, `Run`. No LangSmith HTTP transport. |
| `embeddings` / `vectorstores` | ⬜ | Interfaces are the seam; no concrete backend yet |
| `utils` (env, json patch, function_calling, standard_schema, tiktoken) | ⬜ | |
| `structured_query`, `indexing`, `example_selectors` | ⬜ | |
| `load/import_map`, `load/import_constants` | ⬜ | |

## langchain (main package)

| Subsystem | Status | Notes |
|---|---|---|
| Provider integrations | ⬜ | OpenAI + Anthropic next; the rest are HTTP wrappers over these |
| `agents` | ⬜ | |
| `memory` | ⬜ | |
| `retrievers` | ⬜ | |
| `document_loaders` | ⬜ | |

## langgraph-core

| Subsystem | Status | Notes |
|---|---|---|
| `channels` | ✅ | `BaseChannel`, LastValue, LastValueAfterFinish, AnyValue, Ephemeral, NamedBarrier, BinaryOperatorAggregate, Topic, Untracked, Overwrite + registry, 62 tests |
| `errors` | ✅ | Full set: `BaseLangGraphError`, `EmptyChannelError`, `InvalidUpdateError`, `GraphBubbleUp`, `GraphRecursionError`, `GraphValueError`, `GraphDrained`, `GraphInterrupt`, `NodeInterrupt`, `EmptyInputError`, `NodeError`, `ParentCommand` + type guards |
| `state` | ✅ | `Annotation`/`AnnotationRoot`, `StateGraph`, `CompiledStateGraph` |
| `pregel` | ✅ | **The core.** `Algorithm` (applyWrites, prepareNextTasks, prepareSingleTask, procInput, localRead/Write, scratchpad, shouldInterrupt, candidateNodes), `PregelLoop` as a `\Generator`, `PregelRunner`, `IO`, `PregelNode`, `ChannelRead`/`ChannelWrite`, `Send`/`Command`, retry policy, `MemorySaver`, `interrupt()` |
| `graph` | ⬜ | `Graph` (the low-level builder), `MessageGraph`, drawing |
| `func` | ⬜ | `entrypoint`/`task` |
| `prebuilt` | ⬜ | `createReactAgent`, `ToolNode` — **the next big item**; everything it needs now exists |
| `interrupt` | 🟡 | `GraphInterrupt`/`NodeInterrupt`/`interrupt()` exist and are thrown by the loop; the resume-with-values path is unported |
| `constants`, `hash`, `utils` | 🟡 | `Constants` ported; `hash` and `utils` not |

## langgraph-checkpoint

| Subsystem | Status | Notes |
|---|---|---|
| `BaseCheckpointSaver` | ✅ | `LangGraph\Checkpoint\BaseCheckpointSaver` — `get`/`getTuple`/`list`/`put`/`putWrites`/`deleteThread`, `getNextVersion`, `getDeltaChannelHistory`, the pending-sends migration, the `lc:2` write-index map. The engine's own contract (`Pregel\Checkpoint\BaseCheckpointSaver`) now extends it, so either one is a usable checkpointer |
| `Checkpoint` / `CheckpointTuple` / `CheckpointMetadata` / `ChannelVersions` / `uuid6`/`uuid5` | ✅ | Wire form is the TS `snake_case` record, so a checkpoint written here is readable by the JS savers |
| `MemorySaver` | ✅ | Serialises on write, so it doubles as the serde's integration test |
| `serde` (`Serialization`, `JsonPlusSerializer`) | ✅ | The `lc:2` envelope: constructor records, `DeltaSnapshot`, `Send` packets, `undefined`, byte strings, LangChain `lc:1` objects, circular-reference replacement, and an inert-by-default reviver |
| `sqlite` saver | ✅ | PDO, WAL, `checkpoints`/`writes` schema, `before`/`limit`/metadata-filter in SQL |
| `postgres` / `redis` / `mongodb` savers | ⬜ | |

## langgraph (client SDK)

| Subsystem | Status | Notes |
|---|---|---|
| `client` (REST) | ⬜ | |
| `runs`, `threads`, `stores`, `assistants` clients | ⬜ | |
| `sdk-react` / `vue` / `svelte` / `angular` | ⛔ | Browser UI bindings. No PHP analogue exists; porting them would mean inventing a frontend. |
| `langgraph-cli` / `langgraph-api` | ⛔ | Python toolchain and codegen server. Not a TypeScript SDK. |

---

## Known non-exact behaviours

Places where PHP cannot reproduce JavaScript at all, or where this port makes a
deliberate different choice. Each is pinned by a test so the boundary stays
visible rather than being rediscovered as a surprise.

Places where PHP genuinely cannot reproduce JavaScript, documented rather than
papered over. Each is pinned by a test so the boundary stays visible.

| Behaviour | Why | Where |
|---|---|---|
| **Tool input schemas are JSON Schema, not Zod** | PHP has no Zod. The TypeScript `StructuredTool` accepts either a Zod schema (validated via `interopParseAsync`) or a raw JSON Schema (validated via `@cfworker/json-schema`); this port accepts only JSON Schema. The observable contract is unchanged — bad arguments throw `ToolException`, good arguments reach the body — but Zod *transforms* are gone: a schema that renamed a key or coerced `"3"` to `3` no longer does so on the way through. A tool needing coercion should do it at the top of its body, where the intent is visible. | `Tools\Schema`, `Tools\StructuredTool::callToolWithValidation()` |
| **`BaseLangChain` re-declares the serialization surface** instead of extending `Serializable` | PHP has single inheritance and `Runnable` is the base that matters — it is what makes a model pipeable. So `lcId`/`kwargs`/`toSerializedConstructor` are declared on `BaseLangChain` rather than inherited, keeping the runnable contract primary. | `LanguageModels\BaseLangChain` |
| **Callback hooks are concrete no-ops, not optional methods** | TypeScript declares every hook optional (`handleLLMStart?`) and calls through optional chaining, so a handler declares only what it cares about. PHP has no optional methods, so every hook is a concrete no-op on `BaseCallbackHandler` and a subclass overrides what it needs. Un-overridden hooks still do nothing, so behaviour is identical — but `instanceof`-style "does this handler implement X?" checks need `implements()`, which the chat-model `handleLLMStart` fallback depends on. | `Tracers\BaseCallbackHandler::implements()` |
| **`awaitHandlers` is always true** | The TS flag selects between fire-and-forget background dispatch and awaiting the handler. PHP callbacks are synchronous, so there is nothing to background. The property is retained because `CallbackManager::configure()` reads it. | `Tracers\BaseCallbackHandler` |
| **Handler failures are recorded, not `console.warn`ed** | The original logs with `console.warn`. This records into `BaseRunManager::handlerErrors()` instead, because `E_USER_WARNING` under `failOnWarning="true"` is a test failure — a decorated-but-noisy handler would take down the suite that was merely observing it. | `Tracers\BaseRunManager::dispatch()` |
| **`LangChainTracer` collects runs instead of POSTing them** | The upstream tracer posts to LangSmith through the `langsmith` client, a separate package with its own retry/batching/multipart machinery. That transport is not ported. Everything up to persistence is: the run tree, dotted orders, trace ids. Override `persistRun()` to ship runs somewhere real. | `Tracers\LangChainTracer` |
| **Token usage is read from `response_metadata['usage_metadata']`** | Upstream reads a first-class `usage_metadata` field on `AIMessageChunk`, which the already-committed Messages port does not carry. `MessageMerge::mergeDicts()` sums the numbers under `response_metadata` identically, so the accumulation — the property actually under test — is preserved. | `LanguageModels\BaseChatModel::llmOutputFromUsage()` |
| **An orphan child run is promoted to a root, not left dangling** | Faithful. A run with a `parent_run_id` but no `dotted_order` is rejected outright by LangSmith, so the parent link is dropped. This is the upstream behaviour and it is lossy-but-loadable rather than unpersistable. | `Tracers\BaseTracer::addRunToRunMap()` |
| **A tool's `_serialized_start_time` borrows its execution order** | JS `Date.now()` is milliseconds and sub-millisecond precision is not information we have. Inventing it would make sibling runs claim distinguishable start instants they provably do not have; borrowing the execution order keeps start times consistent with dotted order, which is the property downstream sorting relies on. | `Tracers\Run::microsecondPrecisionDatestring()` |
| **`ToolRuntime::fromConfig()` reads state from `configurable['__state']`** | Upstream receives `state` from LangGraph's `Runtime` injection. LangGraph is a separate subsystem with no `Runtime` in this port yet, so the graph state is read from the config bag where a LangGraph node would put it. Everything else on the runtime (tool-call id, config, context, store, writer) is direct. | `Tools\ToolRuntime` |
| **Tasks in a superstep run sequentially, not concurrently** | PHP has no event loop and no `AbortSignal`. Observable state still matches, because `applyWrites` sorts by task path before folding — but *which* task runs first, and *which* error surfaces when several fail, are properties of the sort rather than of the runtime. JS reports an `AggregateError`; this port raises a `RuntimeException` naming the superstep. | `tests/Unit/Pregel/SuperstepBarrierTest.php` |
| `stream()` is a `\Generator` yielding `[mode, payload]` | The TS loop is an `AsyncGenerator` that `invoke()` awaits and `stream()` yields outward. PHP has one primitive for both, so `invoke()` and `stream()` are the *same* generator and cannot disagree. | `PregelLoop::run()`, `Pregel::stream()` |
| `interrupt()` reaches the task config through a static | A node body is `fn (array $state) => ...` with no `$config`, and PHP has no `AsyncLocalStorage`. Safe because the engine is synchronous and nested tasks run inside the parent's call stack; the previous value is always restored, including on throw. | `PregelScratchpad::withConfig()` |
| `_putCheckpoint`'s `exiting` flag is explicit | The TS tests object *identity* (`this.checkpointMetadata === inputMetadata`) to mean "save on the way out". PHP arrays have no identity, so the one caller that means it says so. A false positive would overwrite successive supersteps onto one row and destroy the thread's history. | `PregelLoop::putCheckpoint()` |
| `PregelNode` does not extend `RunnableBinding` | The TS one does, but a `RunnableBinding` requires a bound runnable, and the engine creates nodes with none: `__start__` only publishes input, branch nodes only route. | `PregelNode` |
| `getWriters()` collapses consecutive `ChannelWrite`s only by *class*, not by the TS `instanceof` symbol | Equivalent here because every writer in this port is a PHP class; there is no cross-realm instance. | `PregelNode::getWriters()` |
| `TextSplitter` default length function counts UTF-16 code units, not code points | `String.length` in JS is UTF-16; `mb_strlen` diverged on 34/400 emoji cases | `TextSplitters\TextLength::utf16CodeUnits()` |
| Splitting an astral character never yields a lone surrogate | A lone surrogate has no valid UTF-8 encoding, and `json_encode` refuses it outright | split by code point via `mb_str_split` |
| `Topic`'s `seen` set keys on a type-tagged serialization | PHP arrays have no reference identity, so `1` and `"1"` would otherwise collide | `Topic::key()` |
| `NamedBarrierValue` compares set membership, not sequence | Arrival order is nondeterministic under concurrency | `barrierSatisfied()` |
| `fromCheckpoint()` is an instance method, not static | Matches the TS prototype-call shape; a static call would lose the channel's config | all channel classes |
| Tasks in a superstep run sequentially, not concurrently | PHP fibers exist but a fiber cannot be resumed from inside a synchronous callback. Observable *state* still matches because `applyWrites` sorts by task path, but which task runs first is now a property of that sort. JS reports an `AggregateError`; this raises a `RuntimeException` naming the superstep. **The one place the port is not behaviourally identical.** | `Pregel\Algorithm` |
| `interrupt()` reaches the task config through a static, restored in `finally` | JS uses `AsyncLocalStorage` | `Pregel\interrupt()` |
| `undefined` has no PHP equivalent | The `lc:2` undefined record is *read* (→ `null`), never written | `JsonPlusDecoder` |
| `RegExp` serde records stay inert | A hand translation to PCRE would silently disagree with the writer | `JsonPlusDecoder` |
| An `Error` revives as `\RuntimeException` | Only the message is persisted; a PHP class name would not survive a cross-runtime read | `JsonPlusDecoder` |
| An empty map serialises as `[]` | One PHP array type. Forcing `{}` would corrupt genuinely-empty *list* channel values. | `JsonPlusEncoder` |
| **A checkpoint with no children serialises empty maps as `[]`, not `{}`** | PHP has one array type, so an empty map and an empty list are the same value and `json_encode` cannot tell them apart. The reverse direction is unaffected (a stored `{}` reads back as `[]`, which every consumer treats as "empty"), and forcing `{}` would corrupt a genuinely empty *list* channel value. | `JsonPlusEncoder::walk()` |
| **`Set` and `Map` need no envelope on the way out** | JS has to distinguish them from arrays in JSON; a PHP array already is the set *and* the map. The `lc:2` `Set`/`Map` records are still *read*, so a checkpoint written by the JS runtime arrives intact. | `JsonPlusEncoder::envelopeFor()` |
| **A `RegExp` constructor record stays inert** | A JS pattern is not a PCRE pattern, and translating one by hand yields a matcher that silently disagrees with the one that wrote it. Inert is the honest outcome, and it is the upstream rule for a record the reader cannot validate. | `JsonPlusDecoder::reviveConstructorRecord()` |
| **An `Error` record revives to a `\RuntimeException` carrying only its message** | That is all the envelope persists. The class cannot round-trip because the JS `Error` hierarchy has no PHP equivalent. | `JsonPlusDecoder::reviveConstructorRecord()` |
| **`list()` takes `CheckpointListOptions|int|null`, not a bare options object** | The engine calls `list($config, $limit)` and PHP has no overloading, so the limit and the options bag are one union type rather than two methods. | `BaseCheckpointSaver::list()` |
| **`MemorySaver` and `SqliteSaver` group a cross-thread `list()` differently** | The in-process saver iterates threads then namespaces; the database orders by `checkpoint_id` globally. Both are newest-first *within* a thread and namespace, which is the only ordering the `before` cursor and the engine depend on, and the upstream spec compares the list as a set for the same reason. | `MemorySaver::list()`, `SqliteSaver::list()` |

---

## Upstream test conversion ledger

| Suite | Upstream | Ported |
|---|---|---|
| `messages` | ~40 | 47 (incl. added edge cases) |
| `runnables` | ~90 | 29 |
| `text_splitters` | 37 | 35 verbatim + 71 added |
| `langgraph channels` | 74 | 62 |
| `langgraph pregel` | ~200 | 162 |
| `langgraph checkpointer` | ~400 | 992 (upstream validation spec, run against 2 savers) |
| `prompts` + `output_parsers` | ~180 | 141 |
| `tools` + `language_models` + `tracers` | ~220 | 161 |
| `pregel algo` (`algo.test.ts` + scheduling assertions) | 3 suites | 42 |
| `pregel loop` (behaviour from `pregel.test.ts`, `python_port/*`) | — | 41 |
| `pregel io` + errors | — | 42 |
| `superstep barrier` (PHP/JS divergence, documented) | — | 6 |
| `state graph` (builder, validation, wiring) | — | 28 |
| `checkpoint` (`jsonplus.test.ts`, `checkpoints.test.ts` base/id/versions blocks) | 3 suites | 63 |
| `checkpoint` shared saver spec (`put`, `putWrites`, `getTuple`, `list`, `deleteThread`) run against **both** savers | 5 specs | 216 (the `list` argument matrix is 648 cases x 2 savers) |
| `checkpoint-sqlite` (`checkpoints.test.ts`) | 1 suite | 7 |
| `checkpoint` <-> `pregel` integration (a real graph, resumable, per saver) | — | 6 |
| `tools` (`tools/tests/tools.test.ts`) | 28 | 55 |
| `language_models` (`chat_models.test.ts`, `llms.test.ts`, `outputs.ts`) | ~15 | 41 |
| `tracers` / `callbacks` (`tracer.test.ts`, `manager.test.ts`, `run_collector.test.ts`) | ~13 | 65 |
| **Total so far** | | **1745** |
