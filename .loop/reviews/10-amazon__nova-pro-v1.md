# Review 10 - amazon/nova-pro-v1
_asked 2026-09-30T09:12:08 - served by amazon/nova-pro-v1 - 11s_

## 1. Inconsistent handling of empty streams in `BaseChatModel::stream()`
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php`
**Why it matters:** An empty stream is treated as an error on the eager path but not consistently on the streaming path, leading to potential discrepancies in error handling.
**Suggested fix:** Ensure that an empty stream is consistently treated as an error on both the streaming and eager paths by throwing an exception when no events are received.

## 2. Misleading docblock in `RunnableInterface::batch()`
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php`
**Why it matters:** The docblock suggests that `batch()` has concurrency control, which it does not, leading to confusion about its behavior.
**Suggested fix:** Update the docblock to accurately reflect that `batch()` maps inputs to results sequentially and does not cap concurrency.

## 3. Inconsistent use of `runName` in `StructuredOutput::assembleStructuredOutputPipeline()`
**Severity:** MINOR
**Evidence:** `src/LangChain/LanguageModels/StructuredOutput.php`
**Why it matters:** The `runName` is not consistently propagated to the traced run, leading to runs appearing unnamed in the tracer.
**Suggested fix:** Ensure that the `runName` is correctly set in the config and propagated to the traced run.

## 4. Inaccurate SSE payload handling in `SseParser::payloadOf()`
**Severity:** MINOR
**Evidence:** `src/LangChain/Utils/Http/SseParser.php`
**Why it matters:** The SSE parser incorrectly removes all leading spaces from `data:` payloads, which can alter the payload content.
**Suggested fix:** Modify `SseParser::payloadOf()` to remove at most one leading space after the `data:` prefix, as specified by the SSE standard.

## 5. Potential for unhandled exceptions in `GuzzleHttpClient::postStream()`
**Severity:** MAJOR
**Evidence:** `src/LangChain/Utils/Http/GuzzleHttpClient.php`
**Why it matters:** The method does not handle all possible exceptions that may occur during the streaming request, potentially leading to uncaught exceptions.
**Suggested fix:** Add a catch-all exception handler to log or rethrow any unexpected exceptions during the streaming request.