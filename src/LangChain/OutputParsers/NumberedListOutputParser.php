<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a numbered list out of a model call.
 *
 * Port of `NumberedListOutputParser` from `@langchain_core/output_parsers/list`.
 *
 * A regular-expression parser rather than a split: the numbering is the only
 * reliable delimiter a model produces reliably, and everything before the first
 * item ("Items:", "Sure, here they are:") is discarded rather than becoming a
 * bogus first entry.
 *
 * @extends ListOutputParser
 */
class NumberedListOutputParser extends ListOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'list'];
    }

    protected ?string $rePattern = '/\d+\.\s([^\n]+)/';

    /**
     * @return list<string>
     */
    public function parse(string $text, ?RunnableConfig $config = null): array
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
