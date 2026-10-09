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
| Tests | **16972 tests (16408 passing, 564 skipped), 40529 assertions** |
| Size | 656 src files / 107,919 lines · 585 test files / 130,210 lines |
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
  - **WP-23a SDK core**: `LangGraph\Sdk\*` assistants/threads/store/crons over `MethodHttpClient` (the shared `HttpClient` is POST-only). `getGraph` is spelled `getAssistantGraph`; WP-06 has since landed `Runnable::getGraph`, but the SDK spelling was not changed.
- **Wave 2 (10 work packages, 12114 tests of which 455 are env-skipped)** — each accepted by an Opus review; many are 🟡, and the non-exact behaviours are in `PORT_STATUS.md`:
  - **WP-05 `StateGraph` parity** (accepted in round 3): `addSequence`, `input` / `output` / `context` schemas, node policies (`retryPolicy`, `cachePolicy`, `timeout`, `errorHandler`), `setNodeDefaults`, `validate()`, subgraph detection. Non-exact: the error handler runs inline (not a separate task); updates and checkpoints are attributed to the failed node; `timeout` is recorded but not enforced; a handled failure used to be cached under the failed node's key (fixed in Wave 3); `GraphCallbackHandler` events are not ported; JSON Schema `input` / `output` is not run as a validator. Integrator commit 94db72b: `StateGraph::compile` skips the `['*']` interrupt-all wildcard (a WP-05 x WP-08b collision).
  - **WP-03 `createReactAgent`**: `LangGraph\Prebuilt\ReactAgent::create(array $params)` (v1 and v2) plus `shouldBindTools` / `bindTools` / `getModel`, `AgentState`, `ConfigurableModelInterface`, `HumanInterrupt`, `HumanInterruptConfig`, `ActionRequest`, `HumanResponse`, and `LangChain\Utils\Testing\FakeToolCallingChatModel` (not final, so a test can subclass it as a spy). `LayeringDependencyDirectionTest` forbids `use LangChain\LanguageModels` anywhere in `src/LangGraph`, so chat models are duck-typed via `modelType() === 'chat'`. It exposed the `Topic::fromCheckpoint` defect (fixed in Wave 3, see Open observations).
  - **WP-08b Pregel state**: `updateState`, `bulkUpdateState`, `getStateHistory` filters (returns arrays), `getSubgraphs`, `durability`, `Validate`, `Utils\Config`, `RunControl`, `ReplayState`, `Timeout` / `TimeoutPolicy` / `NodeTimeoutError`. The timeout is non-exact: sync PHP cannot preempt, so it is judged after the node returns. It also fixed four loop bugs (see the Bugs table).
  - **WP-07 functional API**: `LangGraph\Func\{Func,EntrypointFinal}` (`Func::entrypoint`, `Func::task`, `Func::final`, `Func::getPreviousState`) and `LangGraph\Pregel\{Call,CallScheduler}`. Tasks run sequentially and eagerly; per-task streaming is emitted after the entrypoint returns; the `custom` stream mode is not supported. Gaps: `Pregel::getState()` raises a `TypeError` on an entrypoint, and there is no `Pregel::clearCache()`.
  - **WP-09c `MongoDBSaver`**: `LangGraph\Checkpoint\MongoDB\*` over `MongoCollectionInterface` with `FakeMongoCollection` (which must keep honouring sort, limit after sort, and `strcmp` ordering). **The live integration spec has never been run against a real MongoDB**: it skips without `LANGGRAPH_MONGO_URI` plus `ext-mongodb`, which is the entire 455-test skipped count. `composer.json` has no mongodb dependency, so a production driver adapter is still to do.
  - **WP-13a retrieval stack**: `LangChain\{VectorStores,Retrievers,ExampleSelectors,Indexing,DocumentLoaders}` including `MemoryVectorStore` (from langchain-classic), `Index::index`, `InMemoryRecordManager`. `BaseRetriever extends BaseLangChain`; subclasses implement protected `getRelevantDocuments()`. `BaseRetrieverInterface` deliberately does not extend `RunnableInterface`: an interface extending it, implemented by a `Runnable` subclass, fatals on the ambiguous `CHANNEL_DEFAULT` constant (same class of bug as section 6). Open hooks: `Schema\Document` needs `?string $id`; `FewShotPromptTemplate` / `FewShotChatMessagePromptTemplate` are not ported.
  - **WP-13b stores, caches, chat history, memory, storage**: `LangChain\{Stores,Caches,ChatHistory,Memory,Storage}`, separate from `LangGraph\Store` / `LangGraph\Cache`. Models take `['cache' => true|BaseCache]`; `true` means `InMemoryCache::global()`, a process-wide `\ArrayObject`, so tests should pass `new InMemoryCache()` to avoid leaking hits. `BaseChatModel` and `BaseLLM` declare their own constructor; a subclass constructor must call `parent::__construct($fields)` for `cache` to take effect.
  - **WP-16b OpenAI Responses output + provider split**: `BaseChatOpenAI` (abstract) -> `ChatOpenAICompletions` | `ChatOpenAIResponses`; `ChatOpenAI` extends `BaseChatOpenAI` and builds one delegate per call, routing on `useResponsesApi` or a Responses-only option. Known gap: a streamed custom tool call is not parsed by `AIMessageChunk::parseToolCalls()` (it does not understand `isCustomTool`).
  - **WP-21a agent foundations**: `LangGraph\Agents\*` (namespace is LangGraph, not LangChain, because of the layering rule). `Agents\Nodes\ToolNode` is the createAgent variant and is separate from `Prebuilt\ToolNode`. (`createAgent`, `AgentNode` and middleware landed afterwards, in WP-21b; see Wave 3.)
  - **WP-23b SDK runs and streaming (PARTIAL)**: `RunsClient`, `Utils\{StreamRetry,Reconnect,Signals}`, `ThreadsClient::joinStream`, `Client->runs`, and the `StreamingMethodHttpClient` seam (`requestStream` returns a `StreamResponse`; null chunks are idle ticks). **`ThreadsClient::stream()` (the v2 thread-centric protocol) is not ported**, and a signal is a polled `callable(): bool`, not an `AbortSignal`. Test seams: `callerOptions.sleep` and `callerOptions.clock`.
