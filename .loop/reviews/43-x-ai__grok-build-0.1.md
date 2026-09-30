# Review 43 - x-ai/grok-build-0.1
_asked 2026-09-30T16:58:58 - served by x-ai/grok-build-0.1 - 139s_

## 1. Oversized chunks from deep recursion never trigger warning
**Severity:** MINOR
**Evidence:** `RecursiveCharacterTextSplitter.php` `if ($newSeparators === null) { $finalChunks[] = $s; }` (the `lengthOf($s) >= chunkSize` arm) and no call to `mergeSplits` or `warnOversizedChunk`
**Why it matters:** `oversizedChunkWarnings()` stays empty for the primary splitter's core path, so callers/tests cannot observe the documented over-budget case.
**Suggested fix:** `warnOversizedChunk($this->lengthOf($s)); $finalChunks[] = $s;` before the direct append.

## 2. Line metadata calculation fails for overlapping chunks
**Severity:** MAJOR
**Evidence:** `TextSplitter.php` `$indexChunk = self::indexOf($text, $chunk, $indexPrevChunk + 1);` then `elseif ($indexEndPrevChunk > $indexChunk)` (the -1 case) to `numberOfNewLines` and subtract from `lineCounterIndex`
**Why it matters:** `metadata['loc']['lines']` is wrong whenever `chunkOverlap > 0`, breaking any consumer that uses source positions.
**Suggested fix:** Search chunk position from 0 (or maintain absolute offsets); the from-offset assumes strictly forward non-overlapping chunks.

## 3. Public fields allow violating chunkOverlap < chunkSize after construction
**Severity:** MAJOR
**Evidence:** `TextSplitter.php` `public int $chunkSize; public int $chunkOverlap;` and the `>=` check only in `__construct`
**Why it matters:** Post-construction `$s->chunkOverlap = $s->chunkSize` makes the `while (total > chunkOverlap || ...)` in `mergeSplits` fail to reduce, producing incorrect or non-terminating output.
**Suggested fix:** Make the fields protected; add validated setters (or remove public mutation).

## 4. Malformed UTF-8 fallback drops separators when keepSeparator=true
**Severity:** MINOR
**Evidence:** `TextSplitter.php` `if ($splits === false) { $splits = explode($separator, $text); }` (unconditional, outside the `keepSeparator` branch)
**Why it matters:** A `keepSeparator` splitter (Recursive default) on lossy input will strip separators instead of attaching them to the following piece.
**Suggested fix:** Duplicate the lookahead/keep logic in the fallback path.

## 5. Final oversized chunk never warns in mergeSplits
**Severity:** MINOR
**Evidence:** `TextSplitter.php` `if (total + $len + ... > chunkSize) { if (total > chunkSize) warn... }` then after the loop `joinDocs` + append with no size check
**Why it matters:** The last (or only) chunk that exceeds budget produces no `oversizedChunkWarnings()` entry.
**Suggested fix:** After the final `joinDocs`, or on every `$len > chunkSize` before `$currentDoc[] = $d`, call `warnOversizedChunk($len)`.