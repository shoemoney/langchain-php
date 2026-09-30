# Review 6 - sakana/fugu-ultra-v2
_asked 2026-09-29T09:18:41 - served by sakana/fugu-ultra-v2 - 612s_

Five findings, ordered by severity. Three notes on method before the list:

- The packet ships no line numbers, so every citation is **file + method + the verbatim line** I read. Nothing below is cited to a line number I did not see.
- Where a claim depends on a provider's wire format or on upstream TypeScript I did not read in this packet, I say **[spec/upstream recollection — verify]** inline. Everything else is readable in the packet as given.
- I did not read `MessageMerge`, `SseParser`, `Tools.php`, `RunnableBinding`, or any test file (truncated), so nothing below depends on them.

---

## 1. `content_block_start` is read from `$event['content']`, not `content_block` — streamed Anthropic tool calls lose their id and name

**Severity:** BLOCKER

**Evidence:** `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageOutputs.php`, `eventToChunk()`:

```php
if ($type === 'content_block_start') {
    $block = $event['content'] ?? [];
```

Two corroborating reads in the same file, both packet-readable: the sibling branch two blocks down reads `$event['delta']` for `content_block_delta`, and `usageFromEvent()` reads `$event['message']['usage']` for `message_start` — so this file otherwise knows that each event nests its payload under an event-specific key. `content` is the key used by `blocks()` on the **non-streaming** path (`$payload['content']`), which is where this line looks copy-pasted from. The Anthropic event field is `content_block` **[spec/upstream recollection — verify against one captured frame; upstream `utils/message_outputs.ts` reads `data.content_block`]**.

**Why it matters:** `$block` is always `[]`, so both `($block['type'] ?? null)` tests fail and every `content_block_start` yields `null` — silently. Text survives, because text arrives via `content_block_delta` / `text_delta`. But a streamed tool call's `id` and `name` arrive **only** on `content_block_start`; the subsequent `input_json_delta` frames carry `index` and `args` only. The folded `AIMessageChunk` therefore has accumulated arguments with `name = null` and `id = null`: the caller gets a tool call it cannot dispatch and cannot answer, because a `ToolMessage` needs the `tool_use_id`. Nothing throws. This is the exact shape of the `FakeStreamingChatModel` → `$chunk->toolCalls` defect in your own ledger — a read that returns null forever. It also implies the 25 `ChatAnthropic` tests contain no assertion on a *streamed* tool call's identity, or contain a fixture that encodes the wrong key.

**Suggested fix:** `$block = $event['content_block'] ?? [];`. Then grep `tests/Unit/LanguageModels/Chat/Anthropic` for `content_block_start` fixtures — if any of them nests the block under `content`, the fixture is encoding the defect and must be fixed in the same commit. Add a regression that feeds the real four-frame sequence (`content_block_start` with a `tool_use` block, two `input_json_delta` frames splitting `{"city":"NYC"}` mid-token, `content_block_stop`), folds the chunks, and asserts the resulting tool call's `id`, `name`, and decoded `args`.

---

## 2. `generateMessages()` sends every prompt in a batch through run manager 0, and never calls `handleLLMError`

**Severity:** BLOCKER