- **Wave 3 (13 merges: two engine fixes plus eleven work-package merges, WP-19b landing as two; 14131 tests of which 455 are env-skipped)** — landed 2026-10-08. Several are 🟡; non-exact behaviours are in `PORT_STATUS.md`. Every provider client is tested against a fake transport only, never a live API:
  - **Engine fixes.** `Topic::fromCheckpoint` reads the `[seen, values]` shape only when `unique` (regression test `tests/Unit/Channels/TopicTwoSendsRegressionTest.php`); the port deliberately drops upstream's legacy pre-flat read for non-unique topics. A handled node failure is no longer cached: `StateGraph::getUpdates` appends a reserved `Constants::HANDLED` write and `PregelLoop::cacheTaskWrites` skips any task carrying it.
  - **WP-06 drawing**: `LangChain\Runnables\Graph\{Graph,Node,Edge,Mermaid,RunnableIOSchema}`, `Runnable::getGraph`, `Pregel::getGraph($config, $xray)`. Mermaid text was checked against upstream under Node (24 cases). `drawMermaidPng` returns bytes as a string; the default fetch has never been run against the real mermaid.ink.
  - **WP-11 Supervisor and Swarm**: `LangGraph\Prebuilt\{Supervisor\Supervisor,Swarm\Swarm}` with `::create(array $params)`. No remote-graph branch. The engine does not handle `ParentCommand` (`StateGraph::controlBranch` throws it for `Command(graph: PARENT)`), so each agent node runs through `Supervisor\ParentCommandBridge`; handling it in `PregelRunner` would make the bridge removable.
  - **WP-17a**: Azure OpenAI chat (`Chat\OpenAI\Azure\*`, completions and responses), OpenAI `Profiles`, `OpenAIEmbeddings` / `AzureOpenAIEmbeddings`, legacy `LLMs\{OpenAI,AzureOpenAI}`. Non-exact: the Azure responses URL is built by string cutting.
  - **WP-17b**: OpenAI hosted-tool builders, `Chat\OpenAI\Tools\*`. **WP-18b**: Anthropic hosted-tool builders, `Chat\Anthropic\Tools\*`, with server-tool passthrough in `MessageInputs::convertTool`.
  - **WP-19a**: `ChatFireworks`, `ChatTogetherAI`, `ChatDeepSeek` plus their embeddings and LLMs. ~~Known gap: Fireworks and Together drop streamed `reasoning_content`~~ **FIXED in Wave 4**. **WP-19b**: `ChatXAI` (Chat Completions only) and `ChatOpenRouter` (`withStructuredOutput` does not validate the reply).
  - **WP-21b**: `Agent::create` (`createAgent`, returns `ReactAgent`), `Middleware::create`, and `AgentNode` + the Before/After nodes under `LangGraph\Agents`. The graph-structure tests compare against upstream's snapshot data. **WP-21c**: structured responses (`ToolStrategy` / `ProviderStrategy`) and the tool-call / subagent transformers; the v3 run-stream cases are not ported.
  - **WP-26 `RemoteGraph` (PARTIAL)**: `LangGraph\Pregel\{RemoteGraph,RemoteRunStream}` over `runs->create` + `joinStream`, because `ThreadsClient::stream()` (v2) is not ported; only part of `RemoteGraphRunStream` is ported; the signal is a polled callable.
