# Review 32 - anthropic/claude-fable-5.1
_asked 2026-09-29T11:27:26 - served by anthropic/claude-fable-5.1 - 109s_

## 1. `withStructuredOutput` mis-wraps a schema that is already a function definition
**Severity:** MAJOR
**Evidence:** `BaseChatModel.php` `withStructuredOutput()`: `$functionName = $config['name'] ?? $schema['name'] ?? 'extract'; unset($schema['name']); … 'parameters' => $schema`.
**Why it matters:** Upstream (`chat_models.ts`) checks `schema.name && schema.parameters` and passes such a schema through as the whole function definition; otherwise it takes the name from `schema.title`. Here `{name, description, parameters}` is sent as `parameters: {description, parameters: {...}}` — the model is never told the argument shape (same class as the fixed Anthropic `function.parameters` bug), and a `title`-named schema gets `extract`.
**Suggested fix:** Branch as upstream: if `is_string($schema['name'] ?? null) && is_array($schema['parameters'] ?? null)`, use `$schema` as the function definition; else `$functionName = $config['name'] ?? $schema['title'] ?? 'extract'` and wrap without `unset`. Pin with a test asserting the bound tool's `function.parameters` equals the caller's schema.

## 2. Every OpenAI delta carries `model_name`/`model_provider`, so the fold concatenates them
**Severity:** MAJOR
**Evidence:** `Completions::deltaToChunk()` sets `'response_metadata' => self::responseMetadata($rawChunk)` on each delta; `responseMetadata()` always emits `model_provider`/`model_name`. `BaseChatModel::stream()` folds with `concat()`. (Inference: `MessageMerge::mergeDicts` concatenates strings, as upstream `_mergeDicts` does.)
**Why it matters:** The aggregated message handed to `handleLLMEnd` — and `aggregateStream()`'s `ChatResult` on `invoke()` with a streaming handler — reports `model_name: "gpt-4o-minigpt-4o-mini…"` × N chunks. Upstream stamps `model_name`/`system_fingerprint` only on the finish-reason chunk.
**Suggested fix:** In `deltaToChunk`, emit only `usage`/`usage_metadata` per delta; attach `model_name`, `model_provider`, `system_fingerprint` only when `finish_reason` is set (or on the usage-only chunk). Add a test that streams 3 deltas and asserts `response_metadata['model_name']` equals the raw model string.

## 3. Per-call `tools` bypass conversion
**Severity:** MAJOR
**Evidence:** `ChatOpenAI::invocationParams()`: `'tools' => $this->pick($options, 'tools') ?? $bound['tools']`; only `bindTools()` runs `Tools::convertAll`. Same in `ChatAnthropic::invocationParams()`.
**Why it matters:** Upstream `invocationParams` maps `options.tools` through `formatToOpenAITool` when they are StructuredTools. Here `invoke($msgs, config(options: ['tools' => [$structuredTool]]))` — the shape `RunnableBinding`/`bind()` now produces — `Js::encode`s a `StructuredTool` object into the request body (empty `{}` or a throw), so the tool is never offered.
**Suggested fix:** Route both the per-call and bound `tools` through `Tools::convertAll()` (idempotent for already-wire arrays) inside `invocationParams()`; Anthropic likewise via `MessageInputs::convertTool`. Test: pass a `StructuredTool` in `config->options['tools']` and assert the request body's `tools[0].function.parameters`.

## 4. `handleLLMNewToken` receives a different `chunk` type from each provider
**Severity:** MINOR
**Evidence:** `ChatOpenAI::streamResponseChunks()`: `handleLLMNewToken($text, ['chunk' => $chunk])` where `$chunk` is `AIMessageChunk`; `ChatAnthropic::consume()`: `['chunk' => $generation]` (a `ChatGenerationChunk`). Upstream passes `{ chunk: generationChunk }`.
**Why it matters:** A single tracer/handler reading `$fields['chunk']->message` works for Anthropic and throws (or reads a nonexistent property) for OpenAI; a port that pins wire fidelity should not vary the callback contract per provider.
**Suggested fix:** In ChatOpenAI pass the `ChatGenerationChunk` (build it into a local before `yield`) and add a handler test asserting `$chunk instanceof ChatGenerationChunk` for both clients.

## 5. Streaming failures discard the transport cause the eager path deliberately keeps
**Severity:** MINOR
**Evidence:** `ChatOpenAI::postStream()` and `ChatAnthropic::streamResponseChunks()` catch blocks: `fromResponse($e->body, $e->status, $this->url())` — no `previous:`; `post()` in both classes passes `previous: $e` with a comment saying "a network failure is exactly when the cause is wanted".
**Why it matters:** A connection refused while opening a stream surfaces as "status 0" with no chain; the same failure on `invoke()` is fully diagnosable. Half the client contradicts its own stated rationale.
**Suggested fix:** Add `previous: $e` to both streaming `fromResponse` calls; extend the existing stream-retry tests to assert `getPrevious()` is the `HttpException`.