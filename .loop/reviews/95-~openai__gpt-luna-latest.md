# Review 95 - ~openai/gpt-luna-latest
_asked 2026-09-30T04:40:46 - served by openai/gpt-6-luna - 31s_

## 1. Structured-output run names are lost in sequences
**Severity:** MAJOR
**Evidence:** `RunnableConfig::forChild()` in `RunnableSequence::stepConfig()` overwrites `runName`; `PORT_STATUS.md` records this as “Not fixed yet.”
**Why it matters:** A named structured-output pipeline is traced under a fallback component name instead of the requested name.
**Suggested fix:** Put the child-step identifier in child callback config without replacing the parent `runName`, then verify the name in an end-to-end trace.

## 2. An empty stream is reported as successful
**Severity:** MAJOR
**Evidence:** In `BaseChatModel::stream()`, `$aggregated === null` calls `handleLLMEnd(new LLMResult([[]], []))` and returns; `aggregateStream()` instead throws for an empty response.
**Why it matters:** A provider that yields no chunks looks like a successful empty answer, unlike the eager path.
**Suggested fix:** Throw on an empty stream before reporting completion; let the existing catch report the error.

## 3. SSE parsing strips meaningful leading spaces
**Severity:** MINOR
**Evidence:** `SseParser::payloadOf()` uses `ltrim(substr($line, 5), ' ')`.
**Why it matters:** SSE removes at most one space after `data:`; this strips every leading space and changes payloads such as `data:  text`.
**Suggested fix:** Remove one leading ASCII space from the field value, if present, rather than trimming all leading spaces.

## 4. The batch method has two adjacent docblocks
**Severity:** MINOR
**Evidence:** `RunnableInterface.php` has a `/** ... */` block beginning “Run this component over many inputs” immediately followed by another beginning “Run several inputs.”
**Why it matters:** The first block is unattached to the method and its parameter documentation is discarded by tools and readers.
**Suggested fix:** Merge the useful documentation into one docblock directly above `batch()`.

## 5. Empty streams can finish without settling tracing consistently
**Severity:** MINOR
**Evidence:** In `BaseChatModel::stream()`, the empty-stream branch calls `handleLLMEnd()` and returns before constructing a generation; `aggregateStream()` treats the same condition as an error.
**Why it matters:** Consumers and tracers receive different outcomes for the same no-generation response depending on whether they stream or invoke.
**Suggested fix:** Use one shared empty-response policy for both paths and pin it with a test covering the returned stream and callback events.