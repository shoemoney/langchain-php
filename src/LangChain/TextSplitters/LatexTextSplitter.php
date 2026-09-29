<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * Implementation of splitter which recursively looks at characters, tuned for
 * LaTeX.
 *
 * Port of `LatexTextSplitter` from `@langchain/textsplitters`.
 *
 * {@see RecursiveCharacterTextSplitter} with the LaTeX separator hierarchy
 * substituted in. The hierarchy is ordered by LaTeX's own structure — sectioning
 * commands first, then environments, then inline math — which is what keeps a
 * `\begin{verbatim}` block and its matching `\end{verbatim}` from being pulled
 * into different chunks.
 */
class LatexTextSplitter extends RecursiveCharacterTextSplitter
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
            separators: self::getSeparatorsForLanguage(Language::LATEX),
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            keepSeparator: $keepSeparator,
            lengthFunction: $lengthFunction,
        );
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'document_transformers', 'text_splitters', 'LatexTextSplitter'];
    }
}
