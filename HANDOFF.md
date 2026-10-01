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
| Tests | **3504 passing, 8027 assertions** |
| Size | 232 src files / 35,030 lines · 130 test files / 27,840 lines |
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

**Two structural lessons worth carrying forward:**

1. *A green suite of unit-tested pieces is not evidence of a working
   integration.* The `StateGraph` bug survived 156 tests. Write end-to-end
   tests that run real graphs, and write them **after** the unit tests so they
   are testing the thing rather than mirroring its own assumptions.
2. *Run the suite more than once.* A failure that moves between runs is a
   nondeterminism bug, and that is how the `uuid6` defect was found.

---

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
- **langgraph-checkpoint**: BaseCheckpointSaver, MemorySaver, **SqliteSaver**,
  and the `serde` layer (`JsonPlusSerializer` + `_default` replacer + `_reviver`
  + `LcConstructorLoader`)

---

## What is NOT ported

Ordered by what unblocks real usage first.

1. **Prebuilt agents — `createReactAgent`, `ToolNode`.** ⬜ **Do this first.**
   Everything they depend on now exists, including a real provider. This is the
   payoff step.
2. **Finish the provider clients.** 🟡 `ChatOpenAI` (Chat Completions) and
   `ChatAnthropic` are ported and tested. Outstanding: the OpenAI **Responses
   API** (`converters/responses.ts`, 1,868 lines — a different event protocol,
   not a flag), `azure/`, model `profiles`, the hosted tools, Anthropic's
   citation output parsers. Every other provider is an HTTP wrapper over these
   two.
3. `langgraph` client SDK (REST) — the `client`/`runs`/`threads`/`stores` HTTP
   surface. ⬜
4. Vector stores, embeddings, retrievers, memory, document loaders. ⬜
   Interfaces and seams exist; no concrete backends.
5. Postgres / Redis / MongoDB checkpoint savers. ⬜ `BaseCheckpointSaver` is
   the seam they plug into, and the shared validation spec already runs against
   two savers — a third should be cheap.
6. `langgraph/graph` — the low-level `Graph` builder, `MessageGraph`, drawing.
   `StateGraph` is done; this is the layer under it.
7. `langgraph/func` — the `entrypoint`/`task` API. ⬜
8. `utils`: `env`, `json_patch`, `function_calling`, `standard_schema`,
   `tiktoken`. ⬜
9. `structured_query`, `indexing`, `example_selectors`, `load/import_map`. ⬜

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
- Serde: `undefined` is read (→ `null`) but never written; `RegExp` records stay
  inert; an `Error` revives as `\RuntimeException` (message only); an empty map
  serialises as `[]`.

---

## Where to start

Recommended order, and why:

1. **`createReactAgent` + `ToolNode`.** The visible payoff, and every
   dependency now exists. `ToolNode` needs `BaseToolkit` and `ToolRuntime`,
   which are ported but currently unreferenced by anything — the orphan audit
   flags them, and building the agent is what wires them.
2. **Postgres/Redis savers.** Cheapest real coverage per effort — the
   validation spec already runs against two implementations.
3. **The OpenAI Responses API**, if a model that needs it matters.
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
