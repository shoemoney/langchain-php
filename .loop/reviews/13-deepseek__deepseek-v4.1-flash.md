# Review 13 - deepseek/deepseek-v4.1-flash (TRUNCATED)
_finish_reason=length, reasoning 24000/24000_

Let me analyze this carefully. The instruction is to find five real, specific defects. The trap warnings tell me not to re-report things in HANDOFF.md's bug table or PORT_STATUS.md's known non-exact behaviours table.

Let me look at the source carefully for real issues.

1. **Completions::convertMessage** — for `AIMessage` with tool calls: `$message->additional_kwargs['tool_calls']` is checked as a fallback when `toolCalls` is empty. But the port also uses `invalid_tool_calls` — when a tool call fails to parse, it's stored in `invalid_tool_calls`, not `tool_calls`. Looking at `choiceToMessage`: `$additionalKwargs = ['tool_calls' => $rawToolCalls !== [] ? $rawToolCalls : null]`. So when the model returns tool calls that don't parse, `toolCalls` is empty but `invalid_tool_calls` is non-empty AND `additional_kwargs['tool_calls']` holds the raw calls. Then on the way back out, `elseif (isset($message->additional_kwargs['tool_calls']))` sends the raw tool_calls back. That's actually intentional upstream behavior. OK.

2. **StructuredOutput::assembleStructuredOutputPipeline** — `$result->bind([], ['run_name' => $runName])`. This binds empty kwargs and a config with `run_name`. But wait — looking at the bind signature: `bind(array $kwargs = [], ?array $config = null)`. So this passes the run_name in the config. Fine.

3. **Runnable::map()** — `return new RunnableParallel([]);` — this ignores `$this` entirely! A `.map()` should probably wrap `$this` in a parallel? Let me check upstream. In LangChain JS, `Runnable.map()` returns a `RunnableMap` with... hmm, actually I recall `map()` in JS is a weird one. Let me think. In upstream `Runnable.map()` it's: `map(): RunnableMap<RunInput, RunOutput> { return new RunnableMap({ steps: { input: this } }); }` or similar. Actually I think it's `new RunnableMap({ steps: { input: this } })`. The port returns an empty parallel with NO steps and ignores `$this`. That's a real bug — the mapped runnable is dropped.

This is definitely a real bug worth flagging. The port returns `new RunnableParallel([])` — with no reference to `$this`.

4. **ChatAnthropic** extra kwargs: `$this->kwargs = array_intersect_key($fields, array_flip([...]))` — the list includes `'tools'` and `'toolChoice'`. Wait, but `bindTools` writes to `kwargs['tools']`. If a constructor passes `tools` in fields, that also gets picked up. OK. But note: it says `toolChoice` is in the whitelist. But then in `invocationParams`, `'tool_choice' => $this->toolChoiceOf($options) ?? $this->toolChoiceOf($bound)`. And `toolChoiceOf` reads `$options['toolChoice'] ?? $options['tool_choice']`. But note that `toolChoiceOf` is called with the argument pattern `toolChoiceOf(array $options)`. Bound is passed as `$this->toolChoiceOf($bound)`. Then it reads `$bound['toolChoice']` — yes that would be the camelCase version. OK, seems fine.

5. **ChatOpenAI::invocationParams** — `'tool_choice' => $this->toolChoiceOf($options) ?? $this->formatBoundToolChoice($bound)` — reads both correctly. OK.

Wait, in `normaliseKeys` for `ChatOpenAI`, the map includes `'tool_choice' => 'toolChoice'`. So if `$bound` has `tool_choice` (wire), it's normalized to `toolChoice`. Then `formatBoundToolChoice` reads `$bound['toolChoice'] ?? $bound['tool_choice']` — the second option is dead code but harmless.

6. Let me look at the produce for `MessageInputs::flattenToBlocks`. The docblock says "Block content is spread in as-is" and the code does `foreach ($message->content as $block)` for array content. When content is an array but not a list of blocks — say the content is `['text' => 'hello']`? Actually content should be string or list of blocks. Hmm. Let me not chase that.

Wait — `StringMessage` content — what if a system message has `content` as an array but not block-shape? Probably not an issue.

7. **MessageInputs::foldToolMessages** — modifies `$previous->content` directly: `$previous->content[] = $block;`. But `$previous` is a `HumanMessage` and `$previous->content` was set to `[$block]` — a list. So `[]=` appends. But is `HumanMessage` immutable? It seems to be mutable (the code sets it). OK, fine.

But wait — `$out[] = new HumanMessage(['content' => [$block]]);` then later `$previous->content[] = $block;`. If HumanMessage's content is a readonly property or validated to be one type... The code directly mutates it. Assuming it's mutable. But this could be a real issue if messages are supposed to be immutable. Actually the upstream in JS mutates too I think. Let me not focus on that.

8. **AIMessageChunk extension** — the HANDOFF mentions `AIMessageChunk extends AIMessage` was a bug that was fixed. Let me check what it extends now. We don't have that file in the packet.

9. **ChatOpenAI::generate** — `'stop' => $this->pick($options, 'stop', 'stopSequences', 'stop_sequences') ?? $bound['stopSequences'] ?? $bound['stop'] ?? $this->stopSequences`. Note `$bound['stop']` — but `normaliseKeys` maps `stop => stopSequences`, and only sets `$bag['stopSequences']` if not already present. So `$bound` might still have both. OK.

10. **ChatOpenAI** — `$this->kwargs = array_filter($this->kwargs, ...)` filters out null. But it's `array_filter` without a key-preserving callback for array keys — actually `array_filter` preserves keys by default. OK.

11. **ChatOpenAI::bindTools** — `$strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling;`. So strict defaults to the property value. Then in the loop below: `foreach ($kwargs as $key => $value) { if ($key === 'tools') { continue; } $next->kwargs[$key] = $value; }`. But `strict` is not excluded! So `strict` gets copied into `$next->kwargs['strict']`. Then in `invocationParams`, `$bound['strict']` is not read anywhere (only `$options['strict']` is read via `pick($options, 'strict')`... wait, `pick` is used to gather options but not for strict). Actually `strict` is not in `invocationParams` at all. That's fine — strict affects tool conversion, not the wire. But this leaks `strict` into kwargs which then serializes into traces. Minor.

Wait — actually there's a bigger issue. `ChatAnthropic::bindTools` explicitly excludes `strict` from the loop: `if ($key === 'tools' || $key === 'strict') continue;`. But `ChatOpenAI::bindTools` does NOT exclude `strict`. This is an asymmetry. Is it a bug? The `strict` key would end up in `kwargs`, which is serialized. It's minor at most.

12. Let me look at `class ChatOpenAI` — `$this->kwargs = array_intersect_key($fields, array_flip([...]))`. Notice `'org'`? No. Notice `'streamUsage'` is included. That's the config-tracing behavior. Fine.

13. **ChatOpenAI::post** — the retry `$attempt++ >= $this->maxRetries`. With maxRetries=2, initial attempt fails -> `$attempt++` returns 0 (post-increment), 0 >= 2 is false, so we don't throw. Retry. Then fails -> `$attempt++` returns 1, 1 >= 2 is false. Retry. Then fails -> `$attempt++` returns 2, 2 >= 2 is true, throw. So we make 3 total attempts with maxRetries=2. That means maxRetries is really "max additional retries". That's fine.

Actually wait — for HTTP status: `$retryable && $attempt++ < $this->maxRetries`. Similar semantics. OK, consistent.

14. **ChatOpenAI::streamResponseChunks** — the usage-only chunk yields an AIMessageChunk with `content => ''` and `id` from payload. Then goes to `handleLLMNewToken`? No, that's separate. Actually look at `streamResponseChunks`, this yields `ChatGenerationChunk`. The base class `stream()` aggregates them. But `llmOutputFromUsage` is only used in non-streaming path? Let me check the base's `stream()` method... Actually the base class calls `$this->llmOutputFromUsage($aggregated->message)`. So usage is accounted for.

But there's a subtle issue: this usage-only chunk yields with `''`. This will be concatenated into the aggregate. And the merge should handle that. The usage metadata is under `response_metadata['usage_metadata']`. But wait — `Completions::responseMetadata` puts usage under `usage` AND `usage_metadata` when present. But in the streaming usage-only chunk, the code manually constructs:
```php
new \LangChain\Messages\AIMessageChunk([
    'content' => '',
    'id' => $payload['id'] ?? null,
    'response_metadata' => Completions::responseMetadata($payload),
])
```
`Completions::responseMetadata` reads `$rawResponse['usage']` — for the usage-only stream chunk, is `usage` present at top-level? Yes, the payload includes `usage`. So `responseMetadata` will produce `usage` and `usage_metadata`. OK.

15. **The `Completions::deltaToChunk`** builds `additional_kwargs` with `['completion_index' => $index]`. Then filters out nulls. Fine.

16. **Now let me focus**: `Runnable::map()` returning `new RunnableParallel([])` — this is highly suspicious. Let me look at upstream TS. In JS, `Runnable` has this signature:
```ts
map(): RunnableMap<RunInput, RunOutput> {
    return new RunnableMap({ steps: { input: this } });
}
```
Hmm, actually I'm not 100% sure. Let me think again. In LangChain JS Runnable class, there is a `.map()` method... actually I don't think `Runnable` has a `.map()`. Let me check. In the TS `Runnable` class, I don't recall a `.map()` method. Hmm. Actually maybe the port invented it? Let me not assume. If the port's `Runnable::map()` returns an empty parallel, and if this is called anywhere, it would essentially produce nothing. But if `.map()` isn't in upstream at all, it might be an invented API.

Wait, actually in LangChain JS there is `RunnableMap` and I believe `Runnable.map()` exists... hmm. Actually I think `Runnable.map()` may not exist. Let me not report this if uncertain — actually I'll report it as a possible defect since ignoring `$this` makes no sense for a `.map()` on a runnable. Even if upstream's `.map()` doesn't exist, having the method silently no-op is a bug. Actually let me look for a stronger read.

Hmm. I recall `Runnable.map` exists in some version of LCEL. Actually, hmm. Let me look at what a `.map()` on a runnable would do. In upstream, I believe `Runnable.map()` returns a runnable that "maps" -- actually I'm not sure. Let me flag it as a candidate but with some hedging.

Actually wait — the pattern is: several composition methods in the base class return compositional wrappers. `pipe()`, `pipeTo()`, `bind()`, `map()`, `withFallbacks()`. These all reference `$this`. `map()` doesn't. That's clearly wrong—either `map()` should reference `$this` or it shouldn't exist on the base.

Let me look at upstream more carefully by pattern. `RunnableMap` in JS takes a `steps` object mapping names to Runnables. `Runnable.map()` probably returns `new RunnableMap({ input: this })`. So the port's `new RunnableParallel([])` (empty) is a real bug — it drops the runnable.

This is my #1 finding.

17. **The `ToolMessage` role in OpenAI** — the port's `roleOf` maps 'tool' to 'tool'. Fine.

