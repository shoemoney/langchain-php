<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

use LangChain\Messages\BaseMessageChunk;

/**
 * One piece of a chat completion, as it arrives mid-stream.
 *
 * Port of `ChatGenerationChunk` from `@langchain/core/outputs`.
 *
 * {@see self::concat()} is the load-bearing part. It does not only append text:
 * it hands the message chunks to `BaseMessageChunk::concat()`, which is the
 * class that knows how to merge tool-call deltas (by `index` and `id`, not by
 * arrival order), usage counters, and content blocks. Folding a chat chunk as
 * if it were a string is how a streamed tool call ends up with its arguments
 * scrambled.
 */
class ChatGenerationChunk extends GenerationChunk
{
    /**
     * @param array<string, mixed> $generationInfo
     */
    /**
     * @param string $text Required, even though the message carries the same
     *        content. A chunk whose `text` is derived from `message` would let
     *        the two disagree, and `text` is what every consumer folds and what
     *        `handleLLMEnd` reports — so it is set explicitly and checked by the
     *        caller rather than guessed here.
     * @param array<string, mixed> $generationInfo
     */
    public function __construct(
        public BaseMessageChunk $message,
        string $text,
        array $generationInfo = [],
    ) {
        parent::__construct($text, $generationInfo);
    }

    public function concat(Generation $chunk): static
    {
        // The message is carried over first, then the shared fold fills text and
        // generationInfo. Passing the message through the constructor (rather
        // than assigning it after) is what keeps `text` mandatory, so a chunk
        // can never be built with a message and no text.
        $target = new static($this->message, '');
        parent::concatInto($target, $chunk);

        if ($chunk instanceof self) {
            // The message fold is the point of this override. It merges tool-call
            // deltas by index and id and sums usage counters, none of which a
            // string concat does — folding a chat chunk as if it were text is how
            // a streamed tool call ends up with scrambled arguments.
            $target->message = $this->message->concat($chunk->message);
        }
        // A plain Generation arriving where a ChatGenerationChunk was expected
        // contributes text but cannot extend the message, so the message passes
        // through unchanged.

        return $target;
    }
}
