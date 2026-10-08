# HANDOFF — langchain-php

**Read this first.** Everything below is verified as of the commit named in
"State". Do not trust it if the repo has moved on — re-run `composer test`
first and believe the suite, not this file.

> **Counts live HERE; the per-fix ledger lives in `PORT_STATUS.md`.**
> The `Tests` row below is the one `DocsMatchRealityTest` reads, so run
> `python3 .loop/sync_docs.py` BEFORE the first `composer test` of any
> iteration that changes a count — otherwise that guard correctly reports
> a stale ledger and the run goes red for a reason unrelated to the code
> (reproduced on demand in iteration 277).

---

## The task

A **faithful PHP port** of the LangChain JS and LangGraph JS SDKs. Not a
wrapper, not a Laravel-flavoured re-imagining: same class hierarchies, same
algorithms, same LCEL composition semantics, same Pregel durable-execution
engine — translated to idiomatic PHP and verified by **converted** tests.

The user's instruction was "a completely ported SDK." **That is not achieved
yet.** See "What is NOT ported" — this is the single most important thing to
know before you start.

---

## State

| | |
|---|---|
| Path | `/Users/shoemoney/Projects/langchain-php` |
| Repo | `github.com/shoemoney/langchain-php` (public) |
| Branch | `main`, pushed and **tagged `v0.1.0`** (first release, GitHub release cut) |
| PHP | 8.5.11 installed; CI matrix on 8.2 / 8.3 / 8.4 |
| Tests | **14131 tests (13676 passing, 455 skipped), 33962 assertions** |
| Size | 552 src files / 88,507 lines · 474 test files / 99,863 lines |
| Release | `v0.1.0`, CI green on 8.2/8.3/8.4 + coverage. **Not on Packagist** — consume via the VCS repository. |

**Do not touch `/Users/shoemoney/Projects/agentdesk`.** The user was explicit.
It is a separate project. It happens to contain two upstream TypeScript
checkouts at `agentdesk/.runtime/js-sdks/{langchainjs,langgraphjs}` — those are
the reference sources the port was written *from*. They are reference clones,
not part of this project. Leave them; they can be `rm -rf`'d harmlessly if the
user ever wants them gone.

### Verify before you trust it

```bash
cd /Users/shoemoney/Projects/langchain-php
composer install
composer test
```

The suite must be green. If it is not, something changed since the handoff —
find out what before writing new code.

---

## Architecture decisions you need to know

These are the load-bearing choices. Violating them breaks the port's shape.

### 1. The Pregel loop is a `\Generator`, and that is load-bearing

In TypeScript `PregelLoop` is an `AsyncGenerator`: `stream()` yields it
outward, `invoke()` awaits it to completion. PHP has one primitive that does
both, so **the generator IS the loop**:

- `stream()` returns the generator directly
- `invoke()` drains it and returns the final output

Do not "improve" this into an event loop or a state machine. The whole
portability of the engine rests on this choice.

### 2. Promises exist so ported logic maps 1:1

`LangChain\Utils\Promise` implements real `then`/`catch`/`finally` algebra.
`Await` drives it over PHP 8.1 **Fibers**. Public entry points are
synchronous, but internals stay promise-shaped so the TS maps line for line.

### 3. Streaming is `[channel, value]` tuples

`RunnableInterface::stream()` returns `\Generator<int, array{0: string, 1: mixed}>`.
A stream carries several interleaved signals on different channels
(`default`, `retriever`, `tool`, `model`, `prompt`).

### 4. A provider client is a translation layer, and needs a transport seam

Every provider client in this port is tested without a socket. `LangChain\Utils\Http\HttpClient`
is the seam; `FakeHttpClient` replays scripted responses and records the request.
This is not over-engineering: a chat model's logic is almost entirely
translation, and translation can only be tested against a known payload.

Which means **assert on the request body, not just the response**. Two of the
bugs in the table below were invisible until a test read back what the client
actually sent.

### 5. The message merge algebra is the streaming core

`MessageMerge` (`_mergeDicts` / `_mergeLists` / `_mergeObj`) decides per-field
whether to concatenate, sum, replace, recurse, or skip. Getting it wrong
silently corrupts reconstructed streams. It is heavily tested — leave it alone.

### 6. PHP parameter types are contravariant — this bit us three times

An override may **widen** but never narrow a parameter type, and may not *add* a
required one. All three bugs below came from a signature that PHP forbids.
When a TS signature narrows, keep the shared base type and validate at runtime.

The third was self-inflicted this session: adding `array $kwargs = []` to
`BaseChatModel::bindTools()` broke `FakeStreamingChatModel::bindTools(array
$tools)` at class-load time, which took down every test in the suite at once.

---

## Bugs already found (do not reintroduce)

Every one of these was caught by a test, not by review. They are listed
because each represents a **class** of mistake that is easy to repeat.

