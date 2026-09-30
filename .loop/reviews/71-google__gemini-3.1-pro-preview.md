# Review 71 - google/gemini-3.1-pro-preview
_asked 2026-09-30T00:30:50 - served by google/gemini-3.1-pro-preview - 84s_

## 1. Callable partial variables returning arrays are cast to string
**Severity:** MAJOR
**Evidence:** `(is_callable($value) ? (string) $value() : $value);` in `BasePromptTemplate::mergePartialAndUserVariables`
**Why it matters:** A callable partial variable returning a list of messages (a standard pattern for dynamic history in `MessagesPlaceholder`) is cast to the literal string `"Array"`, corrupting the prompt and causing a fatal error.
**Suggested fix:** Remove the `(string)` cast: `is_callable($value) ? $value() : $value`.

## 2. Tuple-style chat templates crash on content blocks
**Severity:** MAJOR
**Evidence:** `$templateData = $message->content;` followed by `HumanMessagePromptTemplate::fromTemplate($templateData, $extra);` in `ChatPromptTemplate::coerceMessagePromptTemplateLike`.
**Why it matters:** `PromptTemplate::fromTemplate` strictly requires a `string`. Passing a tuple with array content blocks (e.g., `['human', [['type' => 'text', ...]]]`) causes a PHP `TypeError`.
**Suggested fix:** Change `PromptTemplate::fromTemplate`'s signature to `string|array $template`, or instantiate the template directly via `new` when `$templateData` is an array.

## 3. Non-array inputs to prompts are silently discarded
**Severity:** MAJOR
**Evidence:** `return $this->formatPromptValue(is_array($input) ? $input : []);` in `BasePromptTemplate::invoke`.
**Why it matters:** Passing a string to a prompt template (a common shorthand for single-variable prompts) silently discards the string and formats with `[]`, throwing a missing variable error instead of mapping it or failing explicitly.
**Suggested fix:** Throw an `InvalidArgumentException` if `$input` is not an array to prevent silent data loss, or map it to the single variable if `count($this->inputVariables) === 1`.

## 4. Image prompt parsing destroys array structure on missing url
**Severity:** MINOR
**Evidence:** `if (is_array($imageUrl) && array_key_exists('url', $imageUrl))` ... `else { $block['image_url'] = $formatted; }` in `ChatPromptTemplate::parseImagePrompts`.
**Why it matters:** If a user provides an `image_url` array without a `url` key (e.g., `['detail' => 'high']` to be populated later), the `else` branch overwrites the entire array with a string, losing the `detail` configuration.
**Suggested fix:** Change the logic to preserve the array: `if (is_array($imageUrl)) { $imageUrl['url'] = $formatted; $block['image_url'] = $imageUrl; } else { $block['image_url'] = $formatted; }`.