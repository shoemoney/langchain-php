# Triple-A cycle 3 - deepseek/deepseek-v4.1-flash
_asked 2026-10-01T05:20:49 - 60s - max_tokens 24,000_

## 1. `withStructuredOutput(['name' => …])` never reaches the run's own name

**Severity:** MAJOR
**Where:** `src/LangChain/LanguageModels/BaseChatModel.php:303` (and the parallel read at `:408`)
**Input → Output:** `$model->withStructuredOutput($schema, ['name' => 'pull_person'])->invoke($x)` — the port binds the name through the CONFIG slot correctly now, but the config the model actually receives on the way to `Run::name()` is the merged binding config, and `name()` still falls back to the serialized component id (`ChatOpenAI`). The pipeline reports itself as `ChatOpenAI`.
**Should be:** `Run::name()` returns `pull_person`, which is the entire reason `withStructuredOutput` accepts that option (upstream `chat_models/structured_output.ts:114`).
**Why it outs the port:** every structured-output pipeline in a consumer's trace dashboard is labelled with the wrong run name; nothing throws, and `name()` falls back to something plausible, so the loss is invisible until the consumer compares two runs.

## 2. `RunnableLambda`'s arity heuristic re-purposes a *defaulted* second parameter

**Severity:** MINOR
**Where:** `src/LangChain/Runnables/RunnableLambda.php` (the reflection gate in `invoke()`)
**Input → Output:** `(new RunnableLambda(fn(array $xs, string $sep = ' ') => implode($sep, $xs)))->invoke([1, 2, 3])`. PHP reflection reports `getNumberOfParameters() === 2`, so the port passes the `RunnableConfig` object as `$sep`; `implode(RunnableConfig, …)` throws a `TypeError` from a lambda that is legal PHP and legal user intent.
**Should be:** only a lambda whose **second required** parameter is the config slot receives it — a defaulted parameter is the caller's own data, not the port's channel. Upstream's `(input, config?)` typing makes the two distinguishable at the TS level and does not exist here.
**Why it outs the port:** a lambda written with a defaulted second parameter, tested in isolation, works; the moment it is wrapped in `RunnableLambda` it detonates, and the crash names a `TypeError` inside the user's own function with no mention of the port.

## 3. `batch()` accepts `returnExceptions` and drops it on the floor

**Severity:** MINOR
**Where:** `src/LangChain/Runnables/RunnableInterface.php` (`batch()` docblock/body)
**Input → Output:** `$runnable->batch([$ok, $fail], ['returnExceptions' => true])`, where the second input throws. The option is accepted (no error, no refusal), stored nowhere, and the batch **throws** on the failing index.
**Should be:** either `[resultOk, \Throwable]`, matching upstream `batchOptions`, or an explicit `InvalidArgumentException` at call time. Silently accepting an option and doing nothing is the worst outcome: the caller's control flow is written for `returnExceptions: true` and never learns it is not honoured.
**Why it outs the port:** a bulk-evaluation loop written to inspect per-input failures instead takes down the whole batch on the first bad input, with the same stack trace it would have had if the option were never passed — nothing in the exception points back at the ignored option.

## 4. `mergeContent()`'s null normalisation collapses "no text" into "empty text"

**Severity:** MINOR
**Where:** `src/LangChain/Messages/MessageMerge.php` (`mergeContent()`, `ContentBlock::text()` cast)
**Input → Output:** `mergeContent(['hello'], null)` (or `mergeContent(null, null)`) returns a content block whose text is `''`. Upstream reaches its `else` branch and returns a block whose text is `null` (`contentBlocksFromNonStringFirst(null)` yields `[]`) — a distinct value from `''`.
**Should be:** either preserve the null (`ContentBlock` would need a nullable text field) or refuse the input. Silently converting `null` to `''` is a wrong value, and it survives a round-trip to the wire as an empty-string text block where upstream sends nothing.
**Why it outs the port:** a consumer comparing an assistant turn's content before and after merging cannot tell "the model sent nothing" from "the model sent an empty string", and any downstream reducer keying on that distinction is wrong in a way no exception reports.