18. **`ChatAnthropic::headers`** — `return $this->defaultHeaders + [ 'x-api-key' => ..., ...];` — the `+` operator means if defaultHeaders already has `x-api-key`, the default user-supplied is used. Actually `+` union: left side wins, so defaultHeaders overrides. Hmm, that means a user could override the API key by putting it in defaultHeaders. Probably intentional. Fine.

19. **Let me look at `invocationParams` in ChatOpenAI**: `'stop' => $this->pick(...) ?? $bound['stopSequences'] ?? $bound['stop'] ?? $this->stopSequences`. If `$bound` contains `stop` (wire key) but `normaliseKeys` mapped it to `stopSequences` — wait, `normaliseKeys` sets `$bag['stopSequences']` but does NOT unset `$bag['stop']`. So both may be present. Fine.

20. **MessageInputs::foldToolMessages**: The tool message additional_kwargs['status'] check. `if (is_string($status) && $status !== '' && $status !== 'success')`. What about `'error'`? Sets is_error true. Fine. What about `'error'` vs `'failed'`? OK.

Now let me consider important issues around message passing.

21. **`Completions::choiceToMessage`** sets `additional_kwargs['tool_calls'] => $rawToolCalls`. So a NON-streamed response with tool_calls puts the raw OpenAI tool calls in `additional_kwargs`. Then on the way out, if `$message->toolCalls` is non-empty, we re-encode them via `toolCallToWire`. But if the model emitted tool calls that failed to parse (invalid), `toolCalls` is empty but `additional_kwargs['tool_calls']` has the raw. So the way out: `elseif (isset($message->additional_kwargs['tool_calls'])) { $param['tool_calls'] = $message->additional_kwargs['tool_calls']; }`. So the raw calls go back out as-is. That's actually the right behavior — invalid calls sent back to a model would let it self-correct. Good.

But wait, there's a subtle issue: even for VALID tool calls, additional_kwargs['tool_calls'] is set (the raw ones). On the next iteration, `toolCalls !== []` so the elseif is skipped and the re-encoded ones are used. Both paths work. OK.

22. **Completions::choiceToMessage** — the raw tool calls are also stored in `additional_kwargs`. Then when this AIMessage is re-serialized (e.g., checkpointing), the raw is propagated. Fine, but this doubles the message size. Upstream does the same.

23. Let me look at `ChatOpenAI::generate`. `$params['messages'] = Completions::convertMessages($messages)`. Then calls `$this->post($params)`. If the response has choices but no usable ones, second exception. OK.

Then `$generationInfo = ['finish_reason' => ...]`. Fine.

24. Now let's look at **the base `stream()` in `BaseChatModel`**. `$aggregated->concat($chunk)`. This is `ChatGenerationChunk::concat()`. Fine.

But note — the yielded value is `$chunk->message` (an `AIMessageChunk`). And the caller of `stream()` in LangChain semantics expects either `(chunk)->content` to work or for the chunk to be an AIMessageChunk.

Wait — one issue: the `usage`-only chunk from OpenAI streaming — it's yielded as an AIMessageChunk with empty content. But the base's stream yields it as `[CHANNEL_DEFAULT, $chunk->message]`. So the caller sees an empty chunk with usage metadata. That's upstream behavior too. OK.

25. **The `handlerPrefersStreaming` path in the base.** If a handler prefers streaming, invoke() takes the streaming path and drains — passing chunks to handlers via `streamResponseChunks`. But wait: does the base's `generate()` also call handlers? The chunks are passed to `$runManager->handleLLMNewToken()`. That's in the provider's `streamResponseChunks`. So yes, handlers get tokens. OK.

26. Now let me look at the OpenAI streaming `streamResponseChunks` again: after yielding the chunk, it calls `$runManager?->handleLLMNewToken($text, ['chunk' => $chunk])`. Good.

And the base `stream()` marks `$this->stampMessageId($chunk, $runManager)` after each chunk. OK.

27. **MessageInputs::convertMessage** — when `$message instanceof AIMessage && $message->toolCalls !== []`, it returns content blocks with tool_use. But then if `$message->content` is already an array (blocks from prior assistant turn), `self::textBlocks($message->content)` — that only handles string content! `is_string($content) && $content !== ''`. So an AIMessage with block-content (e.g., from v1 output) plus toolCalls would drop the block content. Since v1 is not ported, mostly moot. But it's a divergence.

Actually wait — this could happen even without v1 output: what if the user constructs an AIMessage with content as blocks? Then converting to Anthropic would drop the content. Hmm. This is a real edge case but might be considered out of scope given the current state.

Let me consider as a candidate finding but maybe not top-5.

28. **ChatAnthropic::streamResponseChunks** — retries. But `$this->maxRetries` may be 0. Then `$attempt++ < 0` — `$attempt++` returns 0, 0 < 0 is false, so no retry, throw. Fine.

But wait — in `postStream()` (Anthropic version), the code is inline in streamResponseChunks, and there's no separate postStream method. Wait, but the OpenAI client has a `postStream` private method and the Anthropic client has the logic inline. That's fine.

29. **The `Chunk` and `id`** — the usage-only chunk for OpenAI sets `'id' => $payload['id'] ?? null`. This yields a chunk. But the base `stream()` will call `stampMessageId` — if `id` is null it stamps. If the payload doesn't have id, we get a run-derived id. Fine.

30. **`JsonOutputKeyToolsParser::parseResult` returns null for no tool call.** The comment says upstream returns undefined. OK.

31. Let me look for the specific issue about `openai` and `Anthropic::streamUsage` gating. In ChatAnthropic, `streamUsage` gates whether usage chunks are emitted:

```php
$usage = $this->streamUsage ? MessageOutputs::usageFromEvent($event) : [];
```

But wait — this gates the *emission* of usage chunks, but the underlying stream from Anthropic ALWAYS contains `message_start` with input tokens and `message_delta` with output tokens. So gating here means: when disabled, no usage chunk is yielded. But does this affect handleLLMNewToken? No. Does it affect message_id? No. OK. That's fine.

