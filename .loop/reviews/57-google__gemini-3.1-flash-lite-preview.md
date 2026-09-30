# Review 57 - google/gemini-3.1-flash-lite-preview
_asked 2026-09-29T21:40:47 - served by google/gemini-3.1-flash-lite-preview - 55s_

## 1. Fatal stream merge on type mismatch
**Severity:** MAJOR
**Evidence:** `src/LangChain/Messages/MessageMerge.php:143`
**Why it matters:** `mergeDicts` throws an `InvalidArgumentException` if a chunk's field type changes (e.g., `additional_kwargs` metadata changing type). Streaming LLM responses are notoriously messy; this crashes the entire stream instead of logging a warning or skipping the field, causing data loss for the entire request.
**Suggested fix:** Change the `throw` to a `trigger_error` (or `Psr\Log\LoggerInterface` call) and `continue` the loop, allowing the merge to proceed with the existing value.

## 2. Brittle tool-call chunk parsing
**Severity:** MAJOR
**Evidence:** `src/LangChain/Messages/AIMessageChunk.php:108`
**Why it matters:** `parseToolCalls` rejects any `args` that are not a string. If a user manually constructs an `AIMessageChunk` with an array in `tool_call_chunks` (valid JSON-serializable data), it is rejected as invalid. The code assumes the source is always raw JSON, which is an implementation detail of the provider, not a contract for the chunk itself.
**Suggested fix:** Check `is_array($rawArgs)` and use it directly if true; only attempt `json_decode` if it is a string.

## 3. Incomplete `MessageUtils` coercion
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/MessageUtils.php:26`
**Why it matters:** `coerceMessageLikeToMessage` accepts `mixed $messageLike` but only handles `string`, `BaseMessage`, and `array`. If an object (e.g., a DTO or `stdClass`) is passed, it falls through to `constructFromParams` which expects an array, leading to a type error.
**Suggested fix:** Add an `is_object($messageLike)` check that converts the object to an array (e.g., via `get_object_vars` or `json_decode(json_encode($messageLike), true)`) before passing it to `constructFromParams`.

## 4. `MessageMerge` ignores empty `model_name`
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/MessageMerge.php:133`
**Why it matters:** `REPLACE_KEYS` includes `model_name`. The check `if ($value !== '' && $value !== null)` prevents updating `model_name` if the incoming chunk has an empty string. While this prevents overwriting with empty, it prevents clearing a value if the provider explicitly sends an empty string to indicate a reset.
**Suggested fix:** Remove the `&& $value !== ''` check to allow `model_name` to be updated to an empty string if the provider explicitly sends it.

## 5. `BaseMessage` content block normalization
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/BaseMessage.php:57`
**Why it matters:** `array_values($f['content'])` is used to normalize content blocks. This strips any explicit keys from the content array. While content blocks are typically list-indexed, if a user provides a map-like structure for content, the keys are silently discarded, which might be unexpected behavior for a "faithful" port.
**Suggested fix:** If the input is a list of blocks, ensure it is treated as a list, but consider if `array_values` is strictly necessary or if it masks potential data structure issues. (Inference: This is likely safe, but worth verifying against the upstream JS behavior which doesn't have PHP's array-key ambiguity).