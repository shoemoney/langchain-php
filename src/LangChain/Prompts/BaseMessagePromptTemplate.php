<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;

/**
 * Base class for a template that renders to zero or more messages.
 *
 * Port of `BaseMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * A message prompt template is a *runnable* rather than a prompt template
 * because it is one step of a message list, not a whole prompt: it has no
 * `PromptValue` of its own, it contributes messages to the enclosing
 * {@see ChatPromptTemplate}.
 *
 * @template RunOutput of list<BaseMessage>
 * @extends Runnable<array<string, mixed>, RunOutput>
 */
abstract class BaseMessagePromptTemplate extends Runnable implements \JsonSerializable
{
    /** @var list<string> The variables this message reads. */
    public array $inputVariables = [];

    /**
     * Render this step's messages.
     *
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     */
    abstract public function formatMessages(array $values): array;

    /**
     * @param array<string, mixed>|mixed $input
     * @return list<BaseMessage>
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $this->formatMessages(is_array($input) ? $input : []);
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        $parts = explode('\\', static::class);

        return ['langchain_core', 'prompts', 'chat', end($parts)];
    }

    /** @var array<string, mixed> Constructor fields, already snake_cased. */
    protected array $kwargs = [];

    /**
     * @param array<string, mixed> $kwargs
     * @return array<string, mixed>
     */
    protected function lcMapKeys(array $kwargs): array
    {
        return array_filter($kwargs, static fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, mixed> */
    public function kwargs(): array
    {
        return $this->lcMapKeys($this->kwargs);
    }

    /**
     * @return array{lc: int, type: string, id: list<string>, kwargs: array<string, mixed>}
     */
    public function toJson(): array
    {
        return [
            'lc' => 1,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => $this->kwargs(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }
}
