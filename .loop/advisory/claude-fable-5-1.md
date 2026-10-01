# Advisory review — anthropic/claude-fable-5.1

_Generated 2026-10-01T22:07:16.594371+00:00_

# Whole-system advisory — langchain-php

**Scope caveat, stated first.** The brief says to trust the "Source in focus" listing as the live repository. No such listing is in the packet I received — I have the measured namespace inventory (§2), cross-package counts (§3), three public-surface listings (§4), the divergence table (§6), the ledger (§5) and the diagram. I did not run the suite. Every verdict below is grounded in those sections only; where I cannot point at a line, I say so rather than guess. I have deliberately not re-reported any §5 row.

---

### 1. layering
**Verdict:** needs work
**Evidence:** §3 — `LangChain -> LangGraph: 1 files`; §3 — `LangGraph -> LangChain: 11 files`.
**Finding:** The 11 downward references are expected (graph layer uses `Runnable`, `RunnableConfig`, messages). The single *upward* reference is not: upstream `@langchain/core` has no dependency on `@langchain/langgraph`, so one `LangChain\*` file reaching into `LangGraph\*` is an inverted dependency unless it sits in `LangChain\Utils\Testing`. The brief does not name the file, and §3 does not say whether it distinguishes `LangChain\Runnables` from `LangChain\LanguageModels\Chat\OpenAI` on the graph side, so I cannot tell whether the graph layer touches a provider namespace. The diagram places `GuzzleHttpClient` in the "Provider clients" row though it lives in `Utils\Http` — cosmetic, but it blurs exactly the transport/provider seam the subtitle claims is singular.
**Suggested change:** Name the one `LangChain -> LangGraph` file in HANDOFF. If it is not under `Utils\Testing`, break it. Extend the existing layering guard (the one `audit/layering-dependency-guard` describes) with an explicit negative: no `LangGraph\*` file may `use LangChain\LanguageModels\Chat\*`.

### 2. public-api
**Verdict:** needs work
**Evidence:** §4 — `generatePrompt(array $promptValues, array $options = [], ?array $callbacks = null, ?RunnableConfig $config = null)` vs `generateMessages(array $messageLists, ?RunnableConfig $config = null, array $options = [])`; §4 — `Pregel.php (6 public methods)` listing `getName/invoke/stream/getState/getStateHistory` + ctor.
**Finding:** Two sibling methods on `BaseChatModel` take the same three concepts in different positional orders, and `generatePrompt` accepts both `$callbacks` and `$config` although `RunnableConfig` already carries callbacks — a caller cannot know which wins. On `Pregel`, there is no `updateState()` in the listed surface; upstream's human-in-the-loop story is `getState → updateState → invoke(null)`, and `getState/getStateHistory` returning bare `array` rather than a `StateSnapshot` type means the shape a caller edits is undocumented by the type system. `Schema::validate(mixed $value, mixed $rawArg = null, bool $verbose = false): void` leaks two implementation knobs into the public contract.
**Suggested change:** Unify the `generate*` parameter order to `(input, ?RunnableConfig $config, array $options)` and drop `$callbacks` from `generatePrompt` (or document precedence and pin it). Either port `updateState()` or state in PORT_STATUS that `Command::resume` is the *only* resume mechanism. Introduce a `StateSnapshot` value object.

### 3. composition
**Verdict:** needs work (unverifiable at the seam that matters)
**Evidence:** §4 — `Pregel::stream(mixed $input, ?RunnableConfig $config = null): \Generator`; §4 — `BaseChatModel::stream(...): \Generator`; §4 — `bindTools(array $tools, array $kwargs = []): static`.
**Finding:** Both `stream()`s return single-pass PHP generators, so the plumbing can compose. But `Pregel::stream` exposes no stream-mode parameter: upstream's `streamMode: "messages"` is how model tokens emitted inside a node reach the graph caller. If stream mode rides inside `RunnableConfig`, the brief does not show it; if it does not exist, token streaming through a graph node dead-ends at `Pregel::stream` and yields only per-step updates. `bindTools()` returning `static` (a reconfigured model) rather than a `RunnableBinding` is a legitimate shape, but it means two independent "bound kwargs" mechanisms exist — the model's own tool state and `RunnableBinding::mergeConfig()` (§6 row 2) — and the brief contains no test name proving they agree when stacked (`$model->bindTools([...])->bind(['temperature' => 0])`).
**Suggested change:** Add a test that stacks `bindTools` + `bind()` and asserts the request body carries both; document where stream mode lives, or add it.

### 4. error-handling
**Verdict:** unsure — insufficient evidence
**Evidence:** §1 — `TransportExceptionContractTest: testOpenAiWrapsATransportThatRaisesItsOwnType, testAnthropicWrapsATransportThatRaisesItsOwnType, testTheEagerPathWrapsToo`; §2 — `LangGraph.Errors 13 src / 389 lines / 1 test files`.
**Finding:** The transport contract is pinned on both providers and both stream/eager paths — that is the right guard. For the graph side, 13 exception classes in 389 lines sit behind one test file; whether `GraphInterrupt`, `GraphRecursionError`, `EmptyChannelError` etc. are catchable by a caller as one base type, and whether they carry `previous` causes from node bodies, is not visible in the brief.
**Suggested change:** A single `ExceptionHierarchyTest` asserting every class under `LangGraph\Errors` extends one marker interface, and that a `Throwable` raised inside a node surfaces with `getPrevious()` populated.

