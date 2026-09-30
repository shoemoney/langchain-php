# Review 55 - sakana/fugu-max
_asked 2026-09-29T13:39:39 - served by sakana/fugu-max - 154s_

## 1. Inverted `str_starts_with` in `PartialJsonParser` crashes on `null` and `true`
**Severity:** BLOCKER
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php:110-123`
**Why it matters:** Slicing 5 characters into `$rest` means whenever at least 5 characters remain, `str_starts_with('null', $rest)` tests if 4-character `'null'` starts with a 5-character string (e.g. `"null,"` or `"null}"`). It returns `false`, falls through, and throws `RuntimeException("Unexpected character 'n'")`, breaking partial parsing for any payload containing `null` or `true`.
**Suggested fix:** Check for full tokens when followed by delimiters, or if matching prefixes for streaming, check `str_starts_with('null', substr($rest, 0, min(4, strlen($rest))))` and advance `$this->pos` only by the matched length.

## 2. Unadvanced `$pos` in `PartialJsonParser::parseUnicodeEscape` duplicates trailing digits
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php:168-181`
**Why it matters:** When a streamed unicode escape is incomplete (`$hexLength < 4`), `parseUnicodeEscape()` returns `'u' . $hex` without advancing `$this->pos += $hexLength`. The outer loop in `parseString()` increments `$this->pos` by 1 and parses the hex characters a second time as raw text, corrupting values (e.g. `\u0` parses as `"u00"`).
**Suggested fix:** Advance `$this->pos += $hexLength;` before `return 'u' . $hex;`, matching the `$hexLength === 4` branch.

## 3. Escaped backticks in `StructuredOutputParser::getFormatInstructions` leak raw backslashes
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/StructuredOutputParser.php:93-95`
**Why it matters:** PHP double-quoted strings do not interpret `\` as an escape sequence for backticks. The prompt emitted to the LLM literally contains `\`\`\`json` and `\`\`\``; models following instructions format their markdown with leading backslashes, causing regex extraction in `extractJson()` to fail.
**Suggested fix:** Remove the backslashes before the backticks so the output contains literal ` ```json ` and ` ``` `.

## 4. `StructuredOutputParser::extractJson` fails on preamble preceding bare code fences
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/StructuredOutputParser.php:144-150`
**Why it matters:** Regex 1 anchors to the start (`^```(?:json)?`), and regex 2 requires `json` (`/```json/`). When an LLM outputs preamble text followed by a bare fence (e.g. `Here is the data:\n```\n{"a": 1}\n````), neither regex matches, returning the raw unstripped string which fails `json_decode`.
**Suggested fix:** Match upstream's unanchored pattern `/```(?:json)?\s*([\s\S]*?)```/` in a single pass instead of anchoring bare fences to `^`.

## 5. Unhandled carriage returns in `StructuredOutputParser::escapeNewlines` fail on CRLF
**Severity:** MAJOR
**Evidence:** `src/LangChain/OutputParsers/StructuredOutputParser.php:160-168`
**Why it matters:** `escapeNewlines()` replaces `\n` with `\n` inside strings and strips `\n` outside strings, but ignores `\r`. On Windows CRLF or HTTP payloads with CRLF inside string literals, the raw `\r` (`0x0D`) remains inside the string, causing `json_decode` to throw `JsonException` for unescaped control characters.
**Suggested fix:** Normalize `\r\n` and `\r` to `\n` before running regex replacement, or include `\r` in `str_replace(["\r\n", "\r", "\n"], '\n', $m[1])`.