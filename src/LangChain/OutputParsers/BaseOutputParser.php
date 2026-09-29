<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;

/**
 * A parser for the *text* of a model call.
 *
 * Port of `BaseOutputParser` from `@langchain_core/output_parsers/base`.
 *
 * Where {@see BaseLLMOutputParser} deals in whole generations, this narrows the
 * job to the single generation most callers care about: `parse(string $text)`.
 * {@see self::getFormatInstructions()} is the other half — every parser can
 * describe the shape it wants, which is how a chain builds a prompt that
 * mentions the format without the caller writing that prose twice.
 *
 * @template T
 * @extends BaseLLMOutputParser<T>
 */
abstract class BaseOutputParser extends BaseLLMOutputParser
{
    /**
     * Parse the text of the first generation.
     *
     * @param list<array{text: string, message?: \LangChain\Messages\BaseMessage}> $generations
     * @return T
     */
    public function parseResult(array $generations, ?RunnableConfig $config = null): mixed
    {
        $first = $generations[0] ?? null;
        if ($first === null || !array_key_exists('text', $first)) {
            throw new \InvalidArgumentException('parseResult() requires at least one generation with a "text" key');
        }

        return $this->parse((string) $first['text'], $config);
    }

    /**
     * Parse LLM output.
     *
     * @return T
     */
    abstract public function parse(string $text, ?RunnableConfig $config = null): mixed;

    /**
     * Parse LLM output, given the prompt that produced it.
     *
     * @return T
     */
    public function parseWithPrompt(string $text, PromptValue $prompt, ?RunnableConfig $config = null): mixed
    {
        return $this->parse($text, $config);
    }

    /**
     * Describe the format this parser expects.
     *
     * The string is meant to be dropped straight into a prompt, e.g.
     * `'{"foo": "bar"}'` for a JSON parser.
     *
     * @param array<string, mixed> $options
     */
    abstract public function getFormatInstructions(array $options = []): string;

    /**
     * The unique key identifying this class of parser.
     */
    public function type(): string
    {
        throw new \LogicException('_type not implemented');
    }
}
