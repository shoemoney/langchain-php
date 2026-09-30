# Review 55 - inclusionai/ling-3.0-flash-vl
_asked 2026-09-29T13:38:45 - served by inclusionai/ling-3.0-flash-vl - 55s_

## 1. PartialJsonParser offsets counted in code points, not UTF-16 units
**Severity:** MAJOR
**Evidence:** `PartialJsonParser.php` class-level docblock, `mb_strlen($buffer, 'UTF-8')` in constructor
**Why it matters:** The docblock claims offsets match the TS original's UTF-16 unit indexing, but `mb_strlen` with `'UTF-8'` counts code points. For astral characters (emoji, etc.), error positions reported by `parse()` will be wrong.
**Suggested fix:** Count UTF-16 code units (e.g., `mb_strlen($buffer, 'UTF-16BE')`) to match the TS original, or correct the docblock to state the actual behaviour.

## 2. StructuredOutputParser::parse catches `\Throwable`
**Severity:** MINOR
**Evidence:** `StructuredOutputParser.php:137` (`catch (\Throwable $e)`)
**Why it matters:** Every exception type — `JsonException`, `\RuntimeException` from validation, and any future `OutputParserException` — is wrapped into a single `OutputParserException`. Callers cannot distinguish JSON-parse failures from schema-validation failures.
**Suggested fix:** Catch `JsonException` and `\RuntimeException` separately, or re-throw `OutputParserException` unwrapped so callers can catch it at the call site without double-wrapping.

## 3. `RunnableInterface::batch()` has two competing docblocks
**Severity:** MINOR
**Evidence:** `RunnableInterface.php:113–123` (two `@param` blocks before `batch()`)
**Why it matters:** PHP reflects only the second docblock; the first (with the bare `$options` description) is dead. Tools or IDEs surfacing the first hide the note that `$options` is unused by default.
**Suggested fix:** Remove the first docblock (lines 113–119) so only the accurate one with the "Unused by default" note remains.

## 4. `JsonOutputParser::parsePartialResult` reads only first generation
**Severity:** MINOR
**Evidence:** `JsonOutputParser.php:49–55`
**Why it matters:** The signature accepts `list<array{text: string, message?: BaseMessage}>` but only reads `$generations[0]`. If ever called with multiple accumulated fragments (as the type allows), later entries are silently discarded.
**Suggested fix:** Concatenate all `$generations[*]['text']` before parsing, or narrow the parameter type to a single-element array to match actual usage and signal intent.

## 5. PartialJsonParser corrupts truncated `\u` escapes
**Severity:** MAJOR
**Evidence:** `PartialJsonParser.php:parseUnicodeEscape()` (~lines 168–178)
**Why it matters:** When a `\u` escape is truncated at the buffer end, it returns `'u' . $hex` (e.g., `"u041"`) without the leading backslash and doesn't advance `$this->pos`. The escape is silently replaced with literal text, producing silently wrong parse results — the streaming parser yields corrupted strings instead of signalling incomplete input.
**Suggested fix:** Return `'\u' . $hex` (preserving the backslash) so callers can detect incomplete escapes, and advance `$this->pos` past the consumed hex characters.