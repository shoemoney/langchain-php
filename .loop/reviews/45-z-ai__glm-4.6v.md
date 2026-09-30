# Review 45 - z-ai/glm-4.6v
_asked 2026-09-29T12:30:38 - served by z-ai/glm-4.6v - 49s_

## 1. Stream error handling in GuzzleHttpClient
**Severity:** MAJOR
**Evidence:** src/LangChain/Utils/Http/GuzzleHttpClient.php:57-82
**Why it matters:** The `postStream` method's `try` block catches errors during the request but not during the stream reading loop. If a stream fails after the request is made (e.g., network disconnect), the error is thrown as a generic `HttpException` without the provider's message, making debugging harder.
**Suggested fix:** Move the `try` block to enclose the entire generator body, including the stream reading loop. Catch `HttpException` separately to preserve the provider's error message.

## 2. Abandoned stream trace reporting in BaseChatModel
**Severity:** MAJOR
**Evidence:** src/LangChain/LanguageModels/BaseChatModel.php:325-344
**Why it matters:** The `finally` block marks abandoned streams as successful completions, leading to inconsistent trace reporting. Downstream tools may interpret this as a successful run when the consumer stopped early.
**Suggested fix:** In the `finally` block, check if `$ended` is false and report the run as "abandoned" instead of "completed" using `$runManager->handleLLMEnd` with a custom status.

## 3. Malformed SSE payload handling in SseParser
**Severity:** MINOR
**Evidence:** src/LangChain/Utils/Http/SseParser.php:74-85
**Why it matters:** The `payloadOf` method uses `preg_split` without a limit, which can split a single `data:` line into multiple parts if it contains newlines. This may corrupt JSON payloads that span multiple lines.
**Suggested fix:** Use `preg_split('/\r\n|\n|\r/', $block, -1, PREG_SPLIT_NO_EMPTY)` to preserve line integrity and ensure only complete `data:` lines are processed.

## 4. Strict mode unhandled in withStructuredOutput
**Severity:** MINOR
**Evidence:** src/LangChain/LanguageModels/BaseChatModel.php:197-199
**Why it matters:** The method throws an exception for `strict` mode, but upstream TypeScript implementations handle it by validating the schema more strictly. This divergence may cause unexpected behavior for users expecting strict validation.
**Suggested fix:** Document that strict mode is not supported in the base implementation, or implement basic schema validation (e.g., check for required fields) to align closer with upstream behavior.

## 5. Batch method options parameter inconsistency
**Severity:** MINOR
**Evidence:** src/LangChain/Runnables/RunnableInterface.php:47-52
**Why it matters:** The `batch` method accepts `$options` but the docblock states it's ignored. This creates confusion for developers expecting the parameter to be used.
**Suggested fix:** Remove the `$options` parameter from the method signature or implement basic handling (e.g., pass it to `invoke` calls) to eliminate the inconsistency.