BUT — hmm, looking at upstream, the Anthropic `streamUsage` option might not gate emission but rather gate *include_usage* in the request (like OpenAI's `stream_options.include_usage`). Since Anthropic always includes usage in its stream, there's no request-side option. So gating on emission is the only thing upstream could be doing. Wait, but does upstream gate emission? Let me think. In `@langchain/anthropic`, I believe `streamUsage` defaults to true and... hmm. I don't remember. Let me not chase this.

32. **`ChatAnthropic::invocationParams`** — `'tool_choice' => $this->toolChoiceOf($options) ?? $this->toolChoiceOf($bound)`. Both calls are to the same method. But `$bound` is the normalized `$this->kwargs`. `toolChoiceOf` reads `$options['toolChoice'] ?? $options['tool_choice']`. So if the bound kwargs has `toolChoice`, it's read. Good.

But wait — is there a bug: `bindTools($tools, ['tool_choice' => 'required'])`. Then `bindTools` copies the `$kwargs` to `$next->kwargs`. So `$next->kwargs['tool_choice'] = 'required'`. Then in `invocationParams`, `normaliseKeys($this->kwargs)` maps `tool_choice` to `toolChoice` (since toolChoice is not already present). So `$bound['toolChoice'] = 'required'`. Then `toolChoiceOf($bound)` returns `['type' => 'tool', 'name' => 'required']` — hmm, that's wrong for Anthropic: 'required' should map to something else... but wait, Anthropic doesn't have 'required' as a tool_choice keyword — it has 'any', 'none', 'auto', or a specific tool name.

Look at `formatToolChoice`:
```php
return match ($toolChoice) {
    'auto' => ['type' => 'auto'],
    'any' => ['type' => 'any'],
    'none' => ['type' => 'none'],
    default => ['type' => 'tool', 'name' => $toolChoice],
};
```

So `'required'` becomes `['type' => 'tool', 'name' => 'required']`. Then in `invocationParams`, the check for `($choice['type'] ?? null) === 'tool'` — it would check if 'required' is in `$names` and throw! So `bindTools($tools, ['tool_choice' => 'required'])` would throw "Anthropic tool_choice references 'required', but that tool is not available."

Hmm — is that a bug? Well, `'required'` is OpenAI semantics; for Anthropic 'any' is the equivalent. If a user passes OpenAI-flavored `'required'`, they'd get an error. Upstream `@langchain/anthropic` has a `formatToolChoice` that maps 'any' (their langchain choice) properly. Let me check: I believe LangChain's unified tool_choice accepts 'auto' | 'any' | 'none' | specific-tool-name. So 'required' isn't a LangChain keyword. If someone passes it, upstream would... also treat it as a tool name? Hmm. Probably similar behavior. Not clearly a bug.

33. **Big one to look at: `MessageInputs::foldToolMessages` mutates `$previous->content` directly.** Let me check what `HumanMessage::$content` looks like. If it's protected with type `string|array`, mutating via `$previous->content[] = ...` works only if content is public and not readonly. Assuming public.

But here's a real subtle bug: what if `$previous` is a `HumanMessage` whose content is set to `[$block]` — a 1-indexed list `[0 => $block]`. Then `$previous->content[] = $block;` appends to index 1. So content becomes `[0 => ..., 1 => ...]`. Good.

But actually — what if the *first* tool message in a fold has a HumanMessage directly before it that is NOT a tool-result user message but a real user message with text content? `is_array($previous->content) && ($previous->content[0]['type'] ?? null) === 'tool_result'` — the check requires the content to be an array with first block type 'tool_result'. A normal text user message has string content, or array with 'text' block. So the check prevents folding into a real user turn. Good.

34. **`MessageInputs::convertMessage`** — for ToolMessage it goes through `foldToolMessages` first, so ToolMessage never reaches convertMessage. Actually `foldToolMessages` returns HumanMessages wrapping tool_result blocks. So convertMessage never sees ToolMessage. Then why does `convertMessage` have `'tool'` in the match? Because `$message->type` for the constructed HumanMessage is 'human', not 'tool'. So the `'tool'` case in the match in `convertMessage` is dead code. Minor.

Actually wait — `foldToolMessages` is called at the top of `convert`, and it returns `$merged` (the folded list). Then `array_slice($merged, $leading)` gets the tail. These are the folded HumanMessages. So indeed ToolMessages never reach convertMessage. The `'tool' => 'user'` case is dead. That's just cleanup, not a bug.

35. Let me now consider what the *strongest* findings are.

Finding A: `Runnable::map()` returns `new RunnableParallel([])` — ignores `$this`. Real bug if `.map()` is meant to compose.

Finding B: `ChatOpenAI::bindTools` doesn't exclude `strict` from copying into kwargs (asymmetry with ChatAnthropic). This leaks `strict` into the serialized kwargs and any invocation that reads `$bound['strict']`... actually `strict` isn't read in invocationParams. It's just cosmetic in traces. Minor.

Finding C: The exception thrown when a tool call fails to parse — `Completions::choiceToMessage` catches `\Throwable`. Then sets `invalid_tool_calls`. Fine.

Finding D: `Runnable::pipeTo($fn)` — constructs a `RunnableLambda` with `['func' => $fn]` and `fn (mixed $input) => $input`. But wait, upstream's `pipeTo` composes: `this.pipe(new RunnableLambda(fn))`. The port creates a RunnableLambda that returns `$input` (identity) and then attaches the function as `func` config. This looks suspicious — does `RunnableLambda` interpret `func` config? Or does the RunnableLambda just ignore it and return identity? If the latter, `pipeTo($fn)` would just return a runnable that returns the input unchanged with a config that's ignored. That would be a bug!

Let me think. `new RunnableLambda(fn (mixed $input): mixed => $input, ['func' => $fn])`. The second argument is presumably "config"/"kwargs" or something. So the RunnableLambda's behavior would be the identity. The `func` is not bound. But `pipeTo` should return a runnable that pipes input through `$this` first, then through `$fn`. The port skips `$this` entirely!

Wait, `pipeTo` is a method ON a runnable. So `runnable->pipeTo($fn)` should pipe `$this`'s output into `$fn`. The port returns `RunnableLambda(identity)` — completely wrong, `$this` is not applied.

Hmm, but wait — maybe `RunnableLambda` accepts a callback plus options and the options has a `func` that gets applied. Let me look at the constructor. `new RunnableLambda(fn (mixed $input): mixed => $input, ['func' => $fn])` — the first argument is a callable that returns `$input`. So this creates a runnable whose function is `fn($input) => $input`. The second arg is `['func' => $fn]` — but if RunnableLambda already has its function (the identity), why pass a 'func' config?

Actually this looks like the port tried to capture the `$fn` inside a config bag and hoped RunnableLambda would use it. If RunnableLambda reads `config['func']`, that's a private convention. The public constructor's second arg is a config bag. Let me reason about how RunnableLambda is likely implemented.

Hmm. Actually — maybe RunnableLambda's signature is `__construct(?callable $func, array $config = [])`. If so, the port's construction is wrong: it creates an identity lambda (first arg) and passes `func` as config, which would not override the callable.

Actually looking at the pattern more: `RunnableLambda(fn (mixed $input): mixed => $input, ['func' => $fn])`. If the first arg is the callable and this is really the identity, then `pipeTo` is broken.

But wait, maybe `RunnableLambda`'s constructor accepts a callable OR a config array where `func` is the callable? Some LangChain implementations support this. Hmm.

Given the packet notes say "RunnableLambda does not pass the config to its callable" — so the base's stream/pipeTo uses RunnableLambda, and its semantics are somewhat known. But we don't have RunnableLambda's source.

Let me hedge on this and possibly include it as a suspicion. Actually the `.map()` finding is stronger because `$this` is dropped and it's returning an empty RunnableParallel, which is trivially wrong.

Hmm, but I realize `pipeTo` was probably written this way because RunnableLambda uses `func` config to change its behavior... Let me think again. Actually I bet the RunnableLambda constructor might take `(?callable $func, array $config = [])` — and the port's calling convention is to pass the function as config so it can be swapped. That's strange but might be the port's actual API.

Actually wait — let me reconsider. The comment in the packet says "`RunnableLambda` does not pass the config to its callable" — meaning the callable only receives the input, not config. So `RunnableLambda` HAS a callable. The base class constructs it with... `fn (mixed $input): mixed => $input`... and separately passes `['func' => $fn]`.

Hmm. What if RunnableLambda's constructor inspects `$config['func']` and uses it as the callable if provided? Then `pipeTo` works. That would be an odd but valid pattern. I can't tell from the packet.

OK, let me lower confidence on pipeTo and focus on `.map()`.

But wait — `.map()` returning `new RunnableParallel([])`. Let me look at RunnableParallel constructor. If its signature is `__construct(array $steps = [])` then passing `[]` gives an empty parallel. So `.map()` returns a runnable that runs zero branches and returns `[]`.

Upstream `Runnable.map()`... Actually I want to double-check upstream existence. Let me look at LangChain JS Runnable. Hmm. I recall `Runnable` has methods like `invoke`, `batch`, `stream`, `pipe`, `withFallbacks`, `withRetry`, `map`, `assign`, `pick`, `bind`, `withConfig`, `withListeners`. `.map()` and `.assign()` return `RunnableMap` / `RunnableAssign`. So yes, `.map()` exists.

Upstream `Runnable.map()`: I believe it's implemented as:
```ts
map(): RunnableMap<RunInput, RunOutput> {
    return new RunnableMap({ steps: { input: this } });
}
```
Hmm, wait — actually the upstream `Runnable.map` might require args. Let me think. Actually I don't remember. But whatever it is, dropping `$this` is definitely wrong.

Hmm, actually let me reconsider. Maybe in upstream, `Runnable.map()` doesn't exist and `RunnableParallel` uses static `RunnableMap`. Let me look at the port's usage: it's declared in the base `Runnable` class right after `.bind()` and before `.withFallbacks()`. It returns `RunnableParallel`.

Actually you know what — I've seen this. `Runnable.map()` in LangChain JS/TS is: `map<K extends string, V>(this, fn)`? No. Hmm. Actually I think upstream `Runnable.map()` might not exist. The port might have added it as a convenience. But even so, a `.map()` that returns an empty parallel and ignores `$this` is nonsense. That's a bug.

Actually wait, I need to be careful. In the `Runnable.assign` method of upstream, it's `new RunnableAssign({ mapper: this })` — actually the port uses `RunnablePassthrough::assign()` which is different. Hmm.

OK, I'll report `Runnable::map()` as a real bug with the caveat that it might be invented.

Actually — hold on. Maybe `Runnable.map()` in this port is a stub waiting for the "broader runnables pass"? The PORT_STATUS lists Runnable `map` under runnables... hmm, the status says "runnables ✅" including `Assign`, `Passthrough`. Not `RunnableMap`. Hmm.

Let me look at the "Known non-exact behaviours" — `RunnableLambda does not pass the config to its callable`. That's documented. And `RunnableBinding`/`RunnableParallel` fixes are documented.

OK. Let me look for other issues.

36. **`Completions::deltaToChunk`** — reads `$delta['tool_calls']` and iterates. But if `tool_calls` is present but not an array, `foreach` on non-array would be an error. Or maybe PHP would just do nothing. Actually `foreach` on a non-array throws a warning in PHP 8? No, `foreach` on a non-array where the expression is not iterable is a warning. Hmm, but if `$delta['tool_calls']` is null, `foreach (null as ...)` is a warning. Actually `foreach` on null is allowed (does nothing, emits warning). Wait, no — `foreach (null as $x)` doesn't throw, it just does nothing in PHP 8. Actually in PHP 7, it emits a warning; in PHP 8, null is not iterable... Let me check. Actually I believe `foreach (null as $x)` in PHP 8 produces no warning (it's a no-op). Hmm, I'm not sure.

Let me check: `$delta['tool_calls'] ?? []` — so if not set, `[]`. But if set to a non-array scalar (say a string), `foreach ('foo' as ...)` — that would be an error? No, I think foreach over a string in PHP 8 is a warning "foreach() argument must be of type array|object". Actually maybe it just does nothing.

Not a strong finding. Skip.

37. **`Completions::convertMessage`** — signature is `public static function convertMessage(BaseMessage $message): array`. When content is an array (block format), it does `$param['content'] = $message->content;` — passes as-is. Then on the way to OpenAI, an array content is sent. OpenAI accepts array content in a "parts" form. OK, but only for user/assistant messages. For system it may also work. Fine.

38. **`ChatOpenAI::generate`** — `$text = Completions::stringifyContent($message->content);`. Then `ChatGeneration($message, $text, $generationInfo)` — the ChatGeneration's `text` is the stringified content. Good.

39. Let me look at **`ChatOpenAI` `invocationParams`** — nothing about `strict`. OK. `bindTools` sets `strict` (from kwargs or the property) implicitly in the tool conversion, but does NOT add it as a param. Good.

Wait — the loop after `Tools::convertAll`: 
```php
foreach ($kwargs as $key => $value) {
    if ($key === 'tools') { continue; }
    $next->kwargs[$key] = $value;
}
```
So `strict` is copied into `$next->kwargs['strict']`. `invocationParams` doesn't read `$bound['strict']`. But wait — it's serialized into `kwargs()`. If `kwargs()` is called for tracing, `strict` appears. Not a functional issue but a documentation/trace cleanliness issue. And `ChatAnthropic` excludes it, so the asymmetry is telling. But this is minor.

40. **`ChatAnthropic::bindTools`** — converts tools, then excludes `tools` and `strict`. Good.

41. **`ChatAnthropic` `convertTool` for a `StructuredTool`** — `$tool->schema->toJsonSchema()`. OK.

42. Now — very important — **`ChatAnthropic::invocationParams` doesn't include `stop_sequences` in the kwargs whitelist for `normaliseKeys`**. Wait: `normaliseKeys` maps `stop_sequences => stopSequences`. And `invocationParams` reads `$bound['stopSequences']`. But `ChatAnthropic::__construct` kwargs whitelist is `[..., 'stopSequences', ...]`. And `bindTools` copies kwargs in. So a `bind(['stop_sequences' => ['x']])` would be normalized to `stopSequences`. Good.

But — `ChatAnthropic::__construct` whitelist: `['model', 'temperature', 'topP', 'topK', 'maxTokens', 'stopSequences', 'tools', 'toolChoice', 'streamUsage', 'maxRetries', 'timeout']`. Wait — this doesn't include `defaultHeaders` or `organization`. But `defaultHeaders` was in the constructor. And `apiKey`, `baseUrl`. Those are intentionally excluded from kwargs() because they're sensitive/transport. But `defaultHeaders`? Hmm, defaultHeaders might contain auth tokens, so it should be excluded from trace. OK.

43. What about `ChatAnthropic::__construct` — `$this->defaultHeaders = (array) ($fields['defaultHeaders'] ?? []);`. If someone passes `defaultHeaders` as a non-array, it becomes an array via cast. Fine.

44. **`ChatAnthropic::headers()`** — `$this->defaultHeaders + ['x-api-key' => ..., ...]`. Note the union operator: for keys in both, LEFT side (`defaultHeaders`) wins. So a caller could override the API key via defaultHeaders. Probably intentional but a potential footgun. Actually wait — a union puts the left side's keys first; when there's a key collision, the left wins. So `defaultHeaders['x-api-key']` overrides the actual API key. Fine, intentional.

45. **Now let me look at `ChatOpenAI::streamResponseChunks`**. The usage-only chunk path: 
```php
if (!is_array($choices) || $choices === []) {
    if (isset($payload['usage']) && is_array($payload['usage'])) {
        yield new ChatGenerationChunk(
            new AIMessageChunk([...]),
            '',
        );
    }
    continue;
}
```

Wait — but the standard OpenAI streaming response includes a `usage` field on EVERY chunk (null on all but the last). Actually no — without `stream_options.include_usage`, the usage is null. With it, the LAST chunk has choices=[] and usage set. So the check `$choices === []` distinguishes the usage-only chunk. Good.

