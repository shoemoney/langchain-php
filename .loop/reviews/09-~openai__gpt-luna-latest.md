# Review 9 - ~openai/gpt-luna-latest
_asked 2026-09-30T09:01:19 - served by openai/gpt-6-luna - 57s_

## 1. Start-hook tags and metadata are discarded
**Severity:** MAJOR
**Evidence:** `CallbackManager::handleLLMStart()` accepts `$tags` and `$metadata` but passes `$this->tags` and `$this->metadata` to tracer creation and handlers; the same pattern appears in other start hooks.
**Why it matters:** Per-run tags and metadata supplied to these public methods never reach the run or its observers.
**Suggested fix:** Merge the supplied values with manager state, then pass the merged values consistently to tracer creation and handlers.

## 2. Custom-event overrides are ignored
**Severity:** MAJOR
**Evidence:** `CallbackManager::handleCustomEvent()` and `BaseRunManager::handleCustomEvent()` accept `$tags` and `$metadata` but dispatch `$this->tags` and `$this->metadata`.
**Why it matters:** Callers cannot attach event-specific tags or metadata; handlers receive the run defaults instead.
**Suggested fix:** Dispatch the supplied values when present, falling back to the manager’s tags and metadata otherwise.

## 3. Adding a duplicate tag can stop it propagating
**Severity:** MAJOR
**Evidence:** `CallbackManager::addTags()` calls `removeTags($tags)` before appending; `removeTags()` removes matching tags from both `$tags` and `$inheritableTags`.
**Why it matters:** Adding an already-inheritable tag with `$inherit = false` removes it from the inheritable set, so child runs lose that tag.
**Suggested fix:** Deduplicate the local and inheritable lists independently; a non-inheritable addition should not downgrade an existing inheritable tag.

## 4. Chain-start extras never reach the run
**Severity:** MINOR
**Evidence:** `BaseTracer::handleChainStart()` accepts `$extra` but calls `createRunForChainStart(...)` without forwarding it.
**Why it matters:** Direct calls to the tracer lose chain-start extra fields from the recorded run.
**Suggested fix:** Pass `$extra` as the final argument to `createRunForChainStart()` and add a regression asserting it appears in the run’s `extra`.

## 5. Tool-start JSON encoding can violate the string contract
**Severity:** MINOR
**Evidence:** `CallbackManager::handleToolStart()` passes `json_encode($input, ...)` directly as handler input, although `json_encode()` can return `false`.
**Why it matters:** Structured input containing invalid UTF-8 can deliver `false` instead of a string, causing a handler type error or a silently skipped start event.
**Suggested fix:** Use `JSON_INVALID_UTF8_SUBSTITUTE`, check the result, and ensure handlers always receive a string or a surfaced encoding error.