# Review 38 - perplexity/sonar
_asked 2026-09-30T16:02:21 - served by perplexity/sonar - 11s_

## 1. `bindTools()` still accepts arbitrary provider-shaped arrays without validation
**Severity:** MAJOR  
**Evidence:** `BaseChatModel::bindTools(array $tools, array $kwargs = [])` accepts “provider-shaped tool arrays” directly.  
**Why it matters:** A malformed tool envelope can be silently bound and only fail later at request time, or worse, be sent with missing schema/name fields.  
**Suggested fix:** Validate the array shape in the base method before delegating; require the minimal tool fields and throw early on unknown/malformed entries.

## 2. `withStructuredOutput()` leaves a documented `runName` propagation gap
**Severity:** MAJOR  
**Evidence:** `StructuredOutput::assembleStructuredOutputPipeline()` binds the name, but the comment says “even bound this way the name does not reach `Run::name()`”.  
**Why it matters:** Structured-output runs can be traced under the wrong name, making debugging and observability misleading in production.  
**Suggested fix:** Thread the name through the actual run config path used by the model/sequence, and add an end-to-end assertion that `Run::name()` reflects the bound pipeline name.

## 3. Streaming drops metadata-only chunks from the caller’s stream
**Severity:** MINOR  
**Evidence:** `BaseChatModel::isMetadataOnly()` causes `stream()` to `continue` after folding metadata-only chunks.  
**Why it matters:** Consumers cannot observe provider-side usage/refusal/reasoning events as stream items, so UIs or callbacks that expect a complete event sequence will miss them.  
**Suggested fix:** Preserve metadata-only chunks on a separate stream channel or expose a second event path instead of suppressing them entirely.

## 4. `GuzzleHttpClient::postStream()` retries nothing after partial delivery, but the failure mode is only documented, not enforced
**Severity:** MINOR  
**Evidence:** `postStream()` throws once any bytes are yielded; the comment says reconnecting would re-emit tokens already delivered.  
**Why it matters:** A future change could accidentally retry after partial reads and duplicate output, which is a hard-to-notice correctness bug.  
**Suggested fix:** Encode this contract with a regression test that asserts no retry occurs after the first yielded chunk, even on later stream errors.

## 5. `combineLLMOutput()` silently preserves non-numeric first-write fields
**Severity:** MINOR  
**Evidence:** `sumOutputs()` uses `if (!array_key_exists($key, $base)) { $base[$key] = $value; }` for non-numeric leaves.  
**Why it matters:** If a provider emits conflicting string metadata across prompts, the batch summary keeps the first value and hides disagreement.  
**Suggested fix:** Explicitly document/encode the intended precedence for non-numeric fields, or surface a conflict when batch entries disagree on a shared key.