# Review 40 - mistralai/mistral-large-2512
_asked 2026-09-30T16:37:00 - served by mistralai/mistral-large-2512 - 16s_

Here are five concise, specific findings from the prompts and templates subsystem:

---

## 1. `PromptTemplate` silently drops non-string partial variable values
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php:160-165`
```php
$partialValues[$key] = is_string($value)
    ? $value
    : (is_callable($value) ? $value() : $value);
```
**Why it matters:** A partial variable that is neither a string nor callable (e.g., an array or object) is stored as-is and later JSON-encoded during rendering. This produces unexpected output (e.g., `"{context: [Object object]}"`) instead of failing early. Upstream’s `base.ts:112-117` assigns the callable result uncast, so non-string values propagate visibly.
**Suggested fix:** Reject non-string, non-callable partials at construction with `throw new \InvalidArgumentException("Partial variable `{$key}` must be a string or callable.")`.

---

## 2. `ChatPromptTemplate` merges partials in the wrong order
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/ChatPromptTemplate.php:120-125`
```php
$allValues = $this->mergePartialAndUserVariables($values);
```
**Why it matters:** `mergePartialAndUserVariables()` merges partials *under* user values, so a user-supplied `null` or empty string overrides a non-empty partial. This contradicts the docblock ("caller’s values win") and upstream’s `base.ts:100-105`, which merges partials *over* user values to ensure defaults are always applied.
**Suggested fix:** Swap the merge order in `BasePromptTemplate::mergePartialAndUserVariables()` to `array_merge($userVariables, $partialValues)`.

---

## 3. `MessagesPlaceholder` rejects empty arrays, breaking first-turn conversations
**Severity:** BLOCKER
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php:50-52`
```php
$absent = $input === null || $input === false || $input === '';
```
**Why it matters:** PHP’s `[]` is falsy, so an empty history (the first turn of a conversation) throws `InputFormatError`. Upstream’s `!input` treats an empty array as truthy, allowing empty placeholders.
**Suggested fix:** Change the check to `$absent = $input === null || $input === false || $input === '' || $input === [];`.

---

## 4. `PromptTemplate::serialize()` omits `additionalContentFields` when empty
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php:200-205`
```php
if ($this->additionalContentFields !== null && $this->additionalContentFields !== []) {
    $out['additional_content_fields'] = $this->additionalContentFields;
}
```
**Why it matters:** A template with explicitly set `additionalContentFields: []` serializes identically to one with `null`, losing the intent. Upstream’s `lc_attributes` always includes the field (even if empty) to distinguish "unset" from "empty."
**Suggested fix:** Always include the field: `$out['additional_content_fields'] = $this->additionalContentFields ?? [];`.

---

## 5. `ChatPromptTemplate::fromMessages()` flattens nested templates but drops their metadata/tags
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/ChatPromptTemplate.php:70-80`
```php
foreach ($promptMessage->promptMessages as $inner) {
    $flattened[] = $inner;
}
```
**Why it matters:** Metadata and tags from nested templates are silently discarded, making tracing and debugging harder. Upstream’s `fromMessages` merges metadata/tags recursively.
**Suggested fix:** Merge metadata/tags from nested templates into the parent’s arrays.