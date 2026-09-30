# Review 55 - xiaomi/mimo-v2.6-pro-ultraspeed
_asked 2026-09-29T13:10:19 - served by xiaomi/mimo-v2.6-pro-ultraspeed - 67s_

## 1. Inverted `str_starts_with` makes any `null`/`true`/`false` not at EOF unparseable
**Severity:** BLOCKER
**Evidence:** `src/LangChain/OutputParsers/PartialJsonParser.php::parseValue()` — `str_starts_with('null', $rest)`, `('true', $rest)`, `('false', $rest)` (haystack/needle swapped).
**Why it matters:** `$rest` is up to 5 chars of the buffer, so `[null]` gives `$rest='null]'` and all three checks fail → `RuntimeException("Unexpected character 'n'")`. `[true, false]`, `{"ok": false}` break the same way; only a literal sitting at end-of-buffer parses. The streaming path (`parsePartialResult` over the accumulated buffer) therefore throws or yields nothing for any JSON containing a boolean/null not at the very end.
**Suggested fix:** Compare a prefix of length `min(strlen($word), $this->length - $this->pos)` and advance by that many chars (so a truncated `tru` at EOF still parses, and `true]` matches too). Add `[null]` / `{"a": true}` partial-parse cases to the tests.

## 2. Incomplete `\uXXXX` escape duplicates its hex digits into the parsed string
**Severity:** MAJOR
**Evidence:** `PartialJsonParser::parseUnicodeEscape()` short-hex branch returns `'u'.$hex` **without advancing `$this->pos`**, while `parseString()`'s loop then re-reads those digits one at a time.
**Why it matters:** Streaming `{"name": "caf\u00e9"}` yields the intermediate value `{"name": "cafu0000"}` before the digits arrive (two copies of `00`), and that corrupted value is *yielded* because it differs from `$prevParsed` — a UI or diff consumer sees garbage that is later patched away.
**Suggested fix:** In the short-hex branch do `$this->pos += $hexLength;` before returning `'u'.$hex` (same convention as the 4-digit branch, which lands on the last digit and relies on the caller's `$this->pos++`). Pin with a test that feeds a `\u` escape split across chunks.

## 3. `extractJson` truncates at a ``` inside a string, contradicting its own docblock
**Severity:** MAJOR
**Evidence:** `StructuredOutputParser` class docblock ("any number of ``` sequences *inside* a string value (a markdown-heavy biography must not be mistaken for a fence)") vs `extractJson()`'s non-greedy `/^```(?:json)?\s*([\s\S]*?)```/ `.
**Why it matters:** A fenced reply whose string values contain ``` — the biography-with-code-samples case the doc names — is cut at the *inner* fence, so `json_decode` fails and `parse()` throws `OutputParserException` on perfectly well-formed model output. The comment states intent, not behaviour.
**Suggested fix:** Check upstream's regex first: if it is equally non-greedy, make the docblock honest and pin the actual behaviour with a test. If the guarantee is intended, anchor the closing fence at the end (`/```(?:json)?\s*([\s\S]*)\s*```$/` on the trimmed text) so inner fences stay inside the payload.

## 4. Partial parse is O(n²) per chunk, O(n³) per stream
**Severity:** MAJOR
**Evidence:** `PartialJsonParser::charAt()` is `mb_substr($this->buffer, $i, 1)` (O(n) per call, used for every char), and `BaseCumulativeTransformOutputParser::_transform()` reparses the whole `$accumulated` buffer on every chunk.
**Why it matters:** The class exists for streaming, and `JsonOutputParser`'s doc names "a five-hundred-key object" as the case that matters; each chunk costs O(len²) and the stream cubes it. Upstream indexes a JS string in O(1), so this is a port-introduced regression on top of the inherent per-chunk reparse — the streaming UI this is built for becomes unusable at real payload sizes.
**Suggested fix:** Split the buffer once in the constructor (`mb_str_split($buffer, 1, 'UTF-8')`) and make `charAt()` an array lookup; alternatively scan byte offsets with `strlen`/`substr`, since every token except string content is ASCII.

## 5. `StructuredOutputParser::lcId()` omits the class-name segment
**Severity:** MAJOR
**Evidence:** `JsonOutputParser::lcId()` = `['langchain_core','output_parsers','JsonOutputParser']` vs `StructuredOutputParser::lcId()` = `['langchain','output_parsers','structured']` (inference: upstream `lc_id` is `[...lc_namespace, lcName()]`, so exactly one of these two can be the id).
**Why it matters:** A serialized `StructuredOutputParser` writes a namespace, not a constructor id: `LcConstructorLoader` cannot map it back on read, and a JS runtime consuming a `load` payload sees an id that names no class — silently breaking the byte-compatible serialization the port claims.
**Suggested fix:** Have both return `[...lc_namespace, lcName()]` (i.e. `['langchain','output_parsers','structured','StructuredOutputParser']` if that is the upstream namespace, else `['langchain_core','output_parsers','StructuredOutputParser']`), and add one test per parser asserting the emitted id so the convention cannot drift again.