But — what if the payload has BOTH no choices AND no usage? Then continue. Fine.

46. **Let me look for a wrong `id` propagation issue.** The usage-only chunk has `'id' => $payload['id'] ?? null`. In the merged message, `id` propagates. The comment says "the folded message would report `run-…` instead of the provider's own `chatcmpl-…`". So if the usage chunk is the last chunk, the folding preserves the last-set id. Good.

Hmm — but does `MessageMerge` handle `id` as "last wins" or "keep first"? The comment suggests "keep last". OK.

47. Let me look at **`BaseChatModel::stream()`**. It yields `[CHANNEL_DEFAULT, $chunk->message]`. The message is an AIMessageChunk. That's per the Runnable contract. Fine.

48. Let me look at the OpenAI usage-only path. If the last chunk has no choices but has usage, we yield an AIMessageChunk. Then `$aggregated = $aggregated->concat($chunk)` merges. Then after the loop:
```php
$runManager?->handleLLMEnd(new LLMResult(
    [[new ChatGeneration($aggregated->message, $aggregated->text, $aggregated->generationInfo)]],
    $this->llmOutputFromUsage($aggregated->message),
));
```
The aggregated message has `response_metadata['usage_metadata']`. Good.

49. **Let me look at how `handlerPrefersStreaming` is called.** In `dispatchGenerate` — but `dispatchGenerate` is called from `generateMessages`. In `generateMessages`, the `handleChatModelStart` returns runManagers and then `dispatchGenerate($messages, $options, $thisRunManager, $config)`. Then `dispatchGenerate` calls `aggregateStream` or `generate`. In the streaming path (`aggregateStream`), the chunks are not yielded to the caller — but they ARE passed to `streamResponseChunks` which emits tokens via `$runManager->handleLLMNewToken()`. So handlers get tokens. Good.

50. **I want to find one more strong finding.** Let me look at `foldToolMessages` again — the mutation of `$previous->content`.

Actually — think about this: `$previous->content[] = $block;`. If `HumanMessage::$content` is a public property of type `string|array`, this works. But what if `HumanMessage` has a *setter* that normalizes content, or the property is readonly? If readonly, this would fail at runtime.

Hmm. I recall the LangChain-php Message class probably has public properties. But the direct mutation `$previous->content[] = $block` triggers a "Indirect modification of overloaded property" warning if content is a magic property with `__get`. And `failOnWarning=true`. Hmm — could be a real issue.

But without seeing HumanMessage source, can't verify.

Actually let me reconsider. Even if the property is public, mutating a message object that a caller passed in is bad — the caller's message list is modified. Actually no, `$previous = $out[...]` is a reference to the same object in `$out`, which was created inside this method. Wait — no. Let me re-read:

```php
$previous = $out === [] ? null : $out[count($out) - 1];
if ($previous instanceof HumanMessage && ...) {
    $previous->content[] = $block;
    continue;
}
$out[] = new HumanMessage(['content' => [$block]]);
```

The `$previous` in the fold path could be a synthesized HumanMessage (from a previous iteration) OR a real HumanMessage from the input list. Wait — `$out` starts empty and gets messages from the input appended. When we hit a ToolMessage, we look at the last `$out` entry. If it's a `HumanMessage` with content `[[0]['type']='tool_result']`, fold. Otherwise append a synthesized HumanMessage.

The HumanMessage with `[0]['type']='tool_result'` is only synthesized by THIS method. So `$previous` in the fold path is always synthesized. So no user message is mutated. Good.

OK, that's fine.

51. Let me look at `Completions::convertMessage` for a message with `$message->name !== null`. Some providers reject `name` on user messages. But upstream does this too? Hmm, actually the port just sends `name` if non-empty. Upstream? Probably similar. Skip.

52. Hmm — I want a strong second finding. Let me look at `StructuredOutput::withRaw`:

```php
return new RunnableSequence([
    new RunnableParallel(['raw' => $llm]),
    $parse->withFallbacks([
        RunnablePassthrough::assign(['parsed' => static fn (mixed $input): mixed => null]),
    ]),
]);
```

`$parse = RunnablePassthrough::assign(['parsed' => fn ($input) => $outputParser->invoke(...)])`. When invoked with `['raw' => message]`, `RunnableAssign` merges `parsed` into the input. So output is `['raw' => message, 'parsed' => ...]`. If the parser throws, the fallback runs and sets `parsed` to null. So output `['raw' => message, 'parsed' => null]`. Good.

But wait — `withFallbacks` on the `$parse` — if the parse partially fails, we re-run with a fresh null-setting assign. But the input to the fallback is the ORIGINAL input, not the partially-modified one? Depends on RunnableWithFallbacks implementation. Upstream: yes, the fallback gets the original input. So OK.

53. Let me look at **`BaseChatModel::withStructuredOutput` → `assembleStructuredOutputPipeline` → `$result->bind([], ['run_name' => $runName])`**. The `Runnable::bind($kwargs, $config)`. Then in `RunnableBinding::mergeConfig()` — as documented, bound kwargs go into `config->options`. So `bind([], ['run_name' => X])` merges an empty kwargs bag with a config containing `run_name`. Hmm. Let me check: `bind(array $kwargs = [], ?array $config = null)`. So this passes `$config = ['run_name' => X]`. Then in RunnableBinding, the config is merged into the invocation config. Fine.

54. **Now looking at `HttpClient` interface**: `public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse;`. And GuzzleHttpClient presumably has a different name for `$timeout`. That's documented.

Let me look at `SseParser` — the packet omitted it.

55. Let me look at **`ChatAnthropic::streamResponseChunks` and the `$this->streamUsage` gate** once more.

The usage chunk emission is gated on `streamUsage`. But note: `MessageOutputs::usageFromEvent($event)` is called for every event. Then if `$usage !== []` yields a chunk. If `streamUsage` is false, `$usage = []`, so nothing yields. But then the `move on to $chunk = MessageOutputs::eventToChunk($event)` — which for a `message_start` event returns null, and for `message_delta` returns null. Then for `content_block_delta` returns a chunk. Good.

But here's the thing: when `message_delta` arrives with `stop_reason`, the generationInfo gets `['stop_reason' => ...]`. But the chunk is null (`eventToChunk` returns null for message_delta), so the `generationInfo` is never emitted. Hmm. So `stop_reason` is LOST for streamed Anthropic responses. Let me verify.

Looking at `consume()`:
```php
$chunk = MessageOutputs::eventToChunk($event);
if ($chunk === null) {
    continue;
}

$generationInfo = array_filter(
    ['stop_reason' => $event['delta']['stop_reason'] ?? null],
    static fn (mixed $v): bool => $v !== null,
);
```

So for a `message_delta` event, `eventToChunk($event)` returns... probably null (since it has no content). Hmm, but I don't have that file. If it returns null, we `continue`, so `stop_reason` is not emitted. Then the caller's aggregated message won't have `stop_reason` in generation info.

Actually wait — the OpenAI path reads `finish_reason` from `$choice['finish_reason']` on the choice, and emits a chunk with the finish_reason even if the delta is empty. So OpenAI's `finish_reason` propagates. But Anthropic's `stop_reason` comes on the message_delta event where there's no content. Hmm.

But this could be intentional — do we need to worry? The `generationInfo` from the final chunk is what gets returned by `invoke()`. Actually looking at how it's used: `streamResponseChunks` yields `ChatGenerationChunk` objects, which have `generationInfo`. The `aggregateStream` in BaseChatModel takes the aggregated chunk's `generationInfo`, which is the LAST non-merged value. So if `stop_reason` is emitted on the last content chunk, it's kept. But `stop_reason` typically comes AFTER the last content chunk. So it's lost.

Hmm, this could be a real bug — but I need to verify `MessageOutputs::eventToChunk` behavior.

Actually — the comment in the Anthropic `consume` mentions the streamUsage gating and message_start/message_delta. If `eventToChunk` returns null for those, then only content_block_delta flows through. Then stop_reason wouldn't be propagated. That's a real defect IF Anthropic users expect stop_reason in the final result.

Actually wait — how does upstream handle this? In `@langchain/anthropic`, the `_streamResponseChunks` handles all event types. Content chunks flow through with content. The `message_delta` event with stop_reason... hmm.

Let me look at the `consume` code again:

```php
$chunk = MessageOutputs::eventToChunk($event);
if ($chunk === null) {
    continue;
}
```

If `eventToChunk` returns a non-null AIMessageChunk even for `message_delta` (e.g., with a specific generationInfo), then `stop_reason` is preserved. I really need the file.

Given I can't verify, I'll skip this.

56. Let me look at **the base's `stream()` `handleLLMNewToken`** — the base's `stream()` doesn't call `handleLLMNewToken`. Only the provider's `streamResponseChunks` does (via `$runManager`). So the base's stream yields chunks but doesn't emit token callbacks. Actually, the base `stream()` receives `$chunk->message` from `streamResponseChunks`, which is where tokens are dispatched. So tokens are dispatched inside the provider. Good.

But in the base `stream()` — for the non-streaming fallback path:
```php
if (!$this->supportsStreaming() || $this->disableStreaming) {
    yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];
    return;
}
```
So a non-streaming model yields the single invoke result but does NOT call handleLLMNewToken. That's probably upstream behavior. Fine.

57. **`ChatOpenAI::bindTools`** — the `strict` variable. `$strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling;` — `supportsStrictToolCalling` defaults to null. So `$strict` may be null. Then `Tools::convertAll($tools, $strict === null ? null : (bool) $strict)` — passes null or bool. Then in the loop `$next->kwargs['strict'] = $value` — but if `$strict` came from the property (not the kwargs), it's not in `$kwargs`, so the loop doesn't leak `strict`. Wait — `$strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling` — this reads `$kwargs['strict']` but doesn't add it. Then the loop iterates `$kwargs` — if the caller passed `strict`, it gets copied. If not, no. OK.

Actually the leak I mentioned is only when the caller passes `strict` explicitly. That's a minor trace leak.

58. **What about `ChatAnthropic::bindTools`** — same pattern but explicitly excludes `strict`. So both are fine except OpenAI leaks it.

59. Hmm. Let me search for another real issue.

**The `handleLLMNewToken` in ChatOpenAI's `streamResponseChunks`** — it wraps in a try/catch: `$runManager?->handleLLMNewToken($text, ['chunk' => $chunk])`. Note `handleLLMNewToken` receives a second param `['chunk' => $chunk]`. But the base class's `handleLLMNewToken` signature might expect a different shape. Not verifiable.

60. **Now — an important observation about `ChatOpenAI::post()`.** The try-catch:

```php
try {
    $response = $this->http()->post(...);
} catch (\LangChain\Utils\Http\HttpException $e) {
    if ($attempt++ >= $this->maxRetries) {
        throw $e;
    }
    $this->backoff($attempt);
    continue;
}
```

If the HTTP client throws an HttpException, we retry. But `$attempt++` is used: initial `$attempt=0`, `0 >= 2` is false, so we backoff(1) and retry. Then `$attempt=1`, `1 >= 2` false, retry. `$attempt=2`, `2 >= 2` true, throw. So we made 3 attempts. Good.

