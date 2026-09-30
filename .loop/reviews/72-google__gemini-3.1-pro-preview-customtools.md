# Review 72 - google/gemini-3.1-pro-preview-customtools
_asked 2026-09-30T00:38:44 - served by google/gemini-3.1-pro-preview-customtools - 168s_

## 1. `PartialJsonParser` crashes on UTF-16 surrogate pairs (emoji)
**Severity:** BLOCKER
**Evidence:** `return mb_chr((int) hexdec($hex), 'UTF-8');` in `PartialJsonParser::parseUnicodeEscape()`
**Why it matters:** JSON encodes astral characters (like emoji) as UTF-16 surrogate pairs (e.g., `\uD83D\uDE00`). `mb_chr()` rejects surrogate code points and returns `false`, which causes a fatal `TypeError` because the method must return `string`. Streaming any emoji will fatally crash the parser.
**Suggested fix:** Use `json_decode('"\u'.$hex.'"')` to let PHP's native JSON extension handle the decoding safely, or change the return type and handle the `false` case by returning the raw `\uXXXX` string.

## 2. `StructuredOutputParser` injects literal backslashes into format instructions
**Severity:** MAJOR
**Evidence:** `\`\`\`json` inside the double-quoted string in `StructuredOutputParser::getFormatInstructions()`
**Why it matters:** In PHP double-quoted strings, `\`` is not a recognized escape sequence, so the backslash is preserved literally. The model receives `\``json` instead of ````json`, which corrupts the requested output format and confuses the LLM.
**Suggested fix:** Remove the backslashes: use ````json` and ```` directly in the string.

## 3. `StructuredOutputParser` fails to extract a bare fence with a preamble
**Severity:** MAJOR
**Evidence:** `preg_match('/```json\s*([\s\S]*?)```/', $trimmed, $m2)` in `StructuredOutputParser::extractJson()`
**Why it matters:** The docblock promises to tolerate "a bare ``` fence" and "preambles". But if a model outputs a preamble followed by a bare ```` fence, the first regex fails (not at start) and the second fails (requires `json`), causing valid LLM output to be rejected.
**Suggested fix:** Change the second regex to make `json` optional, matching the first: `'/```(?:json)?\s*([\s\S]*?)```/'`.