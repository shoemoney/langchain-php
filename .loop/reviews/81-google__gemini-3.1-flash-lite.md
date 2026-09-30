# Review 81 - google/gemini-3.1-flash-lite
_asked 2026-09-30T02:23:13 - served by google/gemini-3.1-flash-lite - 12s_

## 1. Serialization of partial variables is missing
**Severity:** MAJOR
**Evidence:** `BasePromptTemplate::lcMapKeys()`: `unset($kwargs['partial_variables']);`
**Why it matters:** `partialVariables` are dropped during serialization. A prompt template reconstructed from the serialized form loses all bound defaults, causing `format()` to fail with "Missing value" errors for variables that were previously bound.
**Suggested fix:** Update `lcMapKeys` to serialize `partialVariables` that are strings, while omitting callables (which cannot be serialized).

## 2. Reserved variable name check is incomplete
**Severity:** MINOR
**Evidence:** `BasePromptTemplate::__construct()`: `if ($name === 'stop') { ... }`
**Why it matters:** The check only reserves `stop`. Upstream LangChain reserves other keys (e.g., `agent_scratchpad`, `chat_history`) depending on the prompt type. Allowing these as input variables leads to silent collisions where the framework's internal state is overwritten by user input.
**Suggested fix:** Expand the reservation check to include the full list of upstream reserved keys (`agent_scratchpad`, `chat_history`, `tools`, `tool_names`).

## 3. Output parser serialization is blocked
**Severity:** MAJOR
**Evidence:** `PromptTemplate::serialize()`: `if ($this->outputParser !== null) { throw new \RuntimeException(...); }`
**Why it matters:** Upstream `PromptTemplate` supports serializing the `outputParser` alongside the template. This implementation forces a runtime exception, breaking any pipeline that attempts to save/load a chain containing a prompt with a configured parser.
**Suggested fix:** Implement `outputParser` serialization by calling `$this->outputParser->toJson()` and including it in the returned array, matching the upstream `lc:1` schema.

## 4. Mustache template validation is incorrectly bypassed
**Severity:** MINOR
**Evidence:** `PromptTemplate::__construct()`: `if ($this->templateFormat === Template::MUSTACHE) { throw new \RuntimeException('Mustache templates cannot be validated.'); }`
**Why it matters:** While the f-string validator cannot parse Mustache, the constructor throws a `RuntimeException` if `validateTemplate` is explicitly set to `true` for a Mustache template. This prevents users from opting into validation logic if they provide a custom validator.
**Suggested fix:** Allow the constructor to accept a custom validation callable for Mustache templates instead of unconditionally throwing an exception.

## 5. `MessagesPlaceholder` coercion is overly restrictive
**Severity:** MAJOR
**Evidence:** `MessagesPlaceholder::coerceStrictly()`: `if (!$isPair && !isset($value['role']) && !isset($value['type'])) { throw new InputFormatError(...); }`
**Why it matters:** The logic rejects valid message-like arrays that rely on `MessageUtils` defaults (e.g., an array with only `content` and `additional_kwargs`). Upstream allows more flexible coercion; this strictness causes valid tool-use history to be rejected as "unsupported".
**Suggested fix:** Delegate the array validation to `MessageUtils::coerceMessageLikeToMessage()` entirely, rather than pre-validating keys, to ensure consistency with the rest of the SDK.