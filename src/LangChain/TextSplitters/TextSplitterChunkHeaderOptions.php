<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * Optional decoration applied to every chunk produced by {@see TextSplitter::createDocuments()}.
 *
 * Port of `TextSplitterChunkHeaderOptions` from `@langchain/textsplitters`.
 *
 * When documents are pulled from several sources into one vector store, a bare
 * chunk is often unattributable — "who said this?" is answered only by
 * metadata, which not every source populates. These options prepend a
 * provenance line to the page content itself, and mark the continuation chunks
 * so a reader can tell a fragment from a fresh quote.
 */
final class TextSplitterChunkHeaderOptions
{
    /**
     * @param string $chunkHeader             Prepended to every chunk.
     * @param string $chunkOverlapHeader      Prepended to every chunk *after* the first of a text.
     * @param bool   $appendChunkOverlapHeader Whether to apply `$chunkOverlapHeader` at all.
     */
    public function __construct(
        public string $chunkHeader = '',
        public string $chunkOverlapHeader = "(cont'd) ",
        public bool $appendChunkOverlapHeader = false,
    ) {
    }

    /**
     * Build from the loose associative shape callers write, ignoring keys the
     * options object does not understand rather than throwing.
     *
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options = []): self
    {
        $defaults = new self();

        return new self(
            chunkHeader: is_string($options['chunkHeader'] ?? null)
                ? $options['chunkHeader']
                : $defaults->chunkHeader,
            chunkOverlapHeader: is_string($options['chunkOverlapHeader'] ?? null)
                ? $options['chunkOverlapHeader']
                : $defaults->chunkOverlapHeader,
            appendChunkOverlapHeader: is_bool($options['appendChunkOverlapHeader'] ?? null)
                ? $options['appendChunkOverlapHeader']
                : $defaults->appendChunkOverlapHeader,
        );
    }
}
