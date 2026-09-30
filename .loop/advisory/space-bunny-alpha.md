# Advisory review — stealth/space-bunny-alpha

_Generated 2026-09-30T17:39:59.845298+00:00_

Review basis: I did not rerun the suite or inspect files beyond the supplied extracts. I therefore treat “2334 tests passing” as a snapshot, not proof of current behavior, and I do not turn historical ledger entries into present-tense defects. Citations use exact identifiers because line numbers were not supplied.

### layering
**Verdict:** needs work  
**Evidence:** The diagram reports **232 source files**, while the supplied namespace inventory sums to **224**; it omits eight source files and contains no `Chat\OpenAI` namespace despite the diagram’s `ChatOpenAI` component and `Chat\OpenAI\Utils\Completions::convertMessage()` reference. The only package-direction datum is `LangGraph -> LangChain: 15 files`; no per-file edges are supplied. The diagram has no legend, and its downward arrows visually imply the opposite direction if they are intended as dependency arrows.  
**Finding:** No concrete low-level-to-high-level layering violation is proven: `LangGraph -> LangChain` is a plausible higher-level dependency, and no `LangChain -> LangGraph` edge is reported. I am nevertheless unable to certify namespace or PSR-4 boundaries because the inventory is incomplete and the 15 graph-to-chain edges are not identified.  
**Suggested change:** Generate a complete 232-class Composer/PSR-4 manifest and a per-edge dependency report. Label diagram arrows explicitly as either data flow or dependency direction, and enforce an allowlist such as “LangGraph may depend on core LangChain abstractions, not provider implementations.”

### public-api
**Verdict:** needs work  
**Evidence:** `BaseChatModel::withStructuredOutput()` returns `LangChain\Runnables\Runnable`, while `Pregel::invoke()` and `Pregel::stream()` accept `?RunnableConfig`, which are coherent composition seams. However, `BaseChatModel::generatePrompt()` takes `?array $callbacks`, `generateMessages()` takes `?RunnableConfig`, and `Pregel::getState()`/`getStateHistory()` accept `RunnableConfig|array`. The requested `Runnable` surface is absent entirely, and the `Pregel::__construct()` signature is truncated in the brief.  
**Finding:** The model and graph APIs connect through `Runnable`, but the mixed raw-array/typed-config contract is observably ambiguous. More importantly, the supplied record is not an effective public-API inventory because inherited `Runnable` methods and part of `Pregel` are missing; I do not count `Pregel`’s public construction properties as a leak merely because they are mutable-looking—the brief records that this mirrors upstream.  
**Suggested change:** Publish the complete effective API for `Runnable`, `BaseChatModel`, and `Pregel`, including inherited methods. Standardize execution on `RunnableConfig`; if array BC is required, put conversion behind an explicitly named factory such as `RunnableConfig::fromArray()` rather than accepting both forms on selected methods.

### composition
**Verdict:** needs work  
**Evidence:** The diagram connects provider clients, `BaseChatModel`, LCEL runnables, tools, messages, and the LangGraph engine, but it supplies no invocation trace. Relevant tests are distributed among namespaces—Pregel 7 files, Runnable 6, Tools 7, State 1, Checkpoint 12, and Pregel.Checkpoint 12—and no cross-package journey test is named. The public signatures establish type-level seams, not that provider streaming, tool execution, graph execution, and checkpoint persistence work together.  
**Finding:** The subsystems demonstrably coexist and expose compatible-looking contracts, but the brief does not prove that they compose end to end. In particular, neither the provider-output-to-tool seam nor the `StateGraph -> Pregel -> checkpoint saver` seam is traced through code or a named integration test.  
**Suggested change:** Add deterministic journey tests using `FakeHttpClient` and the project’s test checkpoint implementation: one caller-managed tool round trip through a bound model, and one interrupt/resume or invoke/resume cycle through `StateGraph`, `Pregel`, channels, serde, and saver.

