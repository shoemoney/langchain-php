# Review 55 - meta-llama/llama-4-maverick
_asked 2026-09-30T22:43:13 - served by meta-llama/llama-4-maverick - 8s_

## 1. TextSplitter Length Function Should Handle Invalid UTF-8
**Severity:** MAJOR
**Evidence:** `TextLength::utf16CodeUnits()`
**Why it matters:** The current implementation silently degrades to `mb_strlen()` on invalid UTF-8, potentially changing chunk boundaries.
**Suggested fix:** Explicitly throw on invalid UTF-8 or document the behaviour.

## 2. RecursiveCharacterTextSplitter Should Accept Empty Separator List
**Severity:** MINOR
**Evidence:** `RecursiveCharacterTextSplitter::splitTextRecursive()`
**Why it matters:** The current implementation resolves to the floor separator (`''`), which is the expected behaviour upstream.
**Suggested fix:** Document the behaviour or add a note explaining the language difference.

## 3. TextSplitter Warns on Oversized Chunks Using trigger_error
**Severity:** MAJOR
**Evidence:** `TextSplitter::warnOversizedChunk()`
**Why it matters:** `trigger_error(E_USER_WARNING)` fails the test suite under `failOnWarning="true"`.
**Suggested fix:** Record the warning instead, as done in `BaseRunManager::recordHandlerError()`.

## 4. RunnableInterface::transform() Streams Input Differently Than Upstream
**Severity:** MINOR
**Evidence:** `RunnableInterface::transform()`
**Why it matters:** The port streams each input as it arrives, while upstream buffers the whole input generator.
**Suggested fix:** Document the divergence in `PORT_STATUS.md` and consider adding a note explaining the reasoning.

## 5. Missing Test for TextSplitter with Invalid UTF-8 Input
**Severity:** MINOR
**Evidence:** Inference
**Why it matters:** The current implementation may silently degrade or throw on invalid UTF-8.
**Suggested fix:** Add a test case for `TextSplitter` with invalid UTF-8 input to verify the behaviour.