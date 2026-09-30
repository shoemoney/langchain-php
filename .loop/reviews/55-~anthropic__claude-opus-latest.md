# Review 55 - ~anthropic/claude-opus-latest
_asked 2026-09-29T21:16:02 - served by anthropic/claude-opus-5.5 - 36s_

## 1. Stream stall silently truncates the answer as if it had succeeded
**Severity:** MAJOR
**Evidence:** `GuzzleHttpClient::postStream()`. When `(microtime(true) - $silentSince) >= $this->streamSilenceLimit`, the loop runs `break;`. The generator then returns normally, with `eof()` still false.
**Why it matters:** A connection that dies mid-answer looks like a clean end-of-stream. `BaseChatModel::stream()` then calls `handleLLMEnd` with a partial message, and the caller gets a truncated completion with no error. This is the same class of bug the ledger already fixed for SSE `{"error":…}` events.
**Suggested fix:** Replace the `break` with a thrown `HttpException("Stream from $url stalled for {$limit}s without EOF", 0, '')`. Add a test that uses a small `$streamSilenceLimit` and asserts that exception.

## 2. A failing prompt in a batch leaves every later prompt's run open forever
**Severity:** MAJOR
**Evidence:** `BaseChatModel::generateMessages()`. `handleChatModelStart` starts one run per prompt. The per-prompt `catch` calls `$thisRunManager?->handleLLMError($e); throw $e;`, so the runs for prompts after the failing index never get an end or error event.
**Why it matters:** These are the hanging spans the code's own comments say it prevents. The code comment also cites upstream's `allSettled`, which settles every prompt, but this loop does not.
**Suggested fix:** Collect the error, keep settling the remaining prompts, and rethrow after the loop. The minimum fix is: in the catch, call `handleLLMError($e)` on `$runManagers[$j]` for every `$j > $index` before rethrowing. Test this with a 3-prompt batch where prompt 1 fails, asserting 3 terminal events.

## 3. `isMetadataOnly()` tool-call guard can never fire on a streamed chunk
**Severity:** MINOR (MAJOR if any provider emits parsed tool calls without chunks)
**Evidence:** `BaseChatModel::isMetadataOnly()` checks `$message instanceof AIMessage && $message->toolCalls !== []`. HANDOFF records that `AIMessageChunk` is a *sibling* of `AIMessage`, not a subclass, and has no `toolCalls` property. The function then reads `$message->toolCallChunks` on any chunk message type.
**Why it matters:** The first branch is dead for every stream. A non-`AIMessageChunk` chunk, such as a `ChatMessageChunk`, would hit an undefined-property read. That part is inference; I have not verified which chunk classes declare the property.
**Suggested fix:** Replace both checks with `$message instanceof AIMessageChunk && $message->toolCallChunks !== []`. Treat any other chunk class as non-metadata-only unless its content is empty.

## 4. `RunnableInterface` docblocks contradict the code
**Severity:** MINOR
**Evidence:** `RunnableInterface.php`:
- `batch()` carries two stacked docblocks. The first one is stale.
- The class docblock says `batch` is "a concurrency-capped `array_map`", but the method docblock and PORT_STATUS say `maxConcurrency` is ignored.
- `transform()` is described as "Invoke on the first input only… the JS `streamEvents` entry point". Upstream `transform` consumes the whole input iterable and is unrelated to `streamEvents`.

**Why it matters:** The class docblock claims a concurrency cap that does not exist, and implementers of `transform()` will follow the wrong contract.
**Suggested fix:** Delete the first `batch` docblock, remove "concurrency-capped" from the class docblock, and rewrite the `transform` doc to match upstream `Runnable.transform`.

I found only four evidence-backed issues in this slice. I am not padding the list with a fifth.