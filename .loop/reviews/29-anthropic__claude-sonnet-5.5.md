# Review 29 - anthropic/claude-sonnet-5.5
_asked 2026-09-29T11:15:53 - served by anthropic/claude-sonnet-5.5 - 14s_

## 1. Stream failure: `finally` fires a spurious error on a clean early exit, and retry masks partial output
**Severity:** MINOR
**Evidence:** `BaseChatModel::stream()`. `$ended = true` is set after the loop, but `handleLLMEnd` runs after that. If `handleLLMEnd` throws, the `catch` calls `handleLLMError` on a run that already ended. This is inference from reading the code, not a run.
**Why it matters:** A handler failure in the end hook produces both an end event and an error event for the same run.
**Suggested fix:** Set `$ended = true` only after `handleLLMEnd` returns. Guard the `catch` with `if (!$endedRun)`.

## 2. `generateMessages` stamps ids and reports usage from the wrong run
**Severity:** MAJOR
**Evidence:** `BaseChatModel::generateMessages`. `stampMessageId($generation, $runManager)` uses `$runManager`, which is `$runManagers[0]`, for every prompt. The code comment says upstream does this, but `$thisRunManager` is used everywhere else in the loop.
**Why it matters:** Messages from prompts 2..n get `run-<id of prompt 1>` as their id, so several distinct messages share one id. Reducers that merge by id (`add_messages`-style) will then collapse or overwrite them.
**Suggested fix:** Verify against upstream. If upstream stamps per prompt, use `$thisRunManager`. Add a test with 2 prompts that asserts the ids are distinct.

## 3. Usage stored in `response_metadata` gets double-counted or mangled by the merge
**Severity:** MAJOR
**Evidence:** `Completions::responseMetadata` writes both `usage` and `usage_metadata` (ints). `MessageMerge` sums numeric leaves. `BaseChatModel::stream()` also does `array_merge($chunk->generationInfo, response_metadata)` before folding. Inference: I did not see MessageMerge.
**Why it matters:** Providers that repeat cumulative usage on several chunks are summed rather than replaced. OpenAI sends usage only at the end, but Anthropic's `message_delta` can carry cumulative output tokens, so totals can be inflated. Nothing in the packet shows a test that asserts real totals against a multi-usage stream.
**Suggested fix:** Add a fixture with cumulative usage on multiple events and assert the exact total. Otherwise merge usage with upstream's `mergeUsageMetadata` semantics.

## 4. Named-tool `tool_choice` guard is order-dependent
**Severity:** MINOR
**Evidence:** `ChatAnthropic::invocationParams`. The guard compares `$choice['name']` with `$t['name']`, but `$params['tools']` can come from an options-supplied OpenAI-shaped envelope, where the name sits under `function.name`. Inference: I did not see `convertTool`.
**Why it matters:** A valid choice throws "tool not available", or an empty name matches an empty string.
**Suggested fix:** Extract names with `$t['name'] ?? $t['function']['name']`. Add a test that passes per-call raw tools with a named choice.

## 5. `batch()` silently ignores `returnExceptions`/options, and `RunnableLambda` drops config
**Severity:** MAJOR
**Evidence:** PORT_STATUS rows for `batch()` and `RunnableLambda::invoke()`. Both are documented, but they are still behavioural gaps in core primitives.
**Why it matters:** `RunnableLambda` cannot read its config. Upstream passes `(input, config)` and LCEL relies on this to propagate callbacks and child runs. A lambda that calls a nested runnable does not inherit the callbacks, so its traces detach from the parent run. This is a fidelity gap in a port whose goal is equivalence, so a documented divergence does not make it correct.
**Suggested fix:** Pass `RunnableConfig` as an optional second argument when the callable's arity is ≥2, and have the lambda forward its config to nested runnables. Implement `returnExceptions` in `batch()` by catching per-item exceptions.