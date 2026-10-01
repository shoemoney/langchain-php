<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;

/**
 * A streaming parser whose output accumulates across chunks.
 *
 * Port of `BaseCumulativeTransformOutputParser` from `@langchain_core/output_parsers`.
 *
 * Chunks are concatenated before parsing, so each yield is the *whole* parsed
 * document so far rather than a fragment. A JSON object streamed one token at a
 * time therefore yields `[]`, `{setup: ""}`, `{setup: "Why"}`, … — each valid on
 * its own. Two things make the stream usable: unchanged results are suppressed
 * (so a stream of whitespace does not spam consumers), and `diff` mode emits an
 * RFC 6902 patch instead of the full value, which is what you want over a wire.
 *
 * @template T
 * @extends BaseTransformOutputParser<T>
 */
abstract class BaseCumulativeTransformOutputParser extends BaseTransformOutputParser
{
    /** When true, yields JSON-Patch operations rather than whole documents. */
    protected bool $diff = false;

    /**
     * @param array{diff?: bool} $fields
     */
    public function __construct(array $fields = [])
    {
        $this->diff = $fields['diff'] ?? false;
    }

    /**
     * Compute the JSON-Patch operations turning `$prev` into `$next`.
     *
     * @return list<array<string, mixed>>|null
     */
    abstract protected function diffOperations(mixed $prev, mixed $next): ?array;

    /**
     * Parse the accumulated text, tolerating an incomplete document.
     *
     * @param list<array{text: string, message?: BaseMessage}> $generations
     */
    abstract public function parsePartialResult(array $generations): mixed;

    /**
     * @param iterable<string|BaseMessage> $input
     * @return \Generator<int, mixed>
     */
    protected function _transform(iterable $input): \Generator
    {
        $prevParsed = null;
        $accumulated = '';

        foreach ($input as $chunk) {
            $text = $chunk instanceof BaseMessage ? $chunk->text() : (string) $chunk;
            $accumulated .= $text;

            $parsed = $this->parsePartialResult([['text' => $accumulated]]);
            if ($parsed !== null && !JsonPatch::deepEquals($parsed, $prevParsed)) {
                if ($this->diff) {
                    $operations = $this->diffOperations($prevParsed, $parsed);
                    if ($operations !== null) {
                        yield $operations;
                    }
                } else {
                    yield $parsed;
                }
                $prevParsed = $parsed;
            }
        }
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return '';
    }
}
