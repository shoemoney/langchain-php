<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use Ramsey\Uuid\Uuid;

/**
 * Translation between LangChain messages and Ollama's `/api/chat` messages.
 *
 * Port of `utils.ts` from `@langchain/ollama`.
 *
 * An Ollama message is `{role, content, images?, tool_calls?, thinking?}`. The
 * tool call's `arguments` is a JSON OBJECT, not a string (unlike OpenAI), which
 * matters on the way out: PHP's one array type encodes an empty argument map as
 * `[]`, and Ollama rejects a list where it wants an object. An empty map is
 * therefore emitted as an empty object.
 */
final class OllamaUtils
{
    private function __construct()
    {
    }

    /**
     * Fold one Ollama response message into a LangChain chunk.
     *
     * Thinking goes to `additional_kwargs.reasoning_content` so it never
     * pollutes `content`. Each tool call gets a fresh uuid: Ollama does not
     * supply call ids, and a tool result cannot be matched without one.
     *
     * @param array<string, mixed> $message An Ollama message.
     * @param array{responseMetadata?: array<string, mixed>, usageMetadata?: array<string, int>} $extra
     */
    public static function convertOllamaMessagesToLangChain(array $message, array $extra = []): AIMessageChunk
    {
        $thinking = $message['thinking'] ?? null;

        $fields = [
            'content' => (string) ($message['content'] ?? ''),
            'additional_kwargs' => is_string($thinking) && $thinking !== ''
                ? ['reasoning_content' => $thinking]
                : [],
            'response_metadata' => ($extra['responseMetadata'] ?? []) + ['model_provider' => 'ollama'],
        ];

        if (isset($extra['usageMetadata'])) {
            $fields['response_metadata']['usage_metadata'] = $extra['usageMetadata'];
        }

        $toolCalls = $message['tool_calls'] ?? null;
        if (is_array($toolCalls) && $toolCalls !== []) {
            $fields['tool_call_chunks'] = array_map(
                static fn (array $call): array => [
                    'name' => $call['function']['name'] ?? null,
                    'args' => self::encodeArguments($call['function']['arguments'] ?? []),
                    'type' => 'tool_call_chunk',
                    'index' => 0,
                    'id' => Uuid::uuid4()->toString(),
                ],
                array_values($toolCalls),
            );
        }

        return new AIMessageChunk($fields);
    }

    /**
     * @param list<BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function convertToOllamaMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            $type = $message->type;

            if ($type === 'human' || $message instanceof ChatMessage) {
                array_push($out, ...self::convertHumanGenericMessagesToOllama($message));
            } elseif ($type === 'ai') {
                array_push($out, ...self::convertAIMessagesToOllama($message));
            } elseif ($type === 'system') {
                array_push($out, ...self::convertSystemMessageToOllama($message));
            } elseif ($type === 'tool') {
                array_push($out, ...self::convertToolMessageToOllama($message));
            } else {
                throw new \InvalidArgumentException('Unsupported message type: ' . $type);
            }
        }

        return $out;
    }

    private static function extractBase64FromDataUrl(string $dataUrl): string
    {
        return preg_match('/^data:.*?;base64,(.*)$/s', $dataUrl, $match) === 1 ? $match[1] : '';
    }

    /**
     * Ollama wants `arguments` as an object; see the class note.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function objectArguments(mixed $args): array|\stdClass
    {
        return is_array($args) && $args !== [] ? $args : new \stdClass();
    }

    /** The JSON text of a tool call's arguments, as a chunk carries them. */
    private static function encodeArguments(mixed $arguments): string
    {
        if (is_string($arguments)) {
            return $arguments;
        }

        return json_encode(
            is_array($arguments) && $arguments !== [] ? $arguments : new \stdClass(),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOf(AIMessage $message): array
    {
        return array_map(
            static fn (array $call): array => array_filter([
                'id' => $call['id'] ?? null,
                'type' => 'function',
                'function' => [
                    'name' => $call['name'],
                    'arguments' => self::objectArguments($call['args'] ?? []),
                ],
            ], static fn (mixed $v): bool => $v !== null),
            array_values($message->toolCalls),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertAIMessagesToOllama(BaseMessage $message): array
    {
        \assert($message instanceof AIMessage);

        // PHP's `BaseMessage` defaults absent content to `[]` where the
        // TypeScript default is `""`. An empty list is "no content", and
        // treating it as block content would silently drop the tool calls.
        $content = $message->content === [] ? '' : $message->content;

        if (is_string($content)) {
            if ($message->toolCalls !== []) {
                return [[
                    'role' => 'assistant',
                    'content' => $content,
                    'tool_calls' => self::toolCallsOf($message),
                ]];
            }

            return [['role' => 'assistant', 'content' => $content]];
        }

        $textMessages = [];
        $hasToolUse = false;
        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $textMessages[] = ['role' => 'assistant', 'content' => $block['text']];
            }
            if (($block['type'] ?? null) === 'tool_use') {
                $hasToolUse = true;
            }
        }

        $toolCallMessage = [];
        if ($hasToolUse && $message->toolCalls !== []) {
            // `tool_use` content blocks are accepted only when the message
            // also carries the structured calls.
            $toolCallMessage[] = [
                'role' => 'assistant',
                'tool_calls' => self::toolCallsOf($message),
                'content' => '',
            ];
        } elseif ($hasToolUse) {
            throw new \InvalidArgumentException("'tool_use' content type is not supported without tool calls.");
        }

        return [...$textMessages, ...$toolCallMessage];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertHumanGenericMessagesToOllama(BaseMessage $message): array
    {
        if (is_string($message->content)) {
            return [['role' => 'user', 'content' => $message->content]];
        }

        $out = [];
        foreach ($message->content as $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;

            if ($type === 'text') {
                $out[] = ['role' => 'user', 'content' => $block['text'] ?? null];
                continue;
            }

            if ($type === 'image_url') {
                $image = $block['image_url'] ?? null;
                $url = is_string($image) ? $image : (is_array($image) ? ($image['url'] ?? null) : null);

                if (is_string($url) && $url !== '') {
                    $out[] = ['role' => 'user', 'content' => '', 'images' => [self::extractBase64FromDataUrl($url)]];
                    continue;
                }
            }

            throw new \InvalidArgumentException('Unsupported content type: ' . (is_string($type) ? $type : get_debug_type($block)));
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertSystemMessageToOllama(BaseMessage $message): array
    {
        \assert($message instanceof SystemMessage);

        if (is_string($message->content)) {
            return [['role' => 'system', 'content' => $message->content]];
        }

        $out = [];
        $types = [];
        $allText = true;
        foreach ($message->content as $block) {
            $type = is_array($block) ? ($block['type'] ?? '') : get_debug_type($block);
            $types[] = (string) $type;
            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $out[] = ['role' => 'system', 'content' => $block['text']];
            } else {
                $allText = false;
            }
        }

        if (!$allText) {
            throw new \InvalidArgumentException('Unsupported content type(s): ' . implode(', ', $types));
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function convertToolMessageToOllama(BaseMessage $message): array
    {
        \assert($message instanceof ToolMessage);

        if (!is_string($message->content)) {
            throw new \InvalidArgumentException('Non string tool message content is not supported');
        }

        return [['role' => 'tool', 'content' => $message->content]];
    }
}
