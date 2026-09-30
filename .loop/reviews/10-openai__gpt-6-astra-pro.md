# Review 10 - openai/gpt-6-astra-pro
_asked 2026-09-29T09:36:30 - served by openai/gpt-6-astra-pro - 37s_

## 1. `Runnable::map()` discards the runnable being mapped
**Severity:** BLOCKER  
**Evidence:** `src/LangChain/Runnables/Runnable.php`, `map()` returns `new RunnableParallel([])` without referencing `$this`.

**Why it matters:** Mapping a runnable should apply that runnable to each input element. Instead, the returned object has no branches and no reference to the original runnable, so it cannot execute it. For example, mapping a doubling runnable over `[1, 2]` cannot produce `[2, 4]`. This breaks an existing upstream composition operation, rather than representing a missing integration.

**Suggested fix:** Implement `map()` using the port’s `RunnableEach`, bound to `$this`, matching `Runnable.map()` in upstream `langchain-core/src/runnables/base.ts`. Change the return type accordingly. Add a regression that maps a runnable over `[1, 2]`, asserts `[2, 4]`, and verifies that the bound runnable receives the invocation config.

## 2. Anthropic drops assistant content blocks when tool calls exist
**Severity:** BLOCKER  
**Evidence:** `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageInputs.php`, `convertMessage()` calls `self::textBlocks($message->content)` whenever an `AIMessage` has tool calls. `textBlocks()` returns `[]` for every array-valued content.

**Why it matters:** An assistant message containing typed content blocks and a tool call loses all its content blocks when sent back as conversation history. Text disappears; thinking blocks and their signatures can disappear too. The next request therefore contains a different conversation and may be rejected when required thinking context is missing. This is separate from the already-fixed **system-message** block preservation.

**Suggested fix:** Preserve array-valued assistant content in this branch. Follow upstream `@langchain/anthropic`’s `utils/message_inputs.ts` rules for combining existing blocks with `toolCalls`, including avoiding duplicate `tool_use` blocks. Add a request-body regression containing assistant text/thinking blocks plus a tool call, and assert that the resulting history preserves the blocks and includes each tool call exactly once.

## 3. Wire-spelled bindings still lose to explicit constructor values
**Severity:** MAJOR  
**Evidence:** Both provider clients’ `bindTools()` append raw kwargs to the constructor kwargs. Their `normaliseKeys()` copies a wire-spelled key only when the camelCase key is absent.

For example, in either client:
```php
$model = new ChatOpenAI(['maxTokens' => 100]);
$bound = $model->bindTools([], ['max_tokens' => 50]);
$bound->invocationParams(); // max_tokens remains 100
```

**Why it matters:** A later binding silently fails whenever an explicit constructor value uses the other spelling. The previous fix removed **resolved defaults** from kwargs, but caller-supplied constructor values still cause the same collision. Chained bindings using alternating spellings also retain stale values.

**Suggested fix:** Canonicalize each incoming layer **before merging**, removing alias keys after normalization. In `bindTools()`, normalize existing kwargs and incoming kwargs independently, then overwrite the existing canonical values with the incoming canonical values. Preserve the documented call-time precedence separately. Test constructor camelCase → bound wire spelling, the reverse direction, and successive bindings in both clients.

## 4. OpenAI SSE error payloads are silently treated as successful output
**Severity:** MAJOR  
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php`, `decode()` yields any decoded array. `streamResponseChunks()` then skips payloads without `choices` unless they contain usage. There is no check for an `error` envelope.

**Why it matters:** An HTTP-200 SSE response containing:
```json
{"error":{"message":"upstream failed","type":"server_error"}}
```
is silently discarded. If tokens preceded it, callers receive a truncated answer followed by normal completion; otherwise, public `stream()` can finish empty and emit a successful end callback. HTTP-status handling does not cover errors delivered inside an established stream.

**Suggested fix:** Detect provider error envelopes before processing choices and throw an `OpenAIException` retaining the error message and payload. Let the existing base-model error callback path handle it; do not retry after delivered bytes. Add scripted streams containing an error as the first event and an error after a text delta. Assert exception propagation and an error callback rather than a successful end callback.

## 5. Anthropic tool-result folding mutates caller-owned history
**Severity:** MAJOR  
**Evidence:** `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageInputs.php`, `foldToolMessages()` appends non-tool messages directly to `$out`. If a preceding caller-supplied `HumanMessage` starts with a `tool_result` block, the method executes:
```php
$previous->content[] = $block;
```
PHP objects retain identity here; `$previous` is the caller’s object.

**Why it matters:** Converting history changes the input history itself. Given a human message containing one tool-result block followed by a `ToolMessage`, the first conversion appends the second result to the original human message. Converting the same history again appends it again, producing duplicate results. Reusing a transcript across calls can therefore change the request without any caller update.

**Suggested fix:** Clone a caller-owned preceding message before extending its content, or construct fresh output messages throughout the folding operation. Add a regression that converts this same message list twice, asserts identical wire payloads, and verifies that the original human message still contains only its original block.