**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php`, `generateMessages()`. Four readable lines, in this order:

```php
$runManager = $runManagers[0] ?? null;          // resolved ONCE, before the loop
...
$result = $this->dispatchGenerate($messages, $options, $runManager, $config);   // always [0]
$this->stampMessageId($generation, $runManager);                                // always [0]
$runManagers[$index]?->handleLLMEnd(...);                                       // but [$index] here
```

There is no `try`/`catch` anywhere in the method. `stream()`, twenty lines below in the same file, does have one: `catch (\Throwable $e) { $runManager?->handleLLMError($e); throw $e; }`. The start payload also hard-codes `'batch_size' => 1` regardless of `count($messageLists)`.

**Why it matters:** Three concrete failures.

1. **Duplicate message ids across a batch.** `stampMessageId()` writes `'run-' . $runManager->runId` using run 0 for every prompt, so two distinct assistant messages in one `generateMessages()` call carry the *same* id. A message id is what a later turn points at; this is a wrong value written into returned data, not just a trace artefact. It is also self-evidently a slip, because `handleLLMEnd` two lines later uses `$runManagers[$index]`.
2. **Every failed non-streaming call leaks an open run.** `handleChatModelStart` has already created the run; the exception from `ChatOpenAI::post()` (a 429 that exhausted `maxRetries` — your most likely real failure) propagates with neither `handleLLMEnd` nor `handleLLMError`. `RunCollectorCallbackHandler` never sees it; `LangChainTracer` retains it forever. A test asserting "invoke throws" is satisfied by the throw and sees none of this.
3. **`handleLLMNewToken` misattribution.** Prompt N's token callbacks are dispatched to run 0's manager.

Additionally, and flagged as **inference**: `$runManagers[$index]?->` is an unguarded array offset. The author knew `$runManagers` can be null — the line above writes `array_map(..., $runManagers ?? [])`, and `$runManagers[0] ?? null` suppresses it too. If `configureCallbacks()` can return null (which that `?? []` asserts it can), then `generateMessages()` with no callbacks emits "Trying to access array offset on value of type null" on every prompt — a PHP warning, which under `failOnWarning="true"` means **no test exercises that path**. Confirm by calling `generateMessages([[...]])` with `$config = null`.

**Suggested fix:** Inside the loop, resolve `$manager = $runManagers[$index] ?? null;` once and use it for `dispatchGenerate()`, `stampMessageId()`, and `handleLLMEnd()`. Wrap the generation:

```php
try {
    $result = $this->dispatchGenerate($messages, $options, $manager, $config);
} catch (\Throwable $e) {
    $manager?->handleLLMError($e);
    throw $e;
}
```

Set `'batch_size' => count($messageLists)`. Two regressions: (a) two message lists through `generateMessages()`, assert the two returned messages have **different** ids; (b) a chat model whose `generate()` throws plus a `RunCollectorCallbackHandler`, assert the collected run carries the error and no run is left open. Note upstream's `_generateUncached` uses `Promise.allSettled` and calls `runManagers?.[i]?.handleLLMError(reason)` per rejected result **[upstream recollection — verify]**; the PHP-side asymmetry between `stream()` and `generateMessages()` stands on its own regardless.

---

## 3. `combineLLMOutput()` discards every prompt's token usage, and carries the wrong docblock

**Severity:** MAJOR

**Evidence:** `src/LangChain/LanguageModels/BaseChatModel.php`. `generateMessages()` accumulates `$llmOutputs[] = $result->llmOutput;` and returns `new LLMResult($generations, $this->combineLLMOutput($llmOutputs), $runIds)`. The method it calls is:

```php
/**
 * Whether any attached handler asked for streamed chunks.
 *
 * @param array<string, mixed> $llmOutputs
 * @return array<string, mixed>
 */
