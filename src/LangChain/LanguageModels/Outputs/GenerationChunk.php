<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

/**
 * One piece of a completion, as it arrives mid-stream.
 *
 * Port of `GenerationChunk` from `@langchain/core/outputs`.
 *
 * The important method is {@see self::concat()}. Streaming gives you deltas; the
 * framework wants the whole text for caching, for `handleLLMEnd`, and for
 * anything that reads the completed output. Folding is the operation that
 * bridges those two, and it is *not* a plain string concat: the message chunks
 * underneath carry tool-call deltas and token counts that only merge correctly
 * by their own rules.
 */
class GenerationChunk extends Generation
{
    /**
     * Fold `$chunk` onto this one.
     *
     * `generationInfo` is merged with the incoming chunk winning, matching the
     * original's `{...this.generationInfo, ...chunk.generationInfo}`.
     */
    public function concat(Generation $chunk): static
    {
        return $this->concatInto(new static(), $chunk);
    }

    /**
     * Fold `$chunk` into `$target`, which the caller has already constructed.
     *
     * Split out because a subclass that adds a field — a message, say — needs
     * to build its own instance and hand it here. `new static(...)` inside the
     * base would discard those extra constructor arguments.
     */
    protected function concatInto(GenerationChunk $target, Generation $chunk): GenerationChunk
    {
        $target->text = $this->text . $chunk->text;
        $target->generationInfo = array_merge($this->generationInfo, $chunk->generationInfo);

        return $target;
    }
}
