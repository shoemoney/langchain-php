<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * Parse a markdown bulleted list out of a model call.
 *
 * Port of `MarkdownListOutputParser` from `@langchain_core/output_parsers/list`.
 *
 * Accepts both `-` and `*` bullets at any indentation, which is the range of
 * output models actually produce. Non-bullet text is discarded rather than
 * mistaken for an item.
 *
 * @extends ListOutputParser
 */
class MarkdownListOutputParser extends ListOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'list'];
    }

    protected ?string $rePattern = '/^\s*[-*]\s([^\n]+)$/m';

    /**
     * @return list<string>
     */
    public function parse(string $text, ?\LangChain\Runnables\RunnableConfig $config = null): array
    {
        $count = preg_match_all($this->rePattern ?? '', $text, $matches);
        if ($count === false) {
            return [];
        }

        return array_map(static fn (mixed $m): string => (string) $m, $matches[1] ?? []);
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return "Your response should be a numbered list with each item on a new line. For example: \n\n1. foo\n\n2. bar\n\n3. baz";
    }
}