### error-handling
**Verdict:** needs work  
**Evidence:** The named guards `TransportExceptionContractTest::testOpenAiWrapsATransportThatRaisesItsOwnType()`, `testAnthropicWrapsATransportThatRaisesItsOwnType()`, and `testTheEagerPathWrapsToo()` establish a normalized transport-error contract for the cases represented by those tests. The brief does not show the normalized class, the caught/wrapped class, or assertions on `Throwable::getPrevious()`. `LangGraph\Errors` has 13 source files and one test file, but file count alone says nothing about whether all hierarchy branches and causes are exercised.  
**Finding:** A caller can reasonably trust one transport exception for the specifically named OpenAI, Anthropic, and eager cases, assuming the supplied green result is current. The record does not establish the same guarantee for streaming, every `HttpClient` implementation, double wrapping, or preservation of the original cause.  
**Suggested change:** Turn this into an explicit adapter-by-path contract matrix—each provider adapter × eager/streaming—and assert the public exception class, message context, no double wrapping, and identity of `getPrevious()`. Document the same contract on `HttpClient` and the two model adapters.

### fidelity
**Verdict:** needs work  
**Evidence:** `PORT_STATUS.md` documents an OpenAI divergence at `Chat\OpenAI\Utils\Completions::convertMessage()` because `output_version: v1` conversion is not ported; it also documents PHP’s inability to represent a lone surrogate and `batch()` deliberately ignoring options for lack of a PHP concurrency equivalent. Conversely, the `RunnableParallel` and `RunnableBinding` rows explicitly describe behavior that now matches upstream, not non-exact behavior. No upstream commit/path/line is supplied for most rows, while “each row is pinned by a test” can establish PHP behavior but not independently establish upstream fidelity.  
**Finding:** No new undocumented semantic fidelity defect is demonstrated by this packet; the concrete current gap shown is the disclosed OpenAI block-conversion behavior. The fidelity record is nevertheless weak because it mixes exact upstream compatibility, PHP limitations, deliberate choices, and an unported upstream mode under a taxonomy that does not cleanly describe all four.  
**Suggested change:** Split the record into “exact compatibility,” “impossible in PHP,” “deliberate PHP divergence,” “unported upstream behavior,” and “unverified against upstream.” Pin every real divergence to an upstream revision/path and to a PHP behavior test, without proposing unrelated or unported LangChain subsystems.

### test-strategy
**Verdict:** needs work  
**Evidence:** The suite reports 2334 tests and 6432 assertions, with strong named guards for the PHP floor, numerical documentation freshness, and selected transport exceptions. `DocsMatchRealityTest` checks totals, file counts, size-row counts, and suite size—not semantic documentation. The namespace test-file column is explicitly not usage or branch coverage: `LangChain\Utils\Testing` has an expected zero, while `LanguageModels\Outputs` has zero path-matching test files despite `LLMResult`, `ChatGeneration`, and `ChatGenerationChunk` being referenced by 11, 10, and 8 files respectively. No line/branch coverage, architecture test, call-graph check, or cross-package journey guard is reported.  
**Finding:** This is a substantial local test suite with valuable compatibility and contract guards, but the green count does not establish system composition, reachability, or semantic documentation accuracy. The test strategy is organized around classes and isolated contracts, while the highest-risk failures live at the seams between those classes.  
**Suggested change:** Add a small architecture/journey gate and static dependency check to CI. Keep the existing unit suite, but require at least one provider-to-tool-to-message path and one graph-to-checkpoint round trip so a green build proves cross-package behavior rather than only local behavior.

### documentation
**Verdict:** broken  
**Evidence:** The `PORT_STATUS.md` section first says there are “Two kinds” of divergence, then calls “A third category” the same impossible-or-deliberate pair, and later calls `UNVERIFIED AGAINST UPSTREAM` a third category. The OpenAI row describes an **unported upstream mode**, which is neither of the first two categories, while the Runnable rows describe exact upstream compatibility. Separately, the architecture reports 232 source files while the supplied namespace inventory accounts for only 224. The actual `HANDOFF.md` text is not included; only numerical guards such as `testHandoffFileCountsAreCurrent()` and `testHandoffStatesACurrentSuiteSize()` are shown.  
**Finding:** The record is not reliable as a next-engineer contract: its central behavior taxonomy is internally contradictory, the architecture inventory is incomplete, and the tests guarantee numerical freshness rather than semantic truth. I cannot claim that `HANDOFF.md` contradicts the code because its contents are not in the brief, but the current guards would not detect such a contradiction.  
**Suggested change:** Rewrite the taxonomy into mutually exclusive categories, move exact-upstream regression rows out of “non-exact behaviours,” regenerate the namespace inventory to all 232 files, and add semantic documentation checks for required headings, valid table shape, architecture identifiers, and current status claims. Include the actual `HANDOFF.md` sections in future review packets, not just count assertions about them.

