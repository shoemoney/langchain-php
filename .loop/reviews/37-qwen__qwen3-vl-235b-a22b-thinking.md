# Review 37 - qwen/qwen3-vl-235b-a22b-thinking
_asked 2026-09-29T11:58:42 - served by qwen/qwen3-vl-235b-a22b-thinking - 58s_

## 1. Output Parser Error Handling
**Severity:** MAJOR
**Evidence:** StructuredOutputParser.php:45-54
**Why it matters:** Error messages include raw input text which could leak sensitive information in production
**Suggested fix:** Remove raw text from error messages; provide redaction option via config

## 2. PartialJsonParser Position Tracking
**Severity:** MINOR
**Evidence:** PartialJsonParser.php:12-19
**Why it matters:** Reports positions in UTF-8 code points instead of matching TypeScript's UTF-16 units
**Suggested fix:** Convert position to UTF-16 code units for error messages to match upstream

## 3. JsonOutputParser Diff Mode Documentation
**Severity:** MINOR
**Evidence:** JsonOutputParser.php:17
**Why it matters:** Users won't know when to use diff mode vs default mode without documentation
**Suggested fix:** Add docblock explaining diff mode is for streaming UIs needing patch operations

## 4. BaseCumulativeTransformOutputParser Type Safety
**Severity:** MINOR
**Evidence:** BaseCumulativeTransformOutputParser.php:11
**Why it matters:** Template type T is declared but unused, causing confusion about type safety
**Suggested fix:** Remove unused template parameter since PHP can't enforce it at runtime

## 5. StructuredOutputParser Schema Validation
**Severity:** MINOR
**Evidence:** StructuredOutputParser.php:49-51
**Why it matters:** Concatenated error messages are hard to parse when multiple validation errors occur
**Suggested fix:** Return structured error array instead of semicolon-delimited string in exception