| Bug | Why it mattered |
|---|---|
| `CheckpointFunctions::uuid6()` used `random_int` for sub-ms bits | Savers order history by comparing ids **as strings**. Two checkpoints in one ms sorted arbitrarily → `getTuple` returned the wrong "latest" → a resume silently loaded wrong state. Presented as a flaky test that *moved between runs*. |
| `StateGraph::__construct` reassigned the **promoted parameter**, not `$this->schema` | Any graph built with an array schema fataled on its first `addNode`. **156 unit tests missed it** because they all passed an `AnnotationRoot`. Only an end-to-end test caught it. |
| `ChatPromptValue` declared in two files | PSR-4 loaded whichever came second as a fatal redeclare. Latent since the files were split. |
| `FunctionMessage::$name` redeclared `string` over inherited `?string` | Fatal on class load, masked until something autoloaded it. |
| `ValueSet::key()` emitted `i:` for floats but `s:` for ints | Its own docblock promised `1.0` and `1` fold together. They never matched. A documented guarantee that was false. |
| `AIMessageChunk extends AIMessage` | In the TS it extends `BaseMessageChunk` and is its **sibling**. Streaming tool-call folding was broken. |
| `ToolMessageChunk extends ToolMessage` | `ToolMessage` is not a `BaseMessageChunk`, so tool-result streaming was unreconstructable. |
| `RunnableConfig::with()` compared snake_case keys to camelCase properties | Every override silently no-op'd. |
| `RunnableParallel` narrowed input to `input[$key]` | The TS `RunnableMap` passes the **whole input** to every branch. This is what makes the canonical `{context, question}` RAG map possible. |
| `coerceToRunnable()` in a plain file | PSR-4 autoloads *classes*, not functions. Added to `composer.json` `files`. |
| `FakeStreamingChatModel` read `$chunk->toolCalls` on an `AIMessageChunk` | `AIMessageChunk` has no such property — it carries undecoded `tool_call_chunks`. The read returned null forever, so a fake scripted with tool calls silently produced a model that never called anything, and any test asserting on the call passed for the wrong reason. Found only by an end-to-end `withStructuredOutput` test. |
| `RunnableParallel` rejected non-array inputs | A divergence the port had introduced and a test had encoded. Upstream's `RunnableMap.invoke` applies no type check, and the check made `{raw: llm}` — the first step of every `includeRaw` structured-output pipeline — unusable with a plain string. Source and test both corrected. |
| `ChatOpenAI::bindTools()` wrote to `kwargs` that `invocationParams()` never read | Bound tools reached `kwargs()` and never the request: the tool was silently not offered. `invocationParams()` now layers options → bound `kwargs` → constructor state. The same class of bug as `RunnableConfig::with()` above. |
| `Runnable::bind($kwargs)` stored the kwargs and nothing read them | `bind(['temperature' => 0])` was a **silent no-op on every runnable in the SDK**, including both provider clients. `RunnableBinding::mergeConfig()` only ever read `$this->config`. Found by probing, not by review: **zero tests in the suite called `->bind()` at all**. Now merged into `config->options`, matching upstream's `_mergeConfig(options, this.kwargs)`. |
| `HttpClient` call sites used named arguments | A PHP named argument binds to the *implementing* class's parameter name, so any `HttpClient` that spelled the parameter `$t` instead of `$timeout` died with "Unknown named parameter" despite satisfying the interface. Callers are positional now. |
| `FakeStreamingChatModel` / `Tools::convert()` indexed before type-checking | `isset($tool['type'])` on a `StructuredTool` is "Cannot use object of type … as array". Ordering, not null-safety. |
| `ChatAnthropic` read `parameters` off the **outer** tool array | `withStructuredOutput()` builds an OpenAI envelope, so the schema sits under `function.parameters`. Anthropic looked one level too high, sent `input_schema: {type: object, properties: {}}`, and **the model was never told what arguments the tool takes** — with no error anywhere. |
| `HttpResponse::header()` returned an array from a `?string` method | PSR-7 `getHeaders()` is `array<string, string[]>`. The `FakeHttpClient` used bare strings, so the only header shape any test ever saw was the one the real transport never produces. |
| `try` wrapped the generator *call* in both `postStream()` paths | `postStream()` is a generator function: calling it runs none of its body, so every streaming connect failure and non-2xx escaped as a bare `HttpException` with the provider's message discarded. |
| A resolved constructor default in `kwargs` masked a later binding | `bindTools($t, ['max_tokens' => 50])` did nothing because `maxTokens` already sat in `kwargs` from the default. `kwargs` now records only caller-supplied values. |
| A `FunctionMessage`'s content was dropped on the wire | The legacy `function` role carries the function's RETURN VALUE; omitting it sends a call with no result, which the provider accepts silently. |
| Five files were written to `…/LanguageModels/Chat/` instead of `…/Chat/{OpenAI,Anthropic}/` | Identical FQCNs, never autoloaded (so the suite stayed green), but `composer dump-autoload -o` fatals on a classmap build. Found by a reviewer grepping for `class ChatOpenAI`. **Check `find src -name '*.php'` against the PSR-4 path when adding a file in a new subtree.** |
| Anthropic stringified block content in the multi-system-message hoist | Per-block `cache_control` became literal `[{"type":"text",…}]` text, so prompt caching silently did nothing. The code comment claimed the opposite of what the code did. |
| A no-argument tool call encoded as `{"arguments":"[]"}` | PHP's one array type. A no-argument tool presented itself as taking a positional list. |
| `user` / `seed` / `responseFormat` recorded in `kwargs` and never sent | In the constructor whitelist, so serialized into every trace — but `invocationParams` read only the per-call layer. |
| Anthropic's `$streamUsage` was a dead flag | Declared, defaulted, written to `kwargs`, read nowhere. `streamUsage: false` did nothing. |
| No retry on stream establishment | `maxRetries` applied only to eager calls. A 429 opening a stream — the most common streaming failure — failed on the first attempt. |
| WP-08b: a resumed run's first checkpoint had no parent | The exiting re-save was its own parent, which broke `parentConfig` chains. Fixed with parent-chained resume checkpoints (`ReplayState`). |
| WP-08b: tasks that had already finished were dropped on resume | A completed sibling's output was lost after a crash or interrupt. Fixed with `includeCompleted` in `tick`. |
| WP-08b: `finishAndHandleError` ran twice after an absorbed interrupt | One finish per run is now enforced. |
| WP-08b: `foreach` over a growing queue skipped `RunnableSequence` steps | A by-value `foreach` walks a snapshot, so steps appended while walking were never visited. `Pregel\Utils\Subgraph` now uses an index loop. |
| WP-07: a graph-wide `Command(resume:)` answered EVERY task's interrupt | Each scratchpad copied `nullResume` from the pending-writes index and consumption cleared only the consumer's copy. A call task now inherits the caller's remaining `nullResume` and `CallScheduler` clears the caller's when a child consumed it (`InterruptHandlingTest::testHandlesMultipleInterruptsFromTasks`). Two sibling top-level nodes still both consume it, as upstream. |
| WP-07: `getPreviousState()` received a `Missing` object on a thread's first invocation | `Checkpoint::channelValue` returns `Missing`; `Algorithm::previousState()` maps it to null. |

