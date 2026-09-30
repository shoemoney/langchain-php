# Review 49 - thinkingmachines/inkling-small
_asked 2026-09-29T12:37:12 - served by thinkingmachines/inkling-small - 17s_

## 1. RunnableLambda ignores invocation config
**Severity:** MAJOR
**Evidence:** PORT_STATUS.md "RunnableLambda does not pass the config to its callable"; `src/LangChain/Runnables/RunnableLambda.php` (inferred from packet).
**Why it matters:** A lambda callable cannot observe `config->options` (temperature, max_tokens, etc.), so any pipeline using `RunnableLambda` silently drops call-time overrides.
**Suggested fix:** Pass `$config` as the second argument to the callable, matching upstream `(input, config?, ...)`.

## 2. AIMessageChunk parseToolCalls assumes args is string
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/AIMessageChunk.php` `trim($rawArgs)` without `is_string()` guard.
**Why it matters:** A malformed chunk with `args` already decoded to array throws `TypeError` instead of becoming an `invalidToolCall`; stream reconstruction aborts.
**Suggested fix:** Add `if (!is_string($rawArgs)) { $invalid[] = ...; continue; }` before `trim()`.

## 3. MessageMerge mergeDicts throws on type mismatch without distinguishing cause
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/MessageMerge.php` `throw new \InvalidArgumentException("field[{$key}] already exists...")` and final `throw new \InvalidArgumentException('Can not merge objects of different types.')`.
**Why it matters:** A caller debugging a stream merge cannot tell whether the chunk has the wrong field type or a genuinely unsupported type; both raise the same class with different messages.
**Suggested fix:** Use distinct exception subclasses (`MergeTypeMismatchException` vs `MergeUnsupportedTypeException`) so tests and logs distinguish them.

## 4. BaseMessage looksLikeFieldMap misclassifies block lists containing 'content'
**Severity:** MINOR
**Evidence:** `src/LangChain/Messages/BaseMessage.php` `looksLikeFieldMap()` checks `array_key_exists('content', $value)`.
**Why it matters:** A content block list that accidentally includes a block with key `'content'` (e.g., a custom block) is interpreted as a field map, corrupting message construction.
**Suggested fix:** Require at least two known keys (`content` + `id`/`name`/`type`) or check that values match expected types before treating as field map.

## 5. RunnableInterface batch documents $options as ignored but does not enforce
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php` docblock: "`$options` is upstream's `batchOptions`. Unused by default."
**Why it matters:** Subclasses can silently ignore `maxConcurrency`/`returnExceptions` without a contract violation; callers expect batch behavior that never arrives.
**Suggested fix:** Add `@param array<string,mixed>|null $options` with explicit `@throws \BadMethodCallException` if a subclass does not implement batch options, or declare an abstract `batchOptions()` contract.