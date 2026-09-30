# Review 64 - bytedance-seed/seed-2.0-lite
_asked 2026-09-29T23:14:01 - served by bytedance-seed/seed-2.0-lite - 33s_

## 1. Oversized chunk warning fails CI test runs
**Severity:** MAJOR
**Evidence:** `TextSplitter::warnOversizedChunk` uses `trigger_error(E_USER_WARNING)`, phpunit.xml has `failOnWarning="true"`
**Why it matters:** Any input that produces a chunk larger than the configured `chunkSize` will immediately fail the test suite, even when that is expected behaviour for edge case inputs.
**Suggested fix:** Replace the warning with a logged debug message via the PSR-3 logger interface instead of triggering a PHP user warning.

## 2. Chunk size calculation double counts separators when keepSeparator=true
**Severity:** BLOCKER
**Evidence:** `TextSplitter::mergeSplits` condition `$total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize`
**Why it matters:** When `keepSeparator=true`, separators are already attached to the preceding split, so adding separator length per split overestimates total chunk size, producing smaller chunks than upstream for all splitter instances with `keepSeparator=true` (including the default `RecursiveCharacterTextSplitter`).
**Suggested fix:** Adjust the separator length calculation to only add `count($currentDoc) - 1` separators, matching the actual number of separators inserted during `joinDocs`.

## 3. HTML/XML separator checks are case-sensitive
**Severity:** MINOR
**Evidence:** `RecursiveCharacterTextSplitter::splitTextRecursive` uses `str_contains($text, $s)` for HTML tag separators
**Why it matters:** Real-world HTML often uses uppercase tag names (e.g. `<DIV>`, `<P>`) and the current separator check will fail to match them, falling back to weaker word/character separators unnecessarily.
**Suggested fix:** Use a case-insensitive match only for the HTML language separator set, matching upstream's tag matching behaviour.

## 4. Missing chunk index fallback produces invalid line metadata
**Severity:** MAJOR
**Evidence:** `TextSplitter::createDocuments` calls `self::indexOf` which returns `-1` when the chunk is not found in the source text
**Why it matters:** If a chunk cannot be located in the source text (e.g. duplicate chunk content), the line counter calculation uses an invalid negative offset, producing incorrect `loc.lines` metadata for all subsequent chunks.
**Suggested fix:** Add an explicit guard that falls back to `$indexPrevChunk + 1` when `indexOf` returns `-1`, matching upstream's fallback behaviour.

## 5. No validation for empty separator list
**Severity:** MINOR
**Evidence:** `RecursiveCharacterTextSplitter::__construct` accepts nullable `$separators` with no validation
**Why it matters:** Passing an empty array of separators will cause an undefined array access on `$separators[count($separators)-1]` during `splitTextRecursive`.
**Suggested fix:** Add a constructor validation check that the separator list is non-empty, and that the final entry is always the empty string floor separator, matching upstream invariants.