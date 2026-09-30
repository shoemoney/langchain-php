# Advisory review — meta/muse-glimmer-30b

_Generated 2026-09-30T13:53:03.376306+00:00_

### 1. layering
**Verdict:** sound
**Evidence:** cross-package reference counts `LangGraph -> LangChain: 15 files`; namespace inventory shows `LangGraph.Pregel 25 src 5378 lines 7 test files` and `LangChain.Runnables 13 src 1412 lines 6 test files`. Diagram layers Provider Clients → Core Abstractions → Composition → Tools+Parsers → Messages → LangGraph Engine.
**Finding:** No evidence in the brief of a low-level utility importing a high-level abstraction or of the graph layer importing a specific provider. The 15 file LangGraph→LangChain references are the expected core-abstraction dependency.
**Suggested change:** none

### 2. public-api
**Verdict:** sound
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php (9 public methods)` `lcNamespace`, `modelType`, `supportsStreaming`, `bindTools`, `supportsToolBinding`, `withStructuredOutput`, `generatePrompt`, `generateMessages`, `stream`; `src/LangGraph/Pregel/Pregel.php (6 public methods)` `__construct`, `getName`, `invoke`, `stream`, `getState`, `getStateHistory`; `src/LangChain/Tools/Schema.php (11 public methods)`.
**Finding:** Surface is coherent and matches the upstream intent. `bindTools` throws by default and `supportsToolBinding` reflects on declaring class is a documented PHP divergence, not a leak.
**Suggested change:** none

### 3. composition
**Verdict:** needs work
**Evidence:** audit/stepconfig-clobber A/B: `RunnableBinding -> RunnableLambda -> runName='solo' CORRECT` vs `RunnableBinding -> RunnableSequence -> step -> runName='seq:step:1' WRONG`. `RunnableBinding::mergeConfig()` now merges bound kwargs into `config->options`. `RunnableLambda::invoke()` now passes config when arity >=2.
**Finding:** Subsystems compose for the happy path, but `RunnableSequence::stepConfig()` overwrites `runName` with `seq:step:N` before the binding’s config reaches the step. That is the seam that breaks end-to-end tracing/composition of bound config through a sequence and therefore through a StateGraph.
**Suggested change:** relocate the `seq:step:N` tag from `runName` to `config->tags` and stop overwriting `runName` in `stepConfig()`. Keep the two `RunnableSequenceStepTagTest` guards green by moving the tag carrier.

### 4. error-handling
**Verdict:** needs work
**Evidence:** `TransportExceptionContractTest: testOpenAiWrapsATransportThatRaisesItsOwnType, testAnthropicWrapsATransportThatRaisesItsOwnType, testTheEagerPathWrapsToo`. Known non-exact behaviours: *A `finally` runs on the way out of a `catch` too, so the abandoned-stream guard double-reported every streaming failure — two error events for one error. A collector that keeps the last event renders that as a single event and hides the duplicate, so the count is asserted directly.*
**Finding:** Wrapping contract is tested, but streaming error reporting is duplicated by `finally` after `catch`. Callers cannot trust a single error event count without the collector hack.
**Suggested change:** deduplicate error emission in `LanguageModels\BaseChatModel::stream()` so `finally` does not re-emit an already reported failure.

### 5. fidelity
**Verdict:** sound with documented gaps
**Evidence:** Known non-exact behaviours table pins divergences: `RunnableParallel` accepts scalar, `RunnableBinding` merges bound kwargs into `config->options`, `HttpClient` parameter names not part of contract, OpenAI content blocks not filtered, Anthropic content block rule, etc. `The OpenAI Responses API is not ported`.
**Finding:** Divergences are explicit, justified by PHP constraints or upstream differences, and each is pinned by a test. Unported Responses API is documented.
**Suggested change:** none; keep the ledger updated when new divergences appear.

### 6. test-strategy
**Verdict:** needs work
**Evidence:** `tests enumerated: 2314, OK (2314 tests, 6403 assertions)`. Load-bearing guards: `PhpVersionCompatibilityTest`, `DocsMatchRealityTest`, `TransportExceptionContractTest`. audit/runname-bind-slot-regression: test asserts `runName = "seq:step:1"` with comment *the sequence clobber is still open*.
**Finding:** Suite proves API shape and mechanical doc sync, but it deliberately asserts the broken `runName` value to stay green. That is a known defect encoded as expectation, so the green suite hides the defect.
**Suggested change:** replace the clobber-asserting test with a failing expectation for the correct bound `runName` and make the tag-carrier change the fix target.

### 7. documentation
**Verdict:** sound
**Evidence:** `DocsMatchRealityTest: testPortStatusTotalIsNotUnderstatingCoverage, testHandoffFileCountsAreCurrent, testTheSizeRowLineCountsAreCurrent, testHandoffStatesACurrentSuiteSize`.
**Finding:** PORT_STATUS.md and HANDOFF.md are mechanically kept in sync with file counts, line counts and suite size. Section 5 notes ledger staleness risk, but the mechanical guards are present.
**Suggested change:** add a freshness guard for the “highest-value item outstanding” entry, e.g. fail CI if it is unchanged > N days.

### 8. dead-code
**Verdict:** unsure
**Evidence:** brief does not list unreachable classes, orphans or parsed-then-dropped code. `LangChain.LanguageModels.Outputs 6 src 294 lines 0 test files` and `LangChain.Schema 4 src 187 lines 0 test files` are value namespaces referenced by 11/10/8 files per brief note.
**Finding:** No dead code is identifiable from the brief. The 0 test-file counts for value namespaces are expected per the reading guide.
**Suggested change:** none

---

A. **Composition – two journeys**

*Bind tools + stream inside a checkpointed StateGraph.*  
`BaseChatModel::bindTools` → `RunnableBinding::mergeConfig` → `RunnableSequence` → `Pregel::invoke` → `stream`. The binding’s `runName` reaches the sequence but is overwritten by `RunnableSequence::stepConfig()` to `seq:step:1`. Tracing therefore loses the bound name. Stream establishment retry works, but a flowing stream error is double-reported by the `finally` guard. Seam: `RunnableSequence::stepConfig` runName clobber and `BaseChatModel::stream` error deduplication.

*Structured-output `includeRaw` pipeline.*  
`RunnableParallel` now passes scalar input to every branch, so `{raw: llm}` works. `RunnableBinding` merges bound kwargs into config, so `withStructuredOutput` options propagate. Seam: `RunnableParallel::invoke` scalar acceptance and `RunnableBinding::mergeConfig` precedence are load-bearing; they work.

B. **Layering violations**
No violation is evidenced. `LangGraph -> LangChain: 15 files` is the expected core-abstraction dependency. The diagram shows provider clients at the top and LangGraph engine at the bottom with a single HTTP seam. No brief datum shows graph code importing `ChatOpenAI`/`ChatAnthropic`.

C. **Honesty of the record**
PORT_STATUS.md and HANDOFF.md are mechanically consistent via `DocsMatchRealityTest`. The ledger correctly records the highest-value item as the `RunnableSequence` runName clobber, and section 5 shows the bind-slot wrapper trap was corrected in place. No contradiction is visible in the brief; the past-tense trap warning is heeded.

D. **What the green suite hides**
1. `RunnableSequence` runName clobber – test asserts the clobbered value `seq:step:1` with an explicit “still open” comment, so green means defect present.
2. Abandoned-stream double error – collector keeps last event, count is asserted directly, hiding the duplicate error emission from `finally`.
3. `DocsMatchRealityTest` guards counts, not semantics – line-count drift can be green while behaviour diverges.

E. **Single highest-leverage change**
Fix `RunnableSequence::stepConfig` to preserve a bound `runName` and move the `seq:step:N` identity tag to `config->tags` instead of overwriting `runName`. This unblocks end-to-end composition of bound tools/streaming/config through sequences and StateGraph, restores correct tracing, and removes the need for the clobber-asserting test. All other issues are downstream of this seam.

**Ranking by impact × confidence**

1. RunnableSequence runName clobber breaking bound config propagation through sequences/graphs – high impact, high confidence.
2. Streaming error double-report via `finally` – medium-high impact, high confidence.
3. Test asserting broken runName state – high impact on trust, high confidence.
4. Documentation freshness for highest-value item – medium impact, medium confidence.
