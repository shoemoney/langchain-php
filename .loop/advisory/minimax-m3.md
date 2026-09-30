# Advisory review — minimax/minimax-m3

_Generated 2026-09-30T19:52:58.656521+00:00_

# Advisory Review — langchain-php

I'll work through the 8 elements, then the cross-cutting A–E, holding to the brief's own rules: no restating section 5 as findings, no claims about present behaviour without present-tense evidence, and no invented defects from a stale ledger.

---

## 1. Layering

**Verdict:** needs work
**Evidence:** Brief §3 — `LangGraph -> LangChain: 15 files`. Diagram shows the layers stacked top-down (PROVIDER → CORE → COMPOSITION → TOOLS → MESSAGES → LANGGRAPH). 15 cross-package references in one direction is small in absolute terms but non-zero, which is what matters for layer purity: every one of those is a place a low-level module imported something from a higher layer. The brief does not name which 15 files. PORT_STATUS row "**`HttpClient` parameter names are not part of the contract**" lives in `Utils\Http` and is the canonical example of a contract that crosses every layer that calls it.
**Finding:** The down-stack is clean. The up-stack (LangGraph importing LangChain) is the leak to check. 15 files is small but it is also the only data point the brief offers — without per-file evidence I cannot name the offenders. The bigger smell is the *type* of cross-layer data: an `HttpClient` interface whose parameter names become part of the contract is exactly the kind of low-level utility whose callers (every provider) leak back into its signature. The `topK` row in §6 confirms a different layering smell: per-layer guards (constructor / bound / per-call) drift apart when each layer carries its own alias list, and the fix had to unify them.
**Suggested change:** Enumerate the 15 `LangGraph -> LangChain` references by file and by class. Any one of them that imports a *concrete* LangChain provider (rather than a message type or schema constant) is a layer violation worth removing. If none do — and the count is all message/schema types — the layering is sound and the number is fine.

---

## 2. Public API

**Verdict:** sound
**Evidence:** Brief §4. `BaseChatModel` 9 methods, `Pregel` 6 (constructor counts as a public surface because the four promoted properties are public readonly), `Schema` 11.
**Finding:** The surfaces are tight. `BaseChatModel` exposes exactly what upstream's `BaseChatModel` exposes (generate/stream/bindTools/withStructuredOutput), the `Runnable` return type on `withStructuredOutput()` is the right abstraction (not a concrete `ChatOpenAI`), and `Pregel`'s promoted public fields match the upstream `langgraph-core/src/pregel/index.ts` evidence already cited in `[advisory/google-gemini-3.5-flash]` (lines 489/492/498/504/519/543). `Schema::from(mixed)` returning `self` is the right shape for a fluent validator builder. No leaky seams visible in the signatures themselves.
**Suggested change:** none. The Gemini-3.5 advisory already correctly rejected the "encapsulate Pregel's public fields" suggestion on fidelity grounds, and that stands.

---

## 3. Composition

**Verdict:** needs work
**Evidence:** Brief §6 row "`RunnableParallel` accepts any input, including a scalar" — pins a past fix for `RunnableParallel::invoke()`. The cross-file `TransportExceptionContractTest` (brief §1 load-bearing guard) pins provider-to-transport wrapping for both `ChatOpenAI` and `ChatAnthropic`, eager and streaming. Brief §6 row "`RunnableBinding` merges bound kwargs into `config->options`" pins a past inert-`bind()` defect. So three load-bearing composition seams are tested in isolation — what is *not* named in the brief is a test that crosses RunnableBinding + RunnableParallel + ChatOpenAI together.
**Finding:** The named guards cover each seam individually. A test asserting "bind tools to a chat model and stream the structured output through a `RunnableSequence` whose first step is a `RunnableParallel` with a raw branch" would be the actual composition test, and the brief does not show one — the journey table the previous reviewer added (per `[audit/roster-ctx-and-journey-legibility]`) was reported to still confuse reviewers by listing test method names rather than capability claims. The composition guard is therefore: present at the seam level, absent at the journey level, and the ledger admits as much.
**Suggested change:** Replace method-name rows in the journey table with capability claims, each pinned by a *named* test that exercises more than one abstraction. The `kimi-k3` advisory independently asked for exactly this and the fix is overdue.

