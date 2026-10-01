# Review 72 - ~x-ai/grok-latest
_asked 2026-10-01T04:44:43 - served by x-ai/grok-4.7 - 256s_

## 1. Explicit empty `tools` falls through to bound tools
**Severity:** MAJOR
**Evidence:** `ChatOpenAI::invocationParams` — `'tools' => $this->convertTools($this->pick($options, 'tools')) ?? $this->convertTools($bound['tools'] ?? null)`; `convertTools` returns `null` for `[]`. Same `??` chain in `ChatAnthropic::invocationParams`.
**Why it matters:** `pick()` returns `[]`, `convertTools` turns it into `null`, and `??` loads the bound tools. A call that clears tools still offers them; the later `unset` of `[]` never sees the empty list.
**Suggested fix:** Treat a present key as authoritative, including `[]`. Fall through only when the key is absent; drop an empty list after that choice, not before it.

## 2. `streamUsage` is ignored on bind and per-call options
**Severity:** MAJOR
**Evidence:** Both clients gate on the property only: `if ($this->streamUsage)` in `ChatOpenAI::invocationParams`, and `$this->streamUsage ? MessageOutputs::usageFromEvent($event) : []` in `ChatAnthropic::consume`. `bindTools` writes `$next->kwargs[$key] = $value` and never assigns `$next->streamUsage`. `streamUsage` is in both kwargs whitelists.
**Why it matters:** `bindTools($tools, ['streamUsage' => false])` still sends `stream_options.include_usage` (OpenAI) or still emits usage chunks (Anthropic), while traces show the flag as off. Constructor is the only path that works.
**Suggested fix:** Resolve `streamUsage` with the same options → kwargs → property order as `maxTokens`, and have `bindTools` set the property on the clone.

## 3. Chat-role messages cannot be sent to either provider
**Severity:** MAJOR
**Evidence:** `Completions` docblock says a `chat` message is mapped and only an unrecognised role is a hard error. `roleOf()` matches only `system|human|ai|tool|function`, then `throw new \InvalidArgumentException('Got unsupported message type: ' . $message->type)`. `MessageInputs::convertMessage` has the same gap (`human|tool|ai|system` only).
**Why it matters:** A `ChatMessage` throws in `convertMessages` / `convert` before any HTTP call, whether `type` is `chat` or the role (`user` is not in the match). The conversation never leaves the client.
**Suggested fix:** Match `ChatMessage` by class, map its role (`user`/`assistant`/`system`) onto the provider role, and throw only for a role that provider does not accept.

## 4. Anthropic `strict` is applied only inside `bindTools`
**Severity:** MAJOR
**Evidence:** `ChatAnthropic::invocationParams` calls `self::convertTools(...)` with no second argument (`$strict` defaults to `null`). `convertTool` adds `'strict'` only when `$strict !== null`. The constructor kwargs whitelist has no `strict`, and there is no `supportsStrictToolCalling` property. `bindTools` is the only caller that passes `$strict`.
**Why it matters:** `new ChatAnthropic(['tools' => [...], 'strict' => true])` and a per-call `tools` + `strict` send schemas with no `strict` key. The request looks bound and the constraint is absent. OpenAI at least passes `$this->supportsStrictToolCalling` from `convertTools`.
**Suggested fix:** Thread an instance/options `strict` into every `convertTools` call, including constructor tools stored raw in `kwargs`.

## 5. Token callbacks receive different chunk types per provider
**Severity:** MINOR
**Evidence:** `ChatOpenAI::streamResponseChunks` calls `handleLLMNewToken($text, ['chunk' => $chunk])` with the `AIMessageChunk` from `deltaToChunk`. `ChatAnthropic::consume` calls `handleLLMNewToken($text, ['chunk' => $generation])` with the `ChatGenerationChunk`.
**Why it matters:** A handler that reads message fields off `chunk` (tool-call chunks, `response_metadata`) works for OpenAI and gets a generation wrapper for Anthropic. Streaming traces diverge by provider with no error.
**Suggested fix:** Pass the `AIMessageChunk` on both paths, matching the OpenAI call.