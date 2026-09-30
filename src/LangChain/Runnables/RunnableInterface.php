<?php

declare(strict_types=1);

namespace LangChain\Runnables;

use LangChain\Messages\BaseMessage;
use LangChain\Schema\Document;
use LangChain\Schema\PromptValue;

/**
 * The one interface every composable LangChain component implements.
 *
 * Port of the `Runnable` interface from `@langchain_core/runnables`. It is the
 * load-bearing abstraction of the whole framework: prompts, models, output
 * parsers, retrievers, and tools are all `Runnable`, which is what lets them be
 * piped together with `|` and batched without any component knowing the types of
 * its neighbours.
 *
 * Three methods, three modes:
 *
 *  - `invoke()` — one input, one output;
 *  - `stream()` — one input, a generator of `[channel, chunk]` pairs;
 *  - `batch()` — many inputs, many outputs.
 *
 * The default implementations are meaningful rather than abstract, because
 * most runnables only need to override one: `stream` yields a single
 * `default`-channel chunk carrying the `invoke` result, and `batch` is a
 * concurrency-capped `array_map` over `invoke`.
 */
interface RunnableInterface
{
    /**
     * The name of the run in traces.
     */
    public function getName(): string;

    /**
     * Run this component on one input.
     *
     * @param mixed            $input  Typically a string, `array<string,mixed>`,
     *                                  a list of {@see BaseMessage}, a {@see PromptValue},
     *                                  or a list of {@see Document} — but a runnable
     *                                  declares its own accepted shape.
     * @param RunnableConfig|null $config
     * @return mixed
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed;

    /**
     * Stream this component's output as it is produced.
     *
     * Yields `[channel, chunk]` pairs. The channel is what lets one stream carry
     * several interleaved signal types — model tokens on `default`, progress on
     * a custom channel, retriever output on `retriever` — without the consumer
     * having to guess which is which.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator;

    /**
     * Run this component over many inputs.
     *
     * @param list<mixed> $inputs
     * @param RunnableConfig|null $config
     * @param array<string, mixed>|null $options
     * @return list<mixed>
     */
    /**
     * Run several inputs.
     *
     * `$config` is upstream's `options` — the call options — and is passed
     * through. `$options` is upstream's `batchOptions`
     * (`{maxConcurrency, returnExceptions}`) and the base implementation
     * **ignores it**, which is worth stating rather than leaving to be
     * discovered:
     *
     *  - `maxConcurrency` has no meaning here. PHP is synchronous and this
     *    method maps inputs to results in order, so there is nothing to bound.
     *  - `returnExceptions` would return a `\\Throwable` in place of the result
     *    for a failed input. It is not implemented; a failing input throws.
     *
     * A subclass that can honour either should say so in its own docblock.
     *
     * @param list<mixed>              $inputs
     * @param array<string, mixed>|null $options Upstream `batchOptions`. Unused by default.
     */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array;

    /**
     * Invoke on the first input only, returning an iterable that streams the
     * rest concurrently — the JS `streamEvents` entry point.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator;
}
