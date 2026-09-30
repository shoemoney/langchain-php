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
| `utils/promise` + event loop | ✅ | `Promise`, `Await` (fiber scheduler) |
| `utils/stream` (Observable) | ✅ | `Observable` |
| `messages` | ✅ | Full hierarchy, content blocks, chunk fold |
| `messages/utils` (coercion, buffer string, stored form) | ✅ | incl. v1 checkpoint upgrade |
| `messages/format` | ✅ | |
| `load/serializable` | ✅ | Byte-compatible payload shape |
| `runnables` | ✅ | `Runnable`, Sequence, Lambda, Parallel, Branch, Binding, WithFallbacks, Each, **Assign**, **Passthrough**. There is no `Runnable::map()` — upstream has no such method, and the one this port had returned an empty map while discarding the runnable it was called on. |
| `runnables` config | ✅ | `RunnableConfig` |
| `documents` (`Document`) | ✅ | |
| `prompt_values` | ✅ | `StringPromptValue`, `ChatPromptValue` |
| `text_splitters` | ✅ | Character, RecursiveCharacter, Markdown, Latex, 16 language tables |
| `language_models` | ✅ | `BaseLangChain`, `BaseLanguageModel`, `BaseChatModel` (incl. `bindTools` / `withStructuredOutput`), `BaseLLM`, `LLM`, `SimpleChatModel`, `StructuredOutput` helpers, `ChatResult`/`LLMResult`/`Generation{,Chunk}`/`ChatGeneration{,Chunk}`, fakes. |
| `prompts` | ✅ | |
| `output_parsers` | ✅ | incl. **openai_tools**: `JsonOutputToolsParser`, `JsonOutputKeyToolsParser` |
| `tools` | ✅ | `StructuredTool`, `Tool`, `DynamicTool`, `DynamicStructuredTool`, `tool()`, `BaseToolkit`, `ToolRuntime`, `ToolException`. Schema is **JSON Schema**, not Zod — see below. |
| `tracers` / `callbacks` | ✅ | `BaseCallbackHandler` + method-bag handlers, `CallbackManager` and the four run managers, `BaseTracer`, `ConsoleCallbackHandler`, `RunCollectorCallbackHandler`, `Run`. No LangSmith HTTP transport. |
| `embeddings` / `vectorstores` | ⬜ | Interfaces are the seam; no concrete backend yet |
| `utils` (env, json patch, function_calling, standard_schema, tiktoken) | ⬜ | |
| `structured_query`, `indexing`, `example_selectors` | ⬜ | |
| `utils/function_calling`, `utils/standard_schema`, `utils/tiktoken`, `utils/env` | ⬜ | Zod has no PHP analogue; the port uses JSON Schema throughout (see below) |
| `load/import_map`, `load/import_constants` | ⬜ | |

## langchain (main package)

| Subsystem | Status | Notes |
|---|---|---|
| Provider integrations | 🟡 | **`ChatOpenAI`** (Chat Completions) and **`ChatAnthropic`** ported + tested. Not done: OpenAI **Responses API** + `azure/`, `converters/responses`, model `profiles`, the hosted tools (web_search/computer_use/shell/…); Anthropic `output_parsers` (citations), `extract_generated_files`, prompt caching helpers; every other provider. |
| `utils/http` | ✅ | `HttpClient` seam, `GuzzleHttpClient`, `HttpResponse`, `HttpException`, `SseParser` |
| `tests/Integration` | ✅ | The `integration` testsuite was declared in `phpunit.xml` and scripted in `composer.json` while `tests/Integration/` did not exist and CI ran only `--testsuite unit` — configuration that looked like coverage and was not. It now holds real boundary-crossing tests (a full Guzzle stack + PSR-7 streams driving both provider clients) and CI runs it. |

### Known defects

None outstanding. Two adversarial review rounds (8 and 9 findings) were run against the
provider clients; every accepted finding is fixed, mutation-verified, and listed above.
One further fix — the `SseParser` separator offset — has **no** observable failure in a
4,000-case differential run and is documented as such rather than claimed as a caught bug.
| `agents` | ⬜ | |
| `memory` | ⬜ | |
| `retrievers` | ⬜ | |
| `document_loaders` | ⬜ | |

## langgraph-core

