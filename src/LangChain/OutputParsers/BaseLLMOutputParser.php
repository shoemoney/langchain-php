<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;

/**
 * Base class for turning the output of a model call into a usable value.
 *
 * Port of `BaseLLMOutputParser` from `@langchain_core/output_parsers/base`.
 *
 * A model hands back a *generation* — text, plus (for chat models) the message
 * it was wrapped in. A parser turns that into whatever the rest of the chain
 * wants: a string, an array, a decoded object, a list. Because a parser is a
 * {@see Runnable}, it drops into a chain with `|`, and because it accepts both
 * a bare string and a {@see BaseMessage}, the same parser works behind a
 * completion model and a chat model without a shim.
 *
 * @template T
 */
abstract class BaseLLMOutputParser extends Runnable
{
    /**
     * Parse a list of generations into the parser's output type.
     *
     * @param list<array{text: string, message?: BaseMessage}> $generations
     * @return T
     */
    abstract public function parseResult(array $generations, ?RunnableConfig $config = null): mixed;

    /**
     * Parse generations, given the prompt that produced them.
     *
     * The default implementation ignores the prompt, exactly as upstream does.
     *
     * @param list<array{text: string, message?: BaseMessage}> $generations
     * @return T
     */
    public function parseResultWithPrompt(array $generations, PromptValue $prompt, ?RunnableConfig $config = null): mixed
    {
        return $this->parseResult($generations, $config);
    }

    /**
     * Parse one piece of model output.
     *
     * A string input becomes a single text generation; a {@see BaseMessage}
     * becomes a generation carrying both the message and its flattened text,
     * so a parser can look at either.
     *
     * @param string|BaseMessage $input
     * @return T
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if ($input instanceof BaseMessage) {
            return $this->parseResult([
                [
                    'message' => $input,
                    'text' => $this->baseMessageToString($input),
                ],
            ], $config);
        }

        if (is_string($input)) {
            return $this->parseResult([['text' => $input]], $config);
        }

        throw new \InvalidArgumentException(
            'An output parser accepts a string or a BaseMessage, got ' . get_debug_type($input)
        );
    }

    /**
     * The text a parser should see for a message.
     *
     * The base implementation JSON-encodes non-string content; subclasses that
     * know how to flatten content blocks (see {@see StringOutputParser}) override it.
     */
    protected function baseMessageToString(BaseMessage $message): string
    {
        return is_string($message->content)
            ? $message->content
            : $this->baseMessageContentToString($message->content);
    }

    /**
     * @param list<mixed> $content
     */
    protected function baseMessageContentToString(array $content): string
    {
        return (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
