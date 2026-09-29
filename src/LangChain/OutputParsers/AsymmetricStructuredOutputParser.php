<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * A parser with different schemas for its input and its output.
 *
 * Port of `AsymmetricStructuredOutputParser` from
 * `@langchain_core/output_parsers/structured`.
 *
 * Most structured-output flows have this shape: the model is asked to emit a
 * specific JSON object, and that object is then *processed* into something
 * else — a tool call, a row for a database, a second model invocation's input.
 * Splitting "what did the model say" (a schema concern) from "what do we do
 * about it" (application code) keeps both halves testable on their own.
 *
 * @template Y
 * @extends BaseOutputParser<Y>
 */
abstract class AsymmetricStructuredOutputParser extends BaseOutputParser
{
    protected readonly JsonMarkdownStructuredOutputParser $structuredInputParser;

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(array $inputSchema = [])
    {
        $this->structuredInputParser = new JsonMarkdownStructuredOutputParser($inputSchema);
    }

    /**
     * Turn the parsed input into the parser's output type.
     *
     * @return Y
     */
    abstract public function outputProcessor(mixed $input): mixed;

    /**
     * @return Y
     * @throws OutputParserException
     */
    public function parse(string $text, ?\LangChain\Runnables\RunnableConfig $config = null): mixed
    {
        try {
            $parsed = $this->structuredInputParser->parse($text);
        } catch (\Throwable $e) {
            throw new OutputParserException(
                "Failed to parse. Text: \"{$text}\". Error: {$e->getMessage()}",
                $text
            );
        }

        return $this->outputProcessor($parsed);
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return $this->structuredInputParser->getFormatInstructions($options);
    }
}
