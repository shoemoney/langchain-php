<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message from a human.
 *
 * Port of `HumanMessage` from `@langchain/core/messages/human`.
 */
class HumanMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_HUMAN;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'HumanMessage'];
    }
    /** @return array{lc: int, type: string, id: list<string>, kwargs?: array|\stdClass} */
    public function toSerializedConstructor(): array
    {
        if (static::class !== self::class) {
            return parent::toSerializedConstructor();
        }
        $bag = $this->kwargs();
        if (
            array_keys($bag) === ['content', 'additional_kwargs', 'response_metadata']
            && isset($this->content, $this->additional_kwargs, $this->response_metadata)
            && is_string($this->content)
            && $this->content === $bag['content']
            && $this->additional_kwargs === []
            && $bag['additional_kwargs'] === []
            && $this->response_metadata === []
            && $bag['response_metadata'] === []
        ) {
            return parent::toSerializedConstructor();
        }
        $state = self::sourceContractPublicState($this);

        return self::serializeSourceContract(
            $this, $this->kwargs(), static::lcId(), true,
            [['aliases' => parent::lcAliases(), 'secrets' => [], 'attributes' => []]],
            $state, $state,
        );
    }

}
