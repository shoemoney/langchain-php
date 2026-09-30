# Review 49 - stealth/space-bunny-alpha
_asked 2026-09-30T17:49:17 - served by stealth/space-bunny-alpha - 17s_

## 1. Chat messages with a role-shaped `type` are rejected by OpenAI
**Severity:** MAJOR  
**Evidence:** `Completions::roleOf()` matches only `system`, `human`, `ai`, `tool`, and `function`, then throws; the packet documents `ChatMessage::$type` as the role.  
**Why it matters:** A `ChatMessage(type='user')` cannot be sent through `ChatOpenAI`, despite being a supported message shape elsewhere in the port.  
**Suggested fix:** Match `ChatMessage` by class and map its role to `user`/`assistant` as appropriate; keep unknown message types as errors.

## 2. Anthropic default headers are typed inconsistently with the HTTP contract
**Severity:** MINOR  
**Evidence:** `ChatAnthropic::$defaultHeaders` is documented as `array<string, string|string[]>`; `headers()` returns an `array<string, string>` and passes it directly to the HTTP client.  
**Why it matters:** A caller supplying a list-valued header can pass the constructor successfully, then fail inside the real transport with an unclear header-format error.  
**Suggested fix:** Normalize each header value to a string or reject array-valued defaults at construction, and make the `HttpClient` parameter type consistent with the accepted header shape.

## 3. `RunnableInterface` contains duplicated, contradictory method documentation
**Severity:** MINOR  
**Evidence:** `RunnableInterface::batch()` has two docblocks: `@return list<mixed>` followed immediately by `@param list<mixed> $inputs`, `$config`, and `$options`.  
**Why it matters:** The duplicated declarations make the public API ambiguous and can mislead generated documentation and downstream maintainers about the ignored batch options.  
**Suggested fix:** Remove the first short docblock and retain one complete `batch()` docblock covering all parameters, return shape, and the deliberate `returnExceptions` divergence.