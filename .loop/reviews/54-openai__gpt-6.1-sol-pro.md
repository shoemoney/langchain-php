# Review 54 - openai/gpt-6.1-sol-pro
_asked 2026-09-30T21:32:36 - served by openai/gpt-6.1-sol-pro - 32s_

## 1. Wire-spelled bindings lose to constructor values
**Severity:** MAJOR
**Evidence:** Both clients’ `bindTools()` store `$next->kwargs[$key] = $value`; `canonicalise()` copies aliases only when `!array_key_exists($camel, $bag)`.
**Why it matters:** Constructing with `maxTokens: 99`, then binding `max_tokens: 50`, still sends 99: the existing canonical key masks the newer binding.
**Suggested fix:** Canonicalise incoming binding kwargs before merging them into stored kwargs. Assert the request sends 50 for both clients when the constructor used the other spelling.

## 2. Empty per-call tools cannot clear bound tools
**Severity:** MAJOR
**Evidence:** Both clients use `convertTools($this->pick($options, 'tools')) ?? ...convertTools($bound['tools'] ?? null)`; `convertTools([])` returns `null`.
**Why it matters:** Passing `tools: []` after binding tools re-offers the bound tools instead of removing them; the model can call tools the caller explicitly excluded.
**Suggested fix:** Select the configuration layer before conversion, preserving an explicitly supplied empty array; then omit the wire `tools` key. Test clearing a nonempty bound list.

## 3. Bound and per-call streamUsage are ignored
**Severity:** MAJOR
**Evidence:** OpenAI checks `if ($this->streamUsage)`; Anthropic uses `$this->streamUsage ? MessageOutputs::usageFromEvent($event) : []`, despite accepting options and storing binding kwargs.
**Why it matters:** `bindTools($tools, ['streamUsage' => false])` records the setting but still requests/emits usage; per-call overrides likewise do nothing.
**Suggested fix:** Resolve `streamUsage` from options, bound kwargs, then the property; pass that resolved value into Anthropic’s consumer. Assert both override directions on actual streams.

## 4. Anthropic empty tool arguments have the wrong shape
**Severity:** MAJOR
**Evidence:** `MessageInputs::convertMessage()` builds tool-use blocks with `'input' => $call['args'] ?? []`; the resulting request goes through `Js::encode($params)`.
**Why it matters:** A no-argument assistant tool call is sent back as `"input":[]`, not `"input":{}`, making an ordinary tool-history round trip malformed.
**Suggested fix:** Preserve an object for empty argument maps when building Anthropic tool-use blocks. Assert the raw second-request body contains `"input":{}` after a no-argument tool response.

## 5. Named tool arrays bypass schema conversion
**Severity:** MAJOR
**Evidence:** `MessageInputs::convertTool()` immediately returns when `isset($tool['input_schema']) || isset($tool['name'])`, bypassing its later `parameters`/`schema` mapping.
**Why it matters:** An accepted array such as `['name' => 'lookup', 'parameters' => $schema]` reaches Anthropic without `input_schema`; the request lacks the required argument schema.
**Suggested fix:** Treat only arrays already carrying `input_schema` as provider-shaped; normalise other named arrays through the schema-mapping branch. Assert the outgoing body contains `input_schema` and no `parameters`.