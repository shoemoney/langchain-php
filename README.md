# langchain-php

A **faithful PHP port** of the [LangChain JS](https://github.com/langchain-ai/langchainjs) and
[LangGraph JS](https://github.com/langchain-ai/langgraphjs) SDKs.

This is not a thin wrapper and not a re-imagining. It is a direct port: the same
class hierarchies, the same algorithms, the same LCEL composition semantics, and
the same Pregel durable-execution engine — translated into idiomatic PHP and
verified by ported test suites.

> **Status:** active port. See [Port status](#port-status) for exactly what is
> covered and what is not. Nothing below is claimed beyond what the test suite proves.

---

## Why PHP?

LangChain's abstractions — a protocol-based `Runnable` interface, lazily
composed chains, streaming, and an actor-model graph runtime with durable
checkpointing — are language-agnostic. What has been missing is a port that
keeps the semantics exact instead of flattening them into a Laravel-ish helper.

This port takes the opposite stance: **fidelity first.** If TypeScript does it,
PHP does it, with the same names.

---

## Install

```bash
composer require shoemoney/langchain-php
```

Requires PHP 8.2+.

---

## Design notes

Three decisions make the port possible without a single `\Closure` soup.

### 1. Promises, resolved synchronously

TypeScript threads a `Promise` through every entry point. PHP has no
language-level async, but it does have **Fibers** (8.1+). `LangChain\Utils\Promise`
implements the real algebra — `then`, `catch`, `finally`, flattening — and
`Await::sync()` drives it. You write the shape you know:

```php
$result = $chain->invoke(['input' => 'hello'], $config);
```

and the internals stay promise-shaped so ported logic maps one-to-one.

### 2. Streaming is a `Generator`

`Runnable::stream()` returns a `\Generator` of `[channel, value]` tuples —
structurally identical to the `[channel, chunk]` tuples the JS
`EventSource`/`Observable` emits.

```php
foreach ($chain->stream($input) as $channel => $chunk) {
    echo $chunk;
}
```

### 3. Serialization is a real interface

`Serializable` provides `toJson()` / `lcNamespace()` / `lcId()` and is used by
the checkpointers, so serialized state is portable across processes exactly as
it is in JS.

---

## Quick start

```php
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Prompts\ChatPromptTemplate;

$prompt = ChatPromptTemplate::fromMessages([
    ['system', 'You are a helpful assistant.'],
    ['human', '{question}'],
]);

$model = new ChatOpenAI(['model' => 'gpt-4o-mini']);

$chain = $prompt->pipe($model);

$response = $chain->invoke(['question' => 'Why is the sky blue?']);
echo $response->content;
```

---

## Port status

Coverage is tracked per subsystem in [`PORT_STATUS.md`](./PORT_STATUS.md). The test
suite is the source of truth: every ported module ships with its converted tests, and
`composer test` must be green for a subsystem to be called complete. **The SDK port is not
complete**; the table summarises, `PORT_STATUS.md` has the evidence and the known
non-exact behaviours.

| Area | State | Notes |
|---|---|---|
| Messages, runnables (LCEL), prompts, output parsers, tools, tracers | Ported | `streamEvents`/`streamLog` are partial |
| Chat models | Partial | `ChatOpenAI` (Chat Completions), `ChatAnthropic`, `ChatOllama`; OpenAI Responses API is input side only |
| Structured query, text splitters, embeddings seam | Ported | `OllamaEmbeddings` is the only concrete embeddings class; no vector stores |
| Pregel engine, channels, `StateGraph`, `Graph`, `MessageGraph` | Ported | `StateGraph` parity and drawing still open |
| Prebuilt | Partial | `ToolNode`, `toolsCondition`; `createReactAgent` not yet |
| Checkpointers | Partial | Memory, SQLite, Postgres (env-gated in CI), Redis (tested against a fake client); no MongoDB |
| Store and cache | Ported | In-memory store and cache |
| LangGraph client SDK | Partial | assistants, threads, store, crons; no runs client or streaming |
| UI bindings (`sdk-react` etc.) | Out of scope | Browser-only |

---

## Architecture

```
src/
  LangChain/
    Embeddings/      Embeddings interface, OllamaEmbeddings
    LanguageModels/  BaseChatModel, BaseLLM, Outputs/
      Chat/          OpenAI/, Anthropic/, Ollama/
    Load/            Serializable
    Messages/        BaseMessage + Human/AI/System/Tool/Function, content blocks
    OutputParsers/   String, JSON, structured, OpenAITools/
    Prompts/         Prompt templates, chat prompt templates
    Runnables/       Runnable, Sequence, Parallel, Branch, Lambda, Binding, Assign, Passthrough
    Schema/          Document, PromptValue
    StructuredQuery/ Query IR, visitors, translators
    TextSplitters/   Character, Recursive, Markdown, Latex, language tables
    Tools/           StructuredTool, DynamicTool, BaseToolkit, ToolRuntime
    Tracers/         Callback managers, tracers, event-stream and log-stream handlers
    Utils/           Promise, Await, Observable, env, JSON patch, AsyncCaller, ...
      Http/          HttpClient seam, Guzzle client, SSE parser
      Testing/       Fakes
  LangGraph/
    Cache/           BaseCache, InMemoryCache
    Channels/        BaseChannel, LastValue, BinaryOperatorAggregate, Topic, ...
    Checkpoint/      BaseCheckpointSaver, MemorySaver, SqliteSaver
      Postgres/      PostgresSaver
      Redis/         RedisSaver, ShallowRedisSaver
      Serde/         JsonPlusSerializer
    Errors/          Interrupts, recursion and update errors
    Graph/           Graph, CompiledGraph, Branch, MessageGraph, messages reducer
    Prebuilt/        ToolNode, toolsCondition, agentName
    Pregel/          algo, loop, read, write, runner, retry
      Checkpoint/    Engine-side saver contract
      Messages/      messages / tools stream handlers
    Sdk/             LangGraph API client (assistants, threads, store, crons)
    State/           Annotation, StateGraph, CompiledStateGraph
    Store/           BaseStore, InMemoryStore
    Utils/           Hash, helpers
```

---

## Development

```bash
composer install
composer test                 # full suite
composer test -- --testsuite unit
composer coverage
```

The port is verified by *converted* tests — assertions, fixtures, and edge cases
are carried over from the TypeScript suites, including the property tests and
the Python-parity conformance tests from `langgraphjs`.

---

## License

MIT
