# Advisory review — google/gemma-4-31b-it

_Generated 2026-09-30T09:52:04.577443+00:00_

This is a whole-system advisory review of the `langchain-php` port.

### 1. layering
**Verdict:** sound
**Evidence:** Namespace inventory; Cross-package reference counts (LangGraph -> LangChain: 15 files).
**Finding:** The dependency direction is correct and matches upstream (LangGraph depends on LangChain core). The PSR-4 layout is clean, and the separation between `Provider Clients` and `Core Abstractions` is maintained.
**Suggested change:** none.

### 2. public-api
**Verdict:** sound
**Evidence:** `BaseChatModel.php`, `Pregel.php`, `Schema.php` public methods.
**Finding:** The surface area is lean and coherent. The use of `static` return types for `bindTools` and `Generator` for `stream` correctly maps TS patterns to PHP 8.2+ idioms without leaking internal implementation details.
**Suggested change:** none.

### 3. composition
**Verdict:** needs work
**Evidence:** `[advisory/openai-o4-mini-high] #5` (runName lost in sequences).
**Finding:** While the components coexist, the "plumbing" of configuration (specifically `runName` and `RunnableConfig`) is leaky. The fact that a `RunnableSequence` clobbers the `runName` of its children indicates a failure in the composition logic of the LCEL (LangChain Expression Language) layer.
**Suggested change:** Fix the `stepConfig` / `forChild` gap to ensure `RunnableConfig` propagates without loss through sequences.

### 4. error-handling
**Verdict:** needs work
**Evidence:** `[~openai/gpt-luna-latest] #1` (Empty stream success vs eager throw).
**Finding:** There is a critical inconsistency in the error contract: `BaseChatModel::stream()` returns a successful empty result on empty responses, while `aggregateStream()` (the eager path) throws a `RuntimeException`. A caller cannot trust a single error-handling strategy across both paths.
**Suggested change:** Unify the behavior of empty responses across `stream()` and `invoke()` to match upstream's specific failure mode.

### 5. fidelity
**Verdict:** sound
**Evidence:** PORT_STATUS.md; `[~anthropic/claude-sonnet-latest] #3` (Removal of `pipeTo`).
**Finding:** Fidelity is high. The port correctly identifies where PHP cannot match TS (e.g., `RunnableParallel` scalar inputs) and documents these as deliberate divergences. The removal of the invented `pipeTo` method shows a commitment to the upstream reference over "convenience" API additions.
**Suggested change:** none.

### 6. test-strategy
**Verdict:** needs work
**Evidence:** `[~openai/gpt-luna-latest] #5` (SSE parsing leading spaces).
**Finding:** The suite is broad (2290 tests) but suffers from "happy-path bias." The SSE bug proves that the tests were too focused on JSON payloads, missing failures that only occur with plain-text or indented content. The suite proves the *logic* works but doesn't sufficiently stress the *wire formats*.
**Suggested change:** Introduce "adversarial" wire-format tests (non-JSON SSE, malformed UTF-8 sequences) that do not rely on the high-level `BaseChatModel` abstractions.

### 7. documentation
**Verdict:** sound
**Evidence:** `DocsMatchRealityTest`; `[advisory/fireworks-ember-1] #documentation`.
**Finding:** The record is honest. The existence of `DocsMatchRealityTest` ensures that the ledger doesn't drift from the code. Recent corrections to `PORT_STATUS.md` (fused rows, stale ledger quotes) show a rigorous approach to the "contract" with future engineers.
**Suggested change:** none.

### 8. dead-code
**Verdict:** sound
**Evidence:** `[~anthropic/claude-sonnet-latest] #5` (Removal of dead autoload mapping).
**Finding:** The project is actively pruning dead configuration and unreachable methods (like the broken `pipeTo`).
**Suggested change:** none.

---

### A. Composition
**Journey 1: Bind tools $\rightarrow$ Model $\rightarrow$ Stream $\rightarrow$ StateGraph.**
*   **Status:** Works, but with a "tracing blind spot."
*   **Seam:** The seam between `RunnableBinding` and `BaseChatModel`. While the tools are bound and the stream flows, the `runName` is lost in the sequence, meaning the trace for the model call is mislabeled.

**Journey 2: Structured Output $\rightarrow$ RunnableSequence $\rightarrow$ Invoke.**
*   **Status:** Works.
*   **Seam:** The seam between `StructuredOutput` and `BaseChatModel`. The port correctly limits the base `withStructuredOutput` to function-calling, avoiding the "silent substitution" of JSON mode.

### B. Layering Violations
**Finding:** None. The `LangGraph -> LangChain` dependency is the intended architecture. Low-level utilities (like `HttpClient`) are agnostic of high-level providers.

### C. The Honesty of the Record
**Finding:** The record is exceptionally honest. `PORT_STATUS.md` explicitly lists "Known non-exact behaviours," which prevents reviewers from treating intentional divergences as bugs. The only contradiction was a stale ledger row (fixed in the Perplexity iteration), which was caught and corrected.

### D. What the Green Suite Hides
A passing suite tells you nothing about:
1.  **Wire-format edge cases:** As seen in the SSE leading-space bug, if the tests only use JSON, the parser's handling of raw text is untested.
2.  **Tracing Metadata Propagation:** Unit tests for `invoke()` often check the *return value*, not the *side-effect* of the trace. The `runName` clobber lived in the green suite because the return value was correct, even though the metadata was destroyed.
3.  **Stream Abandonment:** Because generators are lazy, a test that consumes a stream to completion doesn't test what happens when a consumer `break`s early (the `finally` block logic).

### E. The Single Highest-Leverage Change
**The Change:** Unify the "Empty Response" contract between `stream()` and `invoke()`.
**Why:** This is the most dangerous current defect. It creates a non-deterministic error surface where the same API call succeeds or fails based solely on whether the user chose the streaming or eager path. This breaks the fundamental promise of a `BaseChatModel` abstraction.

---

### Final Ranking of Findings

| Finding | Impact | Confidence | Score (IxC) |
| :--- | :---: | :---: | :---: |
| **Empty Response Inconsistency** (Element 4) | High | High | **Critical** |
| **RunnableConfig/runName Clobber** (Element 3) | Med | High | **High** |
| **Wire-Format Test Blindspots** (Element 6) | Med | Med | **Medium** |
