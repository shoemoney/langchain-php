# Review 87 - perplexity/sonar
_asked 2026-09-30T03:15:53 - served by perplexity/sonar - 8s_

## 1. Recursive splitter never recurses on `''`
**Severity:** MAJOR
**Evidence:** `RecursiveCharacterTextSplitter::splitTextRecursive()` stops recursion on `$this->lengthOf($s) < $this->chunkSize`, and `splitOnSeparator()` filters out empty pieces with `array_filter(... $s !== '')`.
**Why it matters:** With only the floor separator left, an empty string disappears instead of being emitted, so some inputs can shrink or vanish instead of round-tripping like upstream.
**Suggested fix:** Preserve empty pieces only for the `''` floor case, or explicitly emit an empty chunk when the source text is empty and the chosen separator is `''`.

## 2. `preg_split()` fallback can corrupt separator semantics
**Severity:** MAJOR
**Evidence:** `splitOnSeparator()` falls back from `preg_split('/(?=' . preg_quote($separator, '/') . ')/u', ...)` to `explode($separator, $text)` on any false result.
**Why it matters:** A malformed UTF-8 input changes from “keep separator attached” logic to plain delimiter cutting, so chunk boundaries differ silently on exactly the kind of damaged text the comment says should still work.
**Suggested fix:** Fall back to a literal `preg_split()`-free path that preserves the same keep-separator/no-separator behavior, rather than switching algorithms.

## 3. Oversized-chunk warnings are never cleared on read
**Severity:** MINOR
**Evidence:** `TextSplitter::oversizedChunkWarnings()` returns a static accumulator, and `clearOversizedChunkWarnings()` is separate.
**Why it matters:** One test or call can leak warnings into the next, producing false positives about later splitters and making warning assertions order-dependent.
**Suggested fix:** Make the read API drain by default, or scope warnings to the splitter instance instead of a process-wide static.

## 4. `TextLength::utf16CodeUnits()` depends on `preg_match_all()` return shape
**Severity:** MINOR
**Evidence:** `return mb_strlen($text, 'UTF-8') + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);`
**Why it matters:** If the regex fails on invalid UTF-8, the result becomes `int + false` and the length silently degrades; that mis-sizes chunks for damaged inputs.
**Suggested fix:** Check for `false` explicitly and fall back to a safer count or a documented exception instead of arithmetic on the raw return value.

## 5. Language list misses upstream entries beyond the ported set
**Severity:** MINOR
**Evidence:** `Language::SUPPORTED_LANGUAGES` includes 16 items, while the packet’s external LangChain splitter docs show additional supported languages like `kotlin`, `ts`, `csharp`, `c`, `lua`, `perl`.
**Why it matters:** Calls to `fromLanguage()` for those upstream languages will fail even though the reference splitter supports them, reducing fidelity.
**Suggested fix:** Either add the missing language separator tables or document the exact upstream subset this port intentionally supports.