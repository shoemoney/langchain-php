# Advisory review — fireworks/ember-1

_Generated 2026-09-30T09:34:46.137107+00:00_

# Whole-System Advisory Review — langchain-php

### layering
**Verdict:** sound
**Evidence:** Cross-package reference counts (§3): LangGraph → LangChain, 15 files; no reverse count shown. Namespace inventory (§2) shows providers isolated under `LangChain.LanguageModels.Chat.*`.
**Finding:** The LangGraph → LangChain direction is upstream's own architecture (settled on package.json evidence in §5, z-ai-glm-4-6v #1 — not re-litigated). No evidence anywhere in the brief of the graph layer naming a provider, or of a low-level utility importing a high-level abstraction. One gap in the *evidence*, not the code: the brief shows only one direction of the cross-package count. If `LangChain → LangGraph` were nonzero it would be a genuine violation (core depending on the engine); the brief does not show it, so I cannot confirm it is zero.
**Suggested change:** Publish both directions of the cross-package count in the packet so the settled question stays settled visibly.

### public-api
**Verdict:** sound
**Evidence:** §4 signatures; `Pregel::__construct(public readonly array $nodes = [], public array $channels = [], ...)`.
**Finding:** The three surfaces are coherent and honestly sized — `BaseChatModel` at 9 methods with the PHP-forced adaptations (`bindTools()` throws by default, `supportsToolBinding()` reflects) documented in §6 rather than hidden. One real inconsistency: `Pregel::$channels` is `public` and **mutable** while its constructor siblings are `readonly` — a caller can rewrite the engine's channel map after construction, and nothing in the brief explains why this one prop is exempt.
**Suggested change:** Make `$channels` readonly, or record the reason it cannot be in PORT_STATUS.

### composition
**Verdict:** sound
**Evidence:** `TransportExceptionContractTest` (§1) exercises both providers through the one HTTP seam on eager and streaming paths; §6 records `RunnableParallel` accepting scalar input *because* "`{raw: llm}` (the first step of every `includeRaw` structured-output pipeline)" requires it — proof the structured-output pipeline is traced end to end, not merely coexisting. `LangGraph.Checkpoint` + `Pregel.Checkpoint`: 12 test files each.
**Finding:** Subsystems compose functionally. The weak seam is config/tracing, and it is already on record: `RunnableSequence::stepConfig` (RunnableSequence.php:83-90) clobbers `runName`, and a second, unlocated gap sits between `RunnableBinding`'s merged config and the config `BaseChatModel` receives (§5, o4-mini — fix attempted and reverted). One line, since it is still owed: this remains the standing exception to an otherwise composed system.
**Suggested change:** See section E.

### error-handling
**Verdict:** sound
**Evidence:** `TransportExceptionContractTest::testOpenAiWrapsATransportThatRaisesItsOwnType`, `testAnthropicWrapsATransportThatRaisesItsOwnType`, `testTheEagerPathWrapsToo` (§1); `LangGraph.Errors` 13 files / 389 lines / 1 test file (§2).
**Finding:** The provider seam gives a caller exactly what the element asks for: one catchable type across both providers, on both paths, pinned by a contract test. The kimi #5 fix (§5) moved message construction from silent-empty to refusal, which is the right direction and matches upstream. Two honest caveats: the deferred stream `catch(Throwable)` item (§5, glm #3 — a translation `TypeError` surfacing as "The HTTP transport raised TypeError") is still open and still sends a debugger to the network; and the brief does not show whether `LangGraph.Errors`' 13 types share a catchable base, so "catch one type and trust it" is proven for transport, unshown for the graph.
**Suggested change:** Narrow the stream `catch` region in its own iteration (as the record already plans); state the graph-error hierarchy in PORT_STATUS or add one.

