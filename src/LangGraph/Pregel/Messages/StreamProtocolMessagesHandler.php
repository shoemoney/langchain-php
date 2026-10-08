<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Messages;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Constants;

/**
 * A callback handler that implements protocol-native `streamMode: 'messages'`.
 *
 * Port of `StreamProtocolMessagesHandler` from
 * `langgraph-core/src/pregel/messages-v2.ts`.
 *
 * Where {@see StreamMessagesHandler} forwards whole message chunks, this one
 * forwards content-block LIFECYCLE events (`message-start`,
 * `content-block-start`, `content-block-delta`, `content-block-finish`,
 * `message-finish`). The chat model owns constructing those events; this
 * handler only attaches LangGraph's namespace and run metadata and forwards
 * them, then synthesises the same lifecycle for a message that arrived whole
 * (a node's output, or a model that cannot stream events).
 *
 * Events are plain arrays shaped exactly as upstream's `ChatModelStreamEvent`
 * union, so a consumer reads the same keys on either side of the port.
 *
 * `$streamFn` receives upstream's `[namespace, 'messages', [event, metadata]]`
 * triple; see {@see StreamMessagesHandler} for where it is narrowed.
 */
class StreamProtocolMessagesHandler extends BaseCallbackHandler
{
    public string $name = 'StreamProtocolMessagesHandler';

    public bool $preferChatModelStreamEvents = true;

    /** @var callable(array{0: list<string>, 1: string, 2: array{0: array<string, mixed>, 1: array<string, mixed>}}): void */
    public $streamFn;

    /** @var array<string, array{0: list<string>, 1: array<string, mixed>}|null> */
    public array $metadatas = [];

    /** @var array<string, BaseMessage|true> */
    public array $seen = [];

    /** @var array<string, bool> */
    public array $streamedRunIds = [];

    /** @var array<string, string> */
    public array $stableMessageIdMap = [];

    /**
     * @param callable(array{0: list<string>, 1: string, 2: array{0: array<string, mixed>, 1: array<string, mixed>}}): void $streamFn
     */
    public function __construct(callable $streamFn)
    {
        parent::__construct();
        $this->streamFn = $streamFn;
        $this->awaitHandlers = true;
    }

    /**
     * Give a message its stable id and record it as seen.
     *
     * Same rule as {@see StreamMessagesHandler::emit()}, minus the emit.
     */
    private function normalizeMessageId(BaseMessage $message, ?string $runId): ?string
    {
        $messageId = $message->id;

        if ($runId !== null) {
            if ($message instanceof ToolMessage) {
                $messageId ??= 'run-' . $runId . '-tool-' . $message->toolCallId;
            } else {
                if ($messageId === null || $messageId === 'run-' . $runId) {
                    $messageId = $this->stableMessageIdMap[$runId] ?? $messageId ?? 'run-' . $runId;
                }
                $this->stableMessageIdMap[$runId] ??= $messageId;
            }
        }

        if ($messageId !== $message->id) {
            StreamMessagesHandler::assignMessageId($message, $messageId);
        }

        if ($message->id !== null) {
            $this->seen[$message->id] = $message;
        }

        return $message->id;
    }

    /**
     * @param array{0: list<string>, 1: array<string, mixed>} $meta
     * @param array<string, mixed>                            $data
     */
    private function forward(array $meta, array $data, ?string $runId = null): void
    {
        $metadata = $runId !== null ? array_merge($meta[1], ['run_id' => $runId]) : $meta[1];
        ($this->streamFn)([$meta[0], 'messages', [$data, $metadata]]);
    }