- **Wave 4 (13 merges: nine work packages plus four follow-up fixes; 15851 tests of which 570 are env-skipped)** — landed 2026-10-08. **Wave 4 is the last wave in `.loop/COMPLETION_PLAN.md`, and the port is still not complete**: every item below except the fixes is 🟡, non-exact behaviours are in `PORT_STATUS.md`, and nothing here has touched a live provider, MongoDB or MCP server.
  - **WP-20 `initChatModel`** 🟡: `LangChain\LanguageModels\Chat\Universal\{InitChatModel,ConfigurableModel,ConfigurableModelInterface,ModelProviders}`. `InitChatModel::init(?string $model, array $fields)` returns a `ConfigurableModel` that builds the real client lazily. The registry holds nine providers (`openai`, `anthropic`, `azure_openai`, `langsmith`, `ollama`, `deepseek`, `xai`, `fireworks`, `together`); any other is refused with an `Unsupported { modelProvider }` error. `AgentNode` resolves `provider:model` strings through it and passes `useResponsesApi` for `openai:` strings, as upstream does. The layering guard forbids `use LangChain\LanguageModels` in `src/LangGraph`, so LangGraph code reaches it by FQCN.
  - **WP-22a/b/c pre-built middleware** 🟡: all under `LangGraph\Agents\Middleware`, each a static `create()` that returns the `Middleware::create` array (`ClearToolUsesEdit` is `new ClearToolUsesEdit([...])`).
    - 22a: `ModelCallLimitMiddleware`, `ToolCallLimitMiddleware`, `ModelRetryMiddleware`, `ToolRetryMiddleware`, `ModelFallbackMiddleware`, `ToolErrorMiddleware`, `DynamicSystemPromptMiddleware`, plus `Constants` (`INTERNAL_CALL_TAG = 'nostream'`) and the error classes.
    - 22b: `HumanInTheLoopMiddleware`, `SummarizationMiddleware`, `ContextEditingMiddleware` / `ClearToolUsesEdit` / `ContextEdit`.
    - 22c-1: `PiiMiddleware`, `PiiDetectors`, `PiiDetectionError`, `PiiRedactionMiddleware`, `TodoListMiddleware`, `LlmToolSelectorMiddleware`.
    - 22c-2: `ToolEmulatorMiddleware`, `ProviderToolSearchMiddleware`, `Provider\Anthropic\PromptCachingMiddleware`, `Provider\OpenAI\{ModerationMiddleware,ModerationClient}`.
    - **Honest partials:** the v3 stream-transformer cases (`run.messages`) are skipped, `ContextOverflowError` is absent from the port's core errors (one modelRetry case skipped), and the Anthropic `cache_control` set by `PromptCachingMiddleware` reaches `bindTools` but not the request body (see Open observations).
    - Things worth knowing: HITL wire shapes are plain arrays (request `['actionRequests' => [...], 'reviewConfigs' => [...]]`, resume `['decisions' => [...]]`) and `Prebuilt\HumanInterrupt` is an older, different protocol. `ContextEditingMiddleware` returns a `Command` state update when an edit changed something (PHP arrays are values, so upstream's in-place mutation cannot persist), which skips `AgentNode`'s agent-name stamp for that reply. The summarizer, tool emulator and tool selector model calls carry the `nostream` tag and `lc_source` metadata so `stream(messages)` does not leak them. Deprecated options raise `E_USER_DEPRECATED`, so tests trap them with a scoped `set_error_handler` (`PiiRedactionMiddleware` triggers one on every `create()`). `Runtime` carries no callbacks, tags or metadata, so the tool selector's call is not nested under the agent's trace. Scripted fake models stamp a fresh run id on every reply, so replaying one `AIMessage` appends where upstream's single object (id assigned once) replaces; the modelCallLimit thread tests give the message an explicit id. `Agent::create` takes no stream-mode option, so tests set `streamMode` on the compiled graph. A test helper must not be named `run()` or `status()` (final on PHPUnit `TestCase`).
  - **WP-10 store backends** 🟡: `LangGraph\Store\Postgres\{PostgresStore,...}` (PDO, migrations, filters, TTL, pgvector), `LangGraph\Store\Redis\{RedisStore,FilterBuilder,RedisStoreIndexConfig}` (shares the Redis saver's client seam, which gained `ftInfo()`, `ftSearch` RETURN / PARAMS / DIALECT and VECTOR fields) and `LangGraph\Store\MongoDB\{MongoDBStore,MongoStoreCollectionInterface,...}`. Postgres is env-gated on `LANGGRAPH_PG_DSN` with a local-socket fallback (so it ran live in the final measured run), Redis on `LANGGRAPH_REDIS_URL` plus ext-redis, MongoDB on `LANGGRAPH_MONGO_URI` plus ext-mongodb. **Partials:** `MongoDBStore` has no `fromConnString` / `ownsClient` (`stop()` is a no-op), and has never run against a real MongoDB or Atlas (no production driver adapter, no composer dependency); `RedisStore` dropped `fromCluster`. `FakeMongoStoreCollection` is standalone because `FakeMongoCollection` is final.
  - **WP-25 MCP adapters** 🟡 (stretch): `LangGraph\Mcp\*` is a **conversion layer over `McpClientInterface`, not an MCP client**. `client.ts` / `connection.ts` / the client-config types are **not ported**: there is no `MultiServerMCPClient`, no stdio, HTTP or SSE transport, and no live-server test. Entry points: `McpTools::loadMcpTools($serverName, $client, $options)`, `Content::convertCallToolResult`, `Hooks::parse*`, `Elicitation::*` (drives `interrupt()`; a resume re-runs the tool from round one, so effects must be idempotent) and `Errors::*`. `JsonSchemaValidator` is an in-house draft-07 / 2020-12 subset validator. 211 tests over a `FakeMcpClient`.
  - **Follow-up fixes (all FIXED):** `handleLLMNewToken` now receives the streamed chunk in its `$fields` slot at 8 call sites; the `__handled__` marker no longer appears in debug `task_result` payloads (`PregelRunner::taskResult()` skips `Constants::HANDLED`, the marker stays in `task->writes`); `Completions::deltaToChunk` and `choiceToMessage` keep streamed `reasoning_content`, so Fireworks and Together carry it and both upstream `streams reasoning` tests are converted; `ReactAgentToolNodeTest` is back to upstream's two-call form.

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
   (`createReactAgent`, WP-03), the `LangGraph\Agents` foundations (WP-21a), `createAgent` with
   middleware (WP-21b), structured responses and transformers (WP-21c), and Supervisor / Swarm
   (WP-11) landed, and in Wave 4 the pre-built middleware sets (WP-22a/b/c) and `initChatModel` (WP-20),
   all 🟡. **Not ported:** the v3 run stream the transformers would plug into (so the v3 `run.messages`
   middleware cases are skipped), `ContextOverflowError`, and string-model resolution in
   `ModelFallbackMiddleware`, `ToolEmulatorMiddleware` and `ModerationMiddleware` (see Open observations).
2. **Finish the provider clients.** 🟡 `ChatOpenAI` (Chat Completions and Responses), Azure
   OpenAI and OpenAI `Profiles`, `ChatAnthropic`, `ChatOllama`, `ChatFireworks`, `ChatTogetherAI`,
   `ChatDeepSeek`, `ChatXAI` (Completions only), `ChatOpenRouter`, and the OpenAI / Anthropic
   hosted-tool builders are ported and tested against fake transports. Outstanding: **the xAI
   Responses API (a Wave 4 / backlog candidate)**, Anthropic `extract_generated_files` and
   prompt caching helpers, and every provider not listed here.
3. `langgraph` client SDK — 🟡 core (WP-23a) plus the runs client, `joinStream`,
   `streamWithRetry` and signals (WP-23b) landed. Not ported: `ThreadsClient::stream()` (the v2
   thread-centric protocol) and the internal `~ui` client. `RemoteGraph` (WP-26) is 🟡 partial:
   it runs over `runs->create` + `joinStream` and ports only part of `RemoteGraphRunStream`.
4. Concrete vector-store backends, concrete retrievers, concrete document loaders and
   concrete memory classes. ⬜ The base layer plus `MemoryVectorStore` landed (WP-13a/b);
   the concrete embeddings are `OpenAIEmbeddings`, `AzureOpenAIEmbeddings`, `FireworksEmbeddings`,
   `TogetherAIEmbeddings` and `OllamaEmbeddings`.
5. MongoDB checkpoint saver and store. 🟡 `MongoDBSaver` landed over a collection seam (WP-09c) and
   `MongoDBStore` in Wave 4 (WP-10c, no `fromConnString` / `ownsClient`); neither has **ever run against a
   real MongoDB** and there is no production driver adapter. Postgres
   (spec-verified, env-gated in CI) and Redis (fake-verified; real server env-gated on
   `LANGGRAPH_REDIS_URL`) landed in Wave 1.
6. `langgraph/graph` — 🟡 `Graph`, `CompiledGraph`, `Branch`, `MessageGraph` (WP-04),
   `StateGraph` parity (WP-05, non-exact) and drawing / `getGraph` (WP-06; `drawMermaidPng` never run against
   the real mermaid.ink) landed.
7. `langgraph/func` — 🟡 `entrypoint` / `task` landed (WP-07); sequential, no `custom` stream mode.
8. `utils`: 🟡 `env`, `json_patch`, `function_calling` landed (WP-12a is partial, see
   PORT_STATUS); `standard_schema` and `tiktoken` ⬜.
9. `load/import_map`. ⬜ (`structured_query` landed in WP-14; `indexing` and `example_selectors` in WP-13a, minus the `FewShot*` prompt templates.)
10. `streamEvents` v3 protocol layer, the `custom`/`checkpoints`/`tasks` stream modes,
    and the Wave 1 partials (WP-12a, WP-15). 🟡
11. MCP client and transports. ⬜ WP-25 ported only the conversion layer (`LangGraph\Mcp\*`) over
    `McpClientInterface`; `MultiServerMCPClient`, stdio / HTTP / SSE transports and OAuth are not ported, and
    no live MCP server has ever been talked to.

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

- **NEW (Wave 4), `Agents\Model::isConfigurableModel()` does not recognise the real `ConfigurableModel`.**
  `src/LangGraph/Agents/Model.php` checks the LangGraph sub-interface (`LangGraph\Agents\ConfigurableModelInterface`), and the
  real `LangChain\LanguageModels\Chat\Universal\ConfigurableModel` implements only the LangChain one, so the check misses it at
  `AgentNode.php:580` and `Utils.php:297` / `:374`. Behaviour is currently equivalent (`ConfigurableModel` has its own `bindTools`, so
  `simpleBindTools` handles it first, and `profile()` delegates to the built model). Fix per the WP-20 reviewer: check the LangChain
  interface by FQCN (the layering guard forbids a `use`).
- **NEW (Wave 4), `ConfigurableModel::generate()` does not pass the call config** to `getModelInstance()` where upstream passes the
  options. It is effectively unreachable because `invoke` / `batch` are overridden and delegate first. The cache key also covers only
  `configurable` where upstream stringifies the whole config (declared in the docblock).
- ~~**NEW (Wave 4), string models still do not resolve through `initChatModel` in three middleware.**~~ **FIXED** (`chore(integrate)` after Wave 4): `ToolEmulatorMiddleware` (temperature 1), `ModelFallbackMiddleware`, OpenAI `ModerationMiddleware` (unwraps the `ConfigurableModel` to its client), `LlmToolSelectorMiddleware` and `SummarizationMiddleware` all call `\LangChain\LanguageModels\Chat\Universal\InitChatModel::init()` by FQCN (the layering guard still forbids the `use` form). `StubInitChatModel` is gone; the string-model tests drive a real `ollama:` / `langsmith:` client against `tests/Unit/Agents/Support/LocalProviderServer` (a throwaway `php -S` on 127.0.0.1) via `OLLAMA_BASE_URL` / `LANGSMITH_GATEWAY`.
- **NEW (Wave 4), `ChatAnthropic` ignores `cache_control` bound through `bindTools()`.** `invocationParams()` reads it only from per-call
  options, so `PromptCachingMiddleware`'s `modelSettings.cache_control` never reaches the request body. Fix it in the Anthropic client,
  then un-skip `PromptCachingMiddlewareTest::testTheRealAnthropicClientForwardsTheBoundCacheControlOnTheRequestBody`.
- **NEW (Wave 4), a `Command` from an MCP `afterToolCall` hook is JSON-stringified under `ToolNode`.** Reported by the WP-25 implementer and
  not re-probed here: a tool built with `content_and_artifact` returns a `[Command, artifacts]` tuple that `CommandPassthroughTool`
  captures instead of the `Command`. Upstream returns the `Command` directly. Fix: `ToolNode` should unwrap a `[Command, artifact]` tuple.
  Also reported: a refused elicitation resume value is recorded against the task, so a second resume on one thread replays the first refusal.
- **NEW (Wave 4), flaky wall-clock tests under load.** `Unit\Pregel\TimeoutTest::testCallbackEventsRefreshTheIdleClockUnderAuto` and
  `testTheCustomStreamWriterCountsAsProgress` failed once during landing under heavy machine load (the 150ms idle budget elapsed at 289ms);
  the next run was green. The WP-22b implementer separately saw `testTheCustomStreamWriterCountsAsProgress` flake on a loaded machine. The WP-22a backoff tests also keep upstream's
  wall-clock upper bounds and may flake the same way. Rerun before blaming a change; a real fix would inject the clock or widen the budget.
- **NEW (Wave 4), small middleware gaps from the reviewers (none blocking):** `Constants::normaliseRetryOn` uses `class_exists`, so a
  `retryOn` of `[\Throwable::class]` (an interface) is rejected; an explicit null numeric option falls back to the default where Zod would
  reject it; `ProviderToolSearchMiddleware` falls through with the model's name (`default => $name`) where upstream says `other`;
  `PiiRedactionMiddleware` yields null instead of `{}` for an `extract-*` call with empty args; `LlmToolSelectorMiddleware` only treats
  `null`, not an empty string, as `use the request model`; `PhpRedisClient::ftInfo` resets `OPT_REPLY_LITERAL` to `false` instead of its
  previous value; `Errors::fields()` in the MCP layer never reads `getCode()`, so an exception carrying an HTTP status only in its code is
  not seen by `getHttpErrorCode`; `PostgresStore` `inner_product` search deliberately ranks best-first where upstream ranks by the raw
  `<#>` value (a fix of an upstream bug, listed in the non-exact table).
- **NEW (Wave 4), no production MongoDB driver adapter and no MCP client.** Both are known gaps rather than bugs: model the former on
  the test-only `MongoDBStoreDriver*` classes; the latter needs an MCP SDK for PHP (or an in-house client) behind `McpClientInterface`.

- **One nondeterministic hang.** During Wave 1 landing a single full-suite run hung at test
  ~1853/6325 (about `ProviderClientRegressionTest::testAnthropicStreamingFailureBecomesAnAnthropicException`)
  until composer's 300s timeout. It did not reproduce in 3 full runs, 15 isolated runs, or any
  later run. Cause unknown; investigate if it recurs.
- **One nondeterministic mass failure.** During Wave 3 landing a single `sync_docs.py` measurement produced **4410 errors across
  unrelated classes** (`HashTest`, the Redis specs, the transport tests). The suite run immediately afterwards passed, and the
  cause is unexplained; the suspicion is machine load from about 13 concurrent verifier suites. Logged next to the Wave 1 hang
  because both are one-off, unreproduced and load-shaped. If a count measurement ever looks wild, re-run the suite before
  believing it (`sync_docs.py` refuses to write on a failing run, which is what protected the ledger here).
- ~~**ENGINE BUG, found by WP-03 and NOT fixed: `LangGraph\Channels\Topic::fromCheckpoint()` misreads exactly two queued `Send`s.**~~
  **FIXED (Wave 3, 2026-10-08).** The `[seen, values]` branch (`src/LangGraph/Channels/Topic.php`) was taken for any
  2-element list of arrays; it is now guarded by `$this->unique`. Corrected repro, because the first one was wrong:
  the bare `StateGraph` / `Send` pattern (a conditional edge returning two `Send('work', ...)`, one calling `interrupt()`)
  does **NOT** reproduce the loss. Only a `ReactAgent` v2 making **exactly two parallel tool calls with an interrupt in
  one of them** does: before the fix `getState()->next` was `[]` with `tasks=0` and resume returned only
  `[user, 'ai response']`; after it `next=['tools']` and `tasks=2`. The 1 / 2 / 3-`Send` `StateGraph` cases in
  `tests/Unit/Channels/TopicTwoSendsRegressionTest.php` are controls; the reproducing case is
  `testTwoParallelToolCallsWithAnInterruptSurviveCheckpointAndResume`, mutation-checked by removing the guard. The port
  **deliberately drops** upstream's legacy pre-flat `[seen, values]` read for non-unique topics (it has no legacy
  pre-flat checkpoints). ~~Leftover: `tests/Unit/Prebuilt/ReactAgentToolNodeTest.php` still runs upstream's two-call case with
  three calls under a now-stale comment.~~ **FIXED (Wave 4):** the test is back to upstream's two-call form (weather / human_assistance).
- ~~**WP-05 follow-up: skip `PregelLoop::cacheTaskWrites` for writes produced by an error handler.**~~ **FIXED (Wave 3,
  2026-10-08).** A handler outcome now carries a reserved `Constants::HANDLED` (`__handled__`) write, appended by
  `StateGraph::getUpdates`, and `cacheTaskWrites` returns early if any write has that key (upstream never caches a failed
  task, `loop.ts:838-853`). The pin was inverted to `NodeErrorHandlerTest::testAHandledFailureIsNotCachedUnderTheFailedNodesKey`.
  Still open: a node with an error handler gets `compiledRetry = null`, so a graph-level `retryPolicy` could re-run the whole
  wrapper (node plus handler).
- ~~**NEW, `PregelRunner::taskResult` leaks the marker.**~~ **FIXED (Wave 4):** `taskResult()` now skips `Constants::HANDLED` as it does `NO_WRITES`, pinned by `tests/Unit/Pregel/DebugTaskResultHandledMarkerTest.php`. Original report: it copies every write except `NO_WRITES` into the debug-mode
  `task_result` payload, so a handled node shows `{"foo":"h","__handled__":true}` in the `debug` stream. Channel state, `updates`
  and `values` are unaffected, and no test pins the leak. A one-line filter on `Constants::HANDLED` (as for `NO_WRITES`) closes it.
- ~~**NEW, `Completions::deltaToChunk` drops streamed `reasoning_content`.**~~ **FIXED (Wave 4):** `deltaToChunk` and `choiceToMessage` copy it into `additional_kwargs`; both Fireworks / Together `streams reasoning` tests are converted (`CompletionsReasoningContentTest` has the unit cases). Original report: `ChatFireworks` and `ChatTogetherAI` subclass
  `ChatOpenAICompletions` unmodified, so they lose reasoning deltas while streaming (`ChatDeepSeek` and `ChatOpenRouter` carry
  it themselves); two upstream stream-reasoning tests are not converted for that reason. Fix it in
  `src/LangChain/LanguageModels/Chat/OpenAI/Utils/Completions.php` and the two Fireworks / Together tests can follow.
- **NEW, `ParentCommand` is not handled by the engine.** `PregelRunner::runWithRetry` / `commit` and `CallScheduler` never re-address
  a `ParentCommand`, which `StateGraph::controlBranch` throws for any `Command(graph: PARENT)`, so a handoff tool inside a plain
  subgraph kills the run. WP-11 works around it with `Supervisor\ParentCommandBridge`; handling it in the runner as upstream's
  `_runWithRetry` does would make the bridge removable.
- **Other Wave 3 gaps:** `LangGraph\Errors\RemoteException` does not exist, so `RemoteGraph` throws a plain `\RuntimeException` on a server
  `error` event; ~~`ChatOllama` and `ChatOpenAICompletions` call `handleLLMNewToken($text, ['chunk' => $chunk])`, which puts the fields array in
  the `$idx` position~~ **FIXED (Wave 4):** 8 call sites now pass the chunk in `$fields` (pinned by `LLMNewTokenChunkFieldsTest`); the messages reducer treats equal message ids as an update, so a scripted
  provider reusing one response id collapses the AI messages in an agent loop; `AzureChatOpenAIResponses::url()` builds its URL by string cutting.
- **570 skipped tests are env-gated suites and declared skips** (measured in the final Wave 4 run): 455 are
  `MongoDBSaverIntegrationTest` (gated on `LANGGRAPH_MONGO_URI` plus `ext-mongodb`), 75 `RedisStoreIntegrationTest`
  (`LANGGRAPH_REDIS_URL` plus ext-redis), 29 `MongoDBStoreIntegrationTest` and 4 `MongoDBStoreContractTest` (Mongo again),
  and 7 single-test skips in the middleware suites (3 summarization, 1 each for HITL, LLM tool selector, prompt caching and
  tool emulator). They inflate the Skipped count in `composer test`; neither MongoDB class has ever been exercised against a real server.
- **Other Wave 2 gaps owned by later work:** `Pregel::validate()` is not auto-run; `StateGraph::compile(['checkpointer' => true])` becomes a base `MemorySaver`; `Signals::mergeSignals` has no `src` caller until
  `BaseClient::prepareFetchOptions` gets the timeout-signal merge; `tests/Unit/Sdk/Support/RecordingTransport`'s
  "Streaming is WP-23b" message is stale; `Runtime.writer` / `Runtime.interrupt` in `Agents` read config keys
  nothing injects yet.
- **Slow retry tests.** That test and its OpenAI twin take about 3.4s each because a retryable
  429 hits the real `usleep` backoff. The backoff method is overridable but these tests do not
  override it.

---

## Where to start

Recommended order, and why. Waves 0, 1, 2, 3 and 4 of `.loop/COMPLETION_PLAN.md` have landed. **Wave 4 was the last planned wave, and the port is not complete**: the 🟡 rows in `PORT_STATUS.md` and the NOT-ported list above are the remaining work. Wave 4 landed WP-20, WP-22a/b/c, WP-10 (three stores), WP-25 (conversion layer only) and four follow-up fixes; every one of the nine work packages is 🟡.

1. **Close the Wave 4 follow-ups in Open observations first, they are cheap:** fix `Agents\Model::isConfigurableModel()`, make `ChatAnthropic` honour bound `cache_control` (then un-skip its test), and have `ToolNode` unwrap a `[Command, artifact]` tuple.
2. **Older open follow-ups:** `ParentCommand` handling in the runner (then drop `ParentCommandBridge`), `LangGraph\Errors\RemoteException`, and `ContextOverflowError` in the core errors (then un-skip the modelRetry case).
3. **Backlog candidates:** a production MongoDB driver adapter (then run both Mongo specs against a real server), an MCP client with transports behind `McpClientInterface`, the xAI Responses API, `ThreadsClient::stream()` (v2) with the v3 run stream and the remaining `RemoteGraphRunStream` projections (which would also un-skip the v3 middleware cases), a real-server run of `drawMermaidPng`, and then the NOT-ported list.

`Pregel`/`CompiledStateGraph` carry a `checkpointerDisabled` flag set by `StateGraph::compile`.

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
