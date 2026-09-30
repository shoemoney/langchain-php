# Review 54 - openai/gpt-6.1-sol-pro
_asked 2026-09-29T13:00:43 - served by openai/gpt-6.1-sol-pro - 27s_

## 1. Image rendering permanently overwrites reusable messages
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate.php`, `parseImagePrompts()` assigns `$message->content = $blocks` on the original message.
**Why it matters:** Formatting an image URL containing `{id}` with `id=A` removes the marker; a subsequent render with `id=B` still sends A's image. Caller-owned messages are also modified.
**Suggested fix:** Clone the message before assigning rendered blocks. Add a regression rendering the same template with A then B and asserting both URLs and the unchanged original message.

## 2. Required history rejects a valid empty conversation
**Severity:** MAJOR
**Evidence:** `MessagesPlaceholder.php`, `formatMessages()` checks `if (!$input)` before its list-coercion branch.
**Why it matters:** `new MessagesPlaceholder('history')` rejects `['history' => []]` as “undefined,” preventing a first conversation turn even though the caller supplied a valid message list.
**Suggested fix:** Distinguish missing/null input from an empty list; let `[]` reach list coercion and return `[]`. Pin missing, null, empty-list, and populated-list cases separately.

## 3. Factory-inferred variables still include bound partials
**Severity:** MAJOR
**Evidence:** `PromptTemplate.php`, `fromTemplate()` passes every `Template::templateVariables()` result as `inputVariables`, without excluding `partialVariables` keys.
**Why it matters:** A human-message template with `{name}` already bound as a partial still advertises `name` as required; an enclosing chat prompt checks for it and throws before the inner template can apply its partial.
**Suggested fix:** Remove partial-variable keys from inferred input variables, matching upstream `langchain-core/src/prompts/prompt.ts`. Test the partially bound prompt both directly and inside `ChatPromptTemplate::fromMessages()`.

## 4. Chat string factory loses its Mustache configuration
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate.php`, `fromTemplate()` passes `$options` to the inner `PromptTemplate` but calls `fromMessages()` without them.
**Why it matters:** The outer chat defaults to f-string handling. A Mustache variable intentionally omitted by the caller therefore triggers the outer “Missing value” check instead of rendering empty.
**Suggested fix:** Forward the applicable chat options, especially `templateFormat` and `validateTemplate`, to `fromMessages()`. Test `fromTemplate('{{name}}', ['templateFormat' => Template::MUSTACHE])->formatMessages([])`.

## 5. Scalar prompt invocation discards the supplied value
**Severity:** MAJOR
**Evidence:** `BasePromptTemplate.php`, `invoke()` replaces every non-array input with `[]`.
**Why it matters:** A single-variable prompt invoked with `'hello'`, including through an LCEL sequence, throws for a missing variable rather than binding `'hello'`; upstream supports this shorthand.
**Suggested fix:** Mirror upstream `langchain-core/src/prompts/base.ts`: wrap non-record input under the sole input-variable name, and reject it explicitly for multi-variable prompts. Test scalar invocation directly and through a sequence.