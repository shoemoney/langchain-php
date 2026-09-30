# Review 16 - anthropic/claude-opus-5.5
_asked 2026-09-29T10:10:43 - served by anthropic/claude-opus-5.5 - 51s_

Four of these come from code in the packet. The fifth is an inference about wire data that is not in the packet, and it is labelled as such. I did not report anything from the HANDOFF bug table or the PORT_STATUS "Known non-exact behaviours" table.

## 1. Streamed Anthropic responses never carry `stop_reason`
**Severity:** MAJOR
**Evidence:** `ChatAnthropic::consume()`, and `MessageOutputs::eventToChunk()`, which returns `null` for every event type except `content_block_start` and `content_block_delta`.
- `consume()` builds `$generationInfo` from `$event['delta']['stop_reason']`, but only after `if ($chunk === null) continue;`.
- Anthropic sends `stop_reason` in `message_delta.delta`. That event makes `eventToChunk` return `null`, so the loop `continue`s before `generationInfo` is built.
- The events that do produce chunks (`content_block_*`) never carry `delta.stop_reason`.

So streamed `generationInfo` is always `[]`.
**Why it matters:**
- `invoke()` reports `stop_reason` (`generate()` reads it from the payload, and `responseMetadata` copies it).
- `stream()`, and any `invoke()` routed through `aggregateStream` because a handler has `preferStreaming`, silently lose it.
- A caller checking `stop_reason === 'max_tokens'` to detect truncation, or `'tool_use'` to drive a loop, gets a different answer depending on transport.
- The OpenAI client's `finish_reason` path does not have this problem.

**Suggested fix:**
- In `consume()`, compute `stop_reason` from `message_delta` events before the null-chunk check.
- When it is present, yield an empty `AIMessageChunk` with `response_metadata['stop_reason']` and `generationInfo['stop_reason']`, even if usage is gated off.
- Add a test: a streamed script ending in `message_delta{delta:{stop_reason:"max_tokens"}}` must produce the same `stop_reason` as the eager path.

## 2. Non-text Anthropic output blocks overwrite each other, and change shape between invoke and stream
**Severity:** MAJOR
**Evidence:** `MessageOutputs::responseToMessage()` sets `$additionalKwargs[$type ?? 'unknown'] = $block;`. `eventToChunk()` handles `thinking_delta` as `additional_kwargs['thinking'] = <string>` and returns `null` for a `content_block_start` of type `thinking`. There is no handler for `signature_delta`.
**Why it matters:**
- **Blocks are lost.** A response with two `thinking` blocks (interleaved thinking) or several `server_tool_use` / `web_search_tool_result` blocks keeps only the last one. The comment next to the assignment says this avoids making "a response look simpler than it was", but the code does exactly that.
- **Invoke and stream disagree.**
  - Eager: `additional_kwargs.thinking` is a full block array, including `signature`.
  - Streamed: it is a bare concatenated string, with the signature dropped.
- **Multi-turn replay breaks.** Anthropic rejects an echoed thinking block that has no signature.

**Suggested fix:** Match upstream `message_outputs.ts` / `_makeMessageChunkFromAnthropicEvent`, which keeps these as ordered content blocks.
- At minimum, accumulate same-type blocks into a list rather than assigning over the previous one.
- Stream `thinking` and `signature` deltas into the same block shape the eager path produces.
- Add a test with two thinking blocks, one eager and one streamed, asserting the two paths produce equal output.

## 3. Multi-block Anthropic content is flattened to one string, which contradicts its own docblocks and drops block order
**Severity:** MAJOR
**Evidence:**
- `MessageOutputs::joinTextBlocks()` has the docblock "Text blocks join with newlines, and stay blocks when there is more than one". It always returns `string` via `implode("\n\n", …)`.
- The class docblock says that preserving the interleaving of text and `tool_use` "is why this walks the blocks". `responseToMessage` splits the blocks into a string and a `tool_calls` list, which discards the order.

**Why it matters:**
- Upstream keeps `content` as the block array when there is more than one block, or any `tool_use`. The flattening therefore breaks fidelity.
- Per-block fields such as `citations` are lost.
- `"\n\n"` is invented text that the model never produced.
- The input side was deliberately fixed to preserve assistant block content across a tool call ("Anthropic assistant block content survives a tool call"). But a message this client produces has already lost its blocks, so that fix cannot take effect on a real round trip.

**Suggested fix:**
- Return `content` as the ordered list of blocks (text plus `tool_use`), exactly as upstream does, whenever there is more than one block or a non-text block.
- Keep the single-string shortcut only for a lone text block.
- Correct the docblock to match.
- Add a round-trip test: response → `AIMessage` → `MessageInputs::convert()` must reproduce the original block order.

## 4. `ChatOpenAI` rejects `topK` only in the constructor; bound and per-call `top_k` are still silently dropped
**Severity:** MINOR (it is a false claim in PORT_STATUS, so it borders on MAJOR)
**Evidence:**
- `rejectUnsupported()` is called only in `ChatOpenAI::__construct`.
- `bindTools()` copies every kwarg into `$next->kwargs`. It also copies `strict`, unlike `ChatAnthropic::bindTools`, which skips `strict`.
- `invocationParams()` never reads `topK` from either layer.
- PORT_STATUS says: "`ChatOpenAI` refuses `topK` … Refused under both spellings."

**Why it matters:**
- `bindTools($t, ['top_k' => 5])`, `->bind(['topK' => 5])` and a per-call `topK` are all accepted and then ignored. That is exactly the "appears configured and was not" failure the row claims is closed.
- A bound `strict` also lands in `kwargs`, so it is serialized into traces without having any wire effect of its own.

**Suggested fix:**
- Call `rejectUnsupported(self::canonicalise(...))` on the `$kwargs` in `bindTools()`.
- Call it on `$options` at the top of `invocationParams()`.
- Skip `strict` in the `bindTools` copy loop, as Anthropic does.
- Add tests covering all three layers and both spellings.

## 5. (Inference) Anthropic streamed usage summing may double-count tokens
**Severity:** MINOR (this is a guess and must be verified against real event captures)
**Evidence:** `ChatAnthropic::consume()` yields a usage chunk for both `message_start` (nested `message.usage`) and `message_delta` (top-level `usage`). `MessageMerge` then sums them, which PORT_STATUS documents as "Streaming token usage is summed by the chunk fold".
**Why it matters:**
- In real traffic, `message_start.usage` typically includes a small `output_tokens` (for example 1).
- The API documents `message_delta.usage.output_tokens` as cumulative, and newer API versions may also report `input_tokens` there.
- Summing would then over-report output tokens, and possibly double the input tokens.
- Cache fields (`cache_read_input_tokens`, `cache_creation_input_tokens`) are dropped entirely by `usageMetadata()`.

**Suggested fix:**
- Check upstream's `message_start` handling. I believe it zeroes or omits `output_tokens` there; confirm against `@langchain/anthropic` `message_outputs.ts`.
- Apply the same treatment here, so `message_start` contributes only `input_tokens` and `message_delta` contributes only the incremental output.
- Carry the cache token fields through.
- Pin the behaviour with a fixture recorded from a real stream.