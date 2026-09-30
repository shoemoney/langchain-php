# Review 12 - ~moonshotai/kimi-latest
_asked 2026-09-30T09:40:29 - served by moonshotai/kimi-k3 - 29s_

## 1. parseImagePrompts() mutates the caller-owned message
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate::parseImagePrompts()` — `$message->content = $blocks; return $message;` on the `BaseMessage` taken from `$this->promptMessages`.
**Why it matters:** Formatting the same template twice rewrites the stored message in place; worse, a caller who passed a ready-made `HumanMessage` into `fromMessages()` finds their own object altered. This is the exact class the project already fixed in `foldToolMessages()` ("never mutates a caller-owned message").
**Suggested fix:** Clone before writing: `$message = clone $message;` at the top of `parseImagePrompts()`, or build a new message of the same class with the rendered blocks.

## 2. ChatMessage block content silently dropped in coercion
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate::coerceMessagePromptTemplateLike()` — the `ChatMessage` branch: `is_string($templateData) ? $templateData : ''`.
**Why it matters:** A `ChatMessage` with array (block) content coerces to a template with empty text — the content vanishes with no error, producing a prompt with a blank message. Same failure shape as the fixed `HumanMessage(['type'=>'text',...])` empty-message bug: a wrong value written, never flagged.
**Suggested fix:** If upstream throws for non-string content here, throw; if it stringifies blocks, render each block's `text` like `PromptTemplate::stringTemplate()` does. Verify against `prompts/chat.ts` before choosing.

## 3. fromMessages() drops metadata and tags
**Severity:** MINOR
**Evidence:** `ChatPromptTemplate::fromMessages()` builds `new static(...)` passing `validateTemplate`, `templateFormat`, `outputParser` from `$extra` — but never `metadata` or `tags`, though the constructor accepts both.
**Why it matters:** `ChatPromptTemplate::fromMessages([...], ['tags' => ['x']])` silently loses the labels; traces show the prompt untagged with no error. Recorded-but-never-read is this project's own named defect class.
**Suggested fix:** Pass `metadata: $extra['metadata'] ?? []` and `tags: $extra['tags'] ?? []` through; pin with a test.

## 4. PromptTemplate::fromTemplate() ignores metadata/tags/partial options inconsistently
**Severity:** MINOR
**Evidence:** `PromptTemplate::fromTemplate()` reads `templateFormat`, `validateTemplate`, `outputParser`, `partialVariables`, `additionalContentFields` from `$options` — but not `metadata` or `tags`, which the constructor takes.
**Why it matters:** Same silent-drop pattern as #3 on the most-used factory in the library; a caller setting trace tags on a prompt gets nothing and no signal.
**Suggested fix:** Forward `metadata`/`tags` from `$options`; add a round-trip assertion to an existing `PromptTemplateTest` case.

## 5. Only four verified findings
**Severity:** MINOR
**Evidence:** inference
**Why it matters:** I checked the remaining constructs in the packet (placeholder absent-test, partial merge, serialize/deserialize, `assertVariablesMatchMessages`) against the documented upstream behaviour and the fixed-bug ledger; nothing else in the visible source is a defect I can ground without inventing evidence. Per your calibration, I stop at four rather than pad.
**Suggested fix:** None — a fifth finding here would be a guess.