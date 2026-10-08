# langchain-php completion plan

Dependency-ordered work packages (WP) to finish the port. Planned 2026-10-07.
Each WP is sized for one implementer session. Upstream reference sources are
READ ONLY (never modify anything under `/Users/shoemoney/Projects/agentdesk`).

Path prefixes:
`LG` = `/Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langgraphjs/libs/langgraph-core/src`,
`LGL` = `/Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langgraphjs/libs`,
`LC` = `/Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langchainjs/libs/langchain-core/src`,
`LM` = `/Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langchainjs/libs/langchain/src`,
`PV` = `/Users/shoemoney/Projects/agentdesk/.runtime/js-sdks/langchainjs/libs/providers`,
`P` = `/Users/shoemoney/Projects/langchain-php`.

## 1. Definition of "complete"

**In scope**

| Area | Why |
|---|---|
| langgraph-core: `graph/` (Graph, CompiledGraph, Branch, StateGraph parity, MessageGraph, drawing), `prebuilt/`, `func/`, remaining `pregel/` (messages stream mode, debug, validate, timeout, updateState/time-travel, durability), `utils.ts`, `hash.ts` | Engine is ported; these are the user-facing layers. Port's `StateGraph.php` is 611 lines vs upstream `state.ts`+`graph.ts` 3,521. |
| langgraph-checkpoint `store/` + `cache/`; Postgres, Redis, MongoDB savers; store backends | `BaseStore` is referenced by prebuilt/func/agents; zero references in `P/src` today. |
| supervisor (542) + swarm (308) | Small; best end-to-end test of `createReactAgent` + `Command`. |
| langgraph `sdk/client/*` REST surface + `sdk/utils` (~3.5k) | Drive LangGraph Platform from PHP. |
| `pregel/remote.ts` RemoteGraph (920) | Hosted graph as a node. |
| langchain-core: `utils/{env,json,json_schema,function_calling,math,async_caller,context,uuid,namespace,fast-json-patch}`, `singletons/async_local_storage`, `tracers/{log_stream,event_stream}`, `embeddings`, `vectorstores`, `retrievers`, `example_selectors`, `indexing`, `structured_query`, `document_loaders/base`, `caches`, `stores`, `chat_history`, `memory`, `runnables/graph*` | Dependencies of agents/graph drawing. |
| langchain main: `agents/` (createAgent + middleware), `chat_models/universal.ts` (initChatModel), `storage/` | createAgent is the current top-level API. |
| Providers: OpenAI remainder (Responses API, azure, profiles, hosted tools, embeddings, legacy `llms`), Anthropic remainder (`output_parsers`, `utils/standard`, `stream_events`, `tools/`, `profiles`), thin OpenAI-compatible wrappers **fireworks, together, deepseek, xai completions, openrouter**, plus **ollama** | groq/mistral/cohere/perplexity use their own SDKs — excluded. |

**Out of scope (⛔)**: `sdk-react/vue/svelte/angular`, `langgraph-ui`, `langgraph-cli/api`, `create-*` (UI/toolchain); `sdk/src/stream/` + `sdk/src/client/stream/` (browser `useStream` state machinery); `langgraph-cua` (needs a VM provider); `utils/zod-to-json-schema`, `utils/types/zod.ts`, `graph/zod/*` (port is JSON-Schema-native); `utils/sax-js`, `js-sha256`, `p-retry` (PHP builtins); aws/google/ibm/cohere/groq/mistral/perplexity/tavily/exa/neo4j/vector-DB providers; `langchain-classic`; `hub/` + LangSmith HTTP tracer; `middleware/provider/aws`. `langchain-mcp-adapters` is a stretch goal (WP-25).

## 2. Global rules for every WP

- Port the upstream **tests** with the source. State in the final report which `describe` blocks were converted and which were skipped, with reasons.
- **Do not edit** `PORT_STATUS.md`, `HANDOFF.md`, `README.md`, `.loop/*`, or `composer.json`. Put the PORT_STATUS row text and HANDOFF deltas in the final report; the integrator applies them.
- New functions are exposed as **static methods** (`Func::entrypoint()`, `ReactAgent::create()`), not new `composer.json` `files` entries.
- Only touch existing files your WP is the **exclusive owner** of (see Waves). Everything else is new files in new directories.
- Contravariance (HANDOFF §6): overrides may widen, never narrow, parameter types. A class-load fatal takes the whole suite down.
- Concurrency (`Promise.all`) becomes sequential; preserve ordering and report it as a known non-exact behaviour.
- Zod paths accept JSON Schema arrays or `AnnotationRoot`. Do not invent a PHP schema DSL.
- External services (Postgres/Redis/Mongo/HTTP) sit behind an interface seam with a fake; integration tests are env-gated and skip cleanly.
- Commit prefixes: `feat:` / `test:` / `refactor:` — **never `fix:`** (`FixesAreDocumentedTest` requires a ledger row for every `fix:` commit).

