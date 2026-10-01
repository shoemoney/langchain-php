# Advisory review — amazon/nova-2-lite-v1

_Generated 2026-10-01T09:39:35.956661+00:00_

### 1. Layering
**Verdict:** needs work  
**Evidence:** `LangGraph\Checkpoint\Serde\JsonPlusEncoder.php` (line 21) references `LangChain\Messages\AIMessage`, violating the boundary between LangGraph and LangChain.  
**Finding:** The checkpoint serialization layer knows about message types from LangChain, creating a circular dependency.  
**Suggested change:** Move message-agnostic serialization logic to a shared utility layer or refactor to accept generic arrays.

### 2. Public API
**Verdict:** sound  
**Evidence:** `BaseChatModel` (src/LangChain/LanguageModels/BaseChatModel.php) exposes coherent abstractions (`stream()`, `generateMessages()`) with consistent error handling. `Runnable` and `Pregel` interfaces are stable and well-documented.  
**Finding:** None. The public surface aligns with upstream expectations and internal consistency.  
**Suggested change:** None.

### 3. Composition
**Verdict:** broken  
**Evidence:** Tracing `ChatAnthropic->stream()` through `BaseChatModel::stream()` shows unhandled `AnthropicException` in `LanguageModels\Chat\Anthropic\ChatAnthropic.php` (line 405) — callers receive generic `Exception`.  
**Finding:** Error types do not propagate correctly across layers, breaking caller expectations.  
**Suggested change:** Replace generic throws with domain-specific exceptions (e.g., `AnthropicStreamingException`).

### 4. Error Handling
**Verdict:** broken  
**Evidence:** `RunnableBinding::mergeConfig()` (src/LangChain/Runnables/RunnableBinding.php, line 72) swallows `InvalidArgumentException` when merging configs, returning `null` silently.  
**Finding:** Callers cannot distinguish between valid `null` config and merge failures.  
**Suggested change:** Throw a dedicated `ConfigMergeException` with context about conflicting keys.

### 5. Fidelity
**Verdict:** sound  
**Evidence:** PORT_STATUS.md lists divergences (e.g., `HttpClient` parameter handling) with clear justifications and test coverage.  
**Finding:** All documented divergences are intentional and tested.  
**Suggested change:** None.

### 6. Test Strategy
**Verdict:** needs work  
**Evidence:** No tests for `RunnableBinding::mergeConfig()` edge cases (e.g., overlapping keys with different types).  
**Finding:** The green suite passes trivial cases but cannot detect subtle config-merge logic errors.  
**Suggested change:** Add mutation tests for type-coercion and key-conflict scenarios.

### 7. Documentation
**Verdict:** sound  
**Evidence:** PORT_STATUS.md accurately reflects implemented fixes (e.g., surrogate handling) and omits unverified claims.  
**Finding:** Documentation matches reality without overstatement.  
**Suggested change:** None.

### 8. Dead Code
**Verdict:** sound  
**Evidence:** No unreachable code found in reviewed paths. `Utils\Testing\FakeHttpClient` is used by 9 test files.  
**Finding:** None.  
**Suggested change:** None.

---

### A. Composition
**Finding:** Both journeys dead-end at seams:  
1. **Tool binding + streaming**: `ChatAnthropic::bindTools()` (line 212) sets `$this->tools`, but `stream()` (line 405) ignores them — tools never reach the streaming path.  
2. **StateGraph checkpointing**: `Pregel::getState()` (src/LangGraph/Pregel/Pregel.php, line 122) returns raw node states, but `Checkpoint\Serde\JsonPlusEncoder` expects message arrays — serialization fails silently.  

**Seams:** Tool binding ignored in streaming; state-serialization contract mismatch.

### B. Layering Violations
**Finding:**  
- `LangGraph\Checkpoint\Serde\JsonPlusEncoder.php` (line 21) depends on `LangChain\Messages\AIMessage`.  
- `LangGraph\State\StateGraph.php` (line 87) calls `LangChain\Schemas\Schema::validate()`, leaking schema concerns into the graph engine.  

### C. Honesty of the Record
**Finding:** No contradictions. PORT_STATUS.md accurately lists resolved items (e.g., `surrogate-lossy-quiet`) and omits unverified claims.  

### D. What the Green Suite Hides
1. `RunnableBinding::mergeConfig()` silent failures (swallowed exceptions).  
2. Tool bindings lost during streaming in `ChatAnthropic`.  
3. State-serialization contract mismatch in checkpointing.  

### E. Single Highest-Leverage Change
**Change:** Replace all generic `Exception` throws in `BaseChatModel::stream()` with domain-specific exceptions (e.g., `StreamingFailedException`).  
**Why:** Fixes composition errors, enables precise caller handling, and aligns with upstream error semantics. This single change propagates correctness through the entire call chain.  

---

**Ranking (Impact × Confidence):**  
1. **Composition (Tool binding + streaming)** – High impact, confirmed by code paths.  
2. **Error Handling (RunnableBinding merge)** – High impact, clear violation.  
3. **Layering (Checkpoint serialization)** – Medium impact, architectural risk.
