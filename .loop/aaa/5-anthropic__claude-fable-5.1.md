# Triple-A cycle 5 - anthropic/claude-fable-5.1
_asked 2026-10-01T05:39:04 - 93s - max_tokens 24,000_

## 1. A `runName` bound on a chat model never reaches the trace run's name

**Severity:** MINOR
**Where:** `src/LangChain/LanguageModels/BaseChatModel.php:303` (and `:408`), fed by `src/LangChain/Runnables/RunnableBinding.php` `mergeConfig()`
**Input → Output:** `$model->bind([], ['runName' => 'pull_person'])->invoke($messages)` with a `RunCollectorCallbackHandler` attached → the collected run's `name()` is the component id (`ChatOpenAI`), not `pull_person`. The brief does not ship the live `RunnableBinding` body, so the exact line where the merged config diverges from the config the model receives is not shown; the model-side reads at `:303`/`:408` are the cited locations.
**Should be:** `name() === 'pull_person'` — upstream `withConfig({ runName })` on a model makes that the run name.
**Why it outs the port:** the loss is silent (`Run::name()` falls back to serialized id, then `runType`), so a consumer grouping LangSmith-style traces by the name they bound gets a plausible-looking but wrong name and never sees an error. A port that advertises tracers must carry the one field a user explicitly sets.

## 2. Empty inner maps in `versions_seen` still leave as `[]`

**Severity:** MINOR
**Where:** `src/LangGraph/Pregel/Checkpoint/Checkpoint.php` — `toArray()` (the `(object)` cast line; exact line not shown in the brief)
**Input → Output:** a checkpoint whose `versions_seen` is `['fan_out' => []]` — which the algorithm produces for any task whose only trigger is a `Send` push (no entry in `channel_versions`, so the inner map is created and never filled) — encodes as `"versions_seen":{"fan_out":[]}`. The outer cast makes the top level an object; nothing recurses, so the inner empty map is a PHP `[]` and `json_encode` writes a JSON array.
**Should be:** `"versions_seen":{"fan_out":{}}` — the type of every inner value is KNOWN to be a map, exactly the argument the ledger makes for the outer level.
**Why it outs the port:** the stated interop guarantee is "a checkpoint written here is readable by the JS savers". A JS reader doing `Object.entries(versions_seen[name])` tolerates it, but anything type-checking the record (a Zod schema, a Postgres `jsonb` path query expecting an object) sees an array where every other writer puts an object. It is the same defect class as `fce8bc4`, one level down, and the empty case is the one a `Send`-based fan-out hits on its first superstep.

## 3. A streamed tool call with no arguments folds to `args: ""`, which is not JSON

**Severity:** MAJOR
**Where:** `src/LangChain/Messages/AIMessageChunk.php` — the `tool_call_chunks` → `tool_calls` decode in the fold/`concat()` path (line not shown in the brief); provider side `src/LangChain/LanguageModels/Chat/Anthropic/Utils/MessageOutputs.php`
**Input → Output:** Anthropic streams `content_block_start` `{type: tool_use, name: "ping", input: {}}` followed by `content_block_stop` with no `input_json_delta` (the normal wire form for a zero-argument tool). The chunk carries `tool_call_chunks[0].args === ""`. Folding then decoding `""` with `json_decode` yields `null`, so the call either lands in `invalid_tool_calls` or surfaces with `args: null` — the brief does not show whether the port applies upstream's `args || "{}"` default, and no ledger row records it.
**Should be:** `tool_calls[0].args === []`/`{}` — upstream `ai.ts` does `parsePartialJson(rawToolCall.args || "{}")`, so an empty arg string is a valid empty object.
**Why it outs the port:** the eager path was fixed (`{"arguments":"{}"}`, fix `a0db8c8`), but a tool that takes nothing is the most common tool in a demo and streaming is the most common mode. A consumer whose streamed agent "never calls `ping`" has no error to chase — the call simply isn't there.

## 4. `interrupt()` cannot be resumed with a value (structural)

**Severity:** MAJOR
**Where:** `src/LangGraph/Pregel/interrupt.php` (whole file) and `src/LangGraph/Pregel/Command.php`
**Input → Output:** a node calls `interrupt('approve?')`; the graph raises `GraphInterrupt` and checkpoints. The consumer then calls `$graph->invoke(new Command(resume: true), $config)` → the resume value never reaches the paused node; the brief states the resume-with-values path is unported, and `IO.php` only truncates existing resumes (fix `17b7bd8`) rather than delivering a new one.
**Should be:** `interrupt()` returns `true` inside the node on the second run, as in `langgraph-js` `interrupt.ts`.
**Why it outs the port:** human-in-the-loop is the single reason most users adopt LangGraph's checkpointing. Shipping `interrupt()` that throws but cannot be answered is a half-API: the caller gets the pause, builds a UI around it, and only then discovers the resume is a no-op. This is the one structural item; the rest of the budget is on craft above.