### dead-code
**Verdict:** needs work  
**Evidence:** The brief supplies no static call graph, production entrypoint analysis, coverage report, or complete class inventory. Eight source files are already unaccounted for in the supplied namespace table, and the 15 `LangGraph -> LangChain` references are aggregate counts rather than reachability edges.  
**Finding:** No new written-but-unreachable or parsed-but-dropped code is demonstrated by this packet. That is an evidence gap, not a clean bill of health: the incomplete inventory and absence of call data mean whole-system orphan detection has not actually been performed.  
**Suggested change:** Produce a complete symbol inventory and dependency/call graph rooted in Composer autoload entrypoints, HTTP handlers, public factories, and documented extension points. Review unreachable private/internal symbols separately from intentionally unused public hooks so extension APIs are not deleted merely for having no internal caller.

## A. Composition

### Journey 1: Bind a tool and stream a caller-managed tool round trip

1. The caller enters through `BaseChatModel::bindTools(array $tools, array $kwargs = []): static`.
2. The concrete provider implementation—`ChatOpenAI` or `ChatAnthropic` in the diagram—uses the `HttpClient`/`SseParser` seam.
3. The stream should yield message/generation chunks such as `AIMessageChunk`.
4. The caller extracts a tool call, invokes a `StructuredTool` governed by `Schema`.
5. The caller creates a `ToolMessage`, appends it to the messages, and calls the model again.
6. The model consumes that tool result and streams the final answer.

**Seams:**

- **Provider adapter ↔ HTTP/SSE:** The architecture claims one HTTP seam, and the transport contract tests cover OpenAI and Anthropic, including an explicitly named eager path. The brief does not show the streaming failure/parser path.
- **Model output ↔ tool dispatch:** `AIMessageChunk` and the tools components exist, but no shown API or test connects an emitted tool-call chunk to `StructuredTool` invocation.
- **Tool result ↔ next model call:** `ToolMessage` exists, but no journey test is named to prove that a streamed tool call can complete this loop.

**Result:** The first model-to-chunk leg is plausible and type-compatible. The full manual tool round trip is not proven end to end. An automatic agent loop is outside the stated port scope and is not a finding; this journey deliberately uses a caller-managed loop.

### Journey 2: Execute and resume a checkpointed StateGraph

1. A caller builds a `StateGraph`, adds a node backed by a `Runnable` or model, compiles it, and obtains a `Pregel` executable.
2. The caller enters through `Pregel::invoke()` or `Pregel::stream()`, both of which return through the LangGraph loop/`Generator` boundary.
3. The loop evaluates channels and nodes and writes checkpoint state through serde and a saver.
4. The caller later calls `Pregel::getState()` or `getStateHistory()` with the same logical run configuration.
5. A subsequent invocation must resume from the persisted state rather than starting over.

**Seams:**

- **`StateGraph` compiler ↔ `Pregel`:** Both appear in the diagram, but the compiler output contract and a live call edge are not shown.
- **Pregel node ↔ LangChain `Runnable`:** Shared `RunnableConfig` and the 15 package references suggest this seam exists.
- **Loop/channel updates ↔ serde/saver:** Checkpoint code and many tests exist, but no named test spans graph execution, persistence, and resumed execution.
- **Execution config ↔ state lookup:** `invoke()`/`stream()` accept `RunnableConfig`, while state lookup also accepts raw arrays, making the checkpoint identity/config handoff especially important to document and test.

**Result:** The API pieces appear composable, but the supplied evidence stops at component adjacency. It does not establish that a compiled graph actually persists and resumes correctly.

Overall, the strongest conclusion is **coexistence with plausible type seams, not two demonstrated end-to-end journeys**.

## B. Layering violations

There is no demonstrated low-level utility dependency on LangGraph or a high-level model abstraction. The only reported package direction is `LangGraph -> LangChain`, which is the expected direction when LangGraph is the higher-level orchestration engine.

