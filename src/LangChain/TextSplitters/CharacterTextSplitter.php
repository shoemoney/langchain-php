<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * Implementation of splitter which looks at separators.
 *
 * Port of `CharacterTextSplitter` from `@langchain/textsplitters`.
 *
 * The simplest splitter in the family: cut on one literal separator, then merge
 * back up to `chunkSize`. It is the right tool when the input already has a
 * known structure — a log with blank lines between records, for instance. For
 * prose with no reliable delimiter, reach for {@see RecursiveCharacterTextSplitter},
 * which tries progressively weaker separators instead of betting everything on
 * one.
 */
class CharacterTextSplitter extends TextSplitter
{
    /**
     * The literal string to cut on. Empty means "cut anywhere".
     */
    public string $separator = "\n\n";

    /**
     * @param string|null  $separator Defaults to a blank line.
     * @param int|null     $chunkSize
     * @param int|null     $chunkOverlap
     * @param bool|null    $keepSeparator
     * @param (callable(string): int)|null $lengthFunction
     */
    public function __construct(
        ?string $separator = null,
        ?int $chunkSize = null,
        ?int $chunkOverlap = null,
        ?bool $keepSeparator = null,
        ?callable $lengthFunction = null,
    ) {
        parent::__construct(
            chunkSize: $chunkSize,
            chunkOverlap: $chunkOverlap,
            keepSeparator: $keepSeparator,
            lengthFunction: $lengthFunction,
        );

        $this->separator = $separator ?? $this->separator;
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'document_transformers', 'text_splitters', 'CharacterTextSplitter'];
    }

    /**
     * Split incoming text into chunks.
     *
     * @return list<string>
     */
    public function splitText(string $text): array
    {
        // First we naively split the large input into a bunch of smaller ones.
        $splits = $this->splitOnSeparator($text, $this->separator);

        // When the separator is being kept, it is already attached to the text
        // it introduces, so the merge step must join with nothing — joining
        // with the separator too would double it up.
        return $this->mergeSplits($splits, $this->keepSeparator ? '' : $this->separator);
    }
}
