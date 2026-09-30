# Review 61 - qwen/qwen3.7-flash
_asked 2026-09-29T22:20:17 - served by qwen/qwen3.7-flash - 328s_

## 1. `ChatPromptTemplate::fromMessages` silently drops `partialVariables` from `$extra`
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/ChatPromptTemplate.php`, `return new static(...)` omits `$extra['partialVariables']`.
**Why it matters:** Passing `partialVariables` via the `$extra` options bag is silently discarded. The resulting template will throw `PromptInputError` at render time if those variables are not supplied by the caller, defeating the composition API.
**Suggested fix:** Initialize `$flattenedPartialVariables` with `$extra['partialVariables'] ?? []` before merging inner partials, or pass it to the constructor.

## 2. `ChatPromptTemplate::parseImagePrompts` mutates the original `BaseMessage` instance
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/ChatPromptTemplate.php`, `$message->content = $blocks;` and returning the same object reference.
**Why it matters:** `formatMessages` mutates the original `BaseMessage` instances passed in the prompt template's message list. Any caller reusing the message or relying on its original content will see corrupted state (e.g., injected image URLs).
**Suggested fix:** Clone the message (`$message = clone $message;`) before mutating its content, or construct a new `BaseMessage` with the updated blocks.

## 3. `PromptTemplate` serialization round-trips lose `additionalContentFields`
**Severity:** MAJOR
**Evidence:** `src/LangChain/Prompts/PromptTemplate.php`, `serialize()` omits the field and `deserialize()` does not accept it.
**Why it matters:** Prompts configured with `additionalContentFields` (e.g., for content block rendering) silently lose those fields when serialized and reloaded, breaking downstream consumers like LangSmith traces or checkpointed prompts.
**Suggested fix:** Include `additionalContentFields` in `serialize()` output and accept it in `deserialize()` to pass to the constructor.

## 4. `MessagesPlaceholder` reports `Received: undefined` for non-null absent values
**Severity:** MINOR
**Evidence:** `src/LangChain/Prompts/MessagesPlaceholder.php`, hardcoded `"Received: undefined"` when `$input` is `false` or `''`.
**Why it matters:** The error message is a copy-paste from JavaScript where `undefined` is the default missing value. In PHP, passing `false` or an empty string triggers the same error but the message incorrectly claims the value was `undefined`, confusing the caller.
**Suggested fix:** Dynamically report the actual value or type, reusing the `$readable` formatting logic from the `catch` block below.

## 5. `RunnableInterface::batch` has duplicate and conflicting docblocks
**Severity:** MINOR
**Evidence:** `src/LangChain/Runnables/RunnableInterface.php`, two separate `/** ... */` blocks immediately preceding the `batch()` method signature.
**Why it matters:** Duplicate docblocks confuse IDE autocomplete and static analysis tools, and the first block lacks the detailed explanation of `$options` being ignored that the second block provides. This degrades developer experience and documentation quality.
**Suggested fix:** Merge the two docblocks into a single, coherent block that includes both the parameter descriptions and the explanation of `$options` behavior.