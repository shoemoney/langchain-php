<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;

/**
 * The runnable that reads channel values out of the graph's state.
 *
 * Port of `ChannelRead` from `langgraph-core/src/pregel/read.ts`.
 *
 * The read function is injected into the task config by
 * {@see Algorithm::prepareSingleTask()}, not constructed here — that is what
 * lets a read inside a conditional edge see the state *as of that node's own
 * writes*, rather than the committed state. `fresh` selects that: `false`
 * reads committed state, `true` folds the task's in-flight writes over a
 * throwaway copy of the channels first.
 */
class ChannelRead extends RunnableLambda
{
    public string $lcGraphName = 'ChannelRead';

    /**
     * @param string|list<string>    $channel Channel(s) to read.
     * @param bool                  $fresh   Overlay the task's own pending writes.
     * @param callable|null         $mapper  Post-process the read value.
     * @param list<string>          $tags    Trace labels.
     */
    public function __construct(
        public readonly string|array $channel,
        public readonly bool $fresh = false,
        public readonly mixed $mapper = null,
        public readonly array $tags = [],
    ) {
        parent::__construct(static function (mixed $input, ?RunnableConfig $config = null) use ($channel, $fresh, $mapper): mixed {
            return self::doRead($config, $channel, $fresh, $mapper);
        });
    }

    public function getName(): string
    {
        return is_array($this->channel)
            ? 'ChannelRead<' . implode(',', $this->channel) . '>'
            : 'ChannelRead<' . $this->channel . '>';
    }

    /**
     * Perform the read.
     *
     * @param string|list<string> $channel
     */
    public static function doRead(
        ?RunnableConfig $config,
        string|array $channel,
        bool $fresh = false,
        mixed $mapper = null,
    ): mixed {
        $read = $config?->configurable[Constants::CONFIG_KEY_READ] ?? null;
        if (!is_callable($read)) {
            throw new \LogicException(
                'Runnable is not configured with a read function. '
                . 'Make sure to call in the context of a Pregel process'
            );
        }

        $value = $read($channel, $fresh);

        return $mapper !== null ? $mapper($value) : $value;
    }
}