### 5. fidelity
**Verdict:** needs work
**Evidence:** §6 preamble — "two kinds live here, both settled"; §6 rows "RunnableParallel accepts any input", "`finally` runs on the way out of a `catch`…", "One call-option spelling table…", "`ChatOpenAI` refuses `topK`…".
**Finding:** The divergence table's own taxonomy admits (1) PHP-cannot-reproduce and (2) deliberate-different-choice. At least five of its eight rows are neither: they are narratives of defects fixed *to match upstream* (RunnableParallel now matches `RunnableMap.invoke`; RunnableBinding now matches `_mergeConfig`; the `finally`, `KEY_ALIASES` and `topK` rows describe regressions removed). Only the HttpClient-named-parameters row and the OpenAI content-block row are genuine divergences. So the table contradicts the preamble that was rewritten specifically to stop it contradicting itself — the exact class the preamble says `DocsMatchRealityTest` cannot see. The divergences that *are* genuine are justified: PHP named-argument binding is a language fact; `output_version: v1` being unported is coherent scope.
**Suggested change:** Move the fix-narrative rows out of "Known non-exact behaviours" into a "Fixed-to-match-upstream" ledger (or the `<!-- fix:HASH -->` rows they already belong to). Leave the table with its two genuine entries plus the surrogate case the preamble cites but the table omits.

### 6. test-strategy
**Verdict:** needs work
**Evidence:** §1 — `4306 tests, 9338 assertions` (≈2.2/test); §1 load-bearing guards; §6 row "`HttpClient` parameter names… a double whose parameters are named `a`/`b`/`c`/`d`/`e`"; §2 — `LangChain.Utils.Testing: FakeHttpClient referenced by 9 files`.
**Finding:** The load-bearing guards are count-shaped (`DocsMatchRealityTest` checks totals and line counts) and floor-shaped (`PhpVersionCompatibilityTest` checks *functions* newer than the floor — not classes, constants or attributes; CI on 8.2 covers syntax, so that is acceptable). The one structural hazard the suite pins — named-argument binding — is pinned for exactly one interface. Every other interface in the port (`Runnable::invoke`, `BaseCheckpointSaver`, `HttpClient` siblings) has the same hazard, and PHPUnit doubles generated from the interface use the interface's parameter names, so a mock-based suite is *structurally incapable* of seeing it anywhere else.
**Suggested change:** A reflection test: for every `interface` under `src/`, every implementing class must use identical parameter names (or the port declares, per interface, that names are not contractual).

### 7. documentation
**Verdict:** needs work
**Evidence:** Brief header — "fixed at `fce8bc4`"; §5 `[aaa-cycle-1-z-ai__glm-4.6v]` — "Checkpoint::$channelVersions/$versionsSeen are TYPED array… the named case is precisely the one that cannot hit its own fix"; §5 `[audit/prompt-template-ctor-blocker] #OPEN - status not stated`.
**Finding:** The brief's own header holds up `fce8bc4` as the canonical closed fix for empty-map encoding, while a later ledger entry states that fix misses the typed-array case the comment names. `[aaa-5-cycle-harvest] #369` says all four accepted findings were fixed — but I cannot confirm from the packet that *this* one was among the four. Two `audit/` keys are `#OPEN` with no body, and `prompt-template-ctor-blocker` has a title implying a blocker and zero content.
**Suggested change:** Re-run the one-line probe the aaa entry describes (encode a `Checkpoint` with empty `channelVersions`, assert `{}`). If green, update the header and PORT_STATUS to cite the *later* hash, not `fce8bc4`. Fill or delete the two empty `#OPEN` keys.

### 8. dead-code
**Verdict:** unsure — insufficient evidence
**Evidence:** §2 — `LangChain.Schema 4 src / 186 lines / 0 test files` alongside `LangChain.LanguageModels.Outputs 6 src / 294 lines`; §4 — `generatePrompt(..., ?array $callbacks = null, ?RunnableConfig $config = null)`.
**Finding:** Upstream's `schema/` module is a deprecated re-export shim; this port has a `LangChain\Schema` namespace *and* an `Outputs` namespace holding `LLMResult`/`ChatGeneration`. The brief does not say what the four `Schema` classes are or who references them (it gives reference counts for `Outputs`, not for `Schema`). The redundant `$callbacks` parameter on `generatePrompt` is a candidate for parsed-then-ignored input.
**Suggested change:** Publish reference counts for the four `LangChain\Schema` classes; delete or merge any with zero non-test referrers.

---

## A. Composition — two journeys