There is also no evidence that the graph layer imports OpenAI, Anthropic, Guzzle, or another concrete provider. However, “15 files” is too coarse to prove that: one of those files could contain an improper provider-specific dependency.

The architecture drawing itself is ambiguous. Its top-to-bottom arrows appear to run provider → core → runnables → messages → LangGraph, while the measured code dependency is `LangGraph -> LangChain`. If the arrows mean runtime flow, that needs to be stated; if they mean dependencies, they point the wrong way for the graph package.

## C. The honesty of the record

`PORT_STATUS.md` contains a concrete internal contradiction:

- It defines two categories.
- It then calls the same impossible-or-deliberate pair a third category.
- It later defines “unverified against upstream” as a third category.
- Its table then includes exact upstream compatibility and an unported upstream mode without fitting either formal category.

That is not merely awkward prose: a next engineer cannot reliably classify a newly found divergence using the stated rules.

`HANDOFF.md` cannot be semantically assessed from this packet. The named tests prove that its counts and suite-size statements are current, not that its migration status, file mappings, limitations, or recommended next steps match the code.

Regarding scope:

- The packet establishes **no specific ported-but-untested class**. Zero path-matching test files are explicitly not proof of zero usage or coverage.
- `output_version: v1` is identified as unported, so it is disclosed rather than secretly implied.
- No missing vector store, embedding system, agent framework, or other unported subsystem should be inferred from this review.

The numerical freshness guards are useful, but they do not make the record semantically honest.

## D. What the green suite hides

1. **Documentation can be wrong while all named docs tests pass.**  
   `DocsMatchRealityTest` can validate totals, Handoff file counts, size rows, and suite size while the `Known non-exact behaviours` preamble remains self-contradictory and the namespace inventory omits eight of 232 source files.

2. **The transport contract can pass without proving every advertised route.**  
   The named guards establish OpenAI, Anthropic, and an eager path. A green result of those tests alone does not establish streaming normalization, preservation of the original cause, no double wrapping, or behavior for every implementation behind the claimed single HTTP seam.

3. **Isolated subsystem suites can all pass while composition dead-ends.**  
   Pregel has seven test files, Runnable six, State one, and checkpoint-related namespaces more. Nothing in the cited guard list proves that a compiled `StateGraph` crosses the Pregel/channel/serde/saver seam and resumes, or that a streamed provider tool call crosses the message/tool/runnable seam.

## E. The single highest-leverage change

Add **one mandatory end-to-end journey gate** that starts with a tool-bound `BaseChatModel`, uses `FakeHttpClient` to stream a tool call, executes a `StructuredTool`, returns a `ToolMessage`, runs that flow as a `StateGraph` node, and then verifies a subsequent `Pregel` invocation against `getState()` and `getStateHistory()`.

One test crossing all of those seams would validate the architecture’s central claim, expose configuration and callback loss, prove provider-output conversion, exercise tool validation/invocation, and cover graph persistence. It would also provide a load-bearing guard against exactly the class of defect that a large collection of green unit tests can miss.

## Ranked findings — impact × confidence

| Rank | Finding | Impact | Confidence |
|---:|---|---|---|
| 1 | Architecture inventory accounts for 224/232 files, and `PORT_STATUS.md` has an incoherent divergence taxonomy | Very high | High |
| 2 | No supplied evidence proves either realistic cross-package journey end to end | Very high | High that the evidence is absent; medium that a test does not exist |
| 3 | Transport exception trust is established only for the named providers/eager cases; causes and streaming matrix are not shown | High | Medium-high |
| 4 | Public API report omits `Runnable`, truncates `Pregel`, and exposes inconsistent raw-array versus `RunnableConfig` contracts | High | High |
| 5 | Fidelity rows lack upstream revision/path pins and mix exact compatibility with real divergences | High | High |
| 6 | Dead-code/reachability cannot be certified because eight source files are absent from the inventory and no call graph is supplied | Medium | High |

Bottom line: the packet demonstrates a large, well-guarded local implementation, but it does not establish whole-system composition, and the generated architecture/status record is currently less reliable than the green test count suggests.
