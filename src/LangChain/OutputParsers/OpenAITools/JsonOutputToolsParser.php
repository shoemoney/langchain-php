<?php

declare(strict_types=1);

namespace LangChain\OutputParsers\OpenAITools;

use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\BaseCumulativeTransformOutputParser;
use LangChain\OutputParsers\OutputParserException;
use LangChain\OutputParsers\PartialJsonParser;

/**
 * Shared parsing for tool-calling model output.
 *
 * Port of `JsonOutputToolsParser` from
 * `@langchain_core/output_parsers/openai_tools`.
 *
 * A model asked for a tool answers with a *tool call* rather than prose, so
 * "parsing the output" means reading the call's arguments. Two sources are
 * accepted, in this order:
 *
 *  1. `toolCalls` already parsed onto the message — what a ported provider
 *     client produces;
 *  2. `additional_kwargs['tool_calls']` in the provider's own wire shape — what
 *     an unported client leaves behind.
 *
 * The second path is why {@see self::parseToolCall()} exists. A wire tool call
 * carries its arguments as a *string of JSON*, and that string is frequently
 * truncated mid-object when it is being streamed. So `$partial` decides whether
 * unparseable arguments are a failure (the model emitted garbage) or simply
 * "not yet" (the stream has not finished).
 *
 * @template T
 * @extends BaseCumulativeTransformOutputParser<T>
 */
class JsonOutputToolsParser extends BaseCumulativeTransformOutputParser
{
    /**
     * Whether parsed calls keep their `id`.
     *
     * Off by default: the id exists to correlate a call with its result, and a
     * caller that only wants the arguments has no use for it.
     */
    public bool $returnId = false;

    /** @param array{returnId?: bool, diff?: bool} $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->returnId = (bool) ($fields['returnId'] ?? $this->returnId);
    }

    /**
     * JSON-Patch diffing is meaningless for tool calls.
     *
     * The cumulative stream is emitted whole — `{calls so far}` — rather than as
     * a patch, because a partially-streamed tool call is not a document with
     * stable prefixes the way JSON is. The base class's diff mode would emit an
     * operation set against a previous value that is itself incomplete.
     */
    protected function diffOperations(mixed $prev, mixed $next): ?array
    {
        throw new \RuntimeException('Not supported.');
    }

    /**
     * A tool call cannot be parsed from text alone.
     *
     * The arguments live in structured fields on the message, not in its text,
     * so there is nothing for the string form of this parser to read. Throwing
     * is upstream's behaviour and is the honest outcome: a caller reaching here
     * has a text where it needed a message.
     */
    public function parse(string $text, ?\LangChain\Runnables\RunnableConfig $config = null): mixed
    {
        throw new \RuntimeException('Not implemented.');
    }

    /**
     * @param list<array{text: string, message?: \LangChain\Messages\BaseMessage}> $generations
     */
    public function parseResult(array $generations, ?\LangChain\Runnables\RunnableConfig $config = null): mixed
    {
        return $this->parsePartialResult($generations, false);
    }

    /**
     * @param list<array{text: string, message?: \LangChain\Messages\BaseMessage}> $generations
     */
    public function parsePartialResult(array $generations, bool $partial = true): mixed
    {
        $message = $generations[0]['message'] ?? null;

        $toolCalls = null;
        if ($message instanceof AIMessage && $message->toolCalls !== []) {
            $toolCalls = array_map(function (array $toolCall): array {
                $rest = $toolCall;
                unset($rest['id']);

                return $this->returnId ? $toolCall : $rest;
            }, $message->toolCalls);
        } elseif ($message !== null && isset($message->additional_kwargs['tool_calls'])) {
            $raw = json_decode(
                json_encode($message->additional_kwargs['tool_calls'], \JSON_PARTIAL_OUTPUT_ON_ERROR),
                true,
            );
            $toolCalls = array_values(array_filter(array_map(
                fn (array $rawToolCall): ?array => self::parseToolCall($rawToolCall, $this->returnId, $partial),
                is_array($raw) ? $raw : [],
            ), static fn (?array $call): bool => $call !== null));
        }

        if ($toolCalls === null) {
            return [];
        }

        $parsed = [];
        foreach ($toolCalls as $toolCall) {
            $parsed[] = [
                'type' => $toolCall['name'],
                'args' => $toolCall['args'],
                'id' => $toolCall['id'] ?? null,
            ];
        }

        return $parsed;
    }

    /**
     * Parse one provider-shaped tool call.
     *
     * @return array{name: string, args: array<string, mixed>, id?: mixed}|null
     */
    public static function parseToolCall(array $rawToolCall, bool $returnId = false, bool $partial = false): ?array
    {
        $function = $rawToolCall['function'] ?? null;
        if (!is_array($function)) {
            return null;
        }

        $rawArgs = $function['arguments'] ?? '{}';

        if ($partial) {
            try {
                $args = (new PartialJsonParser(is_string($rawArgs) ? $rawArgs : '{}'))->parse();
            } catch (\Throwable) {
                // A half-streamed argument string is expected, not exceptional.
                return null;
            }
        } else {
            try {
                $args = json_decode(is_string($rawArgs) ? $rawArgs : '{}', true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new OutputParserException(implode("\n", [
                    'Function "' . ($function['name'] ?? '') . '" arguments:',
                    '',
                    is_string($rawArgs) ? $rawArgs : json_encode($rawArgs),
                    '',
                    'are not valid JSON.',
                    'Error: ' . $e->getMessage(),
                ]), is_string($rawArgs) ? $rawArgs : null);
            }
        }

        $parsed = [
            'name' => $function['name'] ?? '',
            'args' => is_array($args) ? $args : [],
        ];

        if ($returnId) {
            $parsed['id'] = $rawToolCall['id'] ?? null;
        }

        return $parsed;
    }
}
