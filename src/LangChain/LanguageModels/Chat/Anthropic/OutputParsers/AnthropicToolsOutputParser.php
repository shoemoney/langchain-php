<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\OutputParsers;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\OutputParsers\BaseLLMOutputParser;
use LangChain\OutputParsers\JsonSchemaValidator;
use LangChain\OutputParsers\OutputParserException;
use LangChain\Runnables\RunnableConfig;

/**
 * Pulls the first `tool_use` block's input out of an Anthropic response.
 *
 * Port of `AnthropicToolsOutputParser` from `@langchain/anthropic`
 * (`output_parsers.ts`). The upstream Zod / standard-schema validation becomes
 * an optional JSON Schema, as elsewhere in this port.
 *
 * Like upstream, `keyName` and `returnSingle` are stored but `parseResult()`
 * always takes the first tool call of the first generation that has any.
 *
 * @template T
 * @extends BaseLLMOutputParser<T>
 */
class AnthropicToolsOutputParser extends BaseLLMOutputParser
{
    public bool $returnId = false;

    /** The type of tool calls to return. */
    public string $keyName;

    /** Whether to return only the first tool call. */
    public bool $returnSingle = false;

    /**
     * Optional JSON Schema validating the tool input.
     *
     * @var array<string, mixed>|null
     */
    public ?array $jsonSchema;

    /** @param array{keyName: string, returnSingle?: bool, jsonSchema?: array<string, mixed>|null} $fields */
    public function __construct(array $fields)
    {
        $this->keyName = (string) $fields['keyName'];
        $this->returnSingle = (bool) ($fields['returnSingle'] ?? $this->returnSingle);
        $this->jsonSchema = isset($fields['jsonSchema']) ? (array) $fields['jsonSchema'] : null;
    }

    /**
     * Validate (and JSON-decode, when given a string) a tool input.
     *
     * @return T
     */
    protected function validateResult(mixed $result): mixed
    {
        $parsed = $result;

        if (is_string($result)) {
            $parsed = json_decode($result, true);
            if (json_last_error() !== \JSON_ERROR_NONE) {
                throw new OutputParserException(
                    'Failed to parse. Text: "' . json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
                    . '". Error: ' . json_encode(json_last_error_msg()),
                    $result,
                );
            }
        }

        if ($this->jsonSchema === null) {
            return $parsed;
        }

        $errors = JsonSchemaValidator::validate($parsed, $this->jsonSchema);
        if ($errors !== []) {
            $rendered = (string) json_encode($parsed, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

            throw new OutputParserException(
                'Failed to parse. Text: "' . $rendered . '". Error: ' . json_encode($errors),
                $rendered,
            );
        }

        return $parsed;
    }

    /**
     * @param list<ChatGeneration|array{text?: string, message?: \LangChain\Messages\BaseMessage}> $generations
     *
     * @return T
     */
    public function parseResult(array $generations, ?RunnableConfig $config = null): mixed
    {
        $tool = null;

        foreach ($generations as $generation) {
            $message = $generation instanceof ChatGeneration ? $generation->message : ($generation['message'] ?? null);
            if ($message === null) {
                continue;
            }
            // This port's ChatAnthropic lifts `tool_use` blocks out of `content` into
            // `toolCalls`, so fall back to those when the content holds no raw block.
            $first = (is_array($message->content) ? self::extractToolCalls($message->content)[0] ?? null : null)
                ?? ($message instanceof \LangChain\Messages\AIMessage ? $message->toolCalls[0] ?? null : null);
            if ($first !== null) {
                $tool = $first;
                break;
            }
        }

        if ($tool === null) {
            throw new \RuntimeException('No parseable tool calls provided to AnthropicToolsOutputParser.');
        }

        return $this->validateResult($tool['args']);
    }

    /**
     * `extractToolCalls`: the `tool_use` blocks of an Anthropic content list.
     *
     * @param list<array<string, mixed>> $content
     *
     * @return list<array{name: mixed, args: mixed, id: mixed, type: string}>
     */
    public static function extractToolCalls(array $content): array
    {
        $toolCalls = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'name' => $block['name'] ?? null,
                    'args' => $block['input'] ?? null,
                    'id' => $block['id'] ?? null,
                    'type' => 'tool_call',
                ];
            }
        }

        return $toolCalls;
    }
}