## 3. Pre-wave (Wave 0, sequential)

**WP-00 Foundations** — Port `LG/utils.ts` (`RunnableCallable`, `patchConfigurable`, `prefixGenerator`, `gatherIterator`) → `LangGraph\Utils\*`; `LG/hash.ts` XXH3 → `LangGraph\Utils\Hash` with `LG/tests/hash.test.ts`; `LG/tests/utils.test.ts`. Port `LG/graph/messages_reducer.ts` + `LG/graph/messages_annotation.ts` (`MessagesAnnotation` only) → `LangGraph\Graph\{MessagesReducer,MessagesAnnotation}`; tests `LG/graph/messages_reducer.test.ts`, the non-MessageGraph part of `LG/graph/message.test.ts`. `LG/interrupt.ts` options parity; convert `LG/tests/interrupt.test.ts` and the interrupt sections of `LG/tests/pregel.test.ts` (nested subgraph resume, multiple interrupts per node) — resume handling already exists at `PregelLoop.php` (`CONFIG_KEY_RESUME_MAP`) and `tests/Unit/Pregel/PregelLoopTest.php`; fix gaps if any. Extract `tests/Unit/Checkpoint/CheckpointerSpecTest.php` into an abstract `CheckpointerSpecCase` with `abstract protected function makeSaver()` + two concrete subclasses (Memory, Sqlite).

**WP-01 Store + Cache + config plumbing** — `LGL/checkpoint/src/store/{base,memory,batch,utils}.ts`, `LGL/checkpoint/src/cache/{base,memory}.ts` → `LangGraph\Store\*`, `LangGraph\Cache\*`; tests `LGL/checkpoint/src/tests/{store,cache,namespace,memory-pollution}.test.ts`. Port `LC/embeddings.ts` (`Embeddings` interface) for vector search. Thread `store`/`cache` through `Pregel::__construct`, `StateGraph::compile(store:, cache:)`, store injection in `Algorithm::prepareSingleTask`; type `ToolRuntime::$store` as `?BaseStore`. Touches `Pregel.php`, `PregelLoop.php`, `Algorithm.php`, `StateGraph.php`, `Constants.php`, `ToolRuntime.php`.

## 4. Work packages

### LangGraph core
- **WP-02 ToolNode + toolsCondition + agentName** — `LG/prebuilt/tool_node.ts`, `LG/prebuilt/agentName.ts` → ToolNode `describe` blocks of `LG/tests/prebuilt.test.ts`, `LG/tests/prebuilt/agentName.test.ts`. Target `LangGraph\Prebuilt\{ToolNode,ToolsCondition,AgentName}`. Wires orphans `BaseToolkit`/`FakeTool` (do not edit them). Hazards: `Command`/`Send` from tools; `isGraphInterrupt` rethrow; `handleToolErrors` as `bool|callable`.
- **WP-03 createReactAgent** — `LG/prebuilt/react_agent_executor.ts`, `LG/prebuilt/interrupt.ts` → remaining `LG/tests/prebuilt.test.ts`. Target `LangGraph\Prebuilt\ReactAgent::create()` + `AgentState`. Port `FakeToolCallingChatModel` from `LG/tests/utils.models.ts` to `LangChain\Utils\Testing`. Deps WP-02, WP-05.
- **WP-04 Graph builder base** — `LG/graph/graph.ts` (`Graph`, `CompiledGraph`, `Branch`), `LG/graph/message.ts` (`MessageGraph`), `LG/graph/types.ts` → `LG/tests/graph.test.ts`, rest of `LG/graph/message.test.ts`. Target `LangGraph\Graph\{Graph,CompiledGraph,Branch,MessageGraph}`; refactor `State\StateGraph` to extend `Graph\Graph` and `Pregel\CompiledStateGraph` to extend `Graph\CompiledGraph`. Reconcile with existing `RunnableBranchWriter.php` — no duplicates.
- **WP-05 StateGraph parity** — `LG/graph/state.ts`: `addSequence`, input/output schemas, `NodePolicies` (`retryPolicy`, `cachePolicy`, `onError`), `validate`, subgraph detection (`LG/pregel/utils/subgraph.ts`), `defaultNodeOptions`, `ends` → `LG/graph/state.test.ts`, `LG/tests/node_error_handler.test.ts`, `set_node_defaults.test.ts`, `graph_callbacks.test.ts`. Deps WP-04.
- **WP-06 Drawing** — `LC/runnables/graph.ts`, `graph_mermaid.ts` → `LC/runnables/tests/runnable_graph.test.ts`; `getGraph()`/`getSubgraphs()` on CompiledGraph/Pregel; `LG/tests/diagrams.test.ts`. Target `LangChain\Runnables\Graph\*`. Node-oracle the Mermaid text.
- **WP-07 Functional API** — `LG/func/{index,types}.ts`, `LG/pregel/call.ts` → `LG/tests/func.test.ts`. Target `LangGraph\Func\*` (`Func::entrypoint()`, `Func::task()`, `Func::getPreviousState()`). May need `Algorithm.php` (PUSH tasks with a `Call` payload) — runs after WP-08b.
- **WP-08a Pregel streaming remainder** — `LG/pregel/messages.ts`, `messages-v2.ts`, `debug.ts` gaps, `stream.ts` → `LG/pregel/messages.test.ts`, `messages-v2.test.ts`, `debug.test.ts`, `LG/tests/pregel.stream.test.ts`, `LG/tests/pregel/stream.test.ts`. Target `LangGraph\Pregel\Messages\*`.
- **WP-08b State mgmt + durability + validate/timeout** — `LG/pregel/index.ts` `updateState`/`bulkUpdateState`/`getStateHistory` filters/`getSubgraphs`/`durability`, `LG/pregel/validate.ts`, `timeout.ts`, `utils/config.ts` → `LG/tests/time_travel.test.ts`, select `time_travel_extended.test.ts`, `pregel.validate.test.ts`, `LG/pregel/validate.test.ts`, `utils/config.test.ts`, `run_control.test.ts`, partial `timeout.test.ts`. Deps WP-08a.

