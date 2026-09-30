# Review 55 - z-ai/glm-5.3-flashx
_asked 2026-09-29T15:08:36 - served by z-ai/glm-5.3-flashx - 37s_

## 1. Schema-violation errors give the model nothing to correct
**Severity:** MAJOR
**Evidence:** `Schema.php::validate()` — `$verbose` defaults to false via `StructuredTool::$verboseParsingErrors = false`, so the thrown `ToolException` message is exactly "Received tool input did not match expected schema" with zero field/path detail.
**Why it matters:** Upstream feeds the parse-error string back into the model's context so it can retry; a retry against this message has no information — the model cannot tell which field was missing or mistyped, so it will repeat the mistake and the loop stalls or dies.
**Suggested fix:** Default `verboseParsingErrors` to true (or always include a one-line summary of the first error, e.g. `$: missing required property 'path'`), keeping full pretty-printed JSON behind the verbose flag.

## 2. `Schema::object()` omits `additionalProperties: false`, so unknown keys reach the body
**Severity:** MAJOR
**Evidence:** `Schema.php::object()` builds `['type'=>'object','properties'=>...,'required'=>...]` with no `additionalProperties`; `checkSub()` only rejects unexpected keys when it is literally `false`.
**Why it matters:** Zod objects strip unknown keys by default (inference on upstream semantics), so in the original a body never sees fields it didn't declare; here a model hallucinating a key delivers it intact to the closure, which may act on it or mis-shape its own output. A tool that "works in TS, misbehaves in PHP" with identical args.
**Suggested fix:** Add `'additionalProperties' => false` in `Schema::object()`, matching Zod's strip-and-reject behaviour; leave raw arrays passed to `Schema::from()` untouched.

## 3. `stringInput()` marks `input` optional, so a call with `{}` validates and runs
**Severity:** MAJOR
**Evidence:** `Schema.php::stringInput()` — `'properties' => ['input' => ['type' => 'string']]` with no `'required'`; `checkSub()` enforces only listed required keys.
**Why it matters:** A model that calls a default-schema tool with `{}` (or an empty arg string) passes validation and the closure runs with no `input` key at all — the failure moves inside the body, after any irreversible step, instead of being rejected at the schema gate the class documents as its purpose.
**Suggested fix:** `'required' => ['input']` in `stringInput()`, matching upstream's default `z.string()` which is inherently required.

## 4. Two-element content-block list is misread as a content/artifact tuple
**Severity:** MAJOR
**Evidence:** `StructuredTool.php::splitResult()` — any list of exactly 2 elements is destructured as `[$content, $artifact]` for `content_and_artifact` tools; `ToolOutput::isListOfContentBlocks` elsewhere proves such lists are a legitimate tool return shape.
**Why it matters:** A tool returning two content blocks (e.g. `[textBlock, imageBlock]`) silently sends only the first to the model and files the second as an artifact — data loss with no error, and the model reasons over a truncated result.
**Suggested fix:** Distinguish the tuple from content-block lists, e.g. only treat `[content, artifact]` as a tuple when element 1 is not itself an array with a `type` key, or require an explicit marker key; otherwise throw the same "not a two-tuple" error.

## 5. Artifact silently dropped when no `tool_call_id` is present
**Severity:** MINOR
**Evidence:** `ToolOutput.php::format()` — first branch returns `$content` unchanged when `$toolCallId === null`; `$artifact` is discarded.
**Why it matters:** Application code invoking a `content_and_artifact` tool directly (the documented use: artifact "for the caller, not the model") gets only the summary; the full result is unreachable, and the caller cannot tell the artifact existed.
**Suggested fix:** When `toolCallId === null` and `$artifact !== null`, return a two-tuple `[$content, $artifact]` (or throw), so the direct caller still receives both halves; keep the bare-content passthrough only when there is no artifact.

Those five are what I can point at in the packet; I looked specifically at the `anyOf` branch of `validatesOnlyStrings()` (mixed string/object schemas route down the string path with an object arg) but without `tool()` in the packet that is an unverified guess, so I am not counting it.