**Journey 1: `$model->bindTools($tools)` as a StateGraph node, streamed, under `MemorySaver`.**
- Seam 1 — `bindTools()` → node: works in principle; `static` return is a Runnable.
- Seam 2 — `Pregel::stream()`: no stream-mode parameter in the listed signature. Without a `messages` mode, the caller receives step updates, not tokens. **Likely dead-end for the "stream a response" half of the journey.** The brief does not show otherwise.
- Seam 3 — tool execution: no `ToolNode`/`toolsCondition` appears in any §2 namespace. The loop back to the model must be hand-written. Documented scope, but a seam the next engineer hits immediately.
- Seam 4 — checkpointing an `AIMessage` with `toolCalls` through `Checkpoint\Serde`: the shape-level risk (typed-array empty maps) is the open question in element 7.

**Journey 2: `ChatPromptTemplate | $model->withStructuredOutput($schema, ['includeRaw' => true]) | $parser` via `RunnableSequence::stream()`.**
- `includeRaw` → `RunnableParallel` with scalar input is pinned (§6 row 1) for `invoke`.
- Seam — `withStructuredOutput(): Runnable` streamed: whether the returned Runnable implements a real `transform()` (partial-JSON objects, as upstream's `JsonOutputKeyToolsParser` does) or degrades to buffered `invoke` is not shown. If it degrades, the journey completes but silently loses streaming at the structured-output seam.

## B. Layering violations
One `LangChain -> LangGraph` file (§3) is the only measured violation candidate; identify it. Whether the graph layer knows providers cannot be answered from an 11-file aggregate — the measurement needs a per-namespace breakdown, and the layering guard needs the provider-namespace negative assertion from element 1.

## C. Honesty of the record
- The header/ledger contradiction over `fce8bc4` (element 7) is the one place the packet contradicts itself on a *claimed-closed* fix.
- The "Known non-exact behaviours" table mislabels fixes as divergences (element 5), under a preamble that promises it does not.
- Ported-but-thinly-visible: `LangGraph.Channels` (14 classes, 2 in-namespace test files) and `LangChain.Tracers` (3313 lines, 4 test files). The brief is right that in-namespace count ≠ usage; but it offers reference counts only for `Utils\Testing` and `Outputs`, not for these two. Publish them.
- Unported-but-implied: `Pregel::updateState()` and `streamMode`. The diagram's "Pregel loop is a PHP Generator" and the `Command::resume` machinery imply full HITL; the listed surface does not deliver edit-state.

## D. What the green suite hides (new, not from §5)
1. **Interface parameter-name drift everywhere except `HttpClient`.** Mocks built from an interface cannot ever fail on this; only a hand-written double with alien names can — and the suite has exactly one.
2. **Abandoned generators.** Every `stream()` returns a `\Generator`; a caller who `break`s out of `foreach` triggers generator destruction and the `finally`-based guard described in §6. A suite that always drains to exhaustion cannot see whether an early `break` is reported as a streaming *failure*. The brief's `finally`-in-`catch` row proves this guard has already misfired once in a way one test topology hid.
3. **Tracer serialisation shape.** `Tracers` is 3313 lines; the ledger notes the serialiser has no production consumer by design. Nothing in the packet is a fixture of upstream's run JSON, so a green suite proves runs are *recorded*, not that `persistRun()` receives a shape LangSmith accepts.
(4th, briefly: `Pregel::stream` token passthrough — a suite asserting on final state is blind to it.)

## E. The single highest-leverage change
**Commit cross-language golden fixtures and assert byte-equality against them.** Concretely: a checkpoint JSON written by LangGraph JS (including the brand-new-thread case with empty `channel_versions`), an OpenAI and an Anthropic request body for a tool-bound call, and one recorded SSE stream per provider — loaded by `FakeHttpClient`/the serde and compared as bytes, not via in-process round-trip. Reason: the ledger's accepted defects cluster in one class — `{}` vs `[]`, `args` shape, content-block merge, empty-map encoding — and every one survived a round-trip test because encode/decode are symmetric in PHP. Byte fixtures from the other language are the only oracle that is not symmetric with the code under test. The aaa harness reportedly used "live wire bytes"; the packet does not show them committed as PHPUnit fixtures, which is the gap.

---

## Ranked findings (impact × confidence)
1. **Divergence table mislabels fixes as divergences** — high impact on next engineer, high confidence (§6 text). *Element 5.*
2. **`fce8bc4` cited as closed while ledger says it misses the typed-array case** — high impact, medium confidence (needs the one-line re-run). *Element 7.*
3. **No `updateState()` / no visible stream mode on `Pregel`** — high impact on HITL and streaming journeys, medium-high confidence (§4 surface). *Elements 2, 3, A.*
4. **Named-parameter hazard pinned for one interface only** — medium impact, high confidence. *Element 6, D1.*
5. **One `LangChain -> LangGraph` reference, unnamed** — medium impact if in `src`, medium confidence. *Element 1, B.*
6. **`generatePrompt`/`generateMessages` signature incoherence + redundant `$callbacks`** — medium impact, high confidence. *Element 2.*
7. **`LangChain\Schema` namespace of unknown purpose, 0 reference data** — low-medium impact, low confidence. *Element 8.*
8. **Empty `#OPEN` ledger keys** — low impact, high confidence. *Element 7.*
