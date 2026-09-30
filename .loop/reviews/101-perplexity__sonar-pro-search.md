# Review 101 - perplexity/sonar-pro-search
_asked 2026-09-30T06:21:15 - served by perplexity/sonar-pro-search - 19s_

## 1. Constructor options are not fully canonicalised into `kwargs`
**Severity:** MAJOR  
**Evidence:** `ChatOpenAI::__construct()` stores `array_intersect_key($fields, ...)` after `canonicalise()`, but the whitelist contains `maxTokens`, `topP`, etc. and not their original wire keys.  
**Why it matters:** A caller using `max_tokens`, `top_p`, or `stop_sequences` can get the correct property value but lose the caller-supplied value from `kwargs`; later binding and serialization precedence can therefore differ from the equivalent camelCase call.  
**Suggested fix:** Store the canonicalised bag’s canonical keys consistently, and add constructor-to-`invocationParams()` tests for every supported alias, including precedence against `bindTools()`.

## 2. Anthropic constructor `tools` are accepted but not converted
**Severity:** MAJOR  
**Evidence:** `ChatAnthropic::__construct()` records `'tools'` in `$this->kwargs`, while `convertTools()` is only applied later by `invocationParams()`; however `bindTools()` stores already-converted tools and `convertTools()` treats arrays containing `name` as already provider-shaped.  
**Why it matters:** A raw OpenAI-shaped constructor tool with `function.name` can be misclassified as provider-shaped because it lacks `input_schema` but may contain a nested name only after conversion logic is bypassed, producing a request with the wrong schema or missing tool fields.  
**Suggested fix:** Normalize constructor tools immediately through the same `convertTools()` path, and distinguish provider-shaped Anthropic tools by requiring `input_schema`, not merely `name`.

## 3. Anthropic tool-choice binding can be silently lost
**Severity:** MAJOR  
**Evidence:** `ChatAnthropic::bindTools()` copies arbitrary `$kwargs` into `$next->kwargs`, but `invocationParams()` only reads `toolChoice`/`tool_choice` from `$bound`; constructor `kwargs` does not whitelist `toolChoice`.  
**Why it matters:** A constructor-supplied or bound tool choice can be accepted and serialized but omitted from the request, so the model may choose a different tool or no tool without an error.  
**Suggested fix:** Include `toolChoice` in the constructor `kwargs` whitelist and add matrix tests covering constructor, `bindTools()`, and per-call `tool_choice`.

## 4. Anthropic streaming does not emit token callbacks for tool/content chunks
**Severity:** MINOR  
**Evidence:** `ChatAnthropic::consume()` calls `handleLLMNewToken()` only when `$text !== ''`; `MessageOutputs::eventToChunk()` can produce non-text content such as tool-use blocks.  
**Why it matters:** Consumers relying on callbacks for streamed tool-call progress receive no callback for those chunks, while the same stream still yields them through the generator; tracing and live UI state can therefore diverge.  
**Suggested fix:** Match upstream callback semantics for every meaningful streamed chunk, passing the generated chunk and an empty token only where appropriate; add a tool-use streaming callback test.

## 5. OpenAI response content is passed through without required filtering
**Severity:** MAJOR  
**Evidence:** `Completions::convertMessage()` assigns `$param['content'] = $message->content`; the packet explicitly records that upstream filters `tool_use`, `tool_call`, reasoning, and thinking blocks.  
**Why it matters:** A caller hand-building standard content blocks can send provider-invalid assistant history to strict OpenAI-compatible APIs, causing a request failure or silently rejected context.  
**Suggested fix:** Implement the upstream role-aware content-block filter/converter in `convertMessage()` rather than delegating filtering to callers, and add tests for assistant reasoning/tool blocks and tool-message content.