<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Graph\Branch;

/**
 * The writer a conditional edge contributes to its source node.
 *
 * Port of the `branchWriter` / `branch.run(...)` writer that
 * `CompiledStateGraph.attachBranch` attaches to a node.
 *
 * A conditional edge is a runnable from a node's output to a destination. This
 * writer runs it and turns the answer into writes — but *what* it writes
 * depends on where the source is, and that distinction is the whole design:
 *
 *  - **From `__start__`**, destinations are seeded as pending writes on the
 *    null task, so the very first step routes correctly.
 *  - **From an ordinary node**, each destination becomes a write to
 *    `branch:to:<destination>` — the same channel a declared edge writes to.
 *    A `Send` is written to `TASKS` instead, carrying its own input.
 *
 * Routing becomes a *write* because Pregel has no control flow — only channels
 * and triggers. A `Command` returned by a node is therefore not a jump; it is
 * an instruction to publish to a channel, and the destination is subscribed.
 * That uniformity is why an edge, a conditional edge, and a `Command` are
 * interchangeable as far as the engine is concerned.
 */
class RunnableBranchWriter implements RunnableInterface
{
    public string $lcGraphName = 'RunnableBranchWriter';

    /** Path evaluation, `pathMap` lookup and destination validation live in {@see Branch}, not here. */
    private readonly Branch $branch;

    /**
     * @param callable|RunnableInterface|Branch $path    Maps node output to destinations.
     * @param bool                              $isStart  Whether the source is `__start__`.
     */
    public function __construct(
        public readonly mixed $path,
        public readonly bool $isStart = false,
        public readonly ?string $start = null,
    ) {
        $this->branch = $path instanceof Branch ? $path : new Branch($path);
    }

    public function getName(): string
    {
        return 'RunnableBranchWriter';
    }

    /**
     * Evaluate the branch and record its routing decisions.
     *
     * Returning no destination is legitimate — a conditional edge that decides
     * to go nowhere — and produces no writes rather than an error. Filtering
     * `__end__` out here is what lets a conditional edge route to END without
     * a node named END existing.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $writes = [];
        foreach ($this->branch->destinations($input, $config) as $dest) {
            if ($dest === Constants::END) {
                continue;
            }

            if ($dest instanceof Send) {
                $writes[] = [Constants::TASKS, $dest];
            } elseif (is_string($dest)) {
                $writes[] = ['branch:to:' . $dest, $this->start ?? '__start__'];
            }
        }

        if ($writes === []) {
            return $input;
        }

        $send = $config?->configurable[Constants::CONFIG_KEY_SEND] ?? null;
        if (!is_callable($send)) {
            throw new \LogicException(
                'A conditional edge requires a write function in config. '
                . 'Make sure to call it in the context of a Pregel process'
            );
        }

        $send($writes);

        return $input;
    }

    /**
     * Yields the invoke result, mirroring `ChannelWrite::stream()` — the peer Pregel write step, which
     * also does `yield $this->invoke(...)`.
     *
     * This previously read `yield from $this->invoke($input, $config)`, and `invoke()` returns `$input`,
     * which for a scalar node value is a STRING. `yield from 'a'` raises
     * `Error: Can use "yield from" only with arrays and Traversables`, so streaming a conditional edge
     * with an ordinary scalar value killed the process. Reproduced in 434:
     *
     *     php -r '... foreach ($w->stream("a", $config) as $c) {}'
     *     Error: Can use "yield from" only with arrays and Traversables
     *
     * NOTE the unresolved convention, deliberately not decided here: `Runnable::stream()` yields a
     * `[CHANNEL_DEFAULT, value]` PAIR while `ChannelWrite::stream()` yields the RAW value. Upstream's
     * `Runnable._streamIterator` (`base.ts:297-302`) does `yield this.invoke(input, options)` — raw. This
     * fix chose the peer-class shape because the fatal is unambiguous under EITHER convention, whereas
     * which shape is correct is a separate question that should be settled against upstream rather than
     * inferred from a crash. Recorded in PORT_STATUS.
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield $this->invoke($input, $config);
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        // Upstream inherits `Runnable.batch` (`base.ts:281`, `:3081`), which honours
        // `batchOptions.returnExceptions` and always yields a list. This class implements
        // `RunnableInterface` directly rather than extending `Runnable`, so it delegates to the port's
        // single implementation instead of hand-rolling `array_map` over `invoke()` — the hand-rolled copy
        // read neither `$options` nor `array_values($inputs)`, so `returnExceptions` was silently discarded
        // and a string-keyed batch came back string-keyed. Found by iteration 432; see PORT_STATUS.
        return \LangChain\Runnables\Runnable::batchEachFor($this, $inputs, $config, $options);
    }

    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($input as $item) {
            yield $this->invoke($item, $config);
        }
    }
}
