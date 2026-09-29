<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableConfig;

/**
 * A parser that also accepts a stream of input chunks.
 *
 * Port of `BaseTransformOutputParser` from `@langchain_core/output_parsers`.
 *
 * Streaming changes the problem: the input is a sequence of fragments, not one
 * finished string. This class hands each fragment to {@see self::parseResult()}
 * and yields the result — the right behaviour for a parser whose output is
 * independent of the others (a list, a JSON document whose valid prefixes are
 * still valid JSON). {@see BaseCumulativeTransformOutputParser} covers the
 * cumulative case.
 *
 * Note on the shape of {@see self::transform()}: unlike
 * {@see \LangChain\Runnables\Runnable::transform()}, this override yields the
 * parsed values themselves rather than `[channel, value]` pairs, because that is
 * the contract the TypeScript original exposes and every parser test asserts.
 *
 * @template T
 * @extends BaseOutputParser<T>
 */
abstract class BaseTransformOutputParser extends BaseOutputParser
{
    /**
     * @param iterable<string|BaseMessage> $input
     * @return \Generator<int, T>
     */
    protected function _transform(iterable $input): \Generator
    {
        foreach ($input as $chunk) {
            if ($chunk instanceof BaseMessage) {
                yield $this->parseResult([
                    [
                        'message' => $chunk,
                        'text' => $this->baseMessageToString($chunk),
                    ],
                ]);
                continue;
            }

            yield $this->parseResult([['text' => (string) $chunk]]);
        }
    }

    /**
     * @param iterable<string|BaseMessage> $input
     * @return \Generator<int, T>
     */
    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->_transform($input);
    }
}
