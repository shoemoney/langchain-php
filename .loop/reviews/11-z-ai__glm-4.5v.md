# Review 11 - z-ai/glm-4.5v
_asked 2026-09-29T09:37:07 - served by z-ai/glm-4.5v - 383s_

## 1. Missing `finally` guard in `BaseChatModel::stream()` leaks trace runs on partial consumption
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` — `stream()` method (approx line 220–260)
**Why it matters:** If a caller stops consuming the generator early (e.g., breaking after the first token or hitting a token limit), the `foreach` loop exits, and the function effectively terminates without executing the code following the loop. Crucially, `$runManager?->handleLLMEnd(...)` sits *after* the loop and *outside* a `finally` block. This means every partially-read stream leaves an orphaned "started" run in the tracer that never receives an "end" event, corrupting trace history and breaking timing/calculations in tracing UIs.
**Suggested fix:** Wrap the aggregation and cleanup logic in a `finally` block, or move `$runManager?->handleLLMEnd(...)` into a `finally` block attached to the `try` block containing the `foreach` loop. Ensure `$aggregated` is accessible within the `finally` scope (declare it before the `try`).

## 2. Orphaned run managers in `BaseChatModel::generateMessages()` on batch failure
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` — `generateMessages()` method (approx line 170–210)
**Why it matters:** `handleChatModelStart` is called once for the entire batch of `$messageLists`, creating a run manager for *every* prompt upfront. The subsequent `foreach` loop processes them sequentially. If `dispatchGenerate` throws on the 2nd of 5 prompts, the `catch` block correctly errors the 2nd run. However, the loop terminates, and the run managers for prompts 3, 4, and 5 are never touched—they remain in the "started" state forever. This pollutes the tracer with zombie runs that appear to have hung.
**Suggested fix:** Add a `finally` block (or a `catch` block that re-throws after cleanup) that iterates the remaining `$runManagers` (from `$index + 1` onwards) and calls `handleLLMError` or `handleLLMEnd` on them to close them out.

## 3. Incorrect Run Manager used for `stampMessageId` in batch generation
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` — `generateMessages()` method, inside the `foreach` loop.
**Why it matters:** Inside the loop, the code correctly identifies `$thisRunManager = $runManagers[$index] ...` for the current prompt's callbacks. However, the call to `$this->stampMessageId($generation, $runManager)` uses the **original** `$runManager` (which defaults to index `0`), not `$thisRunManager`. If a provider fails to return an ID for a message in the 2nd prompt, it gets stamped with the Run ID of the **first** prompt. This cross-wires the message's identity to the wrong trace run, breaking lineage tracking in batch mode.
**Suggested fix:** Change the second argument of `$this->stampMessageId($generation, $runManager)` to `$thisRunManager`.

## 4. Streaming usage metrics silently dropped due to key mismatch risk
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php` (`llmOutputFromUsage`) vs `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php` (`streamResponseChunks`)
**Why it matters:** `BaseChatModel::llmOutputFromUsage` explicitly reads `$message->response_metadata["usage_metadata"]`. In `ChatOpenAI::streamResponseChunks`, the usage-only chunk (sent at the end of the stream) populates `response_metadata` via `Completions::responseMetadata($payload)`. If this helper maps the raw OpenAI `usage` payload to any key *other than* `usage_metadata` (e.g., `usage` or `token_usage`), the lookup returns `null`, and the method returns an empty array. The resulting `LLMResult` reports 0 tokens used, silently breaking cost tracking and token limit enforcement for all streamed calls.
**Suggested fix:** Verify that `Completions::responseMetadata` outputs the usage data under the exact key `usage_metadata` to satisfy the `BaseChatModel` contract, or adjust `llmOutputFromUsage` to handle the provider's raw key if the abstraction layer permits.

## 5. `ChatOpenAI::postStream` retries non-idempotent request, risking duplicate charges or data loss
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php` — `postStream()` method (retry loop)
**Why it matters:** The retry logic allows retrying the POST request if `$delivered` is false. However, `$delivered` is only set to `true` when `$bytes !== ''`. If the HTTP layer delivers bytes that are immediately consumed by `SseParser::feed` (buffered internally because the SSE event line is incomplete), `$delivered` remains `false`. If the connection then drops, the client retries the POST. This risks:
1.  **Duplicate Billing:** OpenAI may process the first request (partially received by the client) and charge for it; the retry triggers a second processing.
2.  **Silent Data Loss:** The buffered bytes from the first attempt are discarded when `new SseParser()` is instantiated for the retry, dropping the beginning of the model's response.
**Suggested fix:** Do not retry the POST request once the body iteration has begun (i.e., once the `foreach ($raw as $bytes)` loop has been entered), regardless of whether `yield` occurred. Alternatively, accept that stream retries are unsafe and restrict retries to the connection-establishment phase only (before the `foreach`).