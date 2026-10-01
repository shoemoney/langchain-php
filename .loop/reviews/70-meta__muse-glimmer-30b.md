# Review 70 - meta/muse-glimmer-30b
_asked 2026-10-01T04:03:00 - served by meta/muse-glimmer-30b - 15s_

## 1. MessagesPlaceholder absent check diverges from JS truthiness
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php` `formatMessages` 
**Why it matters:** Upstream `!input` treats `[]` as truthy, so an empty history is a valid placeholder. PHP `[]` is falsy, the current guard uses `=== null/false/''` which now allows `[]` but the comment shows the bug was fixed; the guard still rejects `0` as absent.
**Suggested fix:** Use `!isset($values[$name])` for absent, then coerce empty list to `[]`.

## 2. PromptTemplate::serialize drops additionalContentFields when empty
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php` `serialize()` 
**Why it matters:** `additional_content_fields` is only emitted when non-empty, so a round-trip of an empty map loses the key and changes wire shape.
**Suggested fix:** Always emit `additional_content_fields` if property is set, even if empty array.

## 3. ChatPromptTemplate::fromMessages merges partials but loses validateTemplate
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/ChatPromptTemplate.php` `fromMessages` 
**Why it matters:** Flattened inner `ChatPromptTemplate` partials are merged, but inner `validateTemplate`/`templateFormat` are ignored, so nested templates can silently change validation mode.
**Suggested fix:** Propagate `validateTemplate` and `templateFormat` from first inner template or from `$extra`.

## 4. BasePromptTemplate::mergePartialAndUserVariables invokes callables synchronously
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/BasePromptTemplate.php` `mergePartialAndUserVariables` 
**Why it matters:** Upstream partials may return a Promise; PHP invokes immediately, breaking async partial semantics and causing side-effects at merge time.
**Suggested fix:** Document that partial callables must be synchronous; or wrap invocation in a lazy proxy.

## 5. PromptTemplate::fromTemplate infers variables but does not enforce templateFormat
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php` `fromTemplate` 
**Why it matters:** `Template::templateVariables` is called with format string, but Mustache variables `{{var}}` are not detected by f-string parser, leading to missing input variables.
**Suggested fix:** Branch variable extraction on `templateFormat` and use Mustache parser for `MUSTACHE`.