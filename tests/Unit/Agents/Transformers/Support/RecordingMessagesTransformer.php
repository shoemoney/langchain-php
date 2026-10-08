<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Transformers\Support;

use LangGraph\Agents\Transformers\NativeStreamTransformerInterface;
use LangGraph\Agents\Transformers\StreamChannel;

/**
 * A stand-in for langgraph's messages transformer: pushes one string per message streamed at exactly its
 * namespace (the concatenated text deltas between `message-start` and `message-finish`).
 */
final class RecordingMessagesTransformer implements NativeStreamTransformerInterface
{
    private StreamChannel $messages;

    private string $buffer = '';

    /** @param list<string> $namespace */
    public function __construct(private readonly array $namespace)
    {
        $this->messages = new StreamChannel();
    }

    public function init(): array
    {
        return ['messages' => $this->messages];
    }

    public function process(array $event): bool
    {
        if ($event['method'] !== 'messages' || $event['params']['namespace'] !== $this->namespace) {
            return true;
        }
        $data = $event['params']['data'];
        match ($data['event'] ?? null) {
            'message-start' => $this->buffer = '',
            'content-block-delta' => $this->buffer .= (string) ($data['delta']['text'] ?? ''),
            'message-finish' => $this->messages->push($this->buffer),
            default => null,
        };

        return true;
    }

    public function finalize(): void
    {
        $this->messages->close();
    }

    public function fail(mixed $error): void
    {
        $this->messages->fail($error);
    }
}
