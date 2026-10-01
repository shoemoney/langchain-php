<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Errors\InvalidUpdateError;

/**
 * The runnable that turns a node's return value into channel writes.
 *
 * Port of `ChannelWrite` from `langgraph-core/src/pregel/write.ts`.
 *
 * A `ChannelWrite` is how a node's output becomes state. It is a runnable so it
 * composes: `bound.pipe(new ChannelWrite(...))` is a node that computes and
 * then publishes, and the runner sees one runnable rather than two steps.
 *
 * The write list is a small union of three shapes:
 *
 *  - `['channel' => string, 'value' => mixed, 'skipNone'?: bool, 'mapper'?: RunnableInterface]`
 *    — write a value (optionally computed by a mapper) to a channel;
 *  - `['value' => mixed, 'mapper' => RunnableInterface]` — a mapper that
 *    returns a *list* of `[channel, value]` pairs, for a write that fans out;
 *  - a {@see Send} — write a `Send` into the tasks channel, scheduling a node.
 *
 * `PASSTHROUGH` as a value means "whatever the input was", which is how a node
 * publishes its result without a mapper.
 *
 * It implements {@see RunnableInterface} directly rather than extending
 * {@see RunnableLambda}, because it genuinely needs the config: the write
 * collector is injected there by the scheduler, and there is nowhere else to
 * find it.
 */
class ChannelWrite implements RunnableInterface
{
    public string $lcGraphName = 'ChannelWrite';

    /**
     * @param list<array<string, mixed>|Send> $writes The write entries.
     * @param list<string>                    $tags   Trace labels.
     */
    public function __construct(
        public readonly array $writes = [],
        public readonly array $tags = [],
    ) {
    }

    public function getName(): string
    {
        return $this->lcGraphName;
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        self::doWrite($config, $this->resolveWrites($input, $config));

        return $input;
    }

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

    /**
     * Substitute `PASSTHROUGH` with the actual input before writing.
     *
     * @return list<array<string, mixed>|Send>
     */
    private function resolveWrites(mixed $input, ?RunnableConfig $config): array
    {
        $out = [];
        foreach ($this->writes as $write) {
            if ($write instanceof Send) {
                $out[] = $write;
                continue;
            }

            if (is_array($write) && self::isPassthrough($write['value'] ?? null)) {
                if (array_key_exists('mapper', $write)) {
                    // A tuple entry: the mapper receives the input.
                    $out[] = ['mapper' => $write['mapper'], 'value' => $input];
                } else {
                    $out[] = $write;
                    $out[count($out) - 1]['value'] = $input;
                }
                continue;
            }

            $out[] = $write;
        }

        return $out;
    }

    /**
     * Validate the writes and hand them to the task's write collector.
     *
     * Port of `ChannelWrite.doWrite`. The validation is not defensive padding:
     * writing to `TASKS` directly would bypass the scheduler's index
     * bookkeeping, and an unreplaced `PASSTHROUGH` means the node was supposed
     * to pass its input through and the wiring is wrong. Both are bugs the user
     * can fix, and both are silent if not caught here.
     *
     * @param list<array<string, mixed>|Send> $writes
     */
    public static function doWrite(?RunnableConfig $config, array $writes): void
    {
        foreach ($writes as $w) {
            if (is_array($w) && array_key_exists('channel', $w)) {
                if (($w['channel'] ?? null) === Constants::TASKS) {
                    throw new InvalidUpdateError('Cannot write to the reserved channel TASKS');
                }
                if (self::isPassthrough($w['value'] ?? null)) {
                    throw new InvalidUpdateError('PASSTHROUGH value must be replaced');
                }
            }
            if (is_array($w) && array_key_exists('mapper', $w) && !array_key_exists('channel', $w)) {
                if (self::isPassthrough($w['value'] ?? null)) {
                    throw new InvalidUpdateError('PASSTHROUGH value must be replaced');
                }
            }
        }

        $entries = [];
        foreach ($writes as $w) {
            if ($w instanceof Send) {
                $entries[] = [Constants::TASKS, $w];
                continue;
            }

            if (!is_array($w)) {
                throw new \InvalidArgumentException('Invalid write entry: ' . json_encode($w));
            }

            if (array_key_exists('mapper', $w) && !array_key_exists('channel', $w)) {
                // Tuple entry: the mapper returns a list of [channel, value].
                $mapper = $w['mapper'];
                \assert($mapper instanceof RunnableInterface);
                $mapped = $mapper->invoke($w['value'] ?? null, $config);
                if (is_array($mapped)) {
                    foreach ($mapped as $pair) {
                        $entries[] = [(string) $pair[0], $pair[1]];
                    }
                }
                continue;
            }

            $value = $w['value'] ?? null;
            if (isset($w['mapper']) && $w['mapper'] instanceof RunnableInterface) {
                $value = $w['mapper']->invoke($value, $config);
            }
            if (self::isSkipWrite($value)) {
                continue;
            }
            if (($w['skipNone'] ?? false) && $value === null) {
                continue;
            }

            $entries[] = [(string) $w['channel'], $value];
        }

        $send = $config?->configurable[Constants::CONFIG_KEY_SEND] ?? null;
        if (!is_callable($send)) {
            throw new \LogicException(
                'ChannelWrite requires a write function in config. '
                . 'Make sure to call it in the context of a Pregel process'
            );
        }

        $send($entries);
    }

    /** The `PASSTHROUGH` marker: "write whatever the input was". */
    public static function passthrough(): object
    {
        static $instance = null;
        $instance ??= new class {
            public bool $isPassthrough = true;
        };

        return $instance;
    }

    public static function isPassthrough(mixed $x): bool
    {
        return is_object($x) && property_exists($x, 'isPassthrough') && $x->isPassthrough === true;
    }

    /** The `SKIP_WRITE` marker: "produce no write for this entry". */
    public static function skipWrite(): object
    {
        static $instance = null;
        $instance ??= new class {
            public bool $isSkipWrite = true;
        };

        return $instance;
    }

    public static function isSkipWrite(mixed $x): bool
    {
        return is_object($x) && property_exists($x, 'isSkipWrite') && $x->isSkipWrite === true;
    }
}