**Two structural lessons worth carrying forward:**

1. *A green suite of unit-tested pieces is not evidence of a working
   integration.* The `StateGraph` bug survived 156 tests. Write end-to-end
   tests that run real graphs, and write them **after** the unit tests so they
   are testing the thing rather than mirroring its own assumptions.
2. *Run the suite more than once.* A failure that moves between runs is a
   nondeterminism bug, and that is how the `uuid6` defect was found.

---

## Verify a documentation edit landed BEFORE committing a message that claims it

Twice in eight iterations a PORT_STATUS edit threw an `AssertionError` on a wrong anchor, the shell carried
on, and the commit landed asserting a documentation change that had never been applied. Both times the
anchor was nearly right — a bold-marker mismatch, then a capitalisation mismatch — and both times the suite
stayed green, because documentation is not exercised by it.

The shell here has no `set -e`, so a failed step does not stop the later step that depends on it.

**Before committing any message that describes an edit to a tracked file, grep for the phrase the message
claims and abort if it is absent.** For a PORT_STATUS row, that is one line:

    N=$(grep -c "<the phrase your message claims>" PORT_STATUS.md); [ "$N" -ge 1 ] || exit 1

This is the same rule the rest of this file already encodes in a different form — *a mutation did not land*
and *a sweep that reports zero is a broken sweep* — applied to the one signal that had no control at all:
whether an edit happened.

**More generally: one edit per verified step, never several in one script.** Three times now a multi-part
edit has half-applied (a rename-and-move, a constructor anchor, a field anchor). The defence is identical
each time and costs one `diff` against a pre-edit copy: confirm the file CHANGED, not merely that the
command exited zero.


## House conventions

**Read this line before trusting it: only the FIRST convention below is CI-enforced.** It used to
read "Follow these or CI fails you", which was wrong — measured against `phpunit.xml`
(`failOnWarning="true"`, `failOnRisky="true"`) and `ci.yml` (jobs: `lint`, `test`, `coverage`):

- **PHPUnit 11 attributes** — ENFORCED. Deprecated `@covers` / `@dataProvider` emit a warning and
  `failOnWarning` fails the run.
- **`declare(strict_types=1)` on every file** — **ENFORCED** by `SourceConventionTest`, over every
  file in `src/` and `tests/`. Mutation-verified: removing the declaration fails the guard.
- **Namespace matches the directory (PSR-4)** — **ENFORCED** by the same file.
  Mutation-verified: renaming a `src/` namespace to a non-existent one fails the guard.