protected function combineLLMOutput(array $llmOutputs): array
{
    return [];
}
```

Two things are readable there: the summary line is `handlerPrefersStreaming()`'s docblock, pasted onto the wrong method; and the body unconditionally returns `[]`. Neither `ChatOpenAI` nor `ChatAnthropic` — both shown in full in the packet — overrides it, though both `generate()` methods compute `$this->llmOutputFromUsage(...)`. Ten lines above, a comment asserts: *"The combined output is only what the returned LLMResult carries."* It carries nothing.

**Why it matters:** `generatePrompt()` and `generateMessages()` — the two public batch entry points — always report `llmOutput = []`, even for a single prompt whose `ChatResult` carried authoritative `tokenUsage`. The value is computed, stored in `$llmOutputs`, and thrown away one line later: precisely the "wrote a value nothing reads" class that dominates your ledger, except here the consumer is cost accounting. A caller summing `$result->llmOutput['tokenUsage']` gets zero and cannot tell that from a provider that reported no usage. The per-run `handleLLMEnd` still gets its own output, so a tracer looks fine while the returned result is empty — which is why a green suite misses it.

A fidelity note so you scope this correctly: upstream's base `_combineLLMOutput` is **optional** and yields `undefined` when absent, so an empty base is roughly faithful. The gap is that upstream's `ChatOpenAI` *does* implement it to sum `tokenUsage` **[upstream recollection — verify against `@langchain/openai`]**. So this is a missing port of a named upstream override plus a live doc contradiction, not an invented feature.

**Suggested fix:** Fix the docblock first (one line, and it is currently a lie in a file whose comments are otherwise load-bearing). Then override `combineLLMOutput()` on `ChatOpenAI` and `ChatAnthropic` to sum `tokenUsage.promptTokens` / `completionTokens` / `totalTokens` across the non-empty outputs, skipping nulls, and returning `[]` when every input was empty. Test: two `ChatResult`s with distinct usage through `generateMessages()`, assert the returned `LLMResult->llmOutput['tokenUsage']` equals the sum, and assert a single-prompt call round-trips its usage unchanged.

---

## 4. `ChatOpenAI` normalises keys onto names its `invocationParams()` never reads — `stopSequences` is the live one

**Severity:** MAJOR

**Evidence:** `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php`. `normaliseKeys()` contains `'stop_sequences' => 'stopSequences',`. `invocationParams()` then builds the field as:

```php
'stop' => $this->pick($options, 'stop') ?? $bound['stop'] ?? $this->stopSequences,
```

`stopSequences` appears nowhere on either the `$options` or the `$bound` side. The constructor sets the property from `$fields['stop']` only (`isset($fields['stop']) ? array_values((array) $fields['stop']) : ...`), and the `kwargs` whitelist lists `'stop'` and not `'stopSequences'`. The proof that this is an omission, not a choice, is one file over: `ChatAnthropic::invocationParams()` has the same map entry **and** reads `?? $bound['stopSequences']`, and its constructor reads `$fields['stopSequences']`.

**Why it matters:** Four routes that look supported and are silently dropped:

- `bind(['stop_sequences' => ["\n\n"]])` → normalised to `stopSequences` → never read. Request goes out with no `stop`; the model runs past the boundary and the extra text reaches the downstream parser. No error.
- `bindTools($t, ['stopSequences' => [...]])` → same.
- `new ChatOpenAI(['stopSequences' => ["\n"]])` → property unset **and** absent from `kwargs`, so it vanishes from the request *and* from the trace.
- Per-call `['stopSequences' => [...]]` → `pick($options, 'stop')` does not accept it, so the per-call layer disagrees with the normalisation map that just ran over it.

The same class of dead-bound-key survives on four more OpenAI whitelist entries — `organization`, `streamUsage`, `timeout`, `maxRetries` are all recorded into `kwargs` (so they appear in every serialized trace) and are read **only** from `$this->…` in `invocationParams()` / `post()` / `postStream()`, never from `$bound` or `$options`. `bind(['streamUsage' => false])` is therefore reported and ignored — literally the Anthropic dead-flag bug from your ledger, still alive on OpenAI's bound layer. (`'top_k' => 'topK'` in the OpenAI map is dead in a harmless way: OpenAI has no `top_k` parameter and the class has no `topK` property.)

**Suggested fix:** In `invocationParams()`:
`'stop' => $this->pick($options, 'stop', 'stopSequences', 'stop_sequences') ?? $bound['stop'] ?? $bound['stopSequences'] ?? $this->stopSequences,`
Accept `$fields['stopSequences'] ?? $fields['stop']` in the constructor and add `'stopSequences'` to the whitelist. Separately, either read `organization` / `streamUsage` / `timeout` / `maxRetries` from the bound layer (the `stream_options.include_usage` gate in particular should consult `$bound['streamUsage']` before `$this->streamUsage`) or remove them from the whitelist so the trace stops claiming a bind was honoured. Per HANDOFF §4, test by asserting the **recorded request body** for all three arrival routes: constructor, `bind()`, per-call.

---

## 5. A non-leading `SystemMessage` is sent to Anthropic as `role: system`, and the file's own docblock says it works

**Severity:** BLOCKER (on the false-claim ground)

**Evidence:** `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageInputs.php`. `convert()` hoists only the **leading run**:

```php
$leading = 0;
while ($leading < count($merged) && $merged[$leading] instanceof \LangChain\Messages\SystemMessage) {
    $leading++;
}
```

Everything from `$leading` onward goes to `convertMessage()`, whose role map is:

```php
'human', 'tool' => 'user',
'ai' => 'assistant',
'system' => 'system',
```

The class docblock at the top of the same file contradicts itself in one paragraph: *"**There is no `system` role.** A system message is a top-level request parameter, not a turn"* — and then, two clauses later, *"a system message later in the conversation keeps its position and is sent as a `system` turn, which the provider applies from that point on."* Anthropic's `messages[].role` accepts only `user` and `assistant`; upstream throws `"System messages are only permitted as the first passed message."` **[spec/upstream recollection — verify; the self-contradiction inside the docblock is packet-readable regardless]**.

**Why it matters:** Any transcript with a system message after a user or assistant turn — a mid-conversation instruction refresh, or a re-serialized LangGraph message channel that appended one — serialises into `messages` with `role: system` and the provider rejects the whole request. The `role => 'system'` arm exists precisely so that this path does not throw locally, so the failure surfaces as a provider 400 with the transcript already on the wire, and the documented behaviour tells the next maintainer the provider handles it. The comment is a documented guarantee that is false — the same defect shape as `ValueSet::key()` in your ledger.

**Suggested fix:** After computing `$leading`, scan the remainder for any `SystemMessage` and throw `\InvalidArgumentException('System messages are only permitted as the first passed message.')` — matching upstream, and failing before HTTP rather than after. Delete the `'system' => 'system'` arm from `convertMessage()`'s match so the fallback `default =>` throw becomes the only outcome, and fix the class docblock to say system messages must lead. Do **not** silently hoist a late system message: that reorders the caller's transcript, which is a different wrong answer. Test: a `[system, human, system, human]` list asserts the throw, and the message names the offending position.

---

**Considered and not ranked, listed so you know they were weighed rather than missed** (each would need its own round; I am not asking you to act on them):
`MessageOutputs::responseToMessage()`'s `$additionalKwargs[$type ?? 'unknown'] = $block` keys non-text blocks by type, so two `thinking` or two `server_tool_use` blocks in one response silently overwrite — last wins — and the block ordering relative to text is unrecoverable; `usageFromEvent()` fires on *any* event carrying a `usage` key and `message_delta`'s `output_tokens` may be cumulative rather than incremental, in which case the documented "summed by the chunk fold" over-reports every streamed Anthropic call (one assertion settles it); and `ChatAnthropic::$defaultHeaders` is docblocked `list<array<string, mixed>>` while `headers()` uses it as `array<string, string>` in a `+` union whose **left** side wins, letting a caller override the `anthropic-version` the class docblock calls "pinned, not configurable-by-default".