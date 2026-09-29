# HANDOFF — langchain-php

**Read this first.** Everything below is verified as of the commit named in
"State". Do not trust it if the repo has moved on — re-run `composer test`
first and believe the suite, not this file.

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
| Branch | `main`, clean tree, 10 commits |
| PHP | 8.5.11 installed; CI matrix on 8.2 / 8.3 / 8.4 |
| Tests | **1745 passing, 5303 assertions** |
| Size | 210 src files / 28,796 lines · 33 test files / 13,918 lines |

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

### 4. The message merge algebra is the streaming core

`MessageMerge` (`_mergeDicts` / `_mergeLists` / `_mergeObj`) decides per-field
whether to concatenate, sum, replace, recurse, or skip. Getting it wrong
silently corrupts reconstructed streams. It is heavily tested — leave it alone.

### 5. PHP parameter types are contravariant — this bit us twice

An override may **widen** but never narrow a parameter type. Both bugs below
came from narrowing in TS, which PHP forbids. When a TS signature narrows, keep
the shared base type and validate at runtime.

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

**Two structural lessons worth carrying forward:**

1. *A green suite of unit-tested pieces is not evidence of a working
   integration.* The `StateGraph` bug survived 156 tests. Write end-to-end
   tests that run real graphs, and write them **after** the unit tests so they
   are testing the thing rather than mirroring its own assumptions.
2. *Run the suite more than once.* A failure that moves between runs is a
   nondeterminism bug, and that is how the `uuid6` defect was found.

---

## House conventions

Follow these or CI fails you.

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

1. **Provider clients — OpenAI and Anthropic.** ⬜ **Do this first.** Nothing
   can call a real LLM without them. The seams are ready: `BaseChatModel`,
   `BaseLanguageModel`, `ChatGeneration`/`ChatResult` types, and the fake models
   in `src/LangChain/Utils/Testing/`. Every other provider is an HTTP wrapper
   over these two. Note the `StructuredTool` gap below.
2. **Prebuilt agents — `createReactAgent`, `ToolNode`.** ⬜ Everything they
   depend on now exists. This is the payoff step.
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

1. **OpenAI + Anthropic clients.** Unblocks everything real. Build
   `BaseChatModel::bindTools()` first — `StructuredTool` currently does not
   port `bindTools` / `withStructuredOutput` / provider formatting, and
   `FakeStreamingChatModel::bindTools()` is already ported and renders all four
   provider formats with **nothing calling it yet**. That gap is visible now
   and will be the first thing that looks like a bug.
2. **`createReactAgent` + `ToolNode`.** The visible payoff, and every
   dependency now exists.
3. **Postgres/Redis savers.** Cheapest real coverage per effort — the
   validation spec already runs against two implementations.
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