---

## 4. Error Handling

**Verdict:** needs work
**Evidence:** `LangGraph\Errors` — 13 src files, 1 test file. `[advisory/google-gemini-3.8-flash]` entry: `grep -rl 'LangGraph\Errors' tests/` returned zero before the fix; `ErrorTaxonomyTest.php` now pins the whole hierarchy. The hierarchy is faithful to upstream `errors.ts` (GraphDrained → GraphBubbleUp :65, GraphInterrupt → GraphBubbleUp :85, NodeInterrupt → GraphInterrupt :100, ParentCommand → GraphBubbleUp :164). Brief §6 row on the abandoned-stream `finally` double-report establishes that *one* path in `BaseChatModel::stream()` was found to report errors twice. `TransportExceptionContractTest` (brief §1) pins that providers wrap their own transport-raised exception types.
**Finding:** Two of the three load-bearing error contracts are pinned. The third — the **LangGraph exception hierarchy's interaction with the Pregel loop**  is pinned only for class-shape (does GraphInterrupt extend GraphBubbleUp?), not for behaviour (does the Pregel loop actually catch GraphBubbleUp and re-bubble, or does it swallow?). The brief names 13 errors and 1 test file; one test file is a hierarchy-shape test, not a loop-interaction test. A caller who catches `GraphInterrupt` to implement `interrupt()`-style human-in-the-loop needs to trust that the Pregel loop does not catch and rethrow as something else.
**Suggested change:** Add a test that drives a `Pregel` invocation whose node throws `NodeInterrupt`, and assert the caller sees a `NodeInterrupt` instance with the original payload. The test file already exists (`Errors/ErrorTaxonomyTest.php` per the advisory) — extend it, do not create a second one.

---

## 5. Fidelity

**Verdict:** sound
**Evidence:** Brief §6, eight rows, every row pinned by a named test. The two named upstream line citations in the brief are `langgraph-core/src/pregel/index.ts` (used to justify public properties in `Pregel`) and `chat_models.ts:750/825` (used to justify empty-stream throwing on both paths in `[advisory/google-gemma-4-31b-it]`). The schema-`enum`/`const` strictness was changed to match JS's `3 === 3.0` (audit `schema-enum-const-strictness`, iter 114). The schema-empty-object bug was a real PHP/JSON-shape asymmetry and was measured-against-upstream before fixing (iter 110).
**Finding:** Every named divergence is either (a) pinned by a regression test, or (b) explicitly stated as a "deliberate different choice" (`batch()` ignoring `$options` because no PHP equivalent). The "Known non-exact behaviours" preamble in §6 is the best part of the documentation: it states the two-kind taxonomy (cannot-reproduce vs deliberate), warns against a third "half-verified" kind, and points the reader at the ledger for the unverified remainder. That is exactly the framing a fidelity record should have.
**Suggested change:** none on the content. The §6 preamble's note "`DocsMatchRealityTest` checks counts, not framing" is a load-bearing confession — a future guard for that specific gap (e.g. a test that asserts the preamble still distinguishes the two kinds and is not silently narrowed) would be the only documentation-fidelity guard that catches the failure mode §6 admits exists.

---

## 6. Test Strategy