For non-2xx: `$retryable && $attempt++ < $this->maxRetries`. Same.

But wait — for the non-2xx case, the code does:
```php
$response = $this->http()->post(...);
if ($response->isOk()) { return $response->json(); }
$retryable = $response->status === 429 || $response->status >= 500;
if (!($retryable && $attempt++ < $this->maxRetries)) {
    throw OpenAIException::fromResponse(...);
}
```

So on the FIRST 5xx response, `$attempt=0`, `0 < 2` true, so we DON'T throw, we backoff and retry. On the SECOND (attempt=1), we retry. On the THIRD (attempt=2), `2 < 2` false, throw. Total 3 attempts. Good.

Hmm, but the short-circuit: `!($retryable && $attempt++ < $this->maxRetries)`. If `$retryable` is false, short circuit — `$attempt++` is NOT evaluated. Good.

But if `$retryable` is true and `$attempt++ < $this->maxRetries` is false, `$attempt` is incremented (to 3). But then we throw. Fine.

61. **`ChatOpenAI::streamResponseChunks`** — the same pattern. Let me check `$attempt++ < $this->maxRetries` when delivery hasn't happened. Note `$delivered` is set to true on any non-empty bytes received. If the stream disconnects AFTER some bytes, `$delivered = true`, no retry. Good.

But — a subtle issue: if the stream connects AND sends a bunch of bytes AND THEN fails (say, mid-stream 500 or network), `$delivered = true` — no retry. Correct. But what if the stream connects, sends bytes, and the FIRST BYTES are HTML from a 500 error page (some servers send errors mid-stream)? Then the SSE parser gets HTML, JSON decoding fails, and an exception is thrown. Well — the JSON exception is OpenAIException, caught by `catch (OpenAIException $e) { throw $e; }` — no retry. Fine.

62. **The `$delivered` flag is set based on ANY non-empty bytes**. But those bytes might be an HTML error page for a 500 status. Because the SSE parser will fail JSON decoding and throw, retry isn't triggered. That's a real limitation but matches the code's intent.

63. OK let me now look at some things in the packet that suggest divergence but are actually fine.

Actually — let me look at the base `stream()` in `BaseChatModel`:

```php
if ($aggregated === null) {
    $runManager?->handleLLMEnd(new LLMResult([[]], []));
    return;
}
```

If the stream yielded ZERO chunks, we call `handleLLMEnd` with an empty result and return without yielding anything. That's probably expected — an empty stream.

But — `aggregateStream` (used in the non-streaming path with a streaming handler) THROWS on empty:
```php
if ($aggregated === null) {
    throw new \RuntimeException('Received empty response from chat model call.');
}
```

So `invoke()` on a streaming model with a streaming-preferring handler throws on empty stream, but `stream()` on the same model returns silently. That's an asymmetry. Is it upstream behavior? Probably yes — the non-streaming `invoke()` path treats empty as an error. Fine.

64. **Let me look at the `Runnable::pipeTo` more carefully.** The pattern `new RunnableLambda(fn (mixed $input): mixed => $input, ['func' => $fn])`. If RunnableLambda is `__construct(?callable $func = null, array $config = [])`, then the identity callable is used and `$fn` is stored in config's `func` key which is likely never read. So `pipeTo($fn)` behaves as identity — silently returning the same input, `$this` never runs.

But this could also be intentional: the RunnableLambda's config carries the actual function, and the constructor uses it. If so, why pass an identity? Maybe the "identity" is a default and the second arg overrides. Actually, a common pattern in some ports is `new RunnableLambda($callable, ['name' => ...])`. But passing `['func' => $fn]` where the first arg is identity suggests the port expects RunnableLambda to use `config['func']`.

Hmm. Let me look at RunnableLambda usage elsewhere. In `RunnableParallel` etc. we don't see the source. In `Runnable::pipeTo` we see this weird pattern. In `StructuredOutput::withRaw`, we see `RunnablePassthrough::assign(['parsed' => $outputParser->invoke(...)])` with closures — this suggests assign accepts closures, not RunnableLambdas. OK.

Given the uncertainty, I'll flag `pipeTo` as a possible bug with hedging. Actually, no — I'll pass on it unless I can find a stronger indication.

Wait — actually the base class says the RunnableLambda's callable signature is `(input, config?, ...)` and the port only passes input. So the RunnableLambda is `fn ($input) => ...`. The `['func' => $fn]` is a config bag. If RunnableLambda honored config['func'], then the first arg (identity) would be meaningless. Weird.

Let me look for RunnableLambda usage in tests or elsewhere. Not in the packet. Skip.

65. **Let me look at the port's handling of `OpenAI` tool `strict`.** In the constructor kwargs whitelist, `strict` is NOT listed. So `new ChatOpenAI(['strict' => true])` would set... wait, no. There's no `strict` property assignment in the constructor. So a constructor-supplied `strict` would be ignored entirely (not assigned to a property, and not in the kwargs whitelist). Hmm.

Actually, looking at constructor:
```php
$this->supportsStrictToolCalling = isset($fields['supportsStrictToolCalling']) ? ... : null;
```
There's no `$strict` handling. So `new ChatOpenAI(['strict' => true])` silently drops it.

But `bindTools($tools, ['strict' => true])` DOES use it: `$strict = $kwargs['strict'] ?? $this->supportsStrictToolCalling`. So strict only via bindTools kwargs. That's actually aligned with upstream I think — strict is a per-binding option. Fine.

66. Let me look at **the `FakeStreamingChatModel` reference in HANDOFF** — it's mentioned as having had bugs. But we don't have the file.

67. Let me focus on what I can see.

Actually — I want to look at `ChatOpenAI::invocationParams` again for the `stop` param:
```php
'stop' => $this->pick($options, 'stop', 'stopSequences', 'stop_sequences')
    ?? $bound['stopSequences'] ?? $bound['stop'] ?? $this->stopSequences,
```

Note: `$bound` was already normalized by `normaliseKeys`, so `$bound['stop']` would have been converted to `$bound['stopSequences']`. So `$bound['stop']` is either absent or the same as `$bound['stopSequences']`. Harmless redundancy.

But wait — the `'tool_choice' => $this->toolChoiceOf($options) ?? $this->formatBoundToolChoice($bound)`. Hmm. `toolChoiceOf` reads `$options['toolChoice'] ?? $options['tool_choice']`. But normaliseKeys mapped `tool_choice` to `toolChoice`, so `$options['toolChoice']` is set if either was. Good.

68. Now — I want to revisit **the `withStructuredOutput` return type**. It returns `\LangChain\Runnables\Runnable`, but the docblock says:
```
@return Runnable<mixed, mixed> the model, or
       `{raw: BaseMessage, parsed: array|null}` when `includeRaw` is set
```

This return type declaration `\LangChain\Runnables\Runnable` — is Runnable a class or interface? Looking at the packet, `Runnable` is an abstract class (`abstract class Runnable`). So the return type is fine.

69. **`StructuredOutput::assembleStructuredOutputPipeline`** — return type is `Runnable`. And it returns either `$result = $includeRaw ? self::withRaw(...) : $llm->pipe($outputParser);` — both are Runnables. Then `$result = $result->bind([], ['run_name' => $runName])` — `bind` returns `RunnableBinding`, which is a `Runnable`. Good.

70. Hmm — I want to look at the **`ChatOpenAI` `bindTools` `strict` and how it interacts with `invocationParams`**. When tools are bound, `$next->kwargs['tools']` is set to the converted tools. Then in `invocationParams`, `'tools' => $this->pick($options, 'tools') ?? $bound['tools'] ?? null`. Good.

But — the converted tools are already in OpenAI format. Are they? `Tools::convertAll(...)`. For ChatOpenAI, tools should be in OpenAI format. OK.

71. **The `ChatAnthropic::convertTool`** — converts to Anthropic shape. Good.

72. Let me now look at something I might have missed in `BaseChatModel::generateMessages`:

```php
$runIds = array_map(static fn (object $m): string => $m->runId, $runManagers ?? []);
```

This assumes every run manager has a public `runId` property. Fine.

73. In `generateMessages`, if `$runManagers` is `[]` (no callbacks attached), `$runIds = []`. Fine. But if `$callbackManager` is null (no handlers), does `handleChatModelStart` return null? Then `$runManagers = null`. Then `$runIds = []`. Then `$runManager = $runManagers[0] ?? null` — but wait, `$runManagers` is null, so `null[0] ?? null` — hmm, PHP would... actually `$runManagers[0]` on null is `null ?? null` = null. In PHP 8, accessing an array offset on null generates a warning "Trying to access array offset on value of type null". Under `failOnWarning=true`, that would fail the test!

Wait — the code:
```php
$runManager = $runManagers[0] ?? null;
```

If `$runManagers` is null, `null[0]` — in PHP 8, does `$x[0] ?? null` on null emit a warning? Let me think. `??` suppresses undef index/undefined var warnings. But accessing array offset on null specifically? I believe `$null[0] ?? null` DOES NOT warn — `??` catches it. Hmm. Actually, I think `??` catches the "Trying to access array offset on null" warning too in PHP 8. Let me not go down this path since the whole suite is green.

But is `$runManagers` guaranteed to be an array? `handleChatModelStart` may return `?array`. If it returns null, then `$runManagers ?? []` in `$runIds` line — OK. And `$runManagers[0] ?? null` — OK with ??. And `$runManagers[$index] ?? $runManager` — OK.

74. Let me look at **`BaseChatModel::stampMessageId`**:
```php
private function stampMessageId(Generation $generation, ?CallbackManagerForLLMRun $runManager): void
{
    $message = $generation instanceof ChatGeneration ? $generation->message : null;
    if ($message === null || $message->id !== null || $runManager === null) {
        return;
    }
    $message->id = 'run-' . $runManager->runId;
}
```

The signature is `Generation $generation` — but ChatGeneration extends Generation? Presumably. It only handles ChatGeneration. If a non-chat generation is passed... it just no-ops. Fine.

75. **Now the `stampMessageId` in the streaming path:**
```php
foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
    $this->stampMessageId($chunk, $runManager);
    $chunk->message->response_metadata = array_merge(
        $chunk->generationInfo,
        $chunk->message->response_metadata,
    );
    ...
}
```

Wait — `$chunk->generationInfo` is merged into `$chunk->message->response_metadata`. This means the streaming path puts the generationInfo (like `finish_reason`) into `response_metadata` and NOT into the chunk's `generationInfo` — but the chunk object still has its `generationInfo`. So both are present. That's fine.

But — for a chunk with `finish_reason`, the response_metadata would get `finish_reason` merged in. OK.

76. Let me now look at the `stampMessageId` call inside `generateMessages`:
```php
foreach ($result->generations as $generation) {
    $this->stampMessageId($generation, $runManager);
    $generation->message->response_metadata = array_merge(
        $generation->generationInfo,
        $generation->message->response_metadata,
    );
}
```

