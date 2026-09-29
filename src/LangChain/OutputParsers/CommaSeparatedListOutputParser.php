<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into a comma-separated list.
 *
 * Port of `CommaSeparatedListOutputParser` from `@langchain_core/output_parsers/list`.
 *
 * The cheapest possible structured output: no regex, no nesting, one
 * instruction line in the prompt. Note that the trailing empty string in
 * `"a,b,c,"` is preserved rather than filtered — dropping it would silently
 * change the item count, which a downstream caller may be checking.
 *
 * @extends ListOutputParser
 */
class CommaSeparatedListOutputParser extends ListOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'list'];
    }

    /**
     * @return list<string>
     */
    public function parse(string $text, ?RunnableConfig $config = null): array
    {
        return array_map(
            static fn (string $s): string => trim($s),
            explode(',', trim($text))
        );
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return 'Your response should be a list of comma separated values, eg: `foo, bar, baz`';
    }
}
