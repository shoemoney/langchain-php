# Review 99 - ~google/gemini-pro-latest
_asked 2026-09-30T05:28:52 - served by google/gemini-3.1-pro-preview - 154s_

## 1. `PartialJsonParser` fails on `null`/`true`/`false` with trailing characters
**Severity:** BLOCKER
**Evidence:** `if (str_starts_with('null', $rest))` in `PartialJsonParser::parseValue()`
**Why it matters:** `str_starts_with($haystack, $needle)` checks if the literal string `'null'` starts with `$rest`. If `$rest` is `"null,"` or `"true]"` (due to trailing characters in the 5-char window), it returns false and throws, breaking the stream on valid JSON.
**Suggested fix:** Check if `$rest` starts with the keyword, or the keyword starts with `$rest`: `if (str_starts_with($rest, 'null') || str_starts_with('null', $rest)) {`

## 2. `StructuredOutputParser` rejects bare markdown fences with preambles
**Severity:** MAJOR
**Evidence:** `preg_match('/```json\s*([\s\S]*?)```/', $trimmed, $m2)` in `extractJson()`
**Why it matters:** The docblock explicitly promises to tolerate preambles and bare ` ``` ` fences. However, if a preamble exists, the first regex (`^````) fails, and the fallback regex strictly requires `json`, causing a bare fence to fail parsing entirely.
**Suggested fix:** Make the `json` tag optional in the fallback regex to match the first one: `preg_match('/```(?:json)?\s*([\s\S]*?)```/', $trimmed, $m2)`

## 3. Pending high surrogate is corrupted on short hex escape
**Severity:** MAJOR
**Evidence:** `return '\\u' . $hex;` in the `< 4` branch of `PartialJsonParser::parseUnicodeEscape()`
**Why it matters:** If a stream ends mid-way through the low surrogate of an emoji, the pending high surrogate is not flushed here. `parseString()` will later append `\u{FFFD}` at the very end of the string, transposing the character order and corrupting the text.
**Suggested fix:** Flush the pending surrogate before returning the short hex:
`if ($this->pendingHighSurrogate !== null) { $this->pendingHighSurrogate = null; return "\u{FFFD}\\u" . $hex; }`