# Review 23 - x-ai/grok-build-0.1
_asked 2026-09-29T10:37:05 - served by x-ai/grok-build-0.1 - 107s_

**Here are exactly five findings.**

## 1. ChatAnthropic never rejects unsupported parameters at any layer
**Severity:** MAJOR  
**Evidence:** `ChatAnthropic.php` (constructor after `canonicalise`, `bindTools`, and `invocationParams`); contrast with `ChatOpenAI.php:rejectUnsupported` + `UNSUPPORTED = ['topK']` + calls in the same three places. No equivalent exists for Anthropic.  
**Why it matters:** A caller can do `bindTools(..., ['frequency_penalty' => 0.5])` or pass it per-call. The value lands in `kwargs` (and therefore every trace), is sent on the wire, and produces a provider 400 instead of a clear early error. This is the exact class of silent-misconfig bug that produced the `topK`, `max_tokens`, and `streamUsage` defects listed in HANDOFF.md.  
**Suggested fix:** Add a `private const UNSUPPORTED = ['frequency_penalty', 'presence_penalty', ...];` (and the wire spellings), a `rejectUnsupported` method that canonicalises first, and call it after canonicalise in `__construct`, on the incoming `$kwargs` in `bindTools`, and at the top of `invocationParams` (exactly as OpenAI does).

## 2. ChatAnthropic loses the `strict` decision across chained `bindTools` calls
**Severity:** MINOR  
**Evidence:** `ChatAnthropic.php:bindTools` (`$strict = $kwargs['strict'] ?? null; ... $next->kwargs['tools'] = ...` with no further assignment); `ChatOpenAI.php:bindTools` (the block that does `$next->supportsStrictToolCalling = ...` and the comment "Previously ... chaining a second bind silently dropped the strictness"). ChatAnthropic has no `$supportsStrictToolCalling` property at all.  
**Why it matters:** `$model->bindTools($t1, ['strict' => true])->bindTools($t2)` will convert the second set of tools without the strict flag. The first bind's intent is lost with no error and no trace of what happened.  
**Suggested fix:** Add `public ?bool $supportsStrictToolCalling = null;` to `ChatAnthropic` and replicate the "remember the decision on the bound instance" logic from OpenAI's `bindTools` (reading from `$kwargs['strict'] ?? $this->supportsStrictToolCalling` and writing it back onto `$next`).

## 3. `RunnableParallel::batch` passes the identical config to every branch and ignores `$options`
**Severity:** MINOR  
**Evidence:** `RunnableParallel.php:batch` (`array_map(fn($input) => $this->invoke($input, $config), ...)` — `$options` is in the signature but never used; no `forChild`); compare to `invoke` which does `$config?->forChild("map:key:{$key}")`.  
**Why it matters:** In a batched parallel (common in RAG + structured-output pipelines), child runs all share the same run id / dotted order / callback scope. Tracing and callback managers see collapsed or mis-attributed runs. The documented "batch options are ignored" behaviour is made worse because even the config isolation that `invoke` provides is absent.  
**Suggested fix:** Build a per-input config with `forChild("batch:$i")` (or equivalent) and pass distinct configs. If `$options` cannot be honoured, at minimum document the limitation on the method and in the interface the way the packet already does for the scalar-input case.

## 4. Usage-only chunks in streaming are emitted as separate empty-content messages on the default channel
**Severity:** MINOR  
**Evidence:** `ChatOpenAI.php:streamResponseChunks` (the `if (!is_array($choices) || $choices === []) { if (isset($payload['usage'])) yield new ChatGenerationChunk(new AIMessageChunk(['content' => '', ... 'usage_metadata' => ...]), '', ...); }`); identical pattern in `ChatAnthropic::consume` via `MessageOutputs::usageFromEvent`; base `stream()` then does `yield [CHANNEL_DEFAULT, $chunk->message]` and folds.  
**Why it matters:** A consumer iterating the stream sees an extra message whose only payload is usage metadata (content `''`). If the consumer is only looking at non-empty content or is reconstructing a transcript, it either gets a spurious empty turn or must special-case the last event. This is observable downstream of the folding algebra.  
**Suggested fix:** Either (a) never yield a separate chunk for pure-usage events and instead mutate the last real chunk's `response_metadata` before yielding it, or (b) yield usage on a distinct channel (e.g. `usage`) so callers can filter. The second option is more consistent with the `[channel, value]` streaming model described in the architecture.

## 5. `stampMessageId` always uses the batch root run manager even for per-prompt generations
**Severity:** MINOR  
**Evidence:** `BaseChatModel.php:generateMessages` (inside the per-prompt loop: `$this->stampMessageId($generation, $runManager);` with the explicit comment "Upstream stamps a missing id from `runManagers.at(0)` ... not of the individual prompt"; `$thisRunManager` is computed but not used for stamping).  
**Why it matters:** In a `generateMessages` / batched prompt call, every returned message gets an id derived from the first prompt's run. When those messages later appear in tool calls or are referenced by `tool_call_id`, it is impossible to tell which prompt they belonged to. This is exactly the class of "value written but the wrong value is the one that is read later" that has bitten the project before (see the `runManagers` indexing bug in HANDOFF.md).  
**Suggested fix:** Pass `$thisRunManager` (falling back to `$runManager`) to `stampMessageId`. If upstream truly intends the batch root id, add a test that asserts the id is the same across prompts and document the choice in PORT_STATUS.md as a deliberate non-exact behaviour. Currently the code and the comment disagree with the per-prompt run manager that is already being threaded for `handleLLMEnd`.