### Persistence
- **WP-09a PostgresSaver** — `LGL/checkpoint-postgres/src/{index,sql}.ts` → `tests/sql.test.ts` (unit), `checkpoints.int.test.ts` (env-gated on `LANGGRAPH_PG_DSN`). `PostgresSaverSpecTest extends CheckpointerSpecCase`.
- **WP-09b RedisSaver + ShallowRedisSaver** — `LGL/checkpoint-redis/src/{index,shallow,utils}.ts` → unit tests with a `RedisClientInterface` fake; env-gated int tests.
- **WP-09c MongoDBSaver** — `LGL/checkpoint-mongodb/src/checkpoint.ts` behind a `MongoCollectionInterface` fake.
- **WP-10 Store backends** — postgres/redis/mongodb `store` files; env-gated.

### LangChain core gaps
- **WP-12a Core utils A** — `LC/utils/{env,json,json_schema,function_calling,math,namespace}.ts`, `utils/uuid/*` → their tests. Extend existing `Tools/ToolUtils.php`, `OutputParsers/JsonUtils.php`; no duplicates.
- **WP-12b Core utils B** — `LC/utils/fast-json-patch/*`, `utils/async_caller.ts`, `utils/context.ts`, `singletons/async_local_storage/*` → their tests. Fold existing `OutputParsers/JsonPatch.php` into `Utils\JsonPatch`.
- **WP-13a Retrieval stack** — `LC/vectorstores.ts` (+ `MemoryVectorStore`), `retrievers/*`, `example_selectors/*`, `indexing/*`, `document_loaders/base.ts`. Deps WP-12a, WP-01.
- **WP-13b Stores/caches/memory/storage** — `LC/stores.ts`, `caches/index.ts`, `chat_history.ts`, `memory.ts`, `LM/storage/*`. Adds cache support to `BaseChatModel.php`/`BaseLLM.php`.
- **WP-14 Structured query** — `LC/structured_query/*` → its tests.
- **WP-15 streamEvents / streamLog** — `LC/tracers/log_stream.ts`, `event_stream.ts` → tracer + `runnable_stream_events*` tests. Adds `streamEvents()`/`streamLog()` to `Runnable`.

### Agents
- **WP-21a Agent foundations** — `LM/agents/{state,annotation,errors,utils,RunnableCallable,runtime,model,withAgentName}.ts`, `nodes/ToolNode.ts`, `nodes/{types,utils}.ts` + tests. Separate class from WP-02 ToolNode.
- **WP-21b ReactAgent + createAgent** — `LM/agents/ReactAgent.ts`, `nodes/AgentNode.ts`, Before/After nodes, `middleware.ts`, `middleware/{types,utils}.ts`, `index.ts` + tests.
- **WP-21c Structured responses + transformers** — `LM/agents/responses.ts`, `transformers/*` + tests.
- **WP-22a/b/c Middleware** — limits & retries / HITL+summarization+contextEditing / pii+todo+toolSelector+emulator+toolSearch+provider (anthropic caching, openai moderation).
- **WP-20 initChatModel** — `LM/chat_models/universal.ts`, registry limited to ported providers.

