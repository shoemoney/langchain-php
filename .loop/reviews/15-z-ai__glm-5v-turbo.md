# Review 15 - z-ai/glm-5v-turbo
_asked 2026-09-29T09:58:56 - served by z-ai/glm-5v-turbo - 353s_

## 1. Double error callback on stream failure in `BaseChatModel::stream()`
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php:285–310` (the `try`/`catch`/`finally` block inside `stream()`)
**Why it matters:** When a streamed call fails mid-stream (e.g., provider disconnect, malformed JSON delta), the `catch` block correctly calls `$runManager->handleLLMError($e)` with the real exception and rethrows. However, the `finally` block then runs **unconditionally** and—because `$ended` is still `false`—calls `handleLLMError` **a second time** with a fabricated `RuntimeException('Stream abandoned by the consumer before it completed.')`. This produces two error entries in the trace/run history: one accurate and one misleadingly claiming the consumer abandoned the stream. Any downstream telemetry or debugging that counts errors or inspects the last error message will be corrupted by the phantom “abandoned” record.
**Suggested fix:** Introduce a `$failed` flag set to `true` inside the `catch` block before the rethrow. In the `finally` block, replace the condition `if (!$ended && $runManager !== null)` with `if (!$ended && !$failed && $runManager !== null)` so that the “abandoned” sentinel is only emitted when the generator is actually abandoned (garbage-collected or broken out of) rather than when it exits via exception.

```php
$failed = false;
try {
    // ... yield loop ...
    $ended = true;
    // ...
} catch (\Throwable $e) {
    $failed = true;
    $runManager?->handleLLMError($e);
    throw $e;
} finally {
    if (!$ended && !$failed && $runManager !== null) {
        $runManager->handleLLMError(
            new \RuntimeException('Stream abandoned by the consumer before it completed.')
        );
    }
}
```

---

## 2. Snake_case constructor keys silently discarded from serialised `kwargs`
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php:116–129` (the `array_intersect_key` call that builds `$this->kwargs`) and `src/LangChain/LanguageModels/Chat/Anthropic/ChatAnthropic.php:95–104` (the analogous line)
**Why it matters:** Both constructors accept **both** camelCase *and* snake_case spellings for every parameter (e.g., `max_tokens` *and* `maxTokens`, `stop_sequences` *and* `stopSequences`) and correctly resolve them onto the instance property. However, the allow-list passed to `array_intersect_key` when recording `$this->kwargs` contains **only the camelCase** names:

```php
// ChatOpenAI – note: 'max_tokens', 'stop_sequences', 'top_p', etc. are MISSING
$this->kwargs = array_intersect_key($fields, array_flip([
    'model', 'temperature', 'topP', 'frequencyPenalty', 'presencePenalty',
    'stop', 'stopSequences', 'maxTokens', 'user', 'seed', 'responseFormat', 'tools',
    'toolChoice', 'parallelToolCalls', 'organization', 'streamUsage',
    'maxRetries', 'timeout',
]));
```

If a caller (or a serialised round-tripped checkpoint) passes `['max_tokens' => 50]`, the value **is applied** to `$this->maxTokens` and **is sent on the wire** correctly, but it is **never written into `$this->kwargs`** because the key `max_tokens` is not in the allow-list. Consequently:
* The serialised constructor record (used by `lcSerializable` / checkpoint savers) omits that parameter entirely.
* On deserialization, the model resurrects with the hard-coded default (`1024` for Anthropic, `null`/unset for OpenAI) instead of the caller’s `50`.
* `bindTools($t, ['max_tokens' => 50])` appears to work for the **first** call (the value lives in the cloned instance’s property), but if that instance is serialised and revived (the whole point of the Pregel engine’s checkpoint/restore cycle), the binding is lost.

This is the same class of bug as the already-fixed “resolved constructor default masked a later binding,” but affecting the **spelling** dimension rather than the **source** dimension.

**Suggested fix:** Either (a) add the missing snake_case keys to both allow-lists, or (b)—preferably, since the list will drift again—normalise the keys in `$fields` **before** the intersect, using the same `normaliseKeys()` map that `invocationParams()` already uses:

```php
$normalisedFields = $this->normaliseKeys($fields); // merges snake_case → camelCase
$this->kwargs = array_filter(
    array_intersect_key($normalisedFields, array_flip([...camelCase list...])),
    static fn (mixed $v): bool => $v !== null
);
```

(The same change applies to `ChatAnthropic::__construct`.)

---

## 3. Streaming clients catch only `HttpException`, leaving bare transport exceptions un-retried and unwrapped
**Severity:** MAJOR
**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php:247–260` (`postStream` catch clause) and `src/LangChain/LanguageModels/Chat/Anthropic/ChatAnthropic.php:193–206` (`streamResponseChunks` catch clause)
**Why it matters:** Both streaming methods wrap their drain loops in `try`/`catch` that specifically catch `OpenAIException`/`AnthropicException` and `HttpException`. Any other throwable—a `RuntimeException` from `SseParser` if its internal state is corrupted by malformed framing, a `TypeError` from an unexpected payload shape, or (more critically) a `GuzzleHttp\Exception\ConnectException` or `GuzzleHttp\Exception\RequestException` if `GuzzleHttpClient` ever fails to wrap the underlying Guzzle error in the SDK’s own `HttpException`—will **bypass the retry logic entirely** and propagate as a raw, untyped exception. The caller (which may be the Pregel engine) expects either a successful generator or a provider-specific exception (`OpenAIException`/`AnthropicException`); a bare `ConnectException` breaks that contract and also escapes the `maxRetries` backoff, meaning a transient DNS blip that should be retried up to 2 times instead fails immediately.
**Suggested fix:** Add a catch-all for `\Throwable` **after** the existing specific catches, wrap it in the appropriate provider exception (or rethrow as-is if it is already one), and apply the same `$delivered`-aware retry-or-raise logic:

```php
try {
    // ... drain loop ...
} catch (OpenAIException $e) {
    throw $e;               // already the right shape
} catch (\LangChain\Utils\Http\HttpException $e) {
    // ... existing retry logic ...
} catch (\Throwable $e) {
    // Unexpected failure from parser, transport, or type mismatch.
    // Treat as non-retryable (we don’t know if it’s safe) and wrap
    // so the caller sees a consistent exception type.
    if (!$delivered) {
        // Only wrap if we haven’t yielded partial data; otherwise
        // the exception is mid-stream and must propagate as-is
        // to avoid double-yielding after reconnect.
        throw OpenAIException::fromResponse(
            (string) $e->getMessage(),
            0,
            $this->url(),
            $e
        );
    }
    throw $e;
}
```

(Analogous change for `ChatAnthropic::streamResponseChunks`.)