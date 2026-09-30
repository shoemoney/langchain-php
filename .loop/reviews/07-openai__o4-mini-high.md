# Review 7 - openai/o4-mini-high
_asked 2026-09-29T09:28:54 - served by openai/o4-mini-high - 135s_

## 1. Port the upstream “prebuilt agents” (`createReactAgent` + `ToolNode`)  
**Severity:** MAJOR  
**Evidence:** PORT_STATUS.md under **langgraph-core → prebuilt** is “⬜” and HANDOFF.md calls out “Prebuilt agents — `createReactAgent`, `ToolNode` — ⬜ **Do this first.**”  
**Why it matters:** Without the React‐style agent factories and the `ToolNode` abstraction, users cannot assemble the canonical agent patterns that exist in the TypeScript SDK. This blocks almost every real‐world agent use case.  
**Suggested fix:**  
• Copy the upstream TS source for `createReactAgent.ts` and `ToolNode.ts` (and any helpers they depend on) into a new PHP subtree (e.g. `src/LangChain/Agents/React/`).  
• Translate line‐for‐line into idiomatic PHP (respecting promises/fibers, generators, PSR-4, `declare(strict_types=1)`, PHPUnit attributes, etc.).  
• Port the corresponding TypeScript unit tests into `tests/Unit/Agents/React`, convert them to PHPUnit (using the same inputs/outputs), and add them to the “unit” suite so that CI fails if the behavior drifts.  
• Update `composer.json` autoload-psr-4 to include the new namespace.

---

## 2. Port the OpenAI “Responses” API client  
**Severity:** MAJOR  
**Evidence:** PORT_STATUS.md under **langchain-core → Provider integrations** notes “Not done: OpenAI **Responses API** (`converters/responses.ts`, 1,868 lines — a different event protocol, not a flag).”  
**Why it matters:** The TS SDK supports two distinct OpenAI protocols—Completions _and_ Responses. Without the Responses path, any model or provider that only exposes that endpoint (or that uses its richer event schema) is unusable in the PHP port.  
**Suggested fix:**  
• Translate the upstream TS `responses.ts` converter and client implementation into a new class (e.g. `LangChain\LanguageModels\Chat\OpenAI\ResponsesClient`) under `src/LangChain/LanguageModels/Chat/OpenAI/Responses`.  
• Implement `generate()` and `streamResponseChunks()` to match the TS logic (using `SseParser` for the different framing and JSON schema).  
• Port the TS unit tests for the Responses API into `tests/Unit/LanguageModels/Chat/OpenAI/Responses`, verifying both the wire requests and the parsed `AIMessage`/`ChatGenerationChunk` outputs.  
• Wire it into the public API (e.g. allow `new ChatOpenAI(['useResponses'=>true])` or a separate factory), mirroring the TS entry point.

---

## 3. Enforce non-empty tool names in `Completions::toolCallToWire()`  
**Severity:** MAJOR  
**Evidence:** In `src/LangChain/LanguageModels/Chat/OpenAI/Utils/Completions.php` the method begins by validating `id` but does not validate `name` (it defaults to `''` if missing).  
**Why it matters:** If a tool call arrives with a blank or missing `name`, the generated payload will contain `"function":{"name":""}` and the provider will either silently accept an invalid call or return a confusing error that names no offending function. This is a class of bug that’s invisible until runtime.  
**Suggested fix:**  
In `toolCallToWire()` before JSON‐encoding, add:
```php
if (empty($call['name']) || !is_string($call['name'])) {
    throw new \InvalidArgumentException(
        'All OpenAI tool calls must have a non-empty "name" field.'
    );
}
```
This mirrors the existing `id` check and fails fast on malformed tool‐call objects.

---

## 4. Forward the `RunnableConfig` to `RunnableLambda` callbacks  
**Severity:** MINOR  
**Evidence:** PORT_STATUS.md under “Known non-exact behaviours” states “RunnableLambda does not pass the config to its callable.”  
**Why it matters:** In the TS SDK a lambda runnable receives `(input, config?)`, so it can inspect `config.options`, bindings, metadata, etc. In the current PHP port the lambda is invoked with only `input`, making it impossible to write downstream code that relies on bound options—subtle mismatches when porting real lambdas.  
**Suggested fix:**  
In `src/LangChain/Runnables/RunnableLambda.php`, change the invocation from:
```php
return ($this->callable)($input);
```
to:
```php
// Add a second argument for the config
return ($this->callable)($input, $this->config);
```
and update the docblock to `@param mixed  $input, Config $config` so that lambdas may declare a two-argument signature.

---

## 5. Accept the PHP-facing key `stopSequences` in `ChatOpenAI::invocationParams()`  
**Severity:** MINOR  
**Evidence:** In `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php`, `invocationParams()` builds its stop list with  
```php
'stop' => $this->pick($options, 'stop') ?? $bound['stop'] ?? $this->stopSequences,
```
so passing the natural camel-case option `['stopSequences' => [...]]` in `$options` is ignored.  
**Why it matters:** PHP users will expect to use the PHP property name `stopSequences` for per-call overrides. Today those values are dropped and the model runs without any stop tokens, which can lead to runaway or cost-incurring prompts.  
**Suggested fix:**  
Include the camel-case key when picking:
```php
'stop' => $this->pick($options, 'stop', 'stopSequences') 
           ?? $bound['stop'] 
           ?? $this->stopSequences,
```
Optionally, extend `normaliseKeys()` so that either `stopSequences` or `stop_sequences` is normalized to a single canonical key before `pick()` is applied.