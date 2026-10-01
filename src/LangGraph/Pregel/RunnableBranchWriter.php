<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;

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

    /**
     * @param callable|RunnableInterface $path    Maps node output to destinations.
     * @param bool                       $isStart  Whether the source is `__start__`.
     */
    public function __construct(
        public readonly mixed $path,
        public readonly bool $isStart = false,
        public readonly ?string $start = null,
    ) {
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
        $result = $this->evaluate($input, $config);

        if ($result === null || $result === [] || $result === false || $result === '') {
            return $input;
        }

        $writes = [];
        foreach ((array) $result as $dest) {
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

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->invoke($input, $config);
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(fn (mixed $i): mixed => $this->invoke($i, $config), $inputs);
    }

    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($input as $item) {
            yield $this->invoke($item, $config);
        }
    }

    private function evaluate(mixed $input, ?RunnableConfig $config): mixed
    {
        if ($this->path instanceof RunnableInterface) {
            return $this->path->invoke($input, $config);
        }

        \assert(is_callable($this->path));

        return ($this->path)($input, $config);
    }
}