This merges generationInfo into response_metadata for the non-streaming path too. OK.

77. Alright, let me look for another strong finding.

**Potential issue: `MessageInputs::foldToolMessages` and consecutive tool result folding.**

If a ToolMessage is followed by a NON-tool message, then a subsequent ToolMessage, the fold only applies to CONSECUTIVE tool messages. Good.

But the check `$previous instanceof HumanMessage && is_array($previous->content) && ($previous->content[0]['type'] ?? null) === 'tool_result'`. Consider a user provided a `HumanMessage(['content' => [['type' => 'tool_result', ...]]])` directly (unlikely), then a ToolMessage, then the check would pass and fold incorrectly. Very edge case.

78. **Let me look at `ChatOpenAI::convertMessages`** — wait, `Completions::convertMessages`. `foreach ($messages as $message) { $out[] = self::convertMessage($message); }`. Fine.

79. Hmm — let me look at `ChatOpenAI::generate`'s **`$generations[0]->message`**:
```php
return new ChatResult($generations, $this->llmOutputFromUsage($generations[0]->message));
```

If multiple choices, only the FIRST generation's usage is used. But OpenAI's usage is per-request, not per-choice, so using the first is fine.

80. Now let me look at **`ChatAnthropic::generate`** — `$this->llmOutputFromUsage($message)`. Fine.

81. Let me look at whether `BaseChatModel` has a `disableStreaming` property. It's referenced in `stream()`: `!$this->supportsStreaming() || $this->disableStreaming`. But I don't see it declared in `BaseChatModel` source we have. Maybe in `BaseLanguageModel` or somewhere. Not shown. Can't verify.

82. Since the packet omits a lot, I need to be careful.

Let me consolidate my findings so far:

**Finding 1 (MAJOR): `Runnable::map()` returns `new RunnableParallel([])` and drops `$this`.** Concrete evidence at `Runnable.php`. `RunnableParallel([])` is an empty parallel; `$this` is not composed. Suggest returning `new RunnableParallel(['input' => $this])` or the upstream equivalent. But I need to double-check upstream `Runnable.map`. Hmm.

Actually — I realize there might be a bug pattern here. Let me look at the port's own `Runnable::assign()` — wait, `RunnablePassthrough::assign()` is used in the packet. Where's `Runnable::assign()`? Not shown. `Runnable::map()` — the docblock says "Run a list of branches in parallel" — but the signature takes NO arguments. So it can't do what the docblock says. This is a real defect.

Wait — maybe I mis-read. Let me re-read the code:

```php
/**
 * Run a list of branches in parallel, keyed by name, and return every
 * branch's result keyed the same way.
 */
public function map(): RunnableParallel
{
    return new RunnableParallel([]);
}
```

Yes. `map()` returns an empty RunnableParallel. And it takes no arguments. This is definitely wrong — regardless of what upstream does, this method is nonsense as written. Either it should accept branches, or it should wrap `$this`.

Hmm, ok. But actually, wait. In upstream `Runnable`, there IS a `map()` method. Let me recall... I think `Runnable.map()` in JS returns `this.pipe(new RunnableMap({...}))` or something. Actually, I remember now: `Runnable.map()` returns a `RunnableMap` with a single step `{ input: this }`... no wait. Hmm.

I'll report it with the caveat.

Actually — I wonder if `map()` is being used as a way to CALL `.map()(...)` — like a curried form. The signature `map(): RunnableParallel` returns a RunnableParallel which can then be `->invoke()`. Not curried.

Whatever — the docstring contradicts the implementation. Report it.

**Finding 2 (MAJOR): OpenAI `bindTools` does not exclude `strict` from copied kwargs, leaking it into `kwargs()` (traces), while Anthropic does.** Actually this is MINOR.

Let me find something stronger.

**Finding 3 (MAJOR/BLOCKER?): the retry logic with `usleep` and no jitter** — no, documented.

**Let me look at `Runnable::batch`.**
```php
public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
{
    return array_map(
        fn (mixed $input): mixed => $this->invoke($input, $config),
        array_values($inputs)
    );
}
```

The `$options` param is ignored. In upstream, options might control max concurrency etc. But the port is synchronous, so it doesn't matter. But the `$config` is passed AS-IS to every invocation. In upstream, each invocation gets a fresh run_id in the config for tracing. Passing the same config (with the same run_id) to every invocation would cause the traced runs to collide.

Hmm — but this is a functional bug only if tracing matters.

Actually — the same config passed to multiple invokes means all invocations share the same `run_id`. If the callback manager keys runs by run_id, they'd collide. Real trace-integrity issue but hard to verify without the CallbackManager.

Skip.

**Let me look at `Runnable::transform`.**
```php
public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
{
    foreach ($input as $item) {
        foreach ($this->stream($item, $config) as $pair) {
            yield $pair;
        }
    }
}
```

This is fake — it re-streams each item. Real `transform` should handle streaming transformation, not restart for each item. But given synchronous PHP and no streaming API for `transform`, this is a divergence. Hmm, upstream `Runnable.transform` is a wiring for `RunnableGenerator`. Whether this port's version is intended...

Actually — this is a "silent wrong" behavior: `transform` on a list `[a, b, c]` should transform each. But the port's version streams each item separately. If the transform is meant to aggregate across items (like a `RunnableGenerator`), it cannot. But `Runnable.transform` for a normal runnable IS just mapping stream over items. So maybe OK.

Let me not chase this.

**Finding 4: `Completions::convertMessage` for AIMessage with `toolCalls` and array `content`** — drops text. Actually, let me examine more carefully.

```php
if ($message instanceof AIMessage) {
    if ($message->toolCalls !== []) {
        $param['tool_calls'] = array_map(...);
    } elseif (isset($message->additional_kwargs['tool_calls'])) {
        $param['tool_calls'] = $message->additional_kwargs['tool_calls'];
    }
    ...
}
```

Note — when `toolCalls` is non-empty, the params receive `tool_calls` (from `$message->toolCalls`), but the message content is already set above as `$param['content'] = $message->content;`. That content passes through if it's a string. Good.

So the AIMessage content is preserved. OK, no bug there.

**Finding 5: The `roleOf` method throws on unknown message types.** That's intentional. But note: `'chat' => 'assistant'`? No. `'ai' => 'assistant'` — good. But wait — the docblock says "LangChain distinguishes six roles", but the match handles 5 (`system`, `human`, `ai`, `tool`, `function`) and throws on anything else. What about `'generic'` or `'remove'`? Those would throw. That's intentional (the docblock says "a wrong role is a 400 from the provider, and failing here names the offending message instead"). OK.

**Finding 6: `ChatOpenAI::streamResponseChunks` — the `text` from delta.**

```php
$text = is_string($delta['content'] ?? null) ? $delta['content'] : '';
```

Only handles STRING delta content. What if delta content is an ARRAY of content parts (some providers)? Then text becomes ''. This might be a divergence. But OpenAI's Chat Completions streaming returns string content. Array content in streaming is rare. Skip.

Hmm, I want to find something significant.

**Let me look at the `ChatAnthropic::generate`**:
```php
$converted = MessageInputs::convert($messages);
if (isset($converted['system'])) {
    $params['system'] = $converted['system'];
}
$params['messages'] = $converted['messages'];
```

The comment explains why this is explicit assignment not union. Good.

**Now let me look at the `Anthropic` `streamResponseChunks` — retry with `$delivered` based on any non-empty byte received.** Same pattern as OpenAI. But it's inlined. Consistent. Fine.

**Anthropic `consume` — usage chunk yields BEFORE the chunk.** So usage arrives before the first content chunk (from `message_start`) — but actually message_start is the FIRST event, so usage is emitted first. Fine.

**Let me look at the fold behavior of `ChatGenerationChunk::concat`.** We don't have this file. It's documented as "heavily tested".

**OK let me look at what the AIMessageChunk `id` handling looks like.** The usage-only chunk for OpenAI sets `'id' => $payload['id']`. Since usage chunk is LAST, and merge keeps last, the id is preserved. But wait — does the concat actually keep the LAST chunk's id, or the FIRST? The comment says "the fold keeps the LAST chunk's id". Let me trust it.

**Finding candidates tally:**
- Runnable::map() drops $this — MAJOR
- ChatOpenAI::bindTools leaks strict — MINOR
- Something else

Let me look at `Runnable::pipeTo` once more. The implementation looks suspicious.

```php
public function pipeTo(callable $fn): RunnableLambda
{
    return new RunnableLambda(
        fn (mixed $input): mixed => $input,
        ['func' => $fn]
    );
}
```

The `RunnableLambda` is constructed with an identity callback and a config `['func' => $fn]`. If the constructor stores `$config` and uses `$config['func']` as the callable when the primary callable is the identity, that's bizarre. Most likely RunnableLambda uses the FIRST arg as the callable and the SECOND as config. So `pipeTo` creates a lambda that returns its input unchanged, and the intended function is dropped. So `runnable->pipeTo($fn)` returns a runnable that is identity — the input passes through unchanged, and `$this` (the receiver) is NEVER invoked. Total no-op.

Wait, and it doesn't invoke `$this` either! Like `.map()`, `pipeTo` completely ignores `$this`. So a called `runnable->pipeTo(fn)` does NOT apply `runnable`. Actually — hold on. `pipeTo` is meant to be `runnable.pipe(new RunnableLambda(fn))`. It should return a sequence. The port returns a bare lambda. That means the receiver is dropped.

So `pipeTo` is ALSO broken. It should either be `return $this->pipe(new RunnableLambda($fn));` or `new RunnableSequence([$this, new RunnableLambda($fn)])`.

Yes — this is another strong finding. `pipeTo` on a Runnable returns a RunnableLambda that is NOT chained with `$this`. Both `map()` and `pipeTo` fail to reference `$this`.

Hmm, but wait — maybe the intent is different. Maybe RunnableLambda with a config `['func' => ...]` is the pattern used elsewhere for wrapping closures, and the port's Runnable `pipeTo` uses a special RunnableLambda constructor that takes the function via config. In that case, the RunnableLambda's behavior would be `$fn($input, $config)`. But then `$this` is still not invoked!

Actually, well, hmm. `pipeTo` on Runnable(RunnableX) should return a runnable that when invoked, invokes RunnableX, then passes the output to $fn. If the returned RunnableLambda invokes $fn with the input as-is, `$this` is skipped. So `pipeTo` is broken regardless.

Unless... hmm, maybe `RunnableLambda`'s constructor with `['func' => $fn]` treats `$fn` as a post-processor on the identity. Then input comes in, the identity runs, then $fn gets... no, this is guessing.

Let me report `pipeTo` too. Actually, maybe just one of `map`/`pipeTo` — or both if I can frame them as one finding.

Actually let me combine them: "Composition helpers `map()` and `pipeTo()` drop `$this`."

**Wait**, hold on. The port declares these methods and both fail to reference `$this`. But the house rule is "one class per file, port faithfully". Maybe upstream `Runnable` doesn't have `map()` or `pipeTo()`, and the port invented them. In that case, inventing them AND implementing them wrong is a double bug.

