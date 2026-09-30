# Review 55 - z-ai/glm-5v-turbo
_asked 2026-09-29T13:07:04 - served by z-ai/glm-5v-turbo - 195s_

## 1. Truncated Unicode escapes duplicate hex digits in PartialJsonParser
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php:184` — the incomplete-escape branch `return 'u' . $hex;` does **not** advance `$this->pos`, while the complete branch (line 181) does `$this->pos += $hexLength`.
**Why it matters:** When streaming yields a truncated escape like `\u004` (buffer ends mid-sequence), `parseString()` consumes the `u`, calls `parseUnicodeEscape()` which reads `004` via `mb_substr` but leaves `$this->pos` at `u`. The caller then `$this->pos++` past `u` and re-consumes `0`, `0`, `4` as literal string characters. Result: input `\u004` produces string `u004004` instead of `u004`, silently corrupting every subsequent partial-parse accumulation in `BaseCumulativeTransformOutputParser::_transform()`.
**Suggested fix:** Advance `$this->pos` by `$hexLength + 1` in the incomplete branch before returning (the `+1` skips the `u` itself, matching the net advancement of the complete branch plus the caller's increment). Alternatively, restructure so the caller always advances past the full escape sequence regardless of completeness.

## 2. PartialJsonParser position counting contradicts its own docblock and upstream
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php:22-23` — `$this->length = mb_strlen($buffer, 'UTF-8')` counts **code points**, but the class docblock (line 16) claims: "Offsets are counted in code points to match the TypeScript original, which indexes by UTF-16 unit."
**Why it matters:** These are not equivalent — an astral character (e.g., emoji) is 1 code point but 2 UTF-16 units. Every error message (`Unexpected character... at position N`) and the end-of-buffer truncation check (`$this->pos < $this->length`) will report/code under different numbering than upstream for any string containing characters outside the BMP. If upstream tests assert on position values, or if a caller uses the final position to resume parsing on the next chunk, the offset will drift after each astral character. The `TextSplitter` went to great lengths (PORT_STATUS.md) to match UTF-16; this parser did not.
**Suggested fix:** Count length and advance positions in UTF-16 code units: convert the buffer to UTF-16 via `iconv('UTF-8', 'UTF-16LE', $buffer)` and divide byte-length by 2, or use `mb_str_split` and count 2 for astral/1 for BMP. If code-point counting is deliberate, move it to `PORT_STATUS.md`'s known-divergences table with the same rationale as the splitter.

## 3. CI coverage artifact is uploaded but never consumed
**Severity:** MINOR
**Evidence:** `.github/workflows/ci.yml:56-62` — the `coverage` job runs PHPUnit with `--coverage-clover clover.xml` and uploads it, but no subsequent step or external service (Codecov, Coveralls, a threshold check) reads the file.
**Why it matters:** The presence of a dedicated `coverage` job signals that coverage is monitored. A developer seeing green CI assumes coverage is stable, but the `clover.xml` is written to disk, uploaded as an artifact that expires after 90 days, and otherwise ignored. Coverage can regress to zero without any pipeline signal. This is particularly risky for a port where test quality is the primary correctness evidence.
**Suggested fix:** Add a minimum-coverage gate after the test command, e.g.:
```bash
./vendor/bin/phpunit --testsuite unit --coverage-text --coverage-clover clover.xml 2>&1 | tee coverage.txt
PERCENT=$(grep -oP 'Lines:\s*\K[\d.]+' coverage.txt)
if (( $(echo "$PERCENT < 80" | bc -l) )); then exit 1; fi
```
Or remove the artifact upload and the separate coverage job to eliminate the false impression that coverage is enforced.

## 4. `RunnableInterface::batch()` silently swallows unsupported options
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php:59-68` — the `$options` parameter (`batchOptions`) is accepted, the docblock admits it is ignored, and the (unseen) base implementation discards it.
**Why it matters:** A user migrating from LangChain JS who writes `$runnable->batch($inputs, config: null, options: ['returnExceptions' => true])` expects per-input error collection (JS behaviour) but gets an immediate thrown exception on the first failing input. Because the parameter is accepted without error, the silent no-op is indistinguishable from "it worked but all inputs succeeded." The port's own history (HANDOFF.md) lists multiple bugs of this exact class: `Runnable::bind()`, `ChatOpenAI::bindTools()`, `RunnableConfig::with()` — all stored arguments that nothing read.
**Suggested fix:** Throw `\InvalidArgumentException` if `$options` is non-null and non-empty:
```php
if ($options !== null && $options !== []) {
    throw new \InvalidArgumentException(
        'batch() options (maxConcurrency, returnExceptions) are not supported in synchronous PHP.'
    );
}
```
This forces callers to acknowledge the limitation rather than silently receiving wrong semantics. Document the throw in the interface docblock.

## 5. `StructuredOutputParser::extractJson()` drops content between unmatched fences
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/StructuredOutputParser.php:126-133` — the two regex patterns match fenced content, and the fallback returns `$trimmed` (the whole text). If the text contains an opening ``` without a closing ```, the regex fails and the entire raw text (including the opening fence marker) is passed to `json_decode`, which will fail on the backticks.
**Why it matters:** Models frequently open a code fence, stream the JSON, then close it. If the stream chunk ends mid-fence (e.g., ```json\n{"a": 1), `extractJson` receives the unclosed fence, both regexes fail (require closing ```), and it falls through to `json_decode('```json\n{"a": 1')` which throws `JsonException`. The upstream `extractJson` (from `@langchain_core/output_parsers/structured.ts`) handles unclosed fences by stripping the opening delimiter. This makes the parser fragile to realistic streaming boundaries where the closing fence hasn't arrived yet.
**Suggested fix:** Add a third fallback before returning `$trimmed`: strip a leading ```(?:json)?\s* if present but unmatched, then attempt `json_decode` on the remainder. Match the upstream behaviour in `structured.ts` `extractJson` which handles the unclosed-fence case explicitly.