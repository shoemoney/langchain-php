<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Messages;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Constants;

/**
 * A callback handler that implements `streamMode: 'messages'`.
 *
 * Port of `StreamMessagesHandler` from `langgraph-core/src/pregel/messages.ts`.
 *
 * Collects messages from two places: (1) chat model stream events, token by
 * token, and (2) the output of a node, for messages a node produced without a
 * model streaming them. Both arrive as callbacks, which is why this is a
 * handler and not part of the loop: the loop cannot see inside a node, but a
 * callback manager can.
 *
 * ## Metadata is the gate
 *
 * A run is only forwarded if `handleChatModelStart` / `handleChainStart` stored
 * metadata for it. A model with the `nostream` tag, a node tagged hidden, and a
 * model called outside any graph node all fall out of the same check: no
 * metadata, no emit.
 *
 * ## The chunk shape
 *
 * `$streamFn` receives upstream's `[namespace, 'messages', [message, metadata]]`
 * triple unchanged. `Pregel::stream()` is what narrows that to this port's
 * `['messages', [message, metadata]]` envelope, so the handler stays a faithful
 * port and the one place the two conventions meet is named.
 */
class StreamMessagesHandler extends BaseCallbackHandler
{
    public string $name = 'StreamMessagesHandler';

    public bool $preferStreaming = true;

    /** @var callable(array{0: list<string>, 1: string, 2: array{0: BaseMessage, 1: array<string, mixed>}}): void */
    public $streamFn;

    /** @var array<string, array{0: list<string>, 1: array<string, mixed>}|null> */
    public array $metadatas = [];

    /** @var array<string, BaseMessage> */
    public array $seen = [];

    /** @var array<string, bool> */
    public array $emittedChatModelRunIds = [];

    /** @var array<string, string> */
    public array $stableMessageIdMap = [];

    /**
     * @param callable(array{0: list<string>, 1: string, 2: array{0: BaseMessage, 1: array<string, mixed>}}): void $streamFn
     */
    public function __construct(callable $streamFn)
    {
        parent::__construct();
        $this->streamFn = $streamFn;
    }

    /**
     * The `[namespace, metadata]` pair a run is streamed under, or null if it has no namespace.
     *
     * Port of `normalizeStreamMetadata`. The namespace is the task's checkpoint
     * namespace split on `|`; without one the run did not come from a graph task
     * and there is nothing to attribute the message to.
     *
     * @param array<string, mixed>|null $metadata
     * @param list<string>|null         $tags
     * @return array{0: list<string>, 1: array<string, mixed>}|null
     */
    public static function normalizeStreamMetadata(?array $metadata, ?array $tags = null, ?string $name = null): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $namespace = $metadata['langgraph_checkpoint_ns'] ?? $metadata['checkpoint_ns'] ?? null;
        if (!is_string($namespace) || $namespace === '') {
            return null;
        }

