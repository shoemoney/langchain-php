# Review 52 - perplexity/sonar-pro
_asked 2026-09-30T20:53:52 - served by perplexity/sonar-pro - 13s_

## 1. Anthropic converts arbitrary arrays too permissively
**Severity:** MAJOR  
**Evidence:** `MessageInputs::convertTool()` returns any array with `input_schema` or `name` unchanged.  
**Why it matters:** A malformed provider-shaped tool can omit `input_schema`, description, or a valid name and still reach Anthropic, producing an invalid or underspecified tool request.  
**Suggested fix:** Validate provider-shaped arrays explicitly: require a non-empty `name`, object-valued `input_schema`, and reject unknown/incomplete envelopes instead of passing them through.

## 2. OpenAI constructor tools are not canonicalized before `kwargs`
**Severity:** MAJOR  
**Evidence:** `ChatOpenAI::__construct()` canonicalizes `$fields`, but stores `tools` directly in `$this->kwargs`; `invocationParams()` later calls `convertTools()` on it.  
**Why it matters:** `convertTools()` is typed `?array`, while a malformed or object-valued constructor tool can fail only at invocation, far from construction; more importantly, constructor tools do not receive the constructor’s `supportsStrictToolCalling` decision until request time.  
**Suggested fix:** Validate and normalize constructor `tools` once during construction, storing only provider-shaped tools in `kwargs`, with the same strictness rules used by `bindTools()`.

## 3. Anthropic’s `strict` binding option is silently retained but never emitted consistently
**Severity:** MAJOR  
**Evidence:** `ChatAnthropic::bindTools()` stores `$next->kwargs['strict'] = $strict`, but `invocationParams()` reads only `$bound['tools']` and never includes a standalone `strict` field.  
**Why it matters:** A later bind can inherit strictness, but a caller may believe `strict` is a request option when it is only applied to tools created during one bind; constructor/per-call strict options have no equivalent path.  
**Suggested fix:** Define one explicit strictness policy: consume `strict` only while converting tools, remove it from serialized kwargs, and reject or document unsupported constructor/per-call uses.

## 4. Anthropic response text extraction drops non-string content
**Severity:** MAJOR  
**Evidence:** `ChatAnthropic::generate()` sets `$text = is_string($message->content) ? $message->content : '';`.  
**Why it matters:** A response containing structured content blocks—including text alongside tool use or citations—produces a `ChatGeneration` whose text is empty even though the message contains content, breaking parsers and downstream consumers that use generation text.  
**Suggested fix:** Use the same content-to-text policy as the upstream Anthropic adapter: preserve block content in the message and derive text from text blocks without pretending structured content is empty.

## 5. Anthropic headers accept invalid values despite the declared contract
**Severity:** MINOR  
**Evidence:** `public array $defaultHeaders` is documented as `array<string, string|string[]>`, and `headers()` returns it directly to `HttpClient`.  
**Why it matters:** Non-string header values or arrays with invalid element types are accepted until Guzzle/PSR-7 rejects them during transport, turning a configuration error into a late request failure.  
**Suggested fix:** Validate `defaultHeaders` in the constructor and normalize each value to `string|string[]` with string elements; reject invalid keys or values immediately.