### Providers
- **WP-16a OpenAI Responses converters (input)** — `PV/langchain-openai/src/converters/responses.ts` input side, `utils/tools.ts` Responses tool conversion → input `describe`s of `converters/tests/responses.test.ts`. Target `LangChain\LanguageModels\Chat\OpenAI\Converters\*`.
- **WP-16b Responses output + split** — output side of converters, `chat_models/responses.ts`, `utils/responses_stream_events.ts`, `useResponsesApi` routing; split `ChatOpenAI` into `BaseChatOpenAI`/`ChatOpenAICompletions`/`ChatOpenAIResponses` with `ChatOpenAI` as facade. Riskiest provider change.
- **WP-17a Azure + profiles + embeddings + legacy LLM**; **WP-17b OpenAI hosted tools**.
- **WP-18a Anthropic standard content/stream events/output parsers/profiles**; **WP-18b Anthropic hosted-tool builders**.
- **WP-19a Fireworks/Together/DeepSeek**; **WP-19b xAI completions + OpenRouter**; **WP-19c Ollama** (chat + embeddings, own HTTP API).

### SDK + remote + multi-agent
- **WP-23a SDK core** — `LGL/sdk/src/client/{base,index}.ts`, `client/{assistants,threads,store,crons}/index.ts`, `utils/{sse,async_caller,error,env,tools}.ts` + tests. Target `LangGraph\Sdk\*`. Reuse `HttpClient` + `SseParser`.
- **WP-23b SDK runs + streaming** — `client/runs/index.ts`, `utils/{stream,reconnect,signals}.ts` + tests.
- **WP-26 RemoteGraph** — `LG/pregel/remote.ts`, `remote-run-stream.ts` + tests.
- **WP-11 Supervisor + Swarm** — `LGL/langgraph-supervisor/src/*`, `LGL/langgraph-swarm/src/*` + tests.
- **WP-25 (stretch) MCP tool conversion** — `langchain-mcp-adapters/src/{tools,content}.ts` over an `McpClientInterface`.

## 5. Waves and exclusive file ownership

| Wave | WPs | Exclusive owners of existing files |
|---|---|---|
| 0 | WP-00 → WP-01 (sequential) | everything they touch |
| 1 | 02, 04, 08a, 09a, 09b, 12a, 12b, 14, 15, 16a, 18a, 19c, 23a | 04 → `StateGraph.php`, `CompiledStateGraph.php`; 08a → `Pregel.php`, `PregelLoop.php`; 15 → `Runnable.php`, `RunnableInterface.php`; 18a → `ChatAnthropic.php` + its `Utils/`; 12a → `ToolUtils.php`, `JsonUtils.php`; 12b → `OutputParsers/JsonPatch.php` |
| 2 | 03, 05, 08b, 09c, 13a, 13b, 16b, 21a, 23b, then 07 | 05 → `StateGraph.php`; 08b → `Pregel.php`, `PregelLoop.php`, `Algorithm.php`; 07 after 08b; 16b → `ChatOpenAI.php`; 13b → `BaseChatModel.php`, `BaseLLM.php` |
| 3 | 06, 11, 17a, 17b, 18b, 19a, 19b, 21b → 21c, 26 | 06 → `Runnable.php`, `Pregel.php` |
| 4 | 20, 22a, 22b, 22c, 10 (×3), 25 | new directories only |

## 6. Integration protocol

1. Implementer: worktree + branch `port/wp-XX`; `composer install -q`; `php -l` new files; run `python3 .loop/sync_docs.py` then `composer dump-autoload -o -q && composer test` **twice**; `git checkout -- HANDOFF.md PORT_STATUS.md` before committing (doc counts are the integrator's).
2. Review: one Opus pass per wave over every branch; rejected WPs bounce back to their implementer branch.
3. Integrator merges in wave order (exclusive owners first), after each merge: `composer dump-autoload -o -q`, `python3 .loop/sync_docs.py`, full `composer test`. A merge that goes red is reverted and reported, never force-fixed blind.
4. After the wave: apply PORT_STATUS rows + HANDOFF deltas, `sync_docs.py`, `composer test`, grep-verify doc edits landed, commit, push.
5. Orphan audit (`reference_counts()` in `.loop/advisory.py`) after Waves 1 and 3.
