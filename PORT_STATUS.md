# Port status

Live accounting of what has been ported, verified by the converted test suite.

**Rule:** a subsystem is only marked ported when its tests exist in this repo
and pass. Nothing is marked on the strength of the TypeScript source alone.

Run the evidence yourself:

```bash
composer test
```

---

## Legend

| | Meaning |
|---|---|
| ✅ | Ported, tests ported and passing |
| 🟡 | Partially ported — see notes |
| ⬜ | Not started |
| ⛔ | Deliberately out of scope, with the reason |

---

## langchain-core

| Subsystem | Status | Notes |
|---|---|---|
| `utils/promise` + event loop | ✅ | `Promise`, `Await` (fiber scheduler), 18 tests |
| `utils/stream` (Observable) | ✅ | `Observable` |
| `messages` | ✅ | Full hierarchy, content blocks, chunk fold, 47 tests |
| `messages/utils` (coercion, buffer string, stored form) | ✅ | incl. v1 checkpoint upgrade |
| `messages/format` | ✅ | |
| `load/serializable` | ✅ | Byte-compatible payload shape |
| `runnables` | ✅ | `Runnable`, Sequence, Lambda, Parallel, Branch, Binding, WithFallbacks, Each |
| `runnables` config | ✅ | `RunnableConfig` |
| `documents` (`Document`) | ✅ | |
| `prompt_values` | ✅ | `StringPromptValue`, `ChatPromptValue` |
| `text_splitters` | ✅ | Character, RecursiveCharacter, Markdown, Latex, 16 language tables |
| `language_models` | ⬜ | Next |
| `prompts` | ⬜ | |
| `output_parsers` | ⬜ | |
| `tools` | ⬜ | |
| `tracers` / `callbacks` | ⬜ | |
| `embeddings` / `vectorstores` | ⬜ | |
| `utils` (env, json patch, function_calling, standard_schema, tiktoken) | ⬜ | |
| `structured_query`, `indexing`, `example_selectors` | ⬜ | |
| `load/import_map`, `load/import_constants` | ⬜ | |

## langchain (main package)

| Subsystem | Status | Notes |
|---|---|---|
| Provider integrations | ⬜ | OpenAI + Anthropic next; the rest are HTTP wrappers over these |
| `agents` | ⬜ | |
| `memory` | ⬜ | |
| `retrievers` | ⬜ | |
| `document_loaders` | ⬜ | |

## langgraph-core

| Subsystem | Status | Notes |
|---|---|---|
| `channels` | ✅ | `BaseChannel`, LastValue, LastValueAfterFinish, AnyValue, Ephemeral, NamedBarrier, BinaryOperatorAggregate, Topic, Untracked, Overwrite + registry, 62 tests |
| `errors` | 🟡 | `BaseLangGraphError`, `EmptyChannelError`, `InvalidUpdateError` done. `GraphBubbleUp`, `GraphRecursionError`, `GraphInterrupt`, `GraphDrained` pending — they belong to the Pregel/interrupt work |
| `state` | ⬜ | `StateGraph`, `CompiledStateGraph` |
| `pregel` | ⬜ | **The core.** `algo`, `loop`, `read`, `write`, channels, checkpoint, runner |
| `graph` | ⬜ | `Graph`, `MessageGraph`, edges, drawing |
| `func` | ⬜ | `entrypoint`/`task` |
| `prebuilt` | ⬜ | `createReactAgent`, `ToolNode` |
| `interrupt` | ⬜ | |
| `constants`, `hash`, `utils` | ⬜ | |

## langgraph-checkpoint

| Subsystem | Status | Notes |
|---|---|---|
| `BaseCheckpointSaver` | ⬜ | |
| `MemorySaver` | ⬜ | |
| `serde` (`Serialization`) | ⬜ | |
| `sqlite` / `postgres` / `redis` / `mongodb` savers | ⬜ | |

## langgraph (client SDK)

| Subsystem | Status | Notes |
|---|---|---|
| `client` (REST) | ⬜ | |
| `runs`, `threads`, `stores`, `assistants` clients | ⬜ | |
| `sdk-react` / `vue` / `svelte` / `angular` | ⛔ | Browser UI bindings. No PHP analogue exists; porting them would mean inventing a frontend. |
| `langgraph-cli` / `langgraph-api` | ⛔ | Python toolchain and codegen server. Not a TypeScript SDK. |

---

## Known non-exact behaviours

Places where PHP genuinely cannot reproduce JavaScript, documented rather than
papered over. Each is pinned by a test so the boundary stays visible.

| Behaviour | Why | Where |
|---|---|---|
| `TextSplitter` default length function counts UTF-16 code units, not code points | `String.length` in JS is UTF-16; `mb_strlen` diverged on 34/400 emoji cases | `TextSplitters\TextLength::utf16CodeUnits()` |
| Splitting an astral character never yields a lone surrogate | A lone surrogate has no valid UTF-8 encoding, and `json_encode` refuses it outright | split by code point via `mb_str_split` |
| `Topic`'s `seen` set keys on a type-tagged serialization | PHP arrays have no reference identity, so `1` and `"1"` would otherwise collide | `Topic::key()` |
| `NamedBarrierValue` compares set membership, not sequence | Arrival order is nondeterministic under concurrency | `barrierSatisfied()` |
| `fromCheckpoint()` is an instance method, not static | Matches the TS prototype-call shape; a static call would lose the channel's config | all channel classes |

---

## Upstream test conversion ledger

| Suite | Upstream | Ported |
|---|---|---|
| `messages` | ~40 | 47 (incl. added edge cases) |
| `runnables` | ~90 | 29 |
| `text_splitters` | 37 | 35 verbatim + 71 added |
| `langgraph channels` | 74 | 62 |
| **Total so far** | | **250** |
