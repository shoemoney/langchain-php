<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

use LangGraph\Stream\ChatModelStream;

/**
 * One AI message of an agent run, as `AgentRunStream::messages()` yields it: a {@see ChatModelStream} that also
 * reads like a message, with the text so far on `$message->content`.
 *
 * Everything else (the lifecycle events by iteration, `text()`, `reasoning()`, `usage()`, `namespace`, `node`)
 * is the wrapped stream's. `content` is read-only and, like `text()`, drives the run until the message has finished.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 *
 * @property-read string $content the message text
 */
final class AgentMessageStream implements \IteratorAggregate
{
    public function __construct(public readonly ChatModelStream $stream)
    {
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function getIterator(): \Generator
    {
        return $this->stream->getIterator();
    }

    public function text(): string
    {
        return $this->stream->text();
    }

    public function reasoning(): string
    {
        return $this->stream->reasoning();
    }

    /** @return array<string, mixed>|null */
    public function usage(): ?array
    {
        return $this->stream->usage();
    }

    /** @return list<string> */
    public function namespace(): array
    {
        return $this->stream->namespace;
    }

    public function node(): ?string
    {
        return $this->stream->node;
    }

    public function __get(string $name): string
    {
        if ($name !== 'content') {
            throw new \OutOfBoundsException(sprintf('Unknown message stream property "%s".', $name));
        }

        return $this->stream->text();
    }

    public function __isset(string $name): bool
    {
        return $name === 'content';
    }
}
