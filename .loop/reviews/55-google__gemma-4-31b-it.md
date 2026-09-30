# Review 55 - google/gemma-4-31b-it
_asked 2026-09-29T13:49:00 - served by google/gemma-4-31b-it - 269s_

## 1. Bare JSON fences with preambles are ignored
**Severity:** MAJOR
**Evidence:** `StructuredOutputParser:138-145`
**Why it matters:** The second regex requires the `json` keyword. If a model provides a bare fence (`` ``` ``) preceded by a preamble, both regexes fail, returning the raw text and causing `json_decode` to fail.
**Suggested fix:** Update the second regex to make the `json` keyword optional: `/```(?:json)?\s*([\s\S]*?)```/`.

## 2. Unicode surrogate pairs corrupt non-BMP characters
**Severity:** BLOCKER
**Evidence:** `PartialJsonParser:168-182`
**Why it matters:** `mb_chr` is called on individual `\uXXXX` escapes. This fails for non-BMP characters (e.g., emojis) which require surrogate pairs, resulting in corrupted strings or `RuntimeException`.
**Suggested fix:** In `parseUnicodeEscape`, if the decoded hex is a high surrogate, consume the next `\uXXXX` and decode the pair as a single UTF-8 character.

## 3. Newlines in multi-line strings are deleted
**Severity:** MAJOR
**Evidence:** `StructuredOutputParser:158-165`
**Why it matters:** The regex lacks the `s` (dotall) modifier, so it fails to match strings spanning multiple lines. The subsequent `str_replace` then deletes those newlines, corrupting the string's content.
**Suggested fix:** Add the `s` modifier to the regex and replace the global `str_replace` with a logic that only removes newlines outside of quoted strings.

## 4. Truncated unicode escapes lose the backslash
**Severity:** MINOR
**Evidence:** `PartialJsonParser:164, 178`
**Why it matters:** The backslash is consumed at line 164, but the truncation fallback at line 178 only returns `u` + hex. A buffer ending in `\u12` is parsed as `u12` instead of `\u12`.
**Suggested fix:** Change the return value in the truncation branch to `return '\\u' . $hex;`.

## 5. Falsy values are skipped in JSON-Patch diffs
**Severity:** MAJOR
**Evidence:** `JsonOutputParser:64`
**Why it matters:** `JsonPatch::isFalsy($next)` returns true for the integer `0`. In `diff: true` mode, if a value changes to `0`, it is treated as "no value" and never emitted as a patch.
**Suggested fix:** Replace `isFalsy($next)` with a strict check for `null` or `undefined` to allow `0` and `false` to be patched.