| Subsystem | Status | Notes |
|---|---|---|
| `channels` | ✅ | `BaseChannel`, LastValue, LastValueAfterFinish, AnyValue, Ephemeral, NamedBarrier, BinaryOperatorAggregate, Topic, Untracked, Overwrite + registry |
| `errors` | ✅ | Full set: `BaseLangGraphError`, `EmptyChannelError`, `InvalidUpdateError`, `GraphBubbleUp`, `GraphRecursionError`, `GraphValueError`, `GraphDrained`, `GraphInterrupt`, `NodeInterrupt`, `EmptyInputError`, `NodeError`, `ParentCommand` + type guards |
| `state` | ✅ | `Annotation`/`AnnotationRoot`, `StateGraph`, `CompiledStateGraph` |
| `pregel` | ✅ | **The core.** `Algorithm` (applyWrites, prepareNextTasks, prepareSingleTask, procInput, localRead/Write, scratchpad, shouldInterrupt, candidateNodes), `PregelLoop` as a `\Generator`, `PregelRunner`, `IO`, `PregelNode`, `ChannelRead`/`ChannelWrite`, `Send`/`Command`, retry policy, `MemorySaver`, `interrupt()` |
| `graph` | ⬜ | `Graph` (the low-level builder), `MessageGraph`, drawing |
| `func` | ⬜ | `entrypoint`/`task` |
| `prebuilt` | ⬜ | `createReactAgent`, `ToolNode` — **the next big item**; `bindTools`, `ToolNode`'s `BaseToolkit`/`ToolRuntime`, and both provider clients now exist |
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
| **`RunnableParallel` accepts any input, including a scalar** | Upstream's `RunnableMap.invoke` applies no type check — it hands the input to every branch. An earlier revision of this port rejected non-array inputs, which made `{raw: llm}` (the first step of every `includeRaw` structured-output pipeline) unusable with the string input that is the common case. The check is gone; the regression test is `RunnableTest::testParallelPassesScalarInputToEveryBranch`. | `Runnables\RunnableParallel::invoke()` |
| **`RunnableBinding` merges bound kwargs into `config->options`** | Upstream does `this.bound.invoke(input, this._mergeConfig(options, this.kwargs))` — the kwargs become part of the config the target sees. This port stored them and never read them, so `bind()` was inert; fixed. Because upstream passes the kwargs *last* to a merge that lets later configs overwrite, a bound kwarg **beats** a call-time option of the same name. That is upstream's precedence, pinned by a test so it is not "tidied" the other way. | `Runnables\RunnableBinding::mergeConfig()` |
| **`HttpClient` parameter names are not part of the contract** | A PHP named argument binds to the *implementing* class's parameter name, so calling `->post(..., timeout: 30)` made every implementation that spelled it `$t` fail with "Unknown named parameter" despite satisfying the interface. Callers pass positionally; there is a test using a double whose parameters are named `a`/`b`/`c`/`d`/`e`. | `Utils\Http\HttpClient` |
| **OpenAI content blocks are not filtered on the way out** | Upstream drops `tool_use` / `reasoning` / `thinking` blocks echoed back in history, which strict OpenAI-compatible providers reject. This port passes `content` through unchanged: those block types arise from upstream's `output_version: v1` conversion, which is not ported, so an assistant message here carries tool calls in `toolCalls` and prose in `content`. A caller hand-building such a list must filter it. | `Chat\OpenAI\Utils\Completions::convertMessage()` |
| **Anthropic response content keeps its block structure unless there is exactly one text block** | Upstream's rule (`anthropicResponseToChatMessages`): a lone text block becomes a plain string, anything else keeps the raw block array. This joined several text blocks with a blank line, which lost the structure — a caller could not tell one paragraph from two, block types vanished, and text interleaved with a `thinking` block merged into it. An empty response is an empty *array*, matching upstream's else-branch. | `Chat\Anthropic\Utils\MessageOutputs::contentOf()` | | A `finally` runs on the way out of a `catch` too, so the abandoned-stream guard double-reported every streaming failure — two error events for one error. A collector that keeps the last event renders that as a single event and hides the duplicate, so the count is asserted directly. | `LanguageModels\BaseChatModel::stream()` |
| **One call-option spelling table, shared by all three layers** | Constructor, bound kwargs and per-call options each had their own hand-written alias list, and they drifted: `invocationParams()` accepted `max_tokens` while the constructor read only `maxTokens`, so `new ChatOpenAI(['max_tokens' => 99])` was accepted, ignored, and reported nowhere. One `canonicalise()` now serves all three. | `ChatOpenAI::KEY_ALIASES`, `ChatAnthropic::KEY_ALIASES` |
| **`ChatOpenAI` refuses `topK` at every layer, not just the constructor** | Chat Completions has no `top_k` (upstream sends none) but Anthropic does. Accepting it and dropping it was the worst outcome: the caller's sampling appeared configured and was not. The first guard lived in the constructor only, so bound and per-call `topK` were stored in `kwargs` — serialised into every trace — and then dropped from the request, while this table claimed otherwise. Both spellings are refused at all three layers, and the wire form is *mapped* before checking so it cannot slip through as a quiet variant. | `ChatOpenAI::rejectUnsupported()` |
| **`batch()`'s `$options` is accepted and ignored** | It is upstream's `batchOptions` (`maxConcurrency`, `returnExceptions`). `maxConcurrency` has no meaning in synchronous PHP; `returnExceptions` is not implemented, so a failing input throws. Stated on the interface rather than left to be discovered. | `Runnables\RunnableInterface::batch()` |
| **Anthropic tool-result folding never mutates a caller-owned message** | Appending in place grew the caller's own `HumanMessage` by a `tool_result` block it never had, corrupting the history and leaking an Anthropic-shaped block into any later request built from it — including one to another provider. A new message is constructed instead. | `Chat\Anthropic\Utils\MessageInputs::foldToolMessages()` |
| **A provider error pushed down an SSE stream raises** | OpenAI and Anthropic can deliver `{"error": {...}}` as a stream event instead of closing the connection. Nothing downstream looks for it — no `choices`, no `usage` — so the event was skipped and the stream ended silently, handing the caller a truncated answer with no signal that anything failed. |
| **An abandoned stream closes its trace run** | `stream()` is a generator, so a consumer that breaks early abandons it and the code after the loop never runs. A `finally` reports the run as failed rather than leaving a span open forever. A fully consumed stream is unaffected. |
| **Anthropic assistant block content survives a tool call** | Block content is passed through rather than coerced to text. Coercing it deleted everything the model wrote next to a tool call, so the provider received a bare `tool_use` block with no reasoning attached. |
| **Several leading Anthropic system messages keep their block content** | Blocks are spread in as-is rather than run through `json_encode`. Stringifying them turned a `cache_control` prompt into literal `[{"type":"text",…}]` *text*, so prompt caching silently did nothing. The single-message branch always preserved blocks; only the multi-message branch lost them. | `Chat\Anthropic\Utils\MessageInputs::flattenToBlocks()` |
| **A tool call with no arguments encodes as `{}`, not `[]`** | PHP has one array type, so `json_encode([])` yielded `"[]"` — a different JSON value to the provider, presenting a no-argument tool as one taking a positional list. `(object)` cast on a non-list, so a genuine empty *list* stays `[]`. | `Chat\OpenAI\Utils\Completions::toolCallToWire()` |
| **`user` / `seed` / `responseFormat` read the bound-kwargs layer** | All three are constructor fields, so they land in `kwargs` and are serialized into every trace. Reading only `$options` meant they were recorded and then never sent — the same class of defect as the `max_tokens` masking above, on the three keys that were missed. | `ChatOpenAI::invocationParams()` |
| **Anthropic's `streamUsage` is wired** | It was declared, defaulted, and written into `kwargs` (so it appeared in every trace) while nothing read it: `streamUsage: false` changed nothing. Now gates the usage chunk, matching OpenAI's client and upstream. | `ChatAnthropic::consume()` |
| **Stream *establishment* is retried; a flowing stream is not** | A 429 while opening a stream is the most common streaming failure and the only safely retryable one, because nothing has been delivered. Once a byte is yielded the stream is committed and the error propagates — reconnecting would re-emit tokens the caller already has. A zero-length read does **not** count as delivery. | `ChatOpenAI::postStream()`, `ChatAnthropic::streamResponseChunks()` |
| **No aggregate `content_blocks` key on an Anthropic message** | Was written and never read, duplicating data already stored per block type. Upstream has no such key. | `Chat\Anthropic\Utils\MessageOutputs::responseToMessage()` |
| **Provider `kwargs` records caller-supplied values, not resolved defaults** | `lcSerializable` records constructor arguments. Recording the resolved property instead put a `null` (or Anthropic's 1024) in `kwargs` under the same key, and the invocation lookup chain treats "present" as authoritative — so `bindTools($t, ['max_tokens' => 50])` was silently ignored. | `Chat\OpenAI\ChatOpenAI::__construct()`, `Chat\Anthropic\ChatAnthropic::__construct()` |
| **Both spellings are accepted for bound call options** | `max_tokens` (wire) and `maxTokens` (PHP-facing) normalise to one key for bound kwargs as well as per-call options. Normalising only the per-call layer made a bound wire-spelled option vanish. | `ChatOpenAI::normaliseKeys()`, `ChatAnthropic::normaliseKeys()` |
| **`"any"` maps to OpenAI `"required"`** | LangChain's `any` is not an OpenAI literal. Upstream maps it; treating it as a tool *named* "any" would have silently forced a nonexistent tool. | `Chat\OpenAI\Utils\Tools::formatToolChoice()` |
| **An empty bound tool list sends no `tools` key** | `bindTools([], $kwargs)` is a legitimate way to bind call options without offering anything, and providers reject `tools: []`. | `ChatOpenAI::invocationParams()` |
| **`RunnableLambda` passes the config to a callable that can receive it** | Upstream's lambda signature is `(input, config?, ...)`. This port passed only the input, so a pipeline built from lambdas silently dropped every call-time override — `config->options` is where a `temperature` or `max_tokens` override lives, and nothing else reads it back. The config is now passed, but only when reflection says the callable can take it (arity >= 2, or variadic), so a one-parameter lambda is unchanged and a lambda whose second parameter means something else is not silently re-purposed. Bound-kwargs splatting is untouched. | `Runnables\RunnableLambda::invoke()` |
| **`RunnableAssign` on a non-record input yields the mapping alone** | The JS spread `{...input, ...mapper}` over a string produces character indices. Reproducing that would be a surprise rather than a fidelity, so a non-record input contributes no keys and the mapping stands alone. | `Runnables\RunnableAssign::invoke()` |
| **`BaseChatModel::bindTools()` throws by default** | In TypeScript `bindTools` is an *optional* method, detected with `typeof this.bindTools !== "function"`. PHP has no optional methods, so a model that has not overridden it would be indistinguishable from one that has. It throws, and `supportsToolBinding()` reflects on the declaring class — the same trick `supportsStreaming()` already used. | `LanguageModels\BaseChatModel::bindTools()` |
| **The base `withStructuredOutput()` is function-calling only** | `jsonMode` and `strict` are refused rather than approximated. Asking for bare JSON and asking for a tool call are different requests, and silently substituting one for the other produces output that looks right and is not what was asked for. | `LanguageModels\BaseChatModel::withStructuredOutput()` |
| **`createContentParser` / `createFunctionCallingParser` take no schema** | Upstream branches on Zod v3, Zod v4, Standard Schema, and plain JSON Schema. This port has only the last, so both helpers collapse to their unvalidated form rather than carrying a branch that can never be taken. | `LanguageModels\StructuredOutput` |
| **The OpenAI Responses API is not ported** | Upstream's `ChatOpenAI` splits into a Completions and a Responses client selectable per instance. Only the Completions path is ported — it is the one every OpenAI-compatible provider also speaks. The Responses event stream is a different protocol, not a flag. | `LanguageModels\Chat\OpenAI` |
| **A tool's error status is read from `additional_kwargs`** | Upstream's `ToolMessage` carries a first-class `status` that becomes Anthropic's `is_error`. This port's `ToolMessage` predates that field, so the status is read from the extras bag. | `LanguageModels\Chat\Anthropic\Utils\MessageInputs` |
| **Streaming token usage is summed by the chunk fold** | Anthropic sends input tokens on `message_start` (nested under `message`) and output tokens on `message_delta` (top level). Both are emitted as chunks and added by `MessageMerge`, which is why a streamed call reports a total rather than whichever arrived last. | `Chat\Anthropic\Utils\MessageOutputs::usageFromEvent()` |
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
| An empty **channel value** serialises as `[]`, never `{}` | One PHP array type, and a list channel can legitimately be empty — forcing `{}` would corrupt a real one. The two fields whose type is KNOWN to be a map are the exception, below. | `JsonPlusEncoder` |
| `channel_versions` and `versions_seen` always serialise as maps | These are read as `[$name] => ...` by every consumer and are never lists, so the ambiguity above does not apply. An empty one previously went out as `[]`, and a fresh checkpoint handed the JavaScript side an array where it expected an object. Forcing `{}` is safe here precisely because the type is known, and `channel_values` is deliberately left alone. | `Checkpoint::toArray()` |
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
| `runnables` — `RunnablePassthrough` / `RunnableAssign` | `runnable_passthrough.test.ts` | 10 |
| `output_parsers` — openai_tools | `json_output_tools_parsers.test.ts` | 15 |
| `language_models` — `bindTools` / `withStructuredOutput` (incl. an end-to-end round trip) | `structured_output.test.ts` | 19 |
| `utils/http` — `SseParser` (split events, all three line terminators, `[DONE]`) | — | covered in the two client suites |
| provider — `ChatOpenAI` (wire format, tool calls, streaming, errors, retries) | `langchain-openai` tests | 28 |
| provider — `ChatAnthropic` (system hoisting, tool-result folding, event stream) | `langchain-anthropic` tests | 25 |
| `runnables` — `RunnableBinding` (bound kwargs reaching the target) | — | 13 |
| `utils/http` — `SseParser` (framing, split payloads, `[DONE]`, flush) | — | 12 |
| provider regression suite (adversarial-review round 1, each mutation-verified) | — | 12 |
| provider regression suite (round 2: system blocks, empty args, dropped kwargs, dead flag, stream retry) | — | 18 |
| `runnables` — `RunnableBinding` precedence (added after review) | — | +4 |
| **Total so far** | | **2158** |
