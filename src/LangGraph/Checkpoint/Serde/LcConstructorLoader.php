<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;

use LangChain\Load\Validation;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Schema\ChatPromptValue;
use LangChain\Schema\StringPromptValue;

/**
 * Revive the explicit checkpoint subset of LangChain constructor records.
 *
 * Pinned jsonplus.ts delegates these records to core load(). PHP keeps a
 * separate closed allowlist for checkpoint input; unknown identifiers remain
 * inert instead of using the wider trusted-manifest registry. Literal wrappers
 * are protected by JsonPlusDecoder until a known constructor's kwargs boundary.
 * Positional prompt-value constructors need adapters rather than a kwargs map.
 */
final class LcConstructorLoader
{
    /**
     * `lc_id` path (joined by `|`) to the class that implements it.
     *
     * @var array<string, class-string<\LangChain\Load\Serializable>>
     */
    private const REGISTRY = [
        'langchain_core|messages|AIMessage' => AIMessage::class,
        'langchain_core|messages|ChatMessage' => ChatMessage::class,
        'langchain_core|messages|FunctionMessage' => FunctionMessage::class,
        'langchain_core|messages|HumanMessage' => HumanMessage::class,
        'langchain_core|messages|SystemMessage' => SystemMessage::class,
        'langchain_core|messages|ToolMessage' => ToolMessage::class,
        'langchain_core|messages|RemoveMessage' => \LangChain\Messages\RemoveMessage::class,
        'langchain_core|messages|AIMessageChunk' => \LangChain\Messages\AIMessageChunk::class,
        'langchain_core|messages|HumanMessageChunk' => \LangChain\Messages\HumanMessageChunk::class,
        'langchain_core|messages|SystemMessageChunk' => \LangChain\Messages\SystemMessageChunk::class,
        'langchain_core|messages|ToolMessageChunk' => \LangChain\Messages\ToolMessageChunk::class,
        'langchain_core|messages|FunctionMessageChunk' => \LangChain\Messages\FunctionMessageChunk::class,
        'langchain_core|messages|ChatMessageChunk' => \LangChain\Messages\ChatMessageChunk::class,
        'langchain_core|prompt_values|ChatPromptValue' => ChatPromptValue::class,
        'langchain_core|prompt_values|StringPromptValue' => StringPromptValue::class,
    ];

    private function __construct()
    {
    }

    /**
     * Whether a decoded value is a LangChain serialised constructor.
     *
     * @param array<string, mixed> $value
     */
    public static function isSerializedConstructor(array $value): bool
    {
        return ($value['lc'] ?? null) === 1
            && ($value['type'] ?? null) === 'constructor'
            && is_array($value['id'] ?? null);
    }

    /**
     * Load a record, or return it unchanged when it names nothing known.
     *
     * @param array<string, mixed> $record
     */
    public static function load(array $record): mixed
    {
        $id = $record['id'] ?? [];
        if (!is_array($id)) {
            return $record;
        }

        $class = self::REGISTRY[implode('|', array_map('strval', $id))] ?? null;
        if ($class === null) {
            return $record;
        }

        $kwargs = $record['kwargs'] ?? [];
        if (!is_array($kwargs)) {
            $kwargs = [];
        }

        // Match the literal-unwrapping boundary of core load(), without routing
        // checkpoint input through that wider trusted API.
        $kwargs = Validation::unescapeValue($kwargs);
        if ($class === StringPromptValue::class) {
            return new StringPromptValue($kwargs['text']);
        }
        if ($class === ChatPromptValue::class) {
            $messages = array_map(static function (mixed $message): BaseMessage {
                if ($message instanceof BaseMessage) {
                    return $message;
                }
                if (is_array($message) && isset($message['type'], $message['data']) && is_array($message['data'])) {
                    // PHP prompt values store message dictionaries. Their inner
                    // serialization boundary needs the existing stored unwrapping.
                    return \LangChain\Messages\MessageUtils::mapStoredMessageToChatMessage($message);
                }

                throw new \InvalidArgumentException('ChatPromptValue requires serialized message objects or message dictionaries.');
            }, $kwargs['messages']);

            return new ChatPromptValue($messages);
        }

        return new $class($kwargs);
    }
}
