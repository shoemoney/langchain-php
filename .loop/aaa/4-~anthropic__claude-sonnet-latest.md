# Triple-A cycle 4 - ~anthropic/claude-sonnet-latest
_asked 2026-10-01T05:30:21 - 30s - max_tokens 24,000_

## 1. `batch()` accepts `returnExceptions` and then ignores it, so one bad input discards every other result
**Severity:** MAJOR
**Where:** `src/LangChain/Runnables/RunnableInterface.php` (the `batch()` declaration and its docblock). The brief does not show the line number.
**Input → Output:** `$chain->batch([$ok1, $bad, $ok3], null, ['returnExceptions' => true])` throws the exception from `$bad`. The results already computed for `$ok1` and `$ok3` are lost.
**Should be:** `[result1, <Throwable>, result3]`. Upstream's `batchOptions.returnExceptions` collects errors per item.
**Why it outs the port:** The option is accepted in the signature but does nothing. The ledger justifies the omission by saying `maxConcurrency` has no meaning in synchronous PHP, but that reason does not apply to `returnExceptions`. A caller who sets it expecting partial-failure semantics gets all-or-nothing, with no warning at the call site. This is fixable with a try/catch per item.

## 2. Anthropic block content is passed unfiltered into OpenAI requests, so a cross-provider history is rejected
**Severity:** MAJOR
**Where:** `src/LangChain/LanguageModels/Chat/OpenAI/Utils/Completions.php`, in `convertMessage()`. The brief does not show the line number.
**Input → Output:** A history containing an `AIMessage` produced by `ChatAnthropic` with `content: [{type:'thinking',…},{type:'text',…}]` is sent to `ChatOpenAI`. `Anthropic\Utils\MessageOutputs::contentOf()` now keeps the block array whenever the reply is not exactly one text block, and `convertMessage()` forwards it verbatim, so a strict OpenAI-compatible endpoint answers 400.
**Should be:** `tool_use`, `thinking` and `reasoning` blocks are dropped on the way out, as upstream does, with the text blocks kept (or collapsed to a string).
**Why it outs the port:** The ledger's reason for not filtering is that those blocks only arise from the unported `output_version: v1`. Because `contentOf()` now emits non-text blocks, the premise no longer holds for this port's own Anthropic output. Switching providers, or falling back via `withFallbacks`, mid-conversation fails at the provider. The consumer has to write the filter themselves.

## 3. Run name on a directly bound model is reported as still not reaching `Run::name()`
**Severity:** MINOR
**Where:** `src/LangChain/LanguageModels/BaseChatModel.php:303` and `:408`
**Input → Output:** `$model->bind([], ['runName' => 'pull_person'])->invoke($x)`. The model reads `$config->runName` at those two lines, but the project's own measurement says the traced run is still named after the component id (`ChatOpenAI`). This claim comes from the project's measurement, not from source shown in this brief.
**Should be:** The run is named `pull_person`.
**Why it outs the port:** `Run::name()` falls back to the serialized id and then `runType`, so a missing name still looks like a plausible one. A user who names runs for trace filtering gets a wrong label with no error. A pinning test should assert `Run::name()` on the captured run, not only the bound config.

## 4. (Structural) Two `Checkpoint` classes with the same job
**Severity:** MINOR
**Where:** `src/LangGraph/Checkpoint/Serde/JsonPlusEncoder.php:130` (the `instanceof` check), plus `src/LangGraph/Checkpoint/Checkpoint.php` and `src/LangGraph/Pregel/Checkpoint/Checkpoint.php`
**Input → Output:** The encoder's `instanceof` once matched only one of the two classes, and the empty-map fix missed the class the engine actually writes. The `instanceof` now has to list both classes to stay correct.
**Should be:** One `Checkpoint` type, or a shared interface or trait that carries `toArray()`.
**Why it outs the port:** Any third checkpoint-shaped class, or a future change to one class's `toArray()`, silently reintroduces `channelVersions: []` on a first-ever write. The failure is on the wire, where only a JS reader notices.