        return [explode('|', $namespace), array_merge(['tags' => $tags, 'name' => $name], $metadata)];
    }

    /**
     * Stream one message, naming it stably first.
     *
     * Port of `_emit`. Three things happen before the message leaves:
     *
     *  - **Dedupe.** A message already seen (a node echoing its input, or a model
     *    whose final message was already streamed) is dropped when `$dedupe`.
     *  - **A stable id.** Chunks of one model run must share an id or a client
     *    sees one reply as many messages. A provider that stamps only the first
     *    chunk, or stamps `run-<runId>` and then changes its mind, is smoothed
     *    over by remembering the first id per run. Tool messages are keyed by
     *    their tool call instead, since one run can answer several calls.
     *  - **The id is written back** onto the message and its constructor kwargs,
     *    so what the client received and what is later checkpointed agree.
     *
     * @param array{0: list<string>, 1: array<string, mixed>} $meta
     */
    public function emit(array $meta, BaseMessage $message, ?string $runId, bool $dedupe = false): void
    {
        if ($dedupe && $message->id !== null && isset($this->seen[$message->id])) {
            return;
        }

        $messageId = $message->id;

        if ($runId !== null) {
            if ($message instanceof ToolMessage || $message instanceof ToolMessageChunk) {
                $messageId ??= 'run-' . $runId . '-tool-' . $message->toolCallId;
            } else {
                if ($messageId === null || $messageId === 'run-' . $runId) {
                    $messageId = $this->stableMessageIdMap[$runId] ?? $messageId ?? 'run-' . $runId;
                }

                $this->stableMessageIdMap[$runId] ??= $messageId;
            }
        }

        if ($messageId !== $message->id) {
            self::assignMessageId($message, $messageId);
        }

        if ($message->id !== null) {
            $this->seen[$message->id] = $message;
        }

        ($this->streamFn)([$meta[0], 'messages', [$message, $meta[1]]]);
    }

    /**
     * Set a message's id and the id recorded in its constructor kwargs.
     *
     * Upstream writes both `message.id` and `message.lc_kwargs.id`. The kwargs
     * are what serialization and checkpointing read, so updating only the
     * property would stream one id and persist another. PHP exposes the kwargs
     * read-only, so the write goes through a closure bound to the message's
     * own scope.
     */
    public static function assignMessageId(BaseMessage $message, ?string $id): void
    {
        $message->id = $id;
        \Closure::bind(
            static function (BaseMessage $m) use ($id): void {
                $m->kwargs['id'] = $id;
            },
            null,
            BaseMessage::class,
        )($message);
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
        if (self::isNoStream($tags)) {
            return;
        }

        $this->metadatas[$runId] = self::normalizeStreamMetadata($metadata, $tags, $runName);
    }

    /**
     * @param array{prompt?: int, completion?: int}             $idx
     * @param list<string>                                      $tags
     * @param array{chunk?: mixed} $fields
     */
    public function handleLLMNewToken(
        string $token,
        array $idx,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $fields = [],
    ): void {
        $chunk = $fields['chunk'] ?? null;
        $this->emittedChatModelRunIds[$runId] = true;

        $meta = $this->metadatas[$runId] ?? null;
        if ($meta === null) {
            return;
        }

        if ($chunk instanceof ChatGenerationChunk) {
            $this->emit($meta, $chunk->message, $runId);
        } else {
            $this->emit($meta, new AIMessageChunk(['content' => $token]), $runId);
        }
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMEnd(
        LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $meta = $this->metadatas[$runId] ?? null;

        // A run we hold no metadata for is not ours to forward.
        if ($meta === null) {
            return;
        }

        // A model that did not stream never called handleLLMNewToken, so its one
        // message is emitted here instead.
        if (!isset($this->emittedChatModelRunIds[$runId])) {
            $generation = $output->generations[0][0] ?? null;
            if ($generation instanceof ChatGeneration) {
                $this->emit($meta, $generation->message, $runId, true);
            }
        }
        unset($this->emittedChatModelRunIds[$runId]);

        unset($this->metadatas[$runId], $this->stableMessageIdMap[$runId]);
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
        unset($this->metadatas[$runId]);
    }

    /**
     * @param mixed                $inputs
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

        $this->metadatas[$runId] = self::normalizeStreamMetadata($metadata, $tags, $runName);

        if (!is_array($inputs)) {
            return;
        }

        // Messages a node was handed are not its to announce: remembering them
        // as seen is what lets the node's echo of its own input be deduped.
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
     * @param mixed                $outputs A message, a list of values, or a map of values.
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

        if ($outputs instanceof BaseMessage) {
            $this->emit($meta, $outputs, $runId, true);

            return;
        }

        if (!is_array($outputs)) {
            return;
        }

        if (array_is_list($outputs)) {
            foreach ($outputs as $value) {
                if ($value instanceof BaseMessage) {
                    $this->emit($meta, $value, $runId, true);
                }
            }

            return;
        }

        foreach ($outputs as $value) {
            if ($value instanceof BaseMessage) {
                $this->emit($meta, $value, $runId, true);
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof BaseMessage) {
                        $this->emit($meta, $item, $runId, true);
                    }
                }
            }
        }
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
        unset($this->metadatas[$runId]);
    }

    /**
     * Whether a run opted out of streaming.
     *
     * Includes the legacy `nostream` tag the LangGraph SDK used before the
     * `langsmith:`-prefixed constant existed.
     *
     * @param list<string> $tags
     */
    protected static function isNoStream(array $tags): bool
    {
        return in_array(Constants::TAG_NOSTREAM, $tags, true) || in_array('nostream', $tags, true);
    }
}