- **One top-level type per file** — enforced for `src/` only. See the note below for why `tests/` is
  scoped out; that scoping is deliberate and documented in the test.
  (a) `src/` legitimately contains function-only files — `createTool.php`, `coerceToRunnable.php`,
  `Pregel/interrupt.php` — so a "declared type matches filename" rule must exempt them; and
  (b) **19 files under `tests/` declare a SOURCE namespace** (`tests/Unit/Checkpoint/*` declares
  `LangChain\Checkpoint\*`, not `LangChain\Tests\Unit\Checkpoint\*`). Whether that is a
  deliberate pattern for protected-member access or namespace sloppiness is **unresolved**, so the
  guard must not be landed until it is decided — a guard that needs an unexplained 19-file carve-out
  is a weakened assertion.
- **Generics → `@template` / `@param` / `@return`** — convention only, NOT enforced.
- **No AI/Claude attribution** — convention only, NOT enforced by any job.

- **PHPUnit 11 — attributes, NEVER doc-comments.** `#[CoversClass(X::class)]`.
  `@covers` / `@dataProvider` doc-comments are deprecated and the suite runs
  `failOnWarning="true"`, so they **fail the run**. Data providers are
  `#[DataProvider('name')]` on a `public static` method.
- `<?php` then `declare(strict_types=1);` on every file.
- One class per file, PSR-4 path-matched.
- Generics become `@template` / `@param` / `@return` docblocks. Real signatures
  use `mixed` with a precise docblock — say so in a comment where it matters.
- **No AI/Claude attribution anywhere.** Non-negotiable, this repo and all of
  the user's.
- Validate before reporting: `composer dump-autoload -q && composer test`,
  plus `php -l` on new files.

---

## Workflow that worked

Port in dependency order, **test after every subsystem**, commit per
subsystem. Five parallel subagents were used, each given: the exact upstream
file list, the already-ported modules to build on, the house conventions, an
explicit no-touch list, and an instruction to report honestly on what they
could not express in PHP.

Two agents found real bugs in **already-committed** code. That is the point —
have each agent re-run the whole suite, not just its own tests.

Before reporting, audit for orphans: any `src/` class with no reference from
another src file or a test is dead. This found `ChannelRead` and `ValueSet` —
and `ValueSet` turned out to have a bug, plus `Topic` had reimplemented its
logic privately. A duplicate implementation is a bug even when neither copy is
tested.

---

## What is ported

Read `PORT_STATUS.md` for the authoritative, per-subsystem table. Every row
there is marked done **only if its tests exist and pass**.

- **langchain-core**: utils (promise/await/observable), messages (full
  hierarchy + content blocks + chunk fold), runnables (all 8 operators),
  documents, prompt_values, text_splitters, prompts, output_parsers, tools,
  language_models, tracers/callbacks, load/serializable
- **langgraph-core**: channels (all), pregel (algo, loop, read, write, retry,
  cache-key derivation, runner), state, errors
- **langgraph store + cache (WP-01)**: `LangGraph\Store\*` (BaseStore, InMemoryStore, AsyncBatchedStore, StoreUtils, ops/Item/SearchItem/IndexConfig), `LangGraph\Cache\*` (BaseCache, InMemoryCache), `LangChain\Embeddings\*`. The store reaches nodes and subgraphs via `configurable[Constants::CONFIG_KEY_STORE]` (`__pregel_store`); the loop wraps it in `AsyncBatchedStore` and puts it only on its own cloned config. Call-time overrides are `configurable[CONFIG_KEY_STORE]`/`[CONFIG_KEY_CACHE]`. `StateGraph::compile()` takes `store`/`cache`; `Pregel`/`CompiledStateGraph` constructors take `?BaseStore`, `?BaseCache` last. Node cache: `PregelLoop::cacheTaskWrites()` / `matchCachedWrites()`. `ToolRuntime::$store` stays `?object` (layering guard); known gap: `NextTaskExtraFields::$store` is dead.
- **langgraph-checkpoint**: BaseCheckpointSaver, MemorySaver, **SqliteSaver**,
  and the `serde` layer (`JsonPlusSerializer` + `_default` replacer + `_reviver`
  + `LcConstructorLoader`)
