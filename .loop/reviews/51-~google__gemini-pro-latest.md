# Review 51 - ~google/gemini-pro-latest
_asked 2026-09-30T18:30:17 - served by google/gemini-3.1-pro-preview - 184s_

## 1. `mergeSplits` measures separator length in characters, ignoring `lengthFunction`
**Severity:** MAJOR
**Evidence:** `TextSplitter.php` in `mergeSplits`: `if ($total + $len + count($currentDoc) * mb_strlen($separator, 'UTF-8') > $this->chunkSize)`
**Why it matters:** If `lengthFunction` is a token counter (e.g., tiktoken), `chunkSize` is measured in tokens, but the separator length is added in characters. This mixes units, causing chunks to be sized incorrectly whenever separators are present.
**Suggested fix:** Replace `mb_strlen($separator, 'UTF-8')` with `$this->lengthOf($separator)` in both the `if` and `while` conditions in `mergeSplits`.

## 2. `mergeSplits` overlap check ignores separator lengths
**Severity:** MAJOR
**Evidence:** `TextSplitter.php` in `mergeSplits`: `while ( $total > $this->chunkOverlap || ... )`
**Why it matters:** `$total` only sums the lengths of the splits, omitting the separators between them. This causes the overlap between chunks to exceed `chunkOverlap` by the total length of the separators in the overlap window, silently violating the configured limit.
**Suggested fix:** Change the condition to compare the true length: `$total + max(0, count($currentDoc) - 1) * $this->lengthOf($separator) > $this->chunkOverlap`.

## 3. HTML separator list is missing newline separators
**Severity:** MAJOR
**Evidence:** `RecursiveCharacterTextSplitter.php` in `Language::HTML`: `'<title>', // Normal type of lines ' ', '',`
**Why it matters:** The HTML separator hierarchy jumps directly from `<title>` to a space `' '`, omitting `\n\n` and `\n`. This prevents the splitter from breaking HTML along natural paragraph or line boundaries when tags aren't present, degrading chunk quality.
**Suggested fix:** Insert `"\n\n",` and `"\n",` before `' ',` in the `Language::HTML` array, matching upstream and the other languages.

## 4. Static `$oversizedChunkWarnings` array leaks memory
**Severity:** MINOR
**Evidence:** `TextSplitter.php` in `warnOversizedChunk`: `self::$oversizedChunkWarnings[] = "Created a chunk of size {$size}...`
**Why it matters:** The static array accumulates strings indefinitely across all `TextSplitter` instances. In a long-running worker processing many documents, this causes a silent memory leak unless the caller manually invokes `clearOversizedChunkWarnings()`.
**Suggested fix:** Limit the array size (e.g., `if (count(self::$oversizedChunkWarnings) > 100) array_shift(self::$oversizedChunkWarnings);`), or store warnings on the instance instead of statically.

## 5. `splitOnSeparator` fallback silently drops separators when `keepSeparator` is true
**Severity:** MINOR
**Evidence:** `TextSplitter.php` in `splitOnSeparator`: `if ($splits === false) { $splits = explode($separator, $text); }`
**Why it matters:** If `preg_split` fails (e.g., due to malformed UTF-8), the fallback uses `explode`, which removes the separator. If `keepSeparator` is true, this silently violates the configuration and loses document structure (like HTML tags or headings).
**Suggested fix:** If `$this->keepSeparator` is true, manually re-attach the separator to the exploded chunks (e.g., prepending it to all elements after the first) during the fallback.