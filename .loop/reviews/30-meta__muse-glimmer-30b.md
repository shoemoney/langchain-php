# Review 30 - meta/muse-glimmer-30b
_asked 2026-09-29T11:20:22 - served by meta/muse-glimmer-30b - 18s_

## 1. ChatOpenAI::postStream decodes SSE without checking HTTP status
**Severity:** BLOCKER
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php:postStream()` – `http()->postStream()` is called and the generator is drained, but the response code is never inspected. `post()` checks `isOk()` and throws `OpenAIException`; `postStream()` does not.
**Why it matters:** A 4xx/5xx response body is fed to `SseParser` and `json_decode`. The caller receives a truncated/empty stream with no provider error, losing the failure reason and making retries impossible.
**Suggested fix:** Wrap the `postStream` call, inspect the first response metadata for a non-2xx status and throw `OpenAIException::fromResponse()` before yielding any events, mirroring `post()`.

## 2. ChatAnthropic::invocationParams validates tool_choice against un-normalised tools
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/Chat/Anthropic/ChatAnthropic.php:invocationParams()` – tool_choice is formatted via `MessageInputs::formatToolChoice`, but `$params['tools']` is taken from `$this->pick(... ) ?? $bound['tools'] ?? null` without running `MessageInputs::convertTool` on bound tools. The name check then compares a formatted choice to raw bound values.
**Why it matters:** A bound tool list created by `bindTools()` is already converted to Anthropic wire shape. The validation can reject a valid choice or pass an invalid one, producing a 400 from Anthropic with a misleading message.
**Suggested fix:** Normalise `$params['tools']` to wire shape before the name-membership check, or move the validation after `invocationParams` has been fully normalised.

## 3. SseParser separator offset is documented but still present
**Severity:** MINOR
**Evidence:** `PORT_STATUS.md` Known defects – “One further fix — the `SseParser` separator offset — has **no** observable failure in a 4,000-case differential run and is documented as such rather than claimed as a caught bug.”
**Why it matters:** The parser can split an event on a partial `\r\n` boundary, yielding a payload with a leading empty line. Tests pass, but a real provider that emits split chunks can produce a malformed JSON payload and a spurious `AnthropicException`/`OpenAIException`.
**Suggested fix:** Make `SseParser::feed()` keep a trailing partial line in an internal buffer and only emit complete `data:` lines, matching the upstream `eventsource-parser` behaviour.

## 4. BaseChatModel::stream finally reports a run end even after a caught error
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php:stream()` – `catch` sets `$ended = true` and calls `handleLLMError`, then `finally` checks `!$ended` before `handleLLMEnd`. The `catch` re-throws, so `finally` runs, but `$ended` is true, so the extra end is avoided. The comment claims a double-report is avoided, yet the `finally` still constructs a new `LLMResult` with possibly null `$aggregated` on every early break.
**Why it matters:** An early `break` by the consumer leaves `$aggregated` partially built; the `finally` path ends the run with whatever was accumulated, which can be an empty message with no usage. The trace therefore shows a successful run for an abandoned stream.
**Suggested fix:** In `finally`, only call `handleLLMEnd` when `$ended` is false *and* `$runManager` is not null, and guard the construction of the `LLMResult` with `$aggregated !== null`.

## 5. ChatOpenAI::invocationParams drops bound `parallelToolCalls` when bound via wire spelling
**Severity:** MINOR
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php:invocationParams()` – `parallel_tool_calls` is picked with `$this->pick($options, 'parallelToolCalls', 'parallel_tool_calls') ?? $bound['parallelToolCalls'] ?? null`. The bound bag is normalised via `normaliseKeys`, but the pick for options is not normalised before the lookup, so a bound `parallel_tool_calls` is never found under the camelCase key.
**Why it matters:** `bind([...], ['parallel_tool_calls' => false])` is silently ignored; the request is sent with the default, making the bind appear to work while having no effect.
**Suggested fix:** Normalise `$options` before the `pick` calls, or add `'parallel_tool_calls'` to the bound lookup after normalisation, consistent with `maxTokens`/`max_tokens` handling.