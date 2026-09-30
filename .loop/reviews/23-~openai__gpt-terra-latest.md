# Review 23 - ~openai/gpt-terra-latest
_asked 2026-09-30T11:48:51 - served by openai/gpt-5.6-terra - 39s_

## 1. Chat image formatting mutates the stored prompt message
**Severity:** MAJOR
**Evidence:** `ChatPromptTemplate::parseImagePrompts()` assigns `"$message->content = $blocks;"`.
**Why it matters:** Formatting a template twice with different image URL variables reuses the first rendered URL, because `{variable}` has already been replaced in `promptMessages`.
**Suggested fix:** Clone the message before changing `content` (`$formattedMessage = clone $message`) and return the clone, leaving the template-owned message immutable.

## 2. ChatPromptTemplate::fromTemplate drops partial variables
**Severity:** MAJOR
**Evidence:** `fromTemplate()` calls `static::fromMessages([new HumanMessagePromptTemplate($prompt)])` without `$options`; `fromMessages()` only collects partials from nested `ChatPromptTemplate` instances.
**Why it matters:** `ChatPromptTemplate::fromTemplate('{x}', ['partialVariables' => ['x' => 'A']])` still declares `x` on the outer chat prompt and throws for a missing value before the inner prompt can apply its partial.
**Suggested fix:** Forward `$options` to `fromMessages()`, merge `partialVariables` from `$extra` into the outer template, and exclude those names while deriving outer input variables.

## 3. Empty prompt templates cannot deserialize
**Severity:** MAJOR
**Evidence:** `PromptTemplate::deserialize()` rejects `"$data['template'] === ''"`, while the constructor and `fromTemplate('')` accept an empty string.
**Why it matters:** A valid empty `PromptTemplate` serializes successfully but cannot be loaded back, breaking serialization round-trips and persisted prompt definitions.
**Suggested fix:** Reject only a missing `template` key (and invalid types if needed); permit `''` and construct the template normally.

## 4. RunnableInterface has two consecutive batch docblocks
**Severity:** MINOR
**Evidence:** `RunnableInterface::batch()` is preceded by one docblock ending `@return list<mixed>`, immediately followed by `/** Run several inputs. */`.
**Why it matters:** PHP reflection and documentation tooling associate only the nearest docblock with the method, silently losing the return contract and duplicating contradictory parameter documentation.
**Suggested fix:** Merge the two blocks into one authoritative docblock containing the behavioral explanation, all parameters, and `@return list<mixed>`.

## 5. ChatPromptTemplate::fromMessages discards metadata and tags
**Severity:** MINOR
**Evidence:** Its final `new static(...)` passes `validateTemplate`, `templateFormat`, and `outputParser`, but not `$extra['metadata']` or `$extra['tags']`.
**Why it matters:** Trace metadata and tags supplied when constructing a chat prompt vanish silently, so downstream runs cannot be labelled or correlated as requested.
**Suggested fix:** Forward supported `metadata` and `tags` options into the constructor, alongside the existing options; add them to the documented `$extra` shape.