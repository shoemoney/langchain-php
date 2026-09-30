# Review 92 - ~anthropic/claude-haiku-latest
_asked 2026-09-30T04:02:16 - served by anthropic/claude-haiku-4.5 - 27s_

## 1. StructuredOutputParser format instruction lacks markdown fence
**Severity:** MINOR
**Evidence:** `StructuredOutputParser.php:95-99`, the string literal `\`\`\`json` in a double-quoted PHP string.
**Why it matters:** HANDOFF.md documents this as already-fixed (commit `246f3f7`), stating "The format instruction carries a real markdown fence", but inspection of the current code shows the same literal backslash-backtick sequence. If the fix was applied, the fence should be bare ` ```json ` without escape.
**Suggested fix:** Verify the string against the upstream TypeScript (`utils/structured.ts`) to confirm the intended fence format is present; if the HANDOFF entry is stale, remove it from the ledger.

## 2. PartialJsonParser pending surrogate handling survives without test of the terminal case
**Severity:** MINOR
**Evidence:** `PartialJsonParser::parseString()` lines 153-158: if `$escaped` is true at EOF, re-emits `'\\' ` but `parseUnicodeEscape()` sets `$pendingHighSurrogate` without a paired low surrogate and returns `''` on a truncated sequence.
**Why it matters:** A stream ending with a high surrogate (e.g., `"😀` truncated mid-pair) leaves `$pendingHighSurrogate` set. The next chunk or EOF never calls `parseUnicodeEscape()` again, so the state is silently dropped — a chunk boundary inside a surrogate pair loses the high half and the recovery is not tested.
**Suggested fix:** On EOF in `parseString()`, check if `$pendingHighSurrogate !== null` and emit `\u{FFFD}` to account for the unpaired high; add a test case of a stream cut in the middle of an emoji escape sequence.

## 3. JsonSchemaValidator is called but never shown in the packet
**Severity:** MINOR
**Evidence:** `StructuredOutputParser::parse()` line 80 calls `JsonSchemaValidator::validate($value, $this->schema)` but the validator class is not in the focus packet.
**Why it matters:** Inference needed — cannot verify the validator rejects invalid input correctly or handles all seven JSON Schema types. The validator is depended on critically (every structured output is validated against it) but is not visible for review.
**Suggested fix:** Include `src/LangChain/OutputParsers/JsonSchemaValidator.php` in a follow-up review, or confirm it is tested equivalently to the StructuredOutputParser itself.