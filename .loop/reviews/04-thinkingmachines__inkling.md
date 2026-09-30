# Review 4 - thinkingmachines/inkling
_asked 2026-09-29T09:18:22 - served by thinkingmachines/inkling - 12s_

## 1. `Runnable::bind()` is a silent no-op on every runnable
**Severity:** BLOCKER
**Evidence:** HANDOFF.md bug table: "`Runnable::bind($kwargs)` stored the kwargs and nothing read them"; "zero tests in the suite called `->bind()` at all"
**Why it matters:** `bind(['temperature' => 0])` writes to `$this->kwargs` but `RunnableBinding::mergeConfig()` only reads `$this->config`, so the bound value never reaches the provider. Every runnable in the SDK accepts the call and ignores it.
**Suggested fix:** In `RunnableBinding::mergeConfig()`, merge `$this->kwargs` into `config->options` (matching upstream's `_mergeConfig(options, this.kwargs)`), and have `Runnable::bind()` store into the same layer.

## 2. Anthropic `withStructuredOutput()` sends an empty tool schema
**Severity:** BLOCKER
**Evidence:** HANDOFF.md bug table: "`ChatAnthropic` read `parameters` off the **outer** tool array"; `MessageInputs::convertTool()` reads `$tool['parameters']` instead of `$tool['function']['parameters']`
**Why it matters:** `withStructuredOutput()` builds an OpenAI envelope (`function.parameters`). Anthropic looks one level too high, sends `input_schema: {type: object, properties: {}}`, and the model is never told what arguments the tool takes — with no error anywhere.
**Suggested fix:** In `MessageInputs::convertTool()`, when the input is an array with `type === 'function'`, read the schema from `$function['parameters']` (not `$tool['parameters']`), then assign it to `input_schema`.

## 3. `HttpResponse::header()` violates its own `?string` contract
**Severity:** MAJOR
**Evidence:** HANDOFF.md bug table: "`HttpResponse::header()` returned an array from a `?string` method"; `FakeHttpClient` used bare strings
**Why it matters:** PSR-7 `getHeaders()` is `array<string, string[]>`. The fake transport used bare strings, so the only header shape any test ever saw was the one the real transport never produces. Any real `GuzzleHttpClient` response breaks the fake's assumption.
**Suggested fix:** Change `HttpResponse::header()` to return `?string` (the first value of the header array), and update `FakeHttpClient` to store arrays matching PSR-7.

## 4. `postStream()` `try` never catches stream-establishment failures
**Severity:** BLOCKER
**Evidence:** HANDOFF.md bug table: "`try` wrapped the generator *call* in both `postStream()` paths"; `postStream()` is a generator function
**Why it matters:** Calling a generator function runs none of its body, so every streaming connect failure and non-2xx escaped as a bare `HttpException` with the provider's message discarded. Retries and error messages were unreachable.
**Suggested fix:** Move the `try` to wrap the `foreach ($raw as $bytes)` drain loop inside `postStream()`, not the generator creation call.

## 5. `RunnableParallel` rejected non-array inputs
**Severity:** MAJOR
**Evidence:** HANDOFF.md bug table: "`RunnableParallel` rejected non-array inputs"; `PORT_STATUS.md` divergence table: "Upstream's `RunnableMap.invoke` applies no type check"; regression test `RunnableTest::testParallelPassesScalarInputToEveryBranch`
**Why it matters:** The check made `{raw: llm}` — the first step of every `includeRaw` structured-output pipeline — unusable with a plain string input, which is the common case.
**Suggested fix:** Remove the `is_array($input)` guard in `RunnableParallel::invoke()` so the whole input is passed to every branch, matching upstream `RunnableMap`.