    /**
     * Emit a complete message as protocol events.
     *
     * Public so a manually pushed message can be delivered when this handler is
     * registered, mirroring the classic path's `emit`.
     *
     * @param array{0: list<string>, 1: array<string, mixed>} $meta
     */
    public function emitFinalMessage(array $meta, BaseMessage $message, ?string $runId, bool $dedupe = false): void
    {
        $existingId = $message->id ?? ($runId !== null ? ($this->stableMessageIdMap[$runId] ?? null) : null);
        if ($dedupe && $existingId !== null && isset($this->seen[$existingId])) {
            return;
        }

        $messageId = $this->normalizeMessageId($message, $runId);
        $role = match ($message->type) {
            'human' => 'human',
            'system' => 'system',
            'tool' => 'tool',
            default => 'ai',
        };
        $toolCallId = $role === 'tool' && $message instanceof ToolMessage ? $message->toolCallId : null;

        $start = ['event' => 'message-start'];
        if ($messageId !== null) {
            $start['id'] = $messageId;
        }
        if ($role !== 'ai') {
            $start['role'] = $role;
        }
        if ($toolCallId !== null) {
            $start['tool_call_id'] = $toolCallId;
        }
        $this->forward($meta, $start, $runId);

        if (is_array($message->content)) {
            $contentBlocks = $message->content;
        } else {
            $contentBlocks = $message->content !== '' ? [['type' => 'text', 'text' => $message->content]] : [];
        }

        foreach (array_values($contentBlocks) as $offset => $block) {
            if (!is_array($block)) {
                continue;
            }
            $index = is_int($block['index'] ?? null) ? $block['index'] : $offset;

            $this->forward($meta, [
                'event' => 'content-block-start',
                'index' => $index,
                'content' => self::startBlockFor($block),
            ], $runId);

            $delta = self::deltaFor(array_merge($block, ['index' => $index]));
            if ($delta !== null) {
                $this->forward($meta, $delta, $runId);
            }

            $this->forward($meta, [
                'event' => 'content-block-finish',
                'index' => $index,
                'content' => $block,
            ], $runId);
        }

        $finish = ['event' => 'message-finish'];
        $usage = self::usageMetadata($message);
        if ($usage !== null) {
            $finish['usage'] = $usage;
        }
        $finish['responseMetadata'] = $message->response_metadata;
        $this->forward($meta, $finish, $runId);
    }

    /**
     * @param list<list<BaseMessage>> $messages
     * @param array<string, mixed>    $extraParams
     * @param list<string>            $tags
     * @param array<string, mixed>    $metadata
     */
    public function handleChatModelStart(
        Serialized $llm,
        array $messages,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): void {
        if (in_array(Constants::TAG_NOSTREAM, $tags, true) || in_array('nostream', $tags, true)) {
            return;
        }

        $this->metadatas[$runId] = StreamMessagesHandler::normalizeStreamMetadata($metadata, $tags, $runName);
    }

    /**
     * Core v2 stream events are forwarded via {@see self::handleChatModelStreamEvent()}.
     *
     * @param array{prompt?: int, completion?: int} $idx
     * @param list<string>                          $tags
     * @param array{chunk?: mixed}                  $fields
     */
    public function handleLLMNewToken(
        string $token,
        array $idx,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $fields = [],
    ): void {
    }

    /**
     * @param array<string, mixed> $event
     * @param list<string>         $tags
     */
    public function handleChatModelStreamEvent(
        array $event,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
    ): void {
        $meta = $this->metadatas[$runId] ?? null;
        if ($meta === null) {
            return;
        }

        $forwarded = $event;
        if (($event['event'] ?? null) === 'message-start') {
            $this->streamedRunIds[$runId] = true;
            $id = $event['id'] ?? 'run-' . $runId;
            $this->seen[$id] = true;
            $this->stableMessageIdMap[$runId] ??= $id;
            if (($event['id'] ?? null) === null) {
                $forwarded = array_merge($event, ['id' => $id]);
            }
        }

        $this->forward($meta, $forwarded, $runId);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMEnd(
        \LangChain\LanguageModels\Outputs\LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $meta = $this->metadatas[$runId] ?? null;
        if ($meta === null) {
            return;
        }

        $generation = $output->generations[0][0] ?? null;
        $message = $generation instanceof \LangChain\LanguageModels\Outputs\ChatGeneration ? $generation->message : null;

        if ($message !== null) {
            if (isset($this->streamedRunIds[$runId])) {
                $messageId = $this->normalizeMessageId($message, $runId);
                if ($messageId !== null) {
                    $this->seen[$messageId] = $message;
                }
            } else {
                $this->emitFinalMessage($meta, $message, $runId, true);
            }
        }

        unset($this->streamedRunIds[$runId], $this->metadatas[$runId], $this->stableMessageIdMap[$runId]);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        unset($this->streamedRunIds[$runId], $this->metadatas[$runId], $this->stableMessageIdMap[$runId]);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     */
    public function handleChainStart(
        Serialized $chain,
        mixed $inputs,
        string $runId,
        ?string $runType = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $parentRunId = null,
        array $extra = [],
    ): void {
        if ($runName === null
            || ($metadata['langgraph_node'] ?? null) !== $runName
            || in_array(Constants::TAG_HIDDEN, $tags, true)) {
            return;
        }

        $this->metadatas[$runId] = StreamMessagesHandler::normalizeStreamMetadata($metadata, $tags, $runName);

        if (!is_array($inputs)) {
            return;
        }

        foreach ($inputs as $value) {
            if ($value instanceof BaseMessage) {
                if ($value->id !== null) {
                    $this->seen[$value->id] = $value;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof BaseMessage && $item->id !== null) {
                        $this->seen[$item->id] = $item;
                    }
                }
            }
        }
    }

    /**
     * @param mixed                $outputs
     * @param list<string>         $tags
     * @param array<string, mixed> $kwargs
     */
    public function handleChainEnd(
        mixed $outputs,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        $meta = $this->metadatas[$runId] ?? null;
        unset($this->metadatas[$runId]);
        if ($meta === null) {
            return;
        }

        // A tool result is not a chat message stream: it belongs to `tools`.
        $emitMessage = function (mixed $value) use ($meta, $runId): void {
            if ($value instanceof BaseMessage && !$value instanceof ToolMessage) {
                $this->emitFinalMessage($meta, $value, $runId, true);
            }
        };

        if ($outputs instanceof BaseMessage) {
            $emitMessage($outputs);
        } elseif (is_array($outputs) && array_is_list($outputs)) {
            foreach ($outputs as $value) {
                $emitMessage($value);
            }
        } elseif (is_array($outputs)) {
            foreach ($outputs as $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $emitMessage($item);
                    }
                } else {
                    $emitMessage($value);
                }
            }
        }

