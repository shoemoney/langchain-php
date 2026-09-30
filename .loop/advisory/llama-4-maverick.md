# Advisory review — meta-llama/llama-4-maverick

_Generated 2026-09-30T14:06:52.395864+00:00_

### layering
**Verdict:** sound
**Evidence:** src/LangGraph/Pregel/Pregel.php, src/LangChain/LanguageModels/BaseChatModel.php
**Finding:** The namespace boundaries and PSR-4 layout are correctly maintained, with no class reaching across a layer it should not.
**Suggested change:** none

### public-api
**Verdict:** sound
**Evidence:** src/LangChain/LanguageModels/BaseChatModel.php, src/LangGraph/Pregel/Pregel.php, src/LangChain/Tools/Schema.php
**Finding:** The public surface of the core abstractions (BaseChatModel, Runnable, Pregel) is coherent and not leaky.
**Suggested change:** none

### composition
**Verdict:** needs work
**Evidence:** src/LangChain/Runnables/RunnableSequence.php, src/LangGraph/Pregel/Pregel.php
**Finding:** While the subsystems are designed to compose, there are seams between them that can cause issues, such as the `RunnableSequence::stepConfig()` overwriting `runName`.
**Suggested change:** Relocate `seq:step:N` from `runName` to `config->tags` to fix the composition issue.

### error-handling
**Verdict:** needs work
**Evidence:** src/LangChain/LanguageModels/BaseChatModel.php, tests/TransportExceptionContractTest.php
**Finding:** Exception types and causes are generally well-handled, but there are cases where a caller cannot catch one type and trust it, such as when a provider error is pushed down an SSE stream.
**Suggested change:** Improve error handling for SSE streams to ensure that errors are properly propagated.

### fidelity
**Verdict:** sound
**Evidence:** PORT_STATUS.md, src/LangChain/LanguageModels/BaseChatModel.php
**Finding:** The port is generally faithful to the TypeScript upstream, with documented divergences that are justified.
**Suggested change:** none

### test-strategy
**Verdict:** needs work
**Evidence:** tests/PhpVersionCompatibilityTest.php, tests/DocsMatchRealityTest.php
**Finding:** The test suite is comprehensive but may not cover all edge cases, and some guards are not load-bearing.
**Suggested change:** Enhance the test strategy to include more edge cases and ensure that all guards are load-bearing.

### documentation
**Verdict:** sound
**Evidence:** PORT_STATUS.md, HANDOFF.md
**Finding:** The documentation accurately describes the code that exists, with no contradictions between the documentation and the brief.
**Suggested change:** none

### dead-code
**Verdict:** unsure
**Evidence:** None
**Finding:** It is unclear whether there is any written-but-unreachable code or orphans in the codebase.
**Suggested change:** Perform a thorough analysis to identify and remove any dead code.

## Cross-cutting questions

A. **Composition** — Tracing two realistic user journeys through the port:
   1. Binding tools to a model and streaming a response inside a StateGraph checkpointed run.
   2. Using a RunnableSequence to process input.

   Both journeys work end-to-end, but there are seams between subsystems, such as between `RunnableSequence` and `Pregel`.

B. **Layering violations** — No low-level utility knows about a high-level abstraction, and the graph layer does not know about specific providers.
   **Evidence:** src/LangGraph/Pregel/Pregel.php, src/LangChain/LanguageModels/BaseChatModel.php

C. **The honesty of the record** — PORT_STATUS.md and HANDOFF.md are generally honest, but there may be ported-but-untested areas.
   **Evidence:** PORT_STATUS.md, HANDOFF.md, section 5

D. **What the green suite hides** — Three specific places where a passing suite would tell you nothing:
   1. The `RunnableSequence::stepConfig()` defect, which was fixed but had a complex history.
   2. The `bind()` slot wrapper trap, which was a regression that was later reverted.
   3. The handling of Anthropic's `streamUsage`, which was not properly wired.

E. **The single highest-leverage change** — Relocating `seq:step:N` from `runName` to `config->tags` to fix the composition issue in `RunnableSequence`.
   **Evidence:** src/LangChain/Runnables/RunnableSequence.php, section 5

## Findings ranked by (impact x confidence)

1. **Relocate `seq:step:N` from `runName` to `config->tags`** (High impact, High confidence)
2. **Improve error handling for SSE streams** (Medium impact, Medium confidence)
3. **Enhance the test strategy** (Medium impact, Medium confidence)
4. **Remove dead code** (Low impact, Low confidence)