I'll report both as one finding.

Actually wait — hold on. Let me reconsider `pipeTo`. Maybe RunnableLambda's constructor IS `__construct(?callable $func = null, array $config = [])` and calling `pipeTo($fn)` should be `new RunnableLambda(fn, $fn)`? Hmm.

Actually maybe the port's pattern is: `new RunnableLambda($func, $config)` where the config has additional parameters. And `['func' => $fn]` is a bug because the actual func (`$fn`) should be the FIRST argument, not inside config.

But the identity `fn (mixed $input): mixed => $input` is the first argument. So the RunnableLambda is identity. The `$fn` is in config, which is probably ignored. So the RunnableLambda is identity. And `$this` is not invoked.

Hmm. Yeah, `pipeTo` is broken.

**Wait** — maybe I'm misreading and `pipeTo` should be a "pipe to callback" that replaces `$this`'s output with `$fn`'s output, treating `$fn` as a transformation. In that case, `$this` should be the receiver of the input... but the returned runnable doesn't include `$this` at all.

Yeah, `pipeTo` is broken.

OK, let me also check `Runnable::map()` — this one has NO relationship to `$this`. So it's broken too.

I'll combine them into a single finding about mis-implemented composition helpers.

Hmm — actually these are two separate methods. Let me make them a single finding "Runnable composition helpers miss their receiver" but reference both.

Actually, we may want them separate since they have different fixes. But I'll combine them.

**Now let me look for a BLOCKER.**

Hmm. Let me re-examine `Completions::stringifyContent`:
```php
public static function stringifyContent(mixed $content): string
{
    if (is_string($content)) { return $content; }
    if ($content === null) { return ''; }
    return (string) json_encode($content, ...);
}
```

If `$content` is a list of content blocks like `[['type' => 'text', 'text' => 'hello']]`, it becomes `'[{"type":"text","text":"hello"}]'` — a JSON string. That's how it's used for ToolMessage / FunctionMessage content. Upstream also does this? Probably. Because those are text-only fields.

OK.

**Let me consider the `ChatOpenAI::invocationParams` `'stop'` handling again.**

For the `'stop'` field: `$this->pick($options, 'stop', 'stopSequences', 'stop_sequences')`. Note the `pick` method returns the FIRST non-null. But `stopSequences` (camelCase) is checked second. If the caller passed `stopSequences => []` (empty array), `pick` returns `[]` (not null). Then `?? $bound[...]`. Empty array isn't null, so the value is `[]`. Then `'stop' => []`. Then `array_filter($params, static fn ($v) => $v !== null)` — `[]` is not null, so kept. So `stop: []` gets sent. That might be rejected by OpenAI. Not a big deal.

**Let me look at the `JS isList` utility used by `toolCallToWire`:**
```php
'arguments' => json_encode(
    is_array($args) && $args !== [] && \LangChain\Utils\Js::isList($args) ? $args : (object) $args,
    ...
)
```

Hmm — `is_list($args)` in PHP is the built-in. But the port has its own `Js::isList`. If `$args` is an empty array — `$args !== []` is false, so we go to `(object) $args` = empty object. Good, `{}`.
If `$args` is a non-empty list — `isList` true, encode as list `[...]`. Good.
If `$args` is an assoc array — `isList` false, `(object) $args` → `{...}`. Good.

OK.

**Now let me look at `MessageInputs::textBlocks` again:**
```php
private static function textBlocks(mixed $content): array
{
    return is_string($content) && $content !== ''
        ? [['type' => 'text', 'text' => $content]]
        : [];
}
```

For array content — returns `[]`. So when an assistant message has an array content (blocks), and also has toolCalls, the content blocks are dropped from the converted message. The message becomes `{role: assistant, content: [tool_use blocks only]}`. Content is lost.

This could be a real bug when a caller constructs an AIMessage with structured content (array of blocks) plus toolCalls. Since v1 output isn't ported, this is uncommon but possible.

Hmm, and also — non-AIMessage messages with array content pass through formatContent, which returns the array directly. So only AIMessage+toolCalls is affected.

Actually wait — when does an AIMessage have array content? The port's OpenAI response path uses `$message['content'] ?? ''` which is a string when it comes from the wire (unless there are content blocks in the response). And `MessageOutputs` for Anthropic may produce array content. Hmm — if `MessageOutputs` produces an AIMessage with content as raw blocks, and that message has toolCalls... Actually, the port's Anthropic path... I don't have MessageOutputs.

Let me tentatively flag this as a possible defect: `MessageInputs::textBlocks` — when an assistant message contains both tool calls and block content, the blocks are dropped, silently removing text from the conversation.

Hmm — actually a stronger framing: even without toolCalls, for a NON-tool-call AIMessage, we go through `['role' => $role, 'content' => self::formatContent($message->content)]`, which preserves the array. So the drop only happens with toolCalls. So the specific failure: an assistant turn with text content AND a tool call loses its text.

Hmm — but does upstream Anthropic convert preserve both? Yes, upstream uses `content` blocks merging. Actually upstream: `const content = [...convertToAnthropicBlocks(message.content), ...toolCalls.map(...)]` — hmm, maybe. The port's `self::textBlocks($message->content)` only preserves string content, not array content. So yes, this is a divergence.

Good finding.

**Now let me look at another potential bug in the base class.**

`BaseChatModel::generateMessages` — `stampMessageId($generation, $runManager)` uses `$runManager` (index 0) NOT `$thisRunManager`. The comment says this is upstream behavior. OK.

**Let me look at how `narrowResult` is used.** Not visible.

**Let me look at `Runnable::withFallbacks()`**:
```php
public function withFallbacks(array $fallbacks): RunnableWithFallbacks
{
    return new RunnableWithFallbacks($this, $fallbacks);
}
```

Fine.

**Let me consider `Runnable::bind($kwargs, $config)`.**
```php
public function bind(array $kwargs = [], ?array $config = null): RunnableBinding
{
    return new RunnableBinding($this, $kwargs, $config);
}
```

`$config` is an array, and RunnableBinding expects a `RunnableConfig` or an array? Hmm. `new RunnableBinding($this, $kwargs, $config)`. What's the third parameter type? The packet omits RunnableBinding. Given the config is an array (from `assembleStructuredOutputPipeline` — `['run_name' => $runName]`), the RunnableBinding constructor probably takes `?array $config` and internally wraps it. OK.

**Hmm, actually — `assembleStructuredOutputPipeline` calls `$result->bind([], ['run_name' => $runName])` and the RunnableBinding will use config['run_name'] to set the run name. Fine.**

**One more consideration: `ChatOpenAI::bindTools` `$strict` from caller kwargs isn't removed from `$next->kwargs` — leaks.**

OK let me also look at a higher-level concern:

**Message `role` for OpenAI system messages** — in `Completions::convertMessage`, when the message has a non-empty `name`, it adds `name` to the params. But OpenAI's Chat Completions API rejects `name` for `assistant` role messages? Hmm. Actually OpenAI historically allows `name` on any message. Maybe deprecated. Skip.

**Let me look at `ChatOpenAI` `$this->kwargs` whitelist:**
```php
['model', 'temperature', 'topP', 'frequencyPenalty', 'presencePenalty',
 'stop', 'stopSequences', 'maxTokens', 'user', 'seed', 'responseFormat', 'tools',
 'toolChoice', 'parallelToolCalls', 'organization', 'streamUsage',
 'maxRetries', 'timeout']
```

Includes `organization`. But `apiKey` is excluded. Good. `organization` might be sensitive (an org ID). Hmm. But it's not a secret. OK.

But wait — `responseFormat` is in the whitelist, and `invocationParams` reads `$bound['responseFormat']`. Good.

**`user` and `seed`** — in the whitelist and read. Good.

**Now — the `ChatOpenAI` constructor `$this->kwargs = array_filter($this->kwargs, static fn (mixed $v): bool => $v !== null);`** — this filters out null-valued keys. So `['temperature' => 0.0]` — `0.0 !== null` — true, so kept. `['temperature' => 0]` — `0 !== null` — true, kept. Good. `['stop' => []]` — `[] !== null` — true, kept. Good.

**`array_filter` preserves keys.** OK.

Hmm — wait, `array_filter` with a callback that checks `$v !== null` on an int/float 0: `0 !== null` is TRUE because 0 is not null. So kept. Good.

Alright.

**Let me examine `ChatAnthropic::streamResponseChunks` `MessageInputs::convert` being called INSIDE the retry loop.** No — wait, it's called BEFORE the loop:

```php
$params = $this->invocationParams($options, ['streaming' => true]);
$converted = MessageInputs::convert($messages);
...
$body = (string) json_encode($params, ...);
$attempt = 0;
while (true) { ... }
```

Yes, conversion runs once. Good.

**Wait — but `$this->invocationParams` is called BEFORE the loop too.** Good.

**Hmm — the retry loop re-encodes `$body`? No — `$body` is computed once before the loop. Good.**

**But for the OpenAI `post()` non-streaming retry:** the body is computed once. Good.

**Now — a subtle issue: the retry loop in `postStream`/`streamResponseChunks` creates a NEW `SseParser` per attempt. Good — fresh parser per retry.**

**`ChatOpenAI::postStream`** — creates a new `SseParser` per attempt. Good.

**But — for `ChatAnthropic::streamResponseChunks`**, the `consume` generator is used, which decodes events and yields chunks. But the usage chunk is yielded via `yield new ChatGenerationChunk(...)` inside `consume`. Then chunks flow through. The retry resets `$parser` and `$delivered`. Good.

**Hmm, one more** — is `OpenAIException` a subclass of `HttpException`? If so, in `postStream`:
```php
} catch (OpenAIException $e) {
    throw $e;
} catch (\LangChain\Utils\Http\HttpException $e) {
    ...
}
```

Exception ordering — OpenAIException first, then HttpException. If OpenAIException extends HttpException, the first catch wins. So OpenAIExceptions are rethrown. Good. If OpenAIException does NOT extend HttpException, then catch order doesn't matter — but then the `catch (OpenAIException $e) { throw $e; }` block just rethrows, which is pointless. But it's not harmful.

**Actually — wait, if `OpenAIException` extends `HttpException`, then in the SECOND catch block, we'd catch non-OpenAI HttpExceptions (from the transport). But the first catch grabs OpenAI ones and rethrows without retrying. But when would an OpenAIException be thrown inside the try? During `$this->http()->postStream(...)` — the transport throws a generic HttpException, not OpenAIException. During SSE decoding, `decode` throws OpenAIException. So if the stream fails mid-decode, the OpenAIException propagates. Good, no retry.**

OK.

**Let me now go through the OpenAI streaming "no choices" path once more.**

The OpenAI API, when `stream_options.include_usage` is true, sends a FINAL chunk with `choices: []` and `usage: {...}`. The port:

```php
if (!is_array($choices) || $choices === []) {
    if (isset($payload['usage']) && is_array($payload['usage'])) {
        yield new ChatGenerationChunk(
            new AIMessageChunk([
                'content' => '',
                'id' => $payload['id'] ?? null,
                'response_metadata' => Completions::responseMetadata($payload),
            ]),
            '',
        );
    }
    continue;
}
```

