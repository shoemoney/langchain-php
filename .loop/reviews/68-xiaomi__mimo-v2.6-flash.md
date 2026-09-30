# Review 68 - xiaomi/mimo-v2.6-flash
_asked 2026-09-30T00:28:15 - served by xiaomi/mimo-v2.6-flash - 112s_

## <n>1. `parseImagePrompts` mutates the stored message, so a second render reuses substituted URLs
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate.php` — inside `parseImagePrompts`: `$message->content = $blocks;`, operating on objects held in `$this->promptMessages`.
**Why it matters:** The first `formatMessages()` replaces `{var}` in an `image_url` with the rendered value, permanently. Rendering the same template a second time with different values sends the previous run's URL — silent cross-request data leakage, and a caller-owned `HumanMessage` is corrupted too (the same defect class as the documented Anthropic `foldToolMessages` fix).
**Suggested fix:** Build a shallow copy of the message (`clone` + copied `content` array) and return that; never assign to `$message->content` on the object stored in `promptMessages`.

## <n>2. Callable partials are cast to string, destroying non-string values
**Severity:** MAJOR
**Evidence:** `BasePromptTemplate::mergePartialAndUserVariables()`: `: (is_callable($value) ? (string) $value() : $value);`
**Why it matters:** Upstream passes a partial's resolved value through unchanged. A callable returning a `Document`, an array of documents, or a message list — the documented "expensive retrieved context" use case in this same file's docblock — renders as the literal string `Array` (or fatals for an object with no `__toString`), so the prompt silently contains garbage instead of context.
**Suggested fix:** Drop the cast: `$partialValues[$key] = is_callable($value) && !is_string($value) ? $value() : $value;` and let `PromptTemplate::format()` handle non-string coercion where a string is actually required.

## <n>3. Image-URL placeholder variables cannot be declared, so validation forbids the feature it exists for
**Severity:** MAJOR
**Evidence:** `assertVariablesMatchMessages()`: `if ($promptMessage instanceof BaseMessage) { continue; }` — plain messages contribute no `messageVariables`, while any declared-but-unused variable throws `Input variables ... are not used in any of the prompt messages.`
**Why it matters:** `parseImagePrompts` happily formats `{detail}`/`{img}` in an `image_url` block, but that variable is invisible to validation: declaring it throws at construction; not declaring it means `formatMessages` renders the placeholder unfilled. The class docblock's promise — "a placeholder is never filled" is caught at construction — is false for exactly the image case this class supports.
**Suggested fix:** In `assertVariablesMatchMessages` (and `fromMessages`' variable scan), collect variables from `image_url` blocks of `BaseMessage` entries in addition to template messages.

## <n>4. `coerceMessagePromptTemplateLike` passes non-string content to `fromTemplate` unguarded
**Severity:** MAJOR (inference — `HumanMessagePromptTemplate::fromTemplate`'s signature is not in the packet)
**Evidence:** `ChatPromptTemplate.php`: the `ChatMessage` branch guards `is_string($templateData) ? $templateData : ''`, but the human/ai/system branches call `HumanMessagePromptTemplate::fromTemplate($templateData, $extra)` with `mixed $templateData = $message->content`.
**Why it matters:** If `fromTemplate` takes `string` (as `PromptTemplate::fromTemplate` does), `fromMessages([new HumanMessage([['type'=>'text','text'=>'hi']])])` — a message with content blocks, a normal shape elsewhere in this port — throws a `TypeError` instead of being wrapped into a template. The asymmetric guard shows the author knew content could be an array.
**Suggested fix:** Apply the same `is_string($templateData) ? $templateData : ''` guard (or a block-aware path) on the human/ai/system branches; verify against the actual `fromTemplate` signature first.

## <n>5. `PromptTemplate::serialize()` silently drops `additionalContentFields`
**Severity:** MINOR
**Evidence:** `serialize()` returns only `_type`, `input_variables`, `template`, `template_format`; `deserialize()` reads only those; `__construct` accepts and stores `$additionalContentFields`.
**Why it matters:** A template configured with extra content-block fields round-trips to one without them, with no error — the deserialized prompt emits different message blocks than the original, a wrong-result path a green suite won't see because nothing tests the round trip with that field.
**Suggested fix:** Add `additional_content_fields` to `serialize()` and pass it back in `deserialize()` (and include it in `$this->kwargs` so the `lc:1` envelope carries it too).