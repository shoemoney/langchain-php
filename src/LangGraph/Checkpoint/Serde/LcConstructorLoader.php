<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Serde;

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
 * Rehydrate an `lc: 1` record into the object it describes.
 *
 * Port of the `load()` call in `_reviver` (`jsonplus.ts`), which delegates to
 * `@langchain/core/load`. That package is not part of this port, but a
 * checkpointer is useless without it: a `messages` channel is a list of message
 * objects, and a reviver that returned them as plain arrays would turn a
 * resumed run's history into a shape no channel can read.
 *
 * Only the classes this library actually defines are in the registry, and a
 * record naming anything else is returned untouched rather than guessed at. The
 * security posture is the upstream one: a serialised constructor is *data*, never
 * an instruction to load an arbitrary class by name.
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
        'langchain_core|messages|BaseMessage' => BaseMessage::class,
        'langchain_core|messages|ChatMessage' => ChatMessage::class,
        'langchain_core|messages|FunctionMessage' => FunctionMessage::class,
        'langchain_core|messages|HumanMessage' => HumanMessage::class,
        'langchain_core|messages|SystemMessage' => SystemMessage::class,
        'langchain_core|messages|ToolMessage' => ToolMessage::class,
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

        return new $class($kwargs);
    }
}
