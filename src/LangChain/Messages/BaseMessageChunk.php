<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Base class for a streaming fragment of a message.
 *
 * Port of `BaseMessageChunk` from `@langchain/core/messages/base`. A chunk is
 * everything a message is, plus the ability to fold another chunk of the same
 * kind into itself. Folding is what turns a token stream back into a message.
 */
abstract class BaseMessageChunk extends BaseMessage
{
    /**
     * Fold another chunk of the same kind into this one.
     *
     * The parameter is the shared base rather than the concrete subclass
     * because PHP parameter types are contravariant: an override may widen but
     * never narrow. Implementations therefore validate at runtime and throw
     * rather than silently folding the wrong kind of chunk.
     */
    abstract public function concat(self $other): self;

    /**
     * @throws \InvalidArgumentException when $other is not the same chunk kind
     */
    protected function assertSameChunkClass(BaseMessageChunk $other, string $expected): void
    {
        if (!$other instanceof $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot fold %s into %s; chunks of different message kinds do not merge.',
                (new \ReflectionClass($other))->getShortName(),
                (new \ReflectionClass($this))->getShortName()
            ));
        }
    }

    /**
     * Promote a fully-folded chunk into its finished-message counterpart.
     *
     * This is the last step of stream reconstruction: fold every chunk with
     * {@see self::concat()}, then convert.
     */
    public function toMessage(): BaseMessage
    {
        return match (true) {
            $this instanceof ToolMessageChunk => new ToolMessage([
                'content' => $this->content,
                'additional_kwargs' => $this->additional_kwargs,
                'response_metadata' => $this->response_metadata,
                'tool_call_id' => $this->toolCallId,
                'id' => $this->id,
                'name' => $this->name,
            ]),
            $this instanceof HumanMessageChunk => new HumanMessage([
                'content' => $this->content,
                'additional_kwargs' => $this->additional_kwargs,
                'response_metadata' => $this->response_metadata,
                'id' => $this->id,
                'name' => $this->name,
            ]),
            $this instanceof SystemMessageChunk => new SystemMessage([
                'content' => $this->content,
                'additional_kwargs' => $this->additional_kwargs,
                'response_metadata' => $this->response_metadata,
                'id' => $this->id,
                'name' => $this->name,
            ]),
            default => new AIMessage([
                'content' => $this->content,
                'additional_kwargs' => $this->additional_kwargs,
                'response_metadata' => $this->response_metadata,
                'id' => $this->id,
                'name' => $this->name,
            ]),
        };
    }
}