        unset($this->stableMessageIdMap[$runId]);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $kwargs
     */
    public function handleChainError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        unset($this->metadatas[$runId], $this->stableMessageIdMap[$runId]);
    }

    /**
     * The empty block a content block opens with.
     *
     * Text and reasoning open empty and are filled by a delta; a tool call opens
     * as a chunk carrying only its id and name, with empty args to be streamed.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function startBlockFor(array $block): array
    {
        switch ($block['type'] ?? null) {
            case 'text':
                return ['type' => 'text', 'text' => ''];
            case 'reasoning':
                return ['type' => 'reasoning', 'reasoning' => ''];
            case 'tool_call':
            case 'tool_call_chunk':
                $start = ['type' => 'tool_call_chunk'];
                if (($block['id'] ?? null) !== null) {
                    $start['id'] = $block['id'];
                }
                if (($block['name'] ?? null) !== null) {
                    $start['name'] = $block['name'];
                }
                $start['args'] = '';

                return $start;
            default:
                return $block;
        }
    }

    /**
     * The one delta that fills a block opened by {@see self::startBlockFor()}.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>|null
     */
    private static function deltaFor(array $block): ?array
    {
        $index = is_int($block['index'] ?? null) ? $block['index'] : 0;

        switch ($block['type'] ?? null) {
            case 'text':
                $text = is_string($block['text'] ?? null) ? $block['text'] : '';

                return $text !== ''
                    ? ['event' => 'content-block-delta', 'index' => $index, 'delta' => ['type' => 'text-delta', 'text' => $text]]
                    : null;
            case 'reasoning':
                $reasoning = is_string($block['reasoning'] ?? null) ? $block['reasoning'] : '';

                return $reasoning !== ''
                    ? ['event' => 'content-block-delta', 'index' => $index, 'delta' => ['type' => 'reasoning-delta', 'reasoning' => $reasoning]]
                    : null;
            case 'tool_call_chunk':
                return [
                    'event' => 'content-block-delta',
                    'index' => $index,
                    'delta' => ['type' => 'block-delta', 'fields' => array_merge($block, ['type' => 'tool_call_chunk'])],
                ];
            default:
                return null;
        }
    }

    /**
     * A message's usage counters, if it carries any.
     *
     * Port messages have no `usage_metadata` property of their own; it is read
     * from the constructor kwargs where a provider that sets it records it.
     *
     * @return array<string, mixed>|null
     */
    private static function usageMetadata(BaseMessage $message): ?array
    {
        $usage = $message->kwargs['usage_metadata'] ?? null;

        return is_array($usage) ? $usage : null;
    }
}
