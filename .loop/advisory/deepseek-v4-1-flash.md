# Advisory review — deepseek/deepseek-v4.1-flash

_Generated 2026-09-30T11:42:05.765545+00:00_

# Whole-System Advisory Review — langchain-php

I am reviewing the brief, not the source tree. Where I cannot point at a file:line, I say so. Section 5 items are referenced only where they remain unfixed; I do not re-analyse them.

---

### layering
**Verdict:** needs work
**Evidence:** Cross-package reference counts: `LangGraph -> LangChain: 15 files`. `Pregel.php` public surface shows `public array $channels` (mutable) alongside `public readonly array $nodes`. Diagram stack order: Provider Clients → Core Abstractions → Composition → Tools+Parsers → Messages → LangGraph Engine.
**Finding:** The dependency *direction* is correct — LangGraph is the higher-level engine and depends on LangChain (15 files), not the reverse. But Pregel exposes mutable public state (`$channels`) that callers can mutate directly, which leaks the engine's internal channel registry into its public surface. The diagram's vertical stack is inverted from real dependency direction (LangGraph sits at the bottom of the picture but is the top of the dependency graph); a generated view that reads as "LangGraph is a leaf" is misleading. Whether any of the 15 LangGraph→LangChain imports is a *provider-specific* class (ChatOpenAI/ChatAnthropic) — which would be a genuine violation — the brief does not show.
**Suggested change:** Encapsulate `Pregel::$channels` behind an accessor. Audit the 15 LangGraph→LangChain imports for provider-specific classes and report the result.

### public-api
**Verdict:** needs work
**Evidence:** `Pregel.php`: `__construct(public readonly array $nodes = [], public array $channels = [], public readonly string|array $inputChannels = [], ...)`, plus `getName()`. `BaseChatModel`: 9 methods, `bindTools(...): static`, `withStructuredOutput(...): Runnable`. `Schema`: `__construct(public readonly array $schema)`.
**Finding:** BaseChatModel's surface is coherent — `bindTools` returns `static` (correct for fluent binding), `withStructuredOutput` returns the Runnable abstraction (correct). Schema's readonly promoted array is acceptable for a value object. Pregel is the leak: `$channels` is mutable public state on the engine, and `getName()` on a Pregel is an odd public method — a graph is not a named runnable in the usual sense, and the brief shows no caller contract for it.
**Suggested change:** Make `$channels` non-public; justify or remove `getName()` from Pregel's public surface.

### composition
**Verdict:** needs work
**Evidence:** Section 5 audit note: *"RunnableSequence streams the FIRST step and then INVOKES steps 2..n, yielding only their final value."* Section 5: *"it can only manifest when a chat model sits at position 2..n of a sequence. That is the common `prompt | model | parser` shape."*
**Finding:** Invoke-path composition works. Streaming composition is broken at the sequence seam: `prompt | model` places the model at position 2, so the model is *invoked* rather than streamed — the common streaming shape does not stream. This is the one accepted, unfixed composition defect. (Referenced, not re-analysed — it is section 5 material and remains unfixed.)
**Suggested change:** Fix `RunnableSequence::stream()` to stream every step, or add the divergence to PORT_STATUS.md's known-divergences table.

### error-handling
**Verdict:** needs work
**Evidence:** `LangGraph.Errors`: 13 src, 389 lines, **1 test file**. Load-bearing guard `TransportExceptionContractTest` (3 tests: OpenAI wraps, Anthropic wraps, eager path wraps).
**Finding:** 13 exception classes in 389 lines (~30 lines each) with a single test file is thin coverage for the error taxonomy. `TransportExceptionContractTest` is the right *shape* — it proves a caller can catch one type and trust it across providers — but it covers transport only. Whether the other 12 error types are catchable-and-trustworthy is not evidenced by the brief.
**Suggested change:** Add a contract test per error family asserting the wrapping/translation behaviour, mirroring `TransportExceptionContractTest`.

### fidelity
**Verdict:** sound (with one gap)
**Evidence:** Section 6 enumerates ~30 deliberate divergences, each pinned by a test. Section 5 shows three fidelity misreads rejected by probing upstream first (text-plain, mergeStatus, Anthropic tool conversion).
**Finding:** The fidelity discipline is strong — divergences are enumerated and pinned, and reviewers are required to quote upstream before asserting a defect. The gap is that the RunnableSequence streaming divergence is a known divergence *not* in the section 6 table.
**Suggested change:** Add the sequence-streaming divergence to PORT_STATUS.md's known-divergences table.

### test-strategy
**Verdict:** needs work
**Evidence:** 2300 tests, 6383 assertions (2.77 assertions/test). Load-bearing guards: `PhpVersionCompatibilityTest`, `DocsMatchRealityTest`, `TransportExceptionContractTest`. `RunnableTest.php:56` pins that a lambda streams exactly one chunk.
**Finding:** The suite is broad but shallow per test (2.77 assertions/test). The load-bearing guards are well-chosen — version floor, docs-vs-reality, transport contract. But the suite cannot see the sequence-streaming divergence because no test composes `prompt | model | parser` and asserts streaming tokens; `RunnableTest.php:56`'s one-chunk lambda assertion actively masks it.
**Suggested change:** Add a test that composes a prompt, a chat model, and a parser and asserts the model's chunks are yielded incrementally.

