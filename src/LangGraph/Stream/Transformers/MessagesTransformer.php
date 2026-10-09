<?php

declare(strict_types=1);

namespace LangGraph\Stream\Transformers;

use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\ChatModelStream;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\Types;

/**
 * Groups `messages` events into one {@see ChatModelStream} per message lifecycle.
 *
 * Port of `createMessagesTransformer` from `stream/transformers/messages.ts`. A stream opens on
 * `message-start` and closes on `message-finish`; content-block events in between go to the matching open
 * stream (matched by `run_id`, else the message `id`, else a single default slot). Tool-role messages are
 * skipped: they belong to the tools stream. Only events exactly one namespace level below `$path` are
 * processed, since events at `$path` itself are chain-level replays of messages already streamed one level
 * down. With `$nodeFilter`, only events from that node count. The projection is `['messages' => iterable]`.
 */
final class MessagesTransformer extends AbstractStreamTransformer
{
    private readonly StreamChannel $log;

    /** @var array<string, StreamChannel> */
    private array $active = [];

    /** @var array<string, true> */
    private array $ignored = [];

    /**
     * @param list<string> $path
     * @param (callable(): bool)|null $driver given to every channel so iterating a message advances the run
     */
    public function __construct(
        private readonly array $path = [],
        private readonly ?string $nodeFilter = null,
        private $driver = null,
    ) {
        $this->log = StreamChannel::local();
        $this->log->setDriver($driver);
    }

    public function init(): array
    {
        return ['messages' => $this->log->toAsyncIterable()];
    }

    public function process(array $event): bool
    {
        if (($event['method'] ?? null) !== 'messages') {
            return true;
        }
        $namespace = $event['params']['namespace'];
        if (!Types::hasPrefix($namespace, $this->path)) {
            return true;
        }
        if (\count($namespace) !== \count($this->path) + 1) {
            return true;
        }
        if ($this->nodeFilter !== null && ($event['params']['node'] ?? null) !== $this->nodeFilter) {
            return true;
        }

        $data = $event['params']['data'];
        if (!\is_array($data)) {
            return true;
        }
        $key = self::streamKey($data);

        switch ($data['event'] ?? null) {
            case 'message-start':
                // Tool results belong on the tools stream and state snapshots, not the chat-token projection.
                if (($data['role'] ?? null) === 'tool') {
                    $this->ignored[$key] = true;
                    break;
                }
                $source = StreamChannel::local();
                $source->setDriver($this->driver);
                $this->active[$key] = $source;
                $source->push($data);
                $this->log->push(new ChatModelStream($source, $namespace, $event['params']['node'] ?? null));
                break;

            case 'content-block-start':
            case 'content-block-delta':
            case 'content-block-finish':
            case 'error':
                if (isset($this->ignored[$key])) {
                    break;
                }
                $this->active[$key]?->push($data);
                break;

            case 'message-finish':
                if (isset($this->ignored[$key])) {
                    unset($this->ignored[$key]);
                    break;
                }
                $stream = $this->active[$key] ?? null;
                if ($stream !== null) {
                    $stream->push($data);
                    $stream->close();
                    unset($this->active[$key]);
                }
                break;
        }

        return true;
    }

    public function finalize(): mixed
    {
        foreach ($this->active as $key => $source) {
            $source->push(['event' => 'message-finish']);
            $source->close();
            unset($this->active[$key]);
        }
        $this->ignored = [];
        $this->log->close();

        return null;
    }

    public function fail(mixed $error): void
    {
        foreach ($this->active as $key => $source) {
            $source->fail($error);
            unset($this->active[$key]);
        }
        $this->ignored = [];
        $this->log->fail($error);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function streamKey(array $data): string
    {
        if (isset($data['run_id']) && \is_string($data['run_id'])) {
            return 'run:' . $data['run_id'];
        }
        if (($data['event'] ?? null) === 'message-start' && isset($data['id']) && \is_string($data['id'])) {
            return 'message:' . $data['id'];
        }

        return '__default__';
    }
}
