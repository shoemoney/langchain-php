<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * Implementation of splitter which recursively looks at characters, tuned for
 * Markdown.
 *
 * Port of `MarkdownTextSplitter` from `@langchain/textsplitters`.
 *
 * It is {@see RecursiveCharacterTextSplitter} with the Markdown separator
 * hierarchy substituted in — the subclass exists so callers do not have to
 * remember which separator list to pass. What that hierarchy buys is that a
 * heading is never orphaned from the section it introduces, and that a fenced
 * code block is treated as a unit rather than being sliced mid-statement.
 */
class MarkdownTextSplitter extends RecursiveCharacterTextSplitter
{
    /**
     * @param int|null           $chunkSize
     * @param int|null           $chunkOverlap
     * @param bool|null          $keepSeparator
     * @param (callable(string): int)|null $lengthFunction
     */
    public function __construct(
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
        ?bool $keepSeparator = null,
        ?callable $lengthFunction = null,
    ) {
        parent::__construct(
            separators: self::getSeparatorsForLanguage(Language::MARKDOWN),
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            keepSeparator: $keepSeparator,
            lengthFunction: $lengthFunction,
        );
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'document_transformers', 'text_splitters', 'MarkdownTextSplitter'];
    }
}