### documentation
**Verdict:** needs work
**Evidence:** `DocsMatchRealityTest` guards: `testPortStatusTotalIsNotUnderstatingCoverage`, `testHandoffFileCountsAreCurrent`, `testTheSizeRowLineCountsAreCurrent`, `testHandoffStatesACurrentSuiteSize`. Diagram title: *"230 src files."* Namespace inventory sums to **222**.
**Finding:** The docs are actively guarded against drift, which is rare and good. Two gaps: (1) the namespace inventory sums to 222 src files against the diagram's 230 — an 8-file discrepancy the brief does not explain; (2) the sequence-streaming divergence is not in PORT_STATUS.md's known-divergences table, so the record understates known non-exact behaviour.
**Suggested change:** Reconcile the 230 vs 222 count; add the streaming divergence to the table.

### dead-code
**Verdict:** sound
**Evidence:** `LangChain.Utils.Testing`: 9 src, 0 test files — but brief states `RunCollectorCallbackHandler` referenced by 10 files, `FakeHttpClient` by 9, `StructuredToolSpec` by 3. `LangChain.Schema`: 4 src, 0 test files. `LanguageModels.Outputs`: 6 src, 0 test files — but `LLMResult` referenced by 11 files, `ChatGeneration` by 10, `ChatGenerationChunk` by 8.
**Finding:** The 0-test-file namespaces are explained by the brief as test-support or indirectly-referenced code. I cannot confirm any written-but-unreachable code from the brief alone; the brief does not show a dead-code scan.
**Suggested change:** none (or: run a static reachability check to confirm).

---

## A. Composition — two journeys

**Journey 1: bind tools to a model, stream a response inside a checkpointed StateGraph run.**
- `bindTools`: `BaseChatModel::bindTools` returns `static`; ChatOpenAI/ChatAnthropic implement it. Section 6 confirms tools reach Anthropic converted (`testEveryRouteSendsTheSameToolDefinition`).
- `stream`: `BaseChatModel::stream` returns `Generator`; the Pregel loop is a `Generator`.
- Checkpointing: `LangGraph.Checkpoint` 11 src / 12 test files — well covered.
- **Seam:** if the node body is `prompt | model`, the model sits at position 2 and `RunnableSequence` invokes rather than streams it. Streaming dead-ends at the sequence seam. If the node calls the model directly (no sequence), streaming works.

**Journey 2: structured output with tools.**
- `withStructuredOutput` returns `Runnable`; the base implementation is function-calling only (`jsonMode`/`strict` refused). `bindTools` + `withStructuredOutput` compose. This journey works.

**Named seams:** (1) `RunnableSequence::stream()` — model at position 2..n is invoked, not streamed. (2) Pregel's public `$channels` — a caller mutating it bypasses the engine's channel contract.

## B. Layering violations

- `LangGraph -> LangChain: 15 files` is the correct direction.
- The diagram's vertical stack (Provider Clients at top, LangGraph at bottom) is inverted from real dependency direction — LangGraph depends on LangChain core, not the reverse. The diagram is a generated view and may mislead a reader into thinking LangGraph is a leaf.
- Whether the graph layer knows about specific providers: **the brief does not show this.** I cannot confirm or deny.

## C. Honesty of the record

- `DocsMatchRealityTest` guards counts — strong.
- **Contradiction:** diagram says 230 src files, inventory sums to 222.
- **Gap:** sequence-streaming divergence not in the known-divergences table.
- **Ported-but-untested:** `LangChain.Schema` (4 src, 0 test files), `LanguageModels.Outputs` (6 src, 0 test files) — referenced but no dedicated tests.
- **Unported-but-implied:** OpenAI Responses API is explicitly not ported (section 6) — honest.

## D. What the green suite hides

1. **The sequence-streaming divergence** — no test composes `prompt | model | parser` and asserts streaming; `RunnableTest.php:56` masks it.
2. **The 13 `LangGraph.Errors` classes with 1 test file** — exception-type coverage is thin; a caller catching a specific type is not proven to be able to trust it.
3. **`LangChain.Schema` and `LanguageModels.Outputs` with 0 test files** — branches may be unexercised despite references.

## E. Highest-leverage change

Fix `RunnableSequence::stream()` to stream every step. It is the one accepted, unfixed, composition-breaking defect; it affects the common `prompt | model | parser` shape; and it is invisible to the current suite. Fixing it *with* a test that composes the three closes both the defect and the test blind spot in one move.

---

## Ranking by (impact × confidence)

1. **Sequence-streaming divergence** — high impact, high confidence (accepted in section 5, still unfixed).
2. **Pregel public mutable `$channels`** — medium impact, high confidence (visible in the public surface).
3. **Sequence divergence missing from PORT_STATUS table** — medium impact, high confidence.
4. **230 vs 222 src-file discrepancy** — low impact, medium confidence.
5. **Thin error-taxonomy test coverage** — medium impact, medium confidence.
6. **0-test-file namespaces** — low impact, low confidence (brief explains them).
