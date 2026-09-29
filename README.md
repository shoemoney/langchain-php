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
use LangChain\Messages\HumanMessage;
use LangChain\OpenAI\ChatOpenAI;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableInterface;

$prompt = ChatPromptTemplate::fromMessages([
    ['system', 'You are a helpful assistant.'],
    ['human', '{question}'],
]);

$model = new ChatOpenAI(model: 'gpt-4o-mini');

$chain = $prompt->pipe($model);

$response = $chain->invoke(['question' => 'Why is the sky blue?']);
echo $response->content;
```

---

## Port status

Coverage is tracked per subsystem in [`PORT_STATUS.md`](./PORT_STATUS.md), which
is regenerated as the port progresses. The test suite is the source of truth:
every ported module ships with its converted tests, and `composer test` must be
green for a subsystem to be called complete.

---

## Architecture

```
src/
  LangChain/
    Utils/         Promise, Await, Observable, env, json, context
    Messages/      BaseMessage + Human/AI/System/Tool/Function, content blocks
    Schema/        Document, PromptValue
    Runnables/     Runnable, Sequence, Parallel, Branch, Lambda, Binding
    Prompts/       Prompt templates, chat prompt templates
    LanguageModels/ BaseChatModel, BaseLLM
    OutputParsers/ String, JSON, structured
    Tools/         StructuredTool, DynamicTool, ToolNode
    Tracers/       Callback managers, LangSmith-compatible tracer
    DocumentLoaders/, TextSplitters/, VectorStores/, Embeddings/
  LangGraph/
    Channels/      BaseChannel, LastValue, BinaryOperatorAggregate, Topic, ...
    State/         StateGraph, CompiledStateGraph
    Pregel/        algo, loop, read, write, checkpoint, runner
    Checkpoint/    BaseCheckpointSaver, MemorySaver, SqliteSaver
    Prebuilt/      createReactAgent, ToolNode
    Func/          entrypoint API
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
