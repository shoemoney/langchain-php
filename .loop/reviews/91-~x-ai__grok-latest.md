# Review 91 - ~x-ai/grok-latest
_asked 2026-09-30T03:51:52 - served by x-ai/grok-4.7 - 244s_

## 1. `parseImagePrompts` mutates the stored message
**Severity:** BLOCKER
**Evidence:** `ChatPromptTemplate::parseImagePrompts` does `$message->content = $blocks` on the instance held in `$this->promptMessages`, then `PromptTemplate::fromTemplate($url, …)->format($values)`.
**Why it matters:** The second `formatMessages()` sees an already-substituted URL, so a new `{id}` is ignored and the prompt keeps the first image. The same object is also what `kwargs` serializes. Raw-message image variables are skipped by `assertVariablesMatchMessages`, so a typo is not caught at construction.
**Suggested fix:** `clone` the message, interpolate only the clone, and treat `{…}` in raw `image_url` blocks as declared inputs when `validateTemplate` is on.

## 2. Non-string `ChatMessage` content becomes an empty template
**Severity:** MAJOR
**Evidence:** `coerceMessagePromptTemplateLike`: `ChatMessagePromptTemplate::fromTemplate(is_string($templateData) ? $templateData : '', $type, $extra)` while human/AI/system pass `$message->content` through with no string check.
**Why it matters:** Block content on a chat-role message is replaced with `''` and the prompt renders blank with no error. The other three roles either fatal (`fromTemplate(string)`) or accept blocks — same input, three outcomes.
**Suggested fix:** Pass block arrays through to a `string|array` `fromTemplate`, matching `PromptTemplate`'s constructor; never coerce non-strings to `''`.

## 3. `fromMessages` / `fromTemplate` drop composition options
**Severity:** MAJOR
**Evidence:** Flattening copies `partialVariables` only; the `new static(...)` call never reads `$extra['partialVariables']`, `metadata`, or `tags`, and does not copy the inner prompt's `templateFormat` or `outputParser`. `fromTemplate` never forwards `$options` into `fromMessages`.
**Why it matters:** `fromMessages($msgs, ['partialVariables' => ['tone' => 'kind']])` builds a template that still requires `tone`. A mustache chat prompt flattened or built via `fromTemplate` keeps the outer format as f-string, so `formatMessages` takes the missing-variable throw path instead of passing the whole value tree.
**Suggested fix:** Spread the same extra fields the constructor accepts, and when the only source is a nested `ChatPromptTemplate`, keep its `templateFormat`, `outputParser`, `metadata`, and `tags`.

## 4. `invoke` silently replaces a non-array input with `[]`
**Severity:** MAJOR
**Evidence:** `BasePromptTemplate::invoke`: `return $this->formatPromptValue(is_array($input) ? $input : []);`
**Why it matters:** A string (or `PromptValue`) piped into a prompt is discarded. If every variable is already partial, the call succeeds and the input never appears; otherwise the error is "Missing value for input variable", which names the wrong failure.
**Suggested fix:** Reject non-array input with `PromptInputError` that includes the actual type. Do not format against an empty bag.

## 5. `serialize` / `kwargs` omit fields the object already holds
**Severity:** MAJOR
**Evidence:** `PromptTemplate::serialize()` returns only `_type`, `input_variables`, `template`, `template_format`. `kwargs` is the same three keys. `additionalContentFields` is never written. `partial()` removes bound names from `inputVariables` but neither method stores the bound values (`kwargs()` only documents dropping `partial_variables` for Python).
**Why it matters:** `partial(['name' => 'Ada'])->serialize()` then `deserialize()` still has `{name}` in the template and no value for it, so `format([])` throws or leaves the placeholder. Content-block extras (`cache_control`, image detail) vanish on `toJson()` as well.
**Suggested fix:** Persist string partials and `additionalContentFields` in both `serialize()` and `kwargs`. Re-apply them in `deserialize()`. Keep omitting callable partials if they cannot cross runtimes, and fail the round-trip explicitly when one is present.