OK. But this only yields ONE chunk with usage. This chunk's `response_metadata` has `usage_metadata`. Then in the base's `stream()`, `$aggregated = $aggregated->concat($chunk)`. MessageMerge on response_metadata's nested `usage_metadata` — sums input_tokens, output_tokens. Since there's only one usage chunk, the sum equals just this. Good.

**BUT** — does the merge actually SUM `usage_metadata` numbers, or does it replace? The comment on the base class says "summed as they arrive" for Anthropic. But for OpenAI, if the LAST chunk has usage and there's no earlier usage, replace = sum = the value. Fine.

**Actually** — a subtlety: for OpenAI, the usage chunk's `input_tokens` is the FINAL total (input + cached + cached_creation). So the merge doesn't need to sum. Fine.

Alright.

Let me now consider **`ChatOpenAI::generate`'s token accounting** for `ChatGeneration::$generationInfo`. `$generationInfo = ['finish_reason' => $choice['finish_reason'] ?? null]; $generationInfo = array_filter(...)`. So generationInfo is `['finish_reason' => 'stop']`. Then in `BaseChatModel::generateMessages`:
```php
$generation->message->response_metadata = array_merge(
    $generation->generationInfo,
    $generation->message->response_metadata,
);
```

So finish_reason is merged into response_metadata. But `response_metadata` may already have `finish_reason` (from the response's top-level data?). `Completions::responseMetadata` produces `['model_provider', 'model_name', ...]`. No finish_reason there. So merge adds `finish_reason => 'stop'` to response_metadata. Good.

**Now what about the reverse: does the OpenAI path prefer the choice's `finish_reason` over any top-level?**

OK, fine.

**Now let me look at something I might have missed: `Completions::deltaToChunk`.**

```php
$fields['additional_kwargs'] = array_filter(
    $fields['additional_kwargs'] + ['completion_index' => $index],
    static fn (mixed $v): bool => $v !== null,
);
```

Note: the union `$fields['additional_kwargs'] + ['completion_index' => $index]` — if `additional_kwargs` already has `completion_index`, that value wins. Otherwise, it gets the index. Then filters nulls. But `$index` is an int and never null, so `completion_index` is always set. Fine.

**Alright, let me now formulate 5 findings.**

Given the calibration, I should report the strongest and most verifiable ones. Let me pick:

1. **Runnable::map() and Runnable::pipeTo() drop `$this`** — MAJOR
   - `map()` returns an empty RunnableParallel and never composes `$this` — the docblock promises "run a list of branches" but takes no branches; the receiver is silently discarded.
   - `pipeTo($fn)` returns a `RunnableLambda(identity, ['func' => $fn])` — the receiver is not chained, and the intended function is buried in a config bag. Both methods are silent no-ops.
   - Evidence: `src/LangChain/Runnables/Runnable.php` around lines 85-110.

2. **ChatAnthropic drops block content from assistant turns that also carry tool calls** — MAJOR
   - `MessageInputs::textBlocks($message->content)` only preserves string content. When an AIMessage has block content (an array of blocks, e.g. from a caller building messages programmatically) plus `toolCalls`, the blocks are silently discarded from the outgoing request.
   - Evidence: `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageInputs.php` (`convertMessage`, `textBlocks`).
   - Suggested fix: convert array content to blocks via a `contentToBlocks` helper that returns array content as-is.

3. **ChatOpenAI::bindTools leaks `strict` into `kwargs`** — MINOR
   - Asymmetric with ChatAnthropic (which drops it). Pollutes trace records.
   - Evidence: `src/LangChain/LanguageModels/Chat/OpenAI/ChatOpenAI.php::bindTools`.

4. **`ChatOpenAI::postStream` (and Anthropic) will not retry a 429/5xx delivered as a zero-byte-to-nonzero-byte HTML error page** — hmm, weak.

Let me look for more.

**Finding 4 candidate**: `Runnable::batch` passes the SAME config to every invocation. In upstream, each is called with a fresh run_id (so multiple runs in a batch get distinct ids for tracing). This collides run ids.

Actually — `BaseChatModel::generateMessages` calls `handleChatModelStart` ONCE for the whole batch, then calls `handleLLMEnd` per prompt. So the batch shares a start run but each prompt ends its own. That's the model path. But `Runnable::batch` (the base class) directly calls `$this->invoke($input, $config)` with the SAME config. So each invocation gets the same run_id in the config. That collides in the tracer.

Evidence: `src/LangChain/Runnables/Runnable.php::batch`. Concrete failure: tracing a batched call creates multiple runs with the same `run_id`, which the tracer's run map will dedupe or overwrite. That's a real defect.

Hmm, but is that actually a bug? In upstream, `batch` may be implemented similarly (each item uses the same config), but the callbacks create child run ids. I'm not sure.

Let me skip.

**Finding 5: `ChatOpenAI::generate` uses `$generations[0]->message` for usage.** If the API returns multiple choices, the usage is only read from the first. But OpenAI usage is per-request, so OK.

Hmm.

Let me look at `ChatOpenAI` `new OpenAIException(..., json_encode($payload))` — the third param is a string. If `json_encode` returns false (encode failure), the param is `(string) false` = `''`. Fine.

**Let me look at `ChatAnthropic`'s exception class `AnthropicException`** — but this isn't shown. OK.

**Let me look at whether `ChatAnthropic::streamResponseChunks` yields usage chunks correctly when `streamUsage` is disabled.**

Also note: `MessageOutputs::usageFromEvent($event)` is called for EVERY event, not just message_start/message_delta. That's a "does usage" query that returns [] for other events. Fine.

**Hmm — here's a real thought: the usage chunk for Anthropic is an `AIMessageChunk` with `response_metadata: ['usage_metadata' => $usage]` but WITHOUT `id`.** So if the usage chunk is yielded last, the aggregated message loses its id (if the merge is "last wins" for id). Hmm — actually, for Anthropic, the id arrives on `message_start` (first event) which yields a usage chunk FIRST, then... wait, let me think.

Sequence of Anthropic events:
1. `message_start` — yields usage chunk with usage. This chunk has NO id. But it might have `id` from the event? Let me check — the code is `new AIMessageChunk(['content' => '', 'response_metadata' => ['usage_metadata' => $usage]])`. No id!
2. `content_block_start` — no reference.
3. `content_block_delta` — yields content chunk.
4. ...
5. `content_block_stop` — no eventToChunk.
6. `message_delta` — has `delta.stop_reason`. `eventToChunk` might return null or an empty chunk.
7. `message_stop` — final.

So the aggregated message NEVER gets an id from Anthropic. That's a bug — the id is in the `message_start` event as `message.id`. The port's `MessageOutputs::eventToChunk` MAY handle content_block_start events, but the usage chunk overrides... hmm.

Actually, let me look at the OpenAI path for contrast: the OpenAI usage-only chunk DOES set `'id' => $payload['id'] ?? null`. The Anthropic usage chunk does NOT. That asymmetry is telling.

But if `MessageOutputs::eventToChunk` also emits an `id` for `message_start`, then the id would be set by that chunk. But — the order is: usage chunk yielded first (no id), then eventToChunk's chunk. If eventToChunk's chunk also has the id... hmm. Actually message_start typically has no content, so eventToChunk might return null.

I can't be sure. But — the asymmetry (OpenAI sets id on the usage chunk; Anthropic doesn't) is a signal. Let me tentatively flag this: **`ChatAnthropic::consume`'s usage chunk doesn't propagate the response id, so streamed Anthropic messages lose their provider id.** That's a MAJOR finding if true.

But I need to be careful — maybe `MessageOutputs::eventToChunk` handles it. Or maybe `MessageOutputs::usageFromEvent` returns an object that includes id. Hmm.

Actually — the port's own comment on the OpenAI path explains exactly this issue: "Without it the base class stamps a run-derived id, and since the fold keeps the LAST chunk's id, the folded message would report `run-…` instead of the provider's own `chatcmpl-…`". So they're aware of the pattern. The Anthropic path doesn't do this. That's a real inconsistency.

But note — for Anthropic, the usage chunk is yielded FIRST (message_start is first). And the LAST chunk from the merge... depends. If the content_block_delta chunks come after and carry no id, the merged AIMessageChunk has id from... whatever the first set it. Hmm.

Actually — if the message_start is the first event and it yields `usage_metadata` and no id, and then content blocks yield no id, and message_delta yields nothing (or a chunk with no id), then the merged chunk has `id === null`. Then `BaseChatModel::stream()` calls `stampMessageId($chunk, $runManager)` for EACH chunk — the first chunk (usage chunk) gets stamped with `run-...`. Then later chunks: `if ($message->id !== null) return;` — wait, each chunk is a DIFFERENT object. `stampMessageId` sets the id on each chunk's message individually. The usage chunk gets `run-X`. The content chunks also get `run-X` (each has id null). Then merging keeps the last chunk's id = `run-X`.

So the folded message gets `run-X` instead of the provider's id. Which is the SAME defect the OpenAI path explicitly fixes.

But wait — is the provider's `id` even available in the event? Yes — Anthropic's `message_start` event has `message.id`. `MessageOutputs::eventToChunk` might read it. Hmm.

Given the OpenAI path explicitly fixes this and the Anthropic path explicitly does NOT, it's a real defect pattern. I'll flag it as a candidate finding with the caveat that it depends on `MessageOutputs::eventToChunk` behavior which is `(omitted)` from the packet.

Hmm — I should be careful not to make a guess. Let me hedge. Actually, the packet omits MessageOutputs, so I can't fully verify. But I CAN say: the usage chunks for OpenAI carry `id`, and Anthropic's doesn't, and the port's own comments acknowledge this pattern. That's evidence.

Let me consider whether this is stronger than my other candidates.

Alternative: Let me look for something in the port's own source that has a clear "wrong value written but never read" defect, matching the calibration hint.

**Look at `ChatOpenAI::bindTools`**: `$next->kwargs['strict'] = $value` for `strict`. And `invocationParams` doesn't read `$bound['strict']`. So `strict` in kwargs is written but never read. Written into traces via kwargs(). Hmm — that IS "written but never read". But it's copied because the loop is generic. Not really a "bug".

**Look at `ChatAnthropic::invocationParams`:** `toolChoiceOf` reads `$options['toolChoice'] ?? $options['tool_choice']`. After `normaliseKeys`, `tool_choice` → `toolChoice`, but the original `tool_choice` key is NOT unset. So both are present. Harmless.

**Look at `ChatOpenAI::invocationParams`:** `'user' => $this->pick($options, 'user') ?? ($bound['user'] ?? null)`. Fine.

**Look at `Completions::convertMessage`:** the `FunctionMessage` path sets content. Fine.

**Hmm — `Completions::convertMessages` and message `name` on non-OpenAI-compatible providers.** Nah.

**`ChatOpenAI::generate`:** the `$params['messages'] = Completions::convert