### fidelity
**Verdict:** sound
**Evidence:** §6 ledger — every divergence carries a reason, an upstream citation, and a pinning test (e.g. `RunnableAssign` non-record input, `batch()` options, Responses API scope).
**Finding:** This is the strongest element in the port. The divergences are justified at the level that matters — not "PHP is different" but "here is the upstream line, here is why reproducing it would be a surprise, here is the test pinning the boundary." The inherited-quirk honesty (parseImagePrompts mutation kept *because* chat.ts:1056-1057 does it, recorded as inherited) is exactly right. The standing exception is the `stepConfig`/`runName` defect, confirmed by two independent readers against base.ts:1982-1985 and still unfixed — one line, already on record. The deferred set (convertToChunk's `default => AIMessageChunk` arm, non-leading system message, `fromMessages` option forwarding) is honestly carried as unverified rather than silently dropped.
**Suggested change:** Land the runName fix (section E). Convert the three most-credible deferred fidelity items (convertToChunk, sequence-stream duplication, `tools: []` merge) into reproductions — each is one constructed case away from being decidable.

### test-strategy
**Verdict:** sound
**Evidence:** §1: 2281 tests / 6352 assertions / 5.66s; guard list; §2 coverage signals.
**Finding:** The suite proves the port honours *its own contract* — fast, deterministic, with three genuinely load-bearing guards (PHP floor, docs-match-reality, transport exception contract) that guard the things this project's history says drift. What it cannot see: everything behind `FakeHttpClient` (9 referencing files) is tested against the port's own model of the provider, so a provider-side wire change is invisible; and `LangChain\Schema` (4 files, 187 lines, 0 test files, no measured references) is exercised by nothing. The guard set is real but guards arithmetic, not truth — see documentation.
**Suggested change:** Resolve the Schema question (measure references; pin or delete). Consider one opt-in live-provider smoke test so the fake's model of the world is checked against the world occasionally.

### documentation
**Verdict:** needs work
**Evidence:** §6 table, the row beginning `| **Anthropic response content keeps its block structure...**`; §1 guard list.
**Finding:** Three concrete items. (1) **Malformed table row in PORT_STATUS.md**: the `contentOf()` entry and the abandoned-stream entry are fused into one 5-cell row — the "A `finally` runs on the way out of a `catch` too…" behaviour has no title cell and its `Where` (`BaseChatModel::stream()`) sits in the wrong column. Worse, the fused entry's tense leaves it unreadable whether the double-error-report is *fixed* or *pinned as current behaviour* — in a ledger whose entire purpose is distinguishing those. (2) **The guard set covers test-count, not assertion-count**: the listed guards include `testHandoffStatesACurrentSuiteSize` but no assertion-count check, so if HANDOFF carries an assertion figure (it claimed 6317 when the suite reported otherwise, §5 kimi #1) it can drift green. (3) The §2 inventory accounts for 222 src files against the diagram's 230 — the missing ~8 are evidently the OpenAI provider (`ChatOpenAI`, `Utils\Completions`, `Utils\Tools` are referenced throughout §6 but appear nowhere in the inventory). I cannot tell from the brief whether PORT_STATUS's own size row shares the gap or the brief's extract is truncated; `testTheSizeRowLineCountsAreCurrent` suggests the doc is checked, so this is most likely a packet-truncation artifact — but it should be said either way. The duplicated §6 intro paragraph may be the same class of artifact.
**Suggested change:** Fix the fused row and state fixed-vs-pinned explicitly; add the assertion-count guard the record already agreed was right; reconcile the 222/230 file count.

### dead-code
**Verdict:** sound
**Evidence:** §2 namespace inventory; §5 removal record (pipeTo, autoload-dev mapping, `content_blocks` key).
**Finding:** The project demonstrably deletes dead code once found — invented API removed rather than repaired, dead autoload mapping removed, written-but-unread keys removed. No *evidenced* dead code remains in the brief. The one open question is `LangChain\Schema`: a value namespace with zero test files and — unlike `LanguageModels.Outputs`, which the brief measured and exonerated — no reference counts shown. Per the brief's own reading rules, that is a real signal, currently unmeasured.
**Suggested change:** Run the same reference measurement on `Schema` that Outputs got. If orphaned: delete. If referenced: pin its behaviour.

---

## A. Composition — two user journeys

**Journey 1: bind tools → stream inside a checkpointed StateGraph run.**
`ChatOpenAI::bindTools($tools)` → `RunnableBinding` → node in a `StateGraph` → `Pregel::stream()` drives the generator loop → checkpoint saver persists channels per super-step → `getStateHistory()` reads them back. **This works end to end.** The seams, named:

1. **RunnableBinding merged-config → BaseChatModel received config** — functional (kwargs merge is pinned, §6) but the runName dies here; the *second* gap in this seam is unlocated (§5, o4-mini).
2. **RunnableSequence::stepConfig** — any sequence step's `runName` is overwritten by `forChild('seq:step:N')`; traces show component IDs, not caller names.
3. **Serde** (734 lines, 1 test file) — thin relative to its role in `getStateHistory`; the 12 checkpoint test files likely cover it transitively, but the brief doesn't show that directly.
4. **ToolMessage status via `additional_kwargs`** — documented divergence; a caller expecting upstream's first-class `status` field will find it in the extras bag instead.

**Journey 2: prompt → model → structured-output parser, traced.**
`ChatPromptTemplate::fromMessages` → `RunnableSequence` → `withStructuredOutput($schema)` building `{raw: llm}` parallel → parser. **Works** — the scalar-input parallel is pinned precisely because this pipeline needs it. Seams: the `bind()` runName slot is fixed but the name still doesn't survive the sequence (seam 2 above); `fromMessages` forwards `partialVariables`/`templateFormat` but not metadata/tags (§5, grok #3 — deferred, partially verified).

Neither journey dead-ends. Both degrade at the **same seam**: config/tracing propagation through sequences and bindings.

## B. Layering violations

None evidenced. LangGraph → LangChain (15 files) is upstream's declared direction, settled. No provider type appears in the graph layer in anything the brief shows. `Utils.Testing` shipping in `src/` matches upstream langchain-core's `utils/testing`. The one unshown datum is the reverse count (LangChain → LangGraph); if nonzero it would be the real violation, and the brief does not show it.

## C. The honesty of the record

The record is unusually honest — the deferred items are carried as *unverified* rather than closed, and rejected findings record the rejection reason. Three contradictions stand: (1) the fused PORT_STATUS table row, which breaks the ledger's core fixed-vs-pinned distinction in the very table `DocsMatchRealityTest` guards — and the guard passes anyway, proving it checks counts, not content; (2) no assertion-count guard despite the record agreeing one was needed; (3) the 222-vs-230 file gap between inventory and diagram, unresolved in the brief. Ported-but-untested: `LangChain\Schema`. Unported-but-implied: none found — the Responses API and ToolMessage `status` absences are explicitly declared.

## D. What the green suite hides

1. **The fake-HTTP boundary.** Every provider behaviour — SSE framing, error-event-in-stream, version pinning — is verified against `FakeHttpClient`, i.e. against the port's own model of the provider. A real provider change (the exact thing the pinned `anthropic-version` docblock fears) fails no test.
2. **`LangChain\Schema`.** 187 lines, zero tests, zero measured references. Orphan or unpinned — the green suite cannot distinguish those, and nothing in the brief does either.
3. **The docs guard proves arithmetic, not truth.** The malformed fused row sits inside the table `DocsMatchRealityTest` guards, and the suite is green. A row whose `Where` cited the wrong method, or whose claim was simply false, would pass identically — and the assertion count in HANDOFF has no guard at all.

## E. The single highest-leverage change

**Land the runName fix end to end.** It is already the record's own top item, confirmed by two independent readers against base.ts:1982-1985, and it sits on the seam both journeys in section A degrade at — every traced sequence in every run is mislabeled, which corrupts the observability spine of the whole port. The concrete unblocking step is the one the record names: trace the config from `RunnableBinding::mergeConfig()` to the config `BaseChatModel` actually receives (the o4-mini note already eliminated `forChild` as the broken link), then re-land the reverted `stepConfig` change behind an end-to-end assertion that a caller-supplied runName reaches `Run::name()`. I choose this over novel candidates deliberately: inventing a different answer for novelty's sake would be exactly the failure mode this project catalogues.

---

## Ranking (impact × confidence)

1. **runName/stepConfig + binding→model config gap** — high impact (all traced sequences mislabeled), high confidence (two independent confirmations + upstream citation). *On record; still owed.*
2. **PORT_STATUS fused row + missing assertion-count guard** — medium impact (the contract with the next engineer is broken in the table its own guard watches), high confidence (visible in the brief).
3. **`LangChain\Schema` unresolved** — low-medium impact, medium confidence (no evidence either way; that is the problem).
4. **222/230 inventory gap** — low impact if packet artifact, medium if PORT_STATUS shares it; low confidence either way — stated as unsure.
5. **`Pregel::$channels` public and mutable** — low impact, high confidence (visible in the §4 signature).
6. **No live-provider smoke coverage** — medium impact, high confidence as a structural fact; arguably accepted scope, listed last for that reason.
