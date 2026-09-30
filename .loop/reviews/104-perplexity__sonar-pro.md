# Review 104 - perplexity/sonar-pro
_asked 2026-09-30T07:03:24 - served by perplexity/sonar-pro - 19s_

## 1. `mergeSplits()` measures separators incorrectly
**Severity:** MAJOR
**Evidence:** `TextSplitter::mergeSplits()` uses `count($currentDoc) * mb_strlen($separator, 'UTF-8')`.
**Why it matters:** A custom `lengthFunction`—including the default UTF-16 measure—can disagree with `mb_strlen()`, so chunks can exceed `chunkSize` or be split at different boundaries from upstream.
**Suggested fix:** Compute separator cost with `$this->lengthOf($separator)` and add it only between pieces, matching the same length function used for content.

## 2. `keepSeparator` uses a regex where the upstream operation is literal
**Severity:** MAJOR
**Evidence:** `TextSplitter::splitOnSeparator()` calls `preg_split('/(?=' . preg_quote($separator, '/') . ')/u', $text)`.
**Why it matters:** Invalid UTF-8 causes `preg_split()` to fail and the fallback uses `explode($separator, $text)`, which drops separator preservation entirely; malformed transport data therefore changes chunk contents and boundaries.
**Suggested fix:** Implement literal separator preservation without a UTF-8-dependent regex—split with `explode()` and prepend the separator to subsequent pieces, preserving the documented behavior for all byte strings.

## 3. Recursive splitting rejects fragments exactly equal to `chunkSize`
**Severity:** MAJOR
**Evidence:** `RecursiveCharacterTextSplitter::splitTextRecursive()` tests `if ($this->lengthOf($s) < $this->chunkSize)`.
**Why it matters:** A fragment whose length is exactly the configured maximum is treated as oversized and recursively split or emitted through the oversized path, producing different chunks and warnings despite already satisfying the limit.
**Suggested fix:** Use `<=` for the fit check, and add a regression case where a separator-delimited fragment has length exactly `chunkSize`.

## 4. `createDocuments()` cannot reliably locate repeated or trimmed chunks
**Severity:** MAJOR
**Evidence:** `TextSplitter::createDocuments()` finds each chunk with `self::indexOf($text, $chunk, $indexPrevChunk + 1)`, while `joinDocs()` applies `trim()`.
**Why it matters:** Leading/trailing whitespace removed during merging, repeated overlapping text, or repeated identical chunks can make `indexOf()` select the wrong occurrence or return `-1`; line metadata then reports incorrect source ranges without failing.
**Suggested fix:** Track source offsets alongside splits during splitting/merging, or preserve the upstream offset-walking algorithm with explicit handling for trimmed prefixes and repeated chunk occurrences. Add cases for repeated text, whitespace trimming, and overlap.

## 5. `TextLength::utf16CodeUnits()` mishandles invalid UTF-8 without an explicit contract
**Severity:** MINOR
**Evidence:** `TextLength::utf16CodeUnits()` returns `mb_strlen($text, 'UTF-8') + 0` when `preg_match_all()` returns `false`.
**Why it matters:** The resulting size is neither UTF-16 code-unit length nor a clearly validated input length; malformed text can therefore alter chunk boundaries silently while the public method claims to reproduce JavaScript `text.length`.
**Suggested fix:** Either reject invalid UTF-8 explicitly with a documented exception, or expose the fallback as a named policy and test it through `TextSplitter`; do not silently coerce the failed Unicode scan into a different metric.