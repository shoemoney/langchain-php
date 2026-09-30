# Review 37 - z-ai/glm-4.6v
_asked 2026-09-30T15:26:26 - served by z-ai/glm-4.6v - 44s_

## 1. Cache key generation silently corrupts on unencodable input
**Severity:** BLOCKER
**Evidence:** `src/LangGraph/Pregel/Algorithm.php:buildCacheKey()`
**Why it matters:** `json_encode()` returns `false` for unencodable values (e.g., NaN, resources), which casts to `""`—collapsing distinct inputs into the same cache key. This silently corrupts cached results, making the graph nondeterministic.
**Suggested fix:** Add `JSON_THROW_ON_ERROR` to `json_encode()` and propagate the exception: `json_encode($input, JSON_THROW_ON_ERROR)`.

## 2. Conditional edges silently overwrite duplicate conditions
**Severity:** MAJOR
**Evidence:** `src/LangGraph/State/StateGraph.php:addConditionalEdges()`
**Why it matters:** The check only verifies the branch name, not the full condition. Two conditions with the same name but different paths overwrite each other, causing unexpected routing behavior without error.
**Suggested fix:** Compare the entire condition (runnable/callable) when checking for duplicates, not just the branch name.

## 3. Resume value comment contradicts code behavior
**Severity:** MINOR
**Evidence:** `src/LangGraph/Pregel/IO.php:mapCommand()`
**Why it matters:** The comment claims resume values use "MOST RECENT," but the code uses `array_slice($existing, 0, 1)` (keeps the first/oldest). This misleads developers about the actual behavior.
**Suggested fix:** Update the comment to match the code: "keeps the first queued resume value."

## 4. Path sorting in `applyWrites` may produce non-deterministic order
**Severity:** MAJOR
**Evidence:** `src/LangGraph/Pregel/Algorithm.php:applyWrites()`
**Why it matters:** The `comparePathSegments` function uses `strcmp` for mixed types (e.g., "10" vs. "9"), which orders lexicographically ("10" < "9") instead of numerically. This can reorder tasks in supersteps with numeric indices, breaking determinism.
**Suggested fix:** Use numeric comparison for numeric segments: `is_numeric($a) && is_numeric($b) ? (int)$a <=> (int)$b : strcmp(...)`.

## 5. `StateGraph` input channels not validated against schema
**Severity:** MAJOR
**Evidence:** `src/LangGraph/State/StateGraph.php:mapInput()`
**Why it matters:** Unrecognized keys in input are silently dropped, but the schema defines valid state channels. A typo in an input key (e.g., "item" vs. "items") silently fails to update state, causing hard-to-debug behavior.
**Suggested fix:** Validate input keys against `$this->schema->spec` and throw `InvalidUpdateError` for unrecognized keys.