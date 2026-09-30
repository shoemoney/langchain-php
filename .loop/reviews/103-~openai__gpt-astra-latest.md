# Review 103 - ~openai/gpt-astra-latest
_asked 2026-09-30T06:45:34 - served by openai/gpt-6-astra - 24s_

## 1. Boolean and null tokens fail before delimiters
**Severity:** MAJOR
**Evidence:** `PartialJsonParser::parseValue()` reads `$rest = mb_substr($this->buffer, $this->pos, 5, 'UTF-8')`, then tests `str_starts_with('true', $rest)` and `str_starts_with('null', $rest)`.
**Why it matters:** Valid inputs `[true]` and `{"a":null}` throw: `$rest` includes the closing delimiter, so neither literal matches. Streaming parsing therefore rejects completed documents it should accept.
**Suggested fix:** Match complete literals using their own lengths; allow abbreviated literals only at end-of-input. Add direct parser regressions for literals followed by commas, whitespace, and closing delimiters.

## 2. Surrogate state leaks between JSON strings
**Severity:** BLOCKER
**Evidence:** `PartialJsonParser::parseString()` returns immediately on a closing quote; `$pendingHighSurrogate` is cleared only on other paths, including the end-of-buffer cleanup.
**Why it matters:** Parsing `["\uD83D","\uDE00"]` produces an empty first string and an emoji in the second: two separate strings are silently combined into one character. Ordinary characters between surrogate escapes also fail to break pairing.
**Suggested fix:** Pair only immediately adjacent high/low escapes within the same string. Flush unmatched highs as U+FFFD before another character, another high surrogate, or the closing quote; test cross-string and interrupted pairs.

## 3. Large integers silently saturate during streaming
**Severity:** BLOCKER
**Evidence:** `PartialJsonParser::parseNumber()` ends with `return (int) $numStr;`; eager `JsonOutputParser::parse()` instead uses `json_decode(..., JSON_THROW_ON_ERROR)`.
**Why it matters:** On 64-bit PHP, `18446744073709551616` becomes `PHP_INT_MAX` in the partial parser, while eager decoding returns its floating-point value. The same completed JSON therefore yields different numbers depending on execution mode.
**Suggested fix:** Use JSON numeric decoding for complete numeric tokens, or range-check before casting and use a float outside the integer range. Test positive and negative overflow against eager decoding.

## 4. Embedded backticks truncate fenced JSON
**Severity:** MAJOR
**Evidence:** `StructuredOutputParser::extractJson()` uses `/^```(?:json)?\s*([\s\S]*?)```/` and `/```json\s*([\s\S]*?)```/`.
**Why it matters:** A fenced response containing `{"text":"literal ``` inside"}` is cut at the backticks inside the string and rejected, despite the class explicitly promising to tolerate such content.
**Suggested fix:** Identify the closing fence outside JSON string literals rather than taking the first backtick sequence. Add fenced and preamble-plus-fenced regressions containing embedded triple backticks.

## 5. Unicode escape for “0” becomes a replacement character
**Severity:** BLOCKER
**Evidence:** `PartialJsonParser::parseUnicodeEscape()` returns `mb_chr($code, 'UTF-8') ?: "\u{FFFD}"` for ordinary BMP escapes.
**Why it matters:** PHP treats the string `"0"` as false, so parsing `"\u0030"` returns U+FFFD instead of `"0"`. Escaped keys are corrupted too, potentially changing which field a consumer reads.
**Suggested fix:** Store the `mb_chr()` result and compare it with `=== false` instead of using `?:` wherever this fallback appears. Assert that escaped zero survives both string-value and object-key parsing.