- **Wave 1 (13 work packages, 8974 tests)** — each verified and reviewed; scope is bounded, see the NOT-ported list:
  - **WP-04 graph base**: `Graph\{Graph,CompiledGraph,Branch,MessageGraph}`. `StateGraph` extends `Graph`, `CompiledStateGraph` extends `CompiledGraph` (which extends `Pregel`). `Branch` is the single conditional-edge evaluator (`RunnableBranchWriter` delegates to it). `Graph::compile` computes `triggerToNodes` itself, because Pregel silently schedules nothing without it. `CompiledGraph::attach*` are static (Pregel's arrays are readonly). A whole-value `__root__` state (MessageGraph) is now supported.
  - **WP-08a streaming remainder**: `messages` and `tools` stream modes via `Pregel\Messages\{StreamMessagesHandler,StreamProtocolMessagesHandler,StreamToolsHandler,TracedNode}` and `Pregel\Debug`. `Pregel::HANDLER_STREAM_MODES` is new; `SUPPORTED_STREAM_MODES` is deliberately unchanged (pinned by tests). Chunks are `[mode, payload]`. Layering guard over-matches core `Outputs` (handlers use FQCNs inline).
  - **WP-15 (PARTIAL)**: `Runnable::streamEvents` (v1/v2) and `streamLog`; handlers queue events and the entry point drains after each generator step. `RunnableInterface::CHANNEL_DEFAULT` added (fixed a latent `RunnablePick` fatal). Gaps are in PORT_STATUS.
  - **WP-12a (PARTIAL) / WP-12b**: `Env`, `JsonSchema`, `FunctionCalling`, `MathUtils`, `NamespaceUtils`, `Uuid`; `Utils\JsonPatch` is the single JSON Patch implementation (`OutputParsers\JsonPatch` is a delegating alias); `AsyncCaller` (retry/429/Retry-After, injectable sleeper), `ContextVariables`, `AsyncLocalStorage` (synchronous static stack, not Fiber-safe; runnables do not read it yet). Known defect for the `PartialJsonParser` owner: truncated literals and a lone `-` throw; a partial `\u` escape keeps its backslash (pinned in `JsonUtilsTest::testKnownDivergencesFromUpstream`).
  - **WP-02 prebuilt**: `ToolNode`, `toolsCondition`, `AgentName`, `CommandPassthroughTool` (adapter so a `Command` returned by a tool is not JSON-encoded). `ToolNode` rethrows only `GraphInterrupt`/`NodeInterrupt`. (`createReactAgent` landed later, in WP-03.)
  - **WP-09a/09b savers**: `Checkpoint\Postgres\*` (PDO; spec passed against real Postgres, env-gated on `LANGGRAPH_PG_DSN`) and `Checkpoint\Redis\*` behind `RedisClientInterface` (the suite runs against an in-memory fake; a real server only when `LANGGRAPH_REDIS_URL` is set, and that run deletes `checkpoint:*` keys in that database). Pregel needs a saver extending `LangGraph\Pregel\Checkpoint\BaseCheckpointSaver`. A skipped test breaks the done script and `sync_docs.py` (they need a plain `OK (N tests` line), so env gating switches backends instead of skipping. `ext-redis` is not in composer.json `suggest` yet.
  - **WP-14**: `LangChain\StructuredQuery\*` (IR, translators). **WP-16a**: OpenAI Responses API INPUT and tool converters (`formatToolChoice()` returns `?array`; null means omit); the output side landed in WP-16b. **WP-18a**: Anthropic `outputVersion: 'v1'`, `streamChatModelEvents()`, `AnthropicToolsOutputParser`, `Profiles`. **WP-19c**: `ChatOllama` (NDJSON, not SSE; an empty tool-arg map must encode as `{}`) and `OllamaEmbeddings`.
  - **WP-23a SDK core**: `LangGraph\Sdk\*` assistants/threads/store/crons over `MethodHttpClient` (the shared `HttpClient` is POST-only). `getGraph` is spelled `getAssistantGraph` until WP-06 retires the Pregel `getGraph` OPEN row.
- **Wave 2 (10 work packages, 12114 tests of which 455 are env-skipped)** — each accepted by an Opus review; many are 🟡, and the non-exact behaviours are in `PORT_STATUS.md`:
  - **WP-05 `StateGraph` parity** (accepted in round 3): `addSequence`, `input` / `output` / `context` schemas, node policies (`retryPolicy`, `cachePolicy`, `timeout`, `errorHandler`), `setNodeDefaults`, `validate()`, subgraph detection. Non-exact: the error handler runs inline (not a separate task); updates and checkpoints are attributed to the failed node; `timeout` is recorded but not enforced; a handled failure is cached under the failed node's key; `GraphCallbackHandler` events are not ported; JSON Schema `input` / `output` is not run as a validator. Integrator commit 94db72b: `StateGraph::compile` skips the `['*']` interrupt-all wildcard (a WP-05 x WP-08b collision).
  - **WP-03 `createReactAgent`**: `LangGraph\Prebuilt\ReactAgent::create(array $params)` (v1 and v2) plus `shouldBindTools` / `bindTools` / `getModel`, `AgentState`, `ConfigurableModelInterface`, `HumanInterrupt`, `HumanInterruptConfig`, `ActionRequest`, `HumanResponse`, and `LangChain\Utils\Testing\FakeToolCallingChatModel` (not final, so a test can subclass it as a spy). `LayeringDependencyDirectionTest` forbids `use LangChain\LanguageModels` anywhere in `src/LangGraph`, so chat models are duck-typed via `modelType() === 'chat'`. It exposed the `Topic::fromCheckpoint` defect under Open observations.
  - **WP-08b Pregel state**: `updateState`, `bulkUpdateState`, `getStateHistory` filters (returns arrays), `getSubgraphs`, `durability`, `Validate`, `Utils\Config`, `RunControl`, `ReplayState`, `Timeout` / `TimeoutPolicy` / `NodeTimeoutError`. The timeout is non-exact: sync PHP cannot preempt, so it is judged after the node returns. It also fixed four loop bugs (see the Bugs table).
  - **WP-07 functional API**: `LangGraph\Func\{Func,EntrypointFinal}` (`Func::entrypoint`, `Func::task`, `Func::final`, `Func::getPreviousState`) and `LangGraph\Pregel\{Call,CallScheduler}`. Tasks run sequentially and eagerly; per-task streaming is emitted after the entrypoint returns; the `custom` stream mode is not supported. Gaps: `Pregel::getState()` raises a `TypeError` on an entrypoint, and there is no `Pregel::clearCache()`.
  - **WP-09c `MongoDBSaver`**: `LangGraph\Checkpoint\MongoDB\*` over `MongoCollectionInterface` with `FakeMongoCollection` (which must keep honouring sort, limit after sort, and `strcmp` ordering). **The live integration spec has never been run against a real MongoDB**: it skips without `LANGGRAPH_MONGO_URI` plus `ext-mongodb`, which is the entire 455-test skipped count. `composer.json` has no mongodb dependency, so a production driver adapter is still to do.
  - **WP-13a retrieval stack**: `LangChain\{VectorStores,Retrievers,ExampleSelectors,Indexing,DocumentLoaders}` including `MemoryVectorStore` (from langchain-classic), `Index::index`, `InMemoryRecordManager`. `BaseRetriever extends BaseLangChain`; subclasses implement protected `getRelevantDocuments()`. `BaseRetrieverInterface` deliberately does not extend `RunnableInterface`: an interface extending it, implemented by a `Runnable` subclass, fatals on the ambiguous `CHANNEL_DEFAULT` constant (same class of bug as section 6). Open hooks: `Schema\Document` needs `?string $id`; `FewShotPromptTemplate` / `FewShotChatMessagePromptTemplate` are not ported.
  - **WP-13b stores, caches, chat history, memory, storage**: `LangChain\{Stores,Caches,ChatHistory,Memory,Storage}`, separate from `LangGraph\Store` / `LangGraph\Cache`. Models take `['cache' => true|BaseCache]`; `true` means `InMemoryCache::global()`, a process-wide `\ArrayObject`, so tests should pass `new InMemoryCache()` to avoid leaking hits. `BaseChatModel` and `BaseLLM` declare their own constructor; a subclass constructor must call `parent::__construct($fields)` for `cache` to take effect.
  - **WP-16b OpenAI Responses output + provider split**: `BaseChatOpenAI` (abstract) -> `ChatOpenAICompletions` | `ChatOpenAIResponses`; `ChatOpenAI` extends `BaseChatOpenAI` and builds one delegate per call, routing on `useResponsesApi` or a Responses-only option. Known gap: a streamed custom tool call is not parsed by `AIMessageChunk::parseToolCalls()` (it does not understand `isCustomTool`).
  - **WP-21a agent foundations**: `LangGraph\Agents\*` (namespace is LangGraph, not LangChain, because of the layering rule). `Agents\Nodes\ToolNode` is the createAgent variant and is separate from `Prebuilt\ToolNode`. **`createAgent` itself, `AgentNode` and middleware are WP-21b and are not done.**
  - **WP-23b SDK runs and streaming (PARTIAL)**: `RunsClient`, `Utils\{StreamRetry,Reconnect,Signals}`, `ThreadsClient::joinStream`, `Client->runs`, and the `StreamingMethodHttpClient` seam (`requestStream` returns a `StreamResponse`; null chunks are idle ticks). **`ThreadsClient::stream()` (the v2 thread-centric protocol) is not ported**, and a signal is a polled `callable(): bool`, not an `AbortSignal`. Test seams: `callerOptions.sleep` and `callerOptions.clock`.

---

## What is NOT ported

Ordered by what unblocks real usage first.

### Ported but not yet wired (1 class has zero src referrers; 1 has test-only referrers)

Measured by `reference_counts()` in `.loop/advisory.py`, which counts code
references and ignores comments — not the "test files" column, which is a
directory count and under-reports shared abstractions.

| Class | Waiting on |
|---|---|
| `LangChain\Utils\Testing\FakeTool` | Test-support only: referenced by `ToolNodeTest` since WP-02, no `src/` referrer (by design; upstream's `@langchain/core/utils/testing` double). |
| `LangChain\Utils\Observable` | Port of upstream's `Observable`/`EventSource` pair; nothing in the port consumes an observable yet. |

`LangChain\Tools\BaseToolkit` left this list in WP-02: `ToolNode` flattens toolkits (verified by grep, `src/LangGraph/Prebuilt/ToolNode.php`).

Both are faithful ports of something upstream **has** — unlike `pipeTo()`,
which was both uncalled *and* invented and was removed. Do not delete these
because nothing calls them today; that is the reasoning that removes a port
rather than completing it.

1. **Agents.** 🟡 `ToolNode`, `toolsCondition` and `agentName` (WP-02), `ReactAgent::create`
   (`createReactAgent`, WP-03) and the `LangGraph\Agents` foundations (WP-21a) landed.
   **`createAgent`, `AgentNode`, the Before/After nodes and middleware are not ported**
   (WP-21b), nor are structured responses and transformers (WP-21c). Supervisor and Swarm
   (WP-11) are not started.
2. **Finish the provider clients.** 🟡 `ChatOpenAI` (Chat Completions and the Responses API,
   WP-16b), `ChatAnthropic` and `ChatOllama` are ported and tested. Outstanding: `azure/`
   and OpenAI `profiles` (WP-17a), the OpenAI hosted tools (WP-17b), the Anthropic
   hosted-tool builders (WP-18b), Fireworks/Together/DeepSeek (WP-19a), xAI and OpenRouter
   (WP-19b), Anthropic `extract_generated_files` and prompt caching helpers.
3. `langgraph` client SDK — 🟡 core (WP-23a) plus the runs client, `joinStream`,
   `streamWithRetry` and signals (WP-23b) landed. Not ported: `ThreadsClient::stream()` (the v2
   thread-centric protocol) and the internal `~ui` client. `RemoteGraph` (WP-26) is not started.
4. Concrete vector-store backends, concrete retrievers, concrete document loaders and
   concrete memory classes. ⬜ The base layer plus `MemoryVectorStore` landed (WP-13a/b);
   `OllamaEmbeddings` is the only concrete embeddings class.
5. MongoDB checkpoint saver. 🟡 `MongoDBSaver` landed over a collection seam (WP-09c) but
   has **never run against a real MongoDB** and has no production driver adapter. Postgres
   (spec-verified, env-gated in CI) and Redis (fake-verified; real server env-gated on
   `LANGGRAPH_REDIS_URL`) landed in Wave 1.
6. `langgraph/graph` — 🟡 `Graph`, `CompiledGraph`, `Branch`, `MessageGraph` (WP-04) and
   `StateGraph` parity (WP-05, non-exact) landed. Open: drawing / `getGraph` (WP-06).
7. `langgraph/func` — 🟡 `entrypoint` / `task` landed (WP-07); sequential, no `custom` stream mode.
8. `utils`: 🟡 `env`, `json_patch`, `function_calling` landed (WP-12a is partial, see
   PORT_STATUS); `standard_schema` and `tiktoken` ⬜.
9. `load/import_map`. ⬜ (`structured_query` landed in WP-14; `indexing` and `example_selectors` in WP-13a, minus the `FewShot*` prompt templates.)
10. `streamEvents` v3 protocol layer, the `custom`/`checkpoints`/`tasks` stream modes,
    and the Wave 1 partials (WP-12a, WP-15). 🟡

### Deliberately out of scope

The `sdk-react` / `sdk-vue` / `sdk-svelte` / `sdk-angular` bindings (~34k
lines). Browser UI. There is no PHP analogue, and porting them would mean
inventing a frontend that the user did not ask for.

---

## Known non-exact behaviours

Each is pinned by a test, documented rather than papered over. See the
"Known non-exact behaviours" table in `PORT_STATUS.md` for the full list.

- **Tasks in a superstep run sequentially, not concurrently.** The one place the
  port is not behaviourally identical. Observable *state* still matches because
  `applyWrites` sorts by task path, but which task runs first is now a property
  of that sort. JS raises an `AggregateError`; this raises a
  `RuntimeException` naming the superstep. PHP cannot resume a fiber from
  inside a synchronous callback.
- Default text-splitter length function counts **UTF-16 code units**, not code
  points, because JS `.length` does. `mb_strlen` diverged on 34/400 emoji
  cases.
- An astral character is never split into a lone surrogate — one has no valid
  UTF-8 encoding and `json_encode` refuses it outright.
- `interrupt()` reaches task config through a static restored in `finally`;
  JS uses `AsyncLocalStorage`.
- `Hash` refuses a non-zero XXH3 seed (PHP's native `xxh128` seeds differently
  from upstream); LangGraph never passes one.
- Wave 1 divergences (full list in `PORT_STATUS.md`): `messages` chunks arrive per
  superstep, not per token; `streamEvents` event order follows generator pulls and nested
  `stream()` overriders are untraced; `AsyncCaller`/SDK `maxConcurrency` is stored but
  calls are serial; `AsyncLocalStorage` is not Fiber-isolated; `PartialJsonParser` throws on
  truncated literals.
- Serde: `undefined` is read (→ `null`) but never written; `RegExp` records stay
  inert; an `Error` revives as `\RuntimeException` (message only); an empty map
  serialises as `[]`.

## Open observations

Unresolved, recorded so they are not re-discovered:

- **One nondeterministic hang.** During Wave 1 landing a single full-suite run hung at test
  ~1853/6325 (about `ProviderClientRegressionTest::testAnthropicStreamingFailureBecomesAnAnthropicException`)
  until composer's 300s timeout. It did not reproduce in 3 full runs, 15 isolated runs, or any
  later run. Cause unknown; investigate if it recurs.
- **ENGINE BUG, found by WP-03 and NOT fixed: `LangGraph\Channels\Topic::fromCheckpoint()` misreads exactly two queued `Send`s.**
  It treats any 2-element list whose two entries are arrays as the `unique: true` `[seen, values]` shape,
  even when `unique` is false (`src/LangGraph/Channels/Topic.php`, the `count($checkpoint) === 2` branch).
  Two queued Sends, for example a v2 `ReactAgent` making exactly two tool calls, are restored as
  `["work", {"n":2}]`. After an interrupt the pending PUSH tasks vanish, `getState()` shows `next=[]` and
  `tasks=0`, and `Command(resume)` does nothing. **Repro:** a `StateGraph` with a node whose conditional edge
  returns two `Send('work', ...)`, one of which calls `interrupt()`; run it, then resume. One or three Sends
  work. Upstream's "parallel tool calls" test uses exactly two calls, so WP-03 ran it with three (weather x2
  plus `human_assistance`) and left a comment in the test. Suggested fix: guard that branch with `$this->unique`.
- **WP-05 follow-up: skip `PregelLoop::cacheTaskWrites` for writes produced by an error handler.** The handler
  runs inline in the node's own task, so a handled failure is cached under the failed node's key and replays from
  cache for the whole TTL when `cachePolicy` is on. Upstream never caches a failed task (`loop.ts:838-853`).
  Pinned by `NodeErrorHandlerTest::testAHandledFailureIsCachedUnderTheFailedNodesKey`; the pin must be inverted
  when this is fixed. Related edge: a node with an error handler gets `compiledRetry = null`, so a graph-level
  `retryPolicy` could re-run the whole wrapper (node plus handler).
- **455 skipped tests are the MongoDB integration spec** (`MongoDBSaverIntegrationTest`, gated on
  `LANGGRAPH_MONGO_URI` plus `ext-mongodb`). They inflate the Skipped count in `composer test`, and the saver has
  never been exercised against a real server.
- **Other Wave 2 gaps owned by later work:** `Pregel::validate()` is not auto-run; `StateGraph::compile(['checkpointer' => true])` becomes a base `MemorySaver`; `Signals::mergeSignals` has no `src` caller until
  `BaseClient::prepareFetchOptions` gets the timeout-signal merge; `tests/Unit/Sdk/Support/RecordingTransport`'s
  "Streaming is WP-23b" message is stale; `Runtime.writer` / `Runtime.interrupt` in `Agents` read config keys
  nothing injects yet.
- **Slow retry tests.** That test and its OpenAI twin take about 3.4s each because a retryable
  429 hits the real `usleep` backoff. The backoff method is overridable but these tests do not
  override it.

---

## Where to start

Recommended order, and why. Waves 0, 1 and 2 of `.loop/COMPLETION_PLAN.md` have landed (Wave 2: WP-03, 05, 07, 08b, 09c, 13a, 13b, 16b, 21a, 23b; several are 🟡, see above). `Pregel`/`CompiledStateGraph` carry a `checkpointerDisabled` flag set by `StateGraph::compile`. **Next is Wave 3**: WP-06 (drawing / `getGraph`), WP-11 (Supervisor + Swarm), WP-17a (Azure, profiles, embeddings, legacy LLM), WP-17b (OpenAI hosted tools), WP-18b (Anthropic hosted-tool builders), WP-19a (Fireworks/Together/DeepSeek), WP-19b (xAI + OpenRouter), WP-21b then WP-21c (`createAgent` and middleware, then structured responses), and WP-26 (RemoteGraph).

1. **Fix the `Topic::fromCheckpoint` defect first** (see Open observations): it silently breaks interrupt/resume
   for any graph that fans out exactly two Sends, and WP-21b's agent will hit it.
2. **`createAgent` (WP-21b)**, which needs the WP-21a foundations and the now-landed `StateGraph` input/output schemas.
3. **Drawing / `getGraph` (WP-06)**, which retires the `getGraph` OPEN row in `PORT_STATUS.md` and the
   `getAssistantGraph` spelling in the SDK.
4. Then work down the NOT-ported list.

When porting a subsystem: read the upstream TS, port the **tests** with it,
and prefer running the real upstream TypeScript under Node as an executable
oracle to diff against. That is how the text-splitter port found the UTF-16
length bug (1,070 generated cases, 0 real mismatches) and how it proved the
one remaining divergence was unavoidable rather than a bug.

---

## Do not

- Do not touch `/Users/shoemoney/Projects/agentdesk`.
- Do not `git commit` or `git push` without being asked.
- Do not mark anything in `PORT_STATUS.md` as ported unless its tests exist and
  pass.
- Do not add features absent from the upstream source. This is a port.
- Do not use doc-comment test metadata.
- Do not claim completion of the whole SDK. It is not complete, and
  `PORT_STATUS.md` is where that is tracked honestly.