**Verdict:** sound
**Evidence:** Brief §1 enumerates four load-bearing guards by name: `PhpVersionCompatibilityTest` (floor + per-file `function_exists` scan), `DocsMatchRealityTest` (4 named assertions, one of which catches stale size rows), `TransportExceptionContractTest` (3 named assertions pinning provider-wraps-transport for both providers and both paths). 2336 tests, 6438 assertions, ~9s. `[advisory/grok-build-0.1]` records that three consecutive advisories produced zero repeats of any fixed defect — that is a measured property of the suite, not a hope.
**Finding:** The guards are load-bearing and the suite is fast. The two blind spots are (a) journey-level composition (per §3 above) and (b) the doc-framing gap §6 admits (no guard against the preamble silently being narrowed). Both are real, neither is severe. The `TriageStatusMarkerTest` — which caught the same class of mistake four times across iterations (per `cache-key`, `schema-enum-const-strictness`, `schema-object-empty-array`, and the marker itself) — is the strongest piece of meta-guarding in the project.
**Suggested change:** none on the existing guards. The two gaps above are well-localised additions, not rework.

---

## 7. Documentation

**Verdict:** needs work
**Evidence:** `PORT_STATUS.md` §6 "Known non-exact behaviours" is well-shaped and recently corrected (per `[advisory/claude-opus-5.5]` and `[advisory/fireworks-ember-1]`, both accepted). The "what is NOT in this table" paragraph is exemplary. `[advisory/gpt-chat-latest]` records that an earlier revision of this section had *two stacked preambles defining it differently* — fixed. `[advisory/amazon-nova-pro-v1]` was rejected for producing nothing gradable; six of its eight elements were "needs work" with no file, no line, no evidence. `[advisory/gemma-4-26b-a4b-it-free]` was a 429, not a review.
**Finding:** The substantive documentation is now strong — §6 is the standout, and its self-aware "what is NOT here" paragraph is the right move. The two remaining smells are: (1) the journey table that previous reviewers (including the ledger's own author) keep misreading, and (2) the absence of a guard that pins §6's framing. Both were named in §3 and §5 above.
**Suggested change:** Same as §3: rewrite the journey rows from method names to capability claims, each pinned by a named test.

---

## 8. Dead Code

**Verdict:** sound
**Evidence:** Brief gives no signal of dead code — no orphan namespace, no parsed-then-dropped branch, no unreferenced class outside the documented scope. `LangChain\LanguageModels\Outputs` (6 src, 0 test files) and `LangChain\Schema` (4 src, 0 test files) are both explained by the brief's own caveat about test-file count vs reference count: `LLMResult` is referenced by 11 files, `ChatGeneration` by 10, `ChatGenerationChunk` by 8. The diagram shows all six LANGGRAPH ENGINE boxes populated (PregelLoop, Algorithm, StateGraph, Channels, Checkpoint savers) — the `Algorithm` box is the one that historically attracts dead implementations in graph engines, and the brief does not name it as a problem.
**Finding:** The two zero-test namespaces are data-shape types, not dead code. No smell in the brief.
**Suggested change:** none.

---

## A. Composition — two journeys traced

**Journey 1: "bind tools to a model and stream a response inside a checkpointed StateGraph run"**
- Step 1: `new ChatOpenAI(...)` → constructs, `KEY_ALIASES` unified (brief §6 row 7).
- Step 2: `$model->bindTools([$tool])` → returns `static`, so a `ChatOpenAI` (brief §4 `BaseChatModel::bindTools` signature).
- Step 3: `$model->stream($messages, $config)` → `BaseChatModel::stream()` yields chunks. The `finally`-double-report was fixed (brief §6 row 6). Transport wrapping pinned (`TransportExceptionContractTest`).
- Step 4: Wrap in a `StateGraph` whose nodes call the bound model, attach a `MemorySaver` or `SqliteSaver`. `Pregel` exposes `getState`/`getStateHistory` (brief §4). `SqliteSaver::list()` honours `checkpoint_id` (audit `sqlite-list-only-checkpoint-id`, iter 127).
- **Seam:** the journey works at every named seam. The gap is that the *test* asserting this exact end-to-end path is not named in the brief. The closest thing the brief points to is `TransportExceptionContractTest` (provider↔transport) and `RunnableTest` (structured-output pipeline), neither of which spans the graph layer.

**Journey 2: "structured output via `withStructuredOutput()`, composed into a `RunnableSequence` with a `RunnableParallel` raw branch"**
- Step 1: `$model->withStructuredOutput($schema, ['includeRaw' => true])` returns a `Runnable` (brief §4 return type).
- Step 2: The `includeRaw` path runs `{raw: llm, parsed: parser}` as a `RunnableParallel` whose first step is the model. The scalar-input regression is pinned (brief §6 row 1 — `RunnableTest::testParallelPassesScalarInputToEveryBranch`).
- Step 3: `RunnableBinding` correctly merges kwargs so `bind()` is not inert (brief §6 row 2).
- Step 4: The whole thing as a step in a `RunnableSequence` whose stream yields every step (audit `sequence-stream-divergence`, iter 55 — `stepConfig()` no longer clobbers `runName`, audit `stepconfig-clobber`, iter 85).
- **Seam:** works end-to-end. The gap is the same as Journey 1: no single named test spans all four.

**Verdict for A:** Both journeys work at every named seam. The system *composes*. The honest gap is that the suite proves each seam in isolation and trusts the reader to compose them — which is exactly what the previous `roster-ctx-and-journey-legibility` audit was about, and the iteration-135 attempt was reported to still be misread.

---

## B. Layering violations

I cannot name a specific violation because the brief does not list the 15 `LangGraph -> LangChain` files. What I can say:
- **The graph layer does not appear to know about specific providers.** `LangGraph\Errors` (13 src) has no Chat-prefix imports named. The `HttpClient` interface is the seam, and it is provider-agnostic by design.
- **The schema layer is the one that risks upward leakage** because providers (OpenAI tool schemas, Anthropic tool schemas) all reach into `LangChain\Tools\Schema`. That is downward from CORE/TOOLS, not upward, so it is the *correct* direction.
- **The single named risk is the `HttpClient` parameter-name contract** (brief §6 row 3), which is a downward leak in the sense that every implementor's parameter name becomes load-bearing for callers — a contract property of a low-level utility, not a layering violation per se.

I have no finding to file here that the brief supports with a file:line.

---

## C. Honesty of the record

**PORT_STATUS.md §6** is the most carefully written part of the record. The two-kind preamble, the "what is NOT here" paragraph, and the per-row test pin all line up with the code. The journey table is the one piece the record itself admits is repeatedly misread — that is an honesty problem in the reader/record relationship, not in the record's truth.

**HANDOFF.md / DocsMatchRealityTest** is the right shape — four named assertions including one that checks size rows are current, which is exactly the class of "doc drifts from code" failure that would otherwise go silent.

**No contradiction between the brief and the documents visible to me.** The brief explicitly warns that section 5 of itself can be stale (the past-tense trap, the resolved-deferral-banner issue) and the project has built a `TriageStatusMarkerTest` to catch adding entries without status markers — that is the right defensive move.

**One thing the record does not name:** the `LangGraph\Errors` namespace had ZERO test-file coverage before `[advisory/google-gemini-3.8-flash]` flagged it. The PORT_STATUS §6 table is honest about ported-but-tested things; it is *not* explicit about ported-but-untested things. The hierarchy test now exists, so this is closed, but the next ported-but-untested hole would not be visible in PORT_STATUS.

---

## D. What the green suite hides

Three concrete places where a passing 2336-test run tells you nothing:

1. **The Pregel loop's handling of `NodeInterrupt`.** `ErrorTaxonomyTest` pins the class shape (does NodeInterrupt extend GraphInterrupt? yes). It cannot pin the runtime behaviour: when a node throws `NodeInterrupt` inside a `Pregel` `foreach` step, does the generator yield a partial result, throw to the caller, or swallow and re-emit as a different type? No named test in brief §1 exercises this path. A test asserting "Pregel::invoke() on a node that throws NodeInterrupt re-throws a NodeInterrupt to the caller" would be the missing guard. This is the single biggest composition-of-control-flow gap in the brief.

2. **End-to-end structured-output streaming.** `RunnableTest::testParallelPassesScalarInputToEveryBranch` pins one corner. Nothing in brief §1 pins: take a `ChatOpenAI` (with a fake HTTP client that streams a valid Anthropic-style SSE), bind a `StructuredTool`, call `withStructuredOutput`, and assert the resulting `Runnable` streams parsed JSON chunks that incrementally validate. Every individual piece is tested. The composition is not. A bug in the chunk-to-parser hand-off would pass every unit test.

3. **Checkpoint save→load round-trip with a non-trivial state schema.** `SqliteSaver::list()` is now pinned (audit `sqlite-list-only-checkpoint-id`, iter 127). But the brief does not name a test that: writes a checkpoint via `Pregel` execution, retrieves it via `Pregel::getState()`, mutates the input config, and resumes from it. A defect in the serde layer (e.g. a non-JSON-serialisable value in a channel that round-trips as a different value on load) would be invisible because the round-trip itself is not exercised.

---

## E. The single highest-leverage change

**Add one test: `PregelInvokeReRaisesNodeInterrupt`.** It runs a two-node `StateGraph` whose second node throws `NodeInterrupt($payload)`, calls `Pregel::invoke()` (or pulls one item from `Pregel::stream()`), and asserts the caller catches a `NodeInterrupt` whose payload is the original value.

**Why:** the entire point of the LangGraph error hierarchy (per upstream `errors.ts`) is that `NodeInterrupt` is a control-flow signal a caller catches to implement `interrupt()`-style human-in-the-loop. If `Pregel` swallows it, renames it, or wraps it, the hierarchy is correct shape with broken runtime — and a caller who writes `try { $graph->invoke(...) } catch (NodeInterrupt $e) { resume(...) }` has a silent deadlock. No other missing test in the brief has the same blast radius: every other gap is a wrong-answer, this one is a control-flow break that the suite cannot see at all. The test file already exists (`Errors/ErrorTaxonomyTest.php`), the assertion is small, and the fix-if-broken is local to `Pregel`.

---

## Findings ranked by (impact × confidence)

| # | Finding | Impact | Confidence | Net |
|---|---|---|---|---|
| 1 | No test pins `Pregel`'s runtime handling of `NodeInterrupt` — only the class shape. | High (control-flow break, caller catches the wrong type) | High (brief §1 names no such test; only `ErrorTaxonomyTest` exists in `LangGraph\Errors`) | **High** |
| 2 | No end-to-end journey test exists in the named guards — composition is proved seam-by-seam, never as a whole. | Medium (regressions in inter-seam contracts pass silently) | High (brief §1 lists guards; the journey table is documented as repeatedly misread) | **Medium-High** |
| 3 | The 15 `LangGraph -> LangChain` references are not enumerated — layer purity cannot be checked without it. | Medium (could be a clean signal, could be a real violation) | Medium (could not find per-file evidence in the brief) | **Medium** |
| 4 | `DocsMatchRealityTest` checks doc *counts*, not doc *framing* — the §6 preamble's two-kind taxonomy is unguarded against a silent narrowing. | Low-Medium (already happened once, per `[advisory/gpt-chat-latest]`) | High (advisory explicitly names the gap) | **Medium** |
| 5 | Journey table lists test method names rather than capability claims, and is repeatedly misread by reviewers. | Low (documentation legibility) | High (acknowledged in the ledger itself) | **Low-Medium** |
| 6 | `batch()` ignores `$options` — documented in §6 as deliberate, no test pins the *behaviour* (only the accept-and-ignore). | Low (already explicit) | Medium | **Low** |

**One finding I considered and rejected as a recommendation, per the brief's own rules:** the iteration-85 `stepConfig()` fix (`seq:step:N` moved from `runName` to `config->tags`) was repeatedly recommended as outstanding by stale-ledger advisories and is the canonical example of a fixed defect that reads as open. It is fixed. Do not re-open it.
