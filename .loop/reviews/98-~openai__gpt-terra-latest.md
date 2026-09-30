# Review 98 - ~openai/gpt-terra-latest
_asked 2026-09-30T05:23:09 - served by openai/gpt-5.6-terra - 58s_

## 1. Image prompt rendering mutates the stored message
**Severity:** MAJOR  
**Evidence:** `ChatPromptTemplate::parseImagePrompts()` ends with `$message->content = $blocks;`.  
**Why it matters:** Formatting once replaces `{url}` in a caller-owned prompt message; later calls with different values reuse the already-rendered URL.  
**Suggested fix:** Clone the `BaseMessage` before changing `content`, and return the clone rather than mutating `$promptMessages` members.

## 2. `ChatPromptTemplate::fromTemplate()` drops chat-level options
**Severity:** MAJOR  
**Evidence:** `return static::fromMessages([new HumanMessagePromptTemplate($prompt)]);` passes no `$options`.  
**Why it matters:** Options such as `outputParser`, `metadata`, and `tags` do not reach the resulting `ChatPromptTemplate`, silently losing tracing/parser configuration.  
**Suggested fix:** Pass supported options through: `static::fromMessages([...], $options)`, matching the `fromMessages()` option contract.

## 3. `fromMessages()` ignores caller-supplied partial variables
**Severity:** MAJOR  
**Evidence:** It builds `$flattenedPartialVariables` only from nested prompts, then passes `partialVariables: $flattenedPartialVariables`; `$extra['partialVariables']` is never read.  
**Why it matters:** A caller binding values through `ChatPromptTemplate::fromMessages(..., ['partialVariables' => ...])` gets missing-variable errors or unrendered placeholders.  
**Suggested fix:** Merge explicit partials with nested ones, with a documented precedence, before deriving `inputVariables` and constructing the template.

## 4. Constructor PHPDoc advertises the wrong `templateFormat` type
**Severity:** MINOR  
**Evidence:** `ChatPromptTemplate::__construct()` documents `@param array<string, mixed>|null $templateFormat`, while the signature is `?string $templateFormat`.  
**Why it matters:** IDE users are directed to pass an array and receive a `TypeError`; generated API documentation is false.  
**Suggested fix:** Change the annotation to `@param string|null $templateFormat`.

## 5. `RunnableInterface::batch()` has two adjacent method docblocks
**Severity:** MINOR  
**Evidence:** Two consecutive `/** ... */` blocks appear immediately before `public function batch(...)`.  
**Why it matters:** PHP associates only the second block with the method; the first silently disappears from reflection/docs and leaves conflicting maintenance guidance in source.  
**Suggested fix:** Merge the return/parameter details from the first block into the second, then remove the orphaned docblock.