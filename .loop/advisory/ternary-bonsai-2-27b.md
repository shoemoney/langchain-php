# Advisory review — prism-ml/ternary-bonsai-2-27b

_Generated 2026-09-30T18:08:12.647463+00:00_



### layering
**Verdict:** sound  
**Evidence:** Cross-package reference counts: `LangGraph -> LangChain: 15 files`; generated diagram shows `Provider Clients -> Core Abstractions -> Composition -> Tools+Parsers -> Messages -> LangGraph Engine`.  
**Finding:** No cross-layer edge is shown that would make a low-level utility depend on a high-level abstraction; the only cross-package direction shown is `LangGraph -> LangChain`, which is the expected direction for a LangGraph port.  
**Suggested change:** Add a machine-checked dependency-direction guard, e.g. PHPStan/Composer analysis, so future imports cannot silently invert the layering.

### public-api
**Verdict:** sound  
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php (9 public methods)`, `src/LangGraph/Pregel/Pregel.php (6 public methods)`, `src/LangChain/Tools/Schema.php (11 public methods)`.  
**Finding:** The listed public surface is coherent: model binding, structured output, streaming, and graph state are exposed through stable abstractions rather than provider internals.  
**Suggested change:** Add a public-API snapshot test for `BaseChatModel`, `Pregel`, and `Schema` to prevent accidental leaks.

### composition
**Verdict:** needs work  
**Evidence:** `2335 tests, 6436 assertions`; the brief does not name a single end-to-end test that binds tools to a model, streams inside a `StateGraph`, and checkpoints the run.  
**Finding:** The subsystems are strongly unit-tested and their seams are pinned, but the brief does not prove that the full user journey works end to end.  
**Suggested change:** Add integration tests for two journeys: a tool-bound streaming `StateGraph` with a checkpoint saver, and a structured-output pipeline using binding, parallel, and parser runnables.

### error-handling
**Verdict:** sound  
**Evidence:** `TransportExceptionContractTest: testOpenAiWrapsATransportThatRaisesItsOwnType, testAnthropicWrapsATransportThatRaisesItsOwnType, testTheEagerPathWrapsToo`; `LangGraph.Errors 13 src 389 lines 1 test files`.  
**Finding:** Provider transport exceptions and the eager path are pinned to the same exception contract, and the LangGraph error taxonomy is tested.  
**Suggested change:** none.

### fidelity
**Verdict:** needs work  
**Evidence:** Known non-exact behaviours row: “OpenAI content blocks are not filtered on the way out” at `Chat\OpenAI\Utils\Completions::convertMessage()`; “Anthropic response content keeps its block structure unless there is exactly one text block”.  
**Finding:** Most documented divergences are justified, but the OpenAI row shifts a filtering responsibility to callers that upstream would handle, making it the least comfortable fidelity gap.  
**Suggested change:** Either port the upstream `output_version: v1` conversion/filtering or add an explicit opt-in filter in the message-conversion seam so callers are not silently responsible.

### test-strategy
**Verdict:** needs work  
**Evidence:** `2335 tests, 6436 assertions`; load-bearing guards include `DocsMatchRealityTest` and `TransportExceptionContractTest`; the brief does not show an integration test for the full user journeys.  
**Finding:** The suite proves unit contracts, docs freshness, transport exception wrapping, and error taxonomy; it does not prove the full user journeys or semantic documentation accuracy.  
**Suggested change:** Add integration tests for the two realistic journeys and a semantic review gate for `PORT_STATUS.md` framing, because count-based doc guards cannot catch semantic drift.

### documentation
**Verdict:** sound  
**Evidence:** `DocsMatchRealityTest: testPortStatusTotalIsNotUnderstatingCoverage, testHandoffFileCountsAreCurrent, testTheSizeRowLineCountsAreCurrent, testHandoffStatesACurrentSuiteSize`; known non-exact behaviours table distinguishes PHP limitations from deliberate choices.  
**Finding:** No contradiction is shown between `PORT_STATUS.md`/`HANDOFF.md` and the brief; the table now distinguishes the two kinds of divergence.  
**Suggested change:** Add a semantic check or manual review gate for the taxonomy framing, because count guards cannot catch framing drift.

### dead-code
**Verdict:** sound  
**Evidence:** `LangChain.Utils.Testing 0 test files` but referenced by 10 files; `LangChain.LanguageModels.Outputs 6 src 294 lines 0 test files`; `LangChain.Schema 4 src 187 lines 0 test files`.  
**Finding:** No written-but-unreachable code is shown; zero-test-file namespaces are not evidence of dead code.  
**Suggested change:** Add a reference/coverage report for `LangChain.LanguageModels.Outputs` and `LangChain.Schema` to confirm they are exercised.

## A. Composition

Journey 1: “bind tools to a model and stream a response inside a StateGraph checkpointed run.”

Trace from the brief:

1. User constructs a provider client, e.g. `ChatOpenAI` or `ChatAnthropic`, through the provider-client layer.
2. The provider client reaches the port through the HTTP seam: `HttpClient`, `SseParser`, `GuzzleHttpClient`.
3. User calls `BaseChatModel::bindTools()` with tools represented by `StructuredTool`/`Schema`.
4. A `StateGraph` node invokes `BaseChatModel::stream()` or `invoke()`.
5. Stream chunks are converted through message-conversion code, e.g. `Chat\OpenAI\Utils\Completions::convertMessage()` or `Chat\Anthropic\Utils\MessageOutputs::contentOf()`.
6. Tool calls are represented in messages/tool calls and may be validated through `Schema::validate()`.
7. `Pregel::stream()` / `Pregel::invoke()` drives the loop through `PregelLoop`, `Channels`, and a checkpoint saver such as `SqliteSaver` or `MemorySaver`.

The seams are:

- Provider HTTP seam: `HttpClient`/`SseParser`/`GuzzleHttpClient` → `BaseChatModel::stream()`/`invoke()`.
- Message conversion seam: provider response → `ChatMessage`/`ContentBlock`/tool-call representation.
- Tool binding seam: `BaseChatModel::bindTools()` → `StructuredTool`/`Schema`.
- Graph execution seam: `Pregel::stream()`/`invoke()` → `PregelLoop` → `Channels` → checkpoint saver.

Journey 2: “run a structured-output pipeline with binding, parallel branches, and JSON parsing.”

Trace from the brief:

1. User builds a runnable pipeline using `StructuredOutput` or `withStructuredOutput()`.
2. `RunnableBinding` merges bound kwargs into `config->options`.
3. `RunnableParallel` passes scalar input to every branch.
4. Branches produce JSON or structured output.
5. `JsonOutputParser`/`JsonOutputKeyTools` parse the result.

The seams are:

- Binding seam: `RunnableBinding::mergeConfig()` → downstream runnable config.
- Parallel seam: `RunnableParallel::invoke()` → scalar/array branch inputs.
- Parsing seam: raw model output → `JsonOutputParser`/`JsonOutputKeyTools`.

Do they actually work end to end? The brief does not prove it. It does not show a single test that executes either journey from provider client through graph execution or parser output. They are not shown to dead-end, but they are not traced in one end-to-end test either. The highest-risk seams are the message-conversion seam and the graph execution/checkpoint seam.

## B. Layering violations

No layering violation is shown. The only cross-package datum in the brief is `LangGraph -> LangChain: 15 files`, which is the expected direction for a LangGraph port. The brief does not show the graph layer importing specific providers such as `ChatOpenAI` or `ChatAnthropic`. I am unsure whether a full import graph exists, because the brief does not provide one.

## C. The honesty of the record

No contradiction is shown between `PORT_STATUS.md`/`HANDOFF.md` and the brief. The numerical doc guards are current, and the known non-exact behaviours table distinguishes PHP limitations from deliberate choices.

I cannot identify a ported-but-untested area with confidence. `LangChain.LanguageModels.Outputs` and `LangChain.Schema` have 0 test files, but the brief explicitly warns that 0 test files is not the same as 0 usage. No unported-but-implied area is shown.

## D. What the green suite hides

1. **Namespace inventory test-file column for `LangChain.LanguageModels.Outputs` and `LangChain.Schema`.**  
   A passing suite does not prove those namespaces’ branches are exercised. The brief only gives test-file counts, not reference counts or branch coverage for those namespaces.

2. **Cross-package reference count `LangGraph -> LangChain: 15 files`.**  
   A passing suite does not prove dependency direction or that no provider-specific class is referenced from LangGraph. It only counts files.

3. **Known non-exact behaviours table.**  
   A passing suite pins the listed divergences but cannot reveal unlisted divergences. For example, it does not prove that callers of `ChatOpenAI` are not surprised by the absence of upstream-style filtering of `tool_use`/`reasoning`/`thinking` blocks.

## E. The single highest-leverage change

Add end-to-end integration tests for the two realistic journeys:

1. A tool-bound streaming `StateGraph` run with a checkpoint saver.
2. A structured-output pipeline using binding, parallel, and JSON parsing.

Why: the codebase has strong unit coverage and documented seams, but the brief does not prove that the subsystems compose into real user journeys. A small integration suite would convert “coexisting subsystems” into “proven composition” and would catch seam regressions that unit tests and doc-count guards cannot see.

## Findings ranked by impact × confidence

| Rank | Finding | Impact | Confidence | Score |
|---:|---|---:|---:|---:|
| 1 | Missing end-to-end composition proof for tool-bound streaming `StateGraph` and structured-output pipelines | 5 | 3 | 15 |
| 2 | OpenAI content-block filtering burden shifts responsibility to callers | 3 | 4 | 12 |
| 3 | Dependency-direction guard is not machine-checked | 3 | 3 | 9 |
| 4 | Exercise of `LangChain.LanguageModels.Outputs` and `LangChain.Schema` is not shown | 2 | 4 | 8 |
| 5 | Documentation semantic accuracy is not machine-checked | 2 | 4 | 8 |
