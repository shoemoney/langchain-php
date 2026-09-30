# Review 31 - z-ai/glm-5.3-flashx
_asked 2026-09-29T11:21:32 - served by z-ai/glm-5.3-flashx - 26s_

## 1. Streaming path has no empty-response guard — a stream that delivers nothing succeeds silently
**Severity:** MAJOR
**Evidence:** `BaseChatModel::stream()` — `if ($aggregated === null) { $runManager?->handleLLMEnd(new LLMResult([[]], [])); return; }` versus `aggregateStream()`, which throws `'Received empty response from chat model call.'`
**Why it matters:** The eager path refuses a provider that returned nothing; the streaming path hands the caller zero events with no error — the "silent empty looks like success" failure the eager guard exists to prevent.
**Suggested fix:** After the loop, if `$aggregated === null` and the stream was not abandoned, throw the same empty-response `RuntimeException` (keep the `finally` branch for the abandoned case).

## 2. `ChatAnthropic::$defaultHeaders` docblock type contradicts its use, and it can override the "pinned" version
**Severity:** MINOR
**Evidence:** `ChatAnthropic` — property declared `@var list<array<string, mixed>>`, but `headers()` uses `$this->defaultHeaders + ['x-api-key' => …, 'anthropic-version' => …]`, i.e. a `map<string,string>`.
**Why it matters:** A caller following the docblock passes a list of block arrays and `+` produces garbage headers; separately, a `defaultHeaders['anthropic-version']` entry silently overrides the version the class comment says is "pinned, not configurable-by-default".
**Suggested fix:** Fix the docblock to `array<string, string>` and either drop `anthropic-version`/`content-type` from overridable keys or document that they are overridable.

## 3. `ChatAnthropic::bindTools` writes a null `strict` into `kwargs`
**Severity:** MINOR
**Evidence:** `ChatAnthropic::bindTools()` — `$next->kwargs['strict'] = $strict;` runs even when `$strict === null`; the constructor's `kwargs` is `array_filter`ed for nulls but this path is not, and `strict` is not in the constructor whitelist.
**Why it matters:** Every trace serialised from a bound model that never asked about strictness carries `strict: null` — exactly the "resolved default recorded as caller-supplied" noise the constructor comment says it avoids.
**Suggested fix:** Only write the key when `$strict !== null`.

## 4. Stream-establishment failure loses the original exception
**Severity:** MINOR
**Evidence:** `ChatOpenAI::postStream()` and `ChatAnthropic::streamResponseChunks()` — final `throw …Exception::fromResponse($e->body, $e->status, $this->url());` with no `previous: $e`, while the equivalent non-stream paths in `post()` pass `previous: $e`.
**Why it matters:** A status-0 network failure on the streaming path arrives with no cause attached — the exact diagnostic the non-stream path's comment says is wanted ("status 0 alone does not say the connection was refused").
**Suggested fix:** Add `previous: $e` to the final throw in both streaming paths.

## 5. `isMetadataOnly()` ignores `additional_kwargs`, suppressing refusal-only chunks
**Severity:** MINOR
**Evidence:** `BaseChatModel::isMetadataOnly()` checks only `content`, `toolCalls`, `toolCallChunks`; `Completions::deltaToChunk()` can produce a chunk whose only payload is `additional_kwargs['refusal']`.
**Why it matters:** A streamed call where the model's sole output is a refusal yields zero chunks to the caller (folded but never surfaced) — the caller sees an empty answer with no signal, the same shape as finding 1.
**Suggested fix:** Return false when `$message->additional_kwargs !== []` (or specifically when `refusal`/`function_call` are set).

That is five; all are read from the included source, none from the already-fixed tables.