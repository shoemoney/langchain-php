<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into a fixed-length list with a custom separator.
 *
 * Port of `CustomListOutputParser` from `@langchain_core/output_parsers/list`.
 *
 * The useful addition over {@see CommaSeparatedListOutputParser} is the
 * optional `length`: when set, a list of the wrong size is a parse failure
 * rather than a silently wrong answer, which matters when the caller is about
 * to zip the items onto positional arguments.
 *
 * @extends ListOutputParser
 */
class CustomListOutputParser extends ListOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'list'];
    }

    private readonly ?int $expectedLength;

    private readonly string $separator;

    /**
     * @param int|null    $length    Expected item count, or null to accept any.
     * @param string|null $separator Defaults to a comma.
     */
    public function __construct(?int $length = null, ?string $separator = null)
    {
        $this->expectedLength = $length;
        $this->separator = $separator ?: ',';
    }

    /**
     * @return list<string>
     */
    public function parse(string $text, ?RunnableConfig $config = null): array
    {
        try {
            $items = array_map(
                static fn (string $s): string => trim($s),
                explode($this->separator, trim($text))
            );
            if ($this->expectedLength !== null && count($items) !== $this->expectedLength) {
                throw new OutputParserException(
                    "Incorrect number of items. Expected {$this->expectedLength}, got " . count($items) . '.'
                );
            }

            return $items;
        } catch (OutputParserException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new OutputParserException("Could not parse output: {$text}");
        }
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        $length = $this->expectedLength === null ? '' : "{$this->expectedLength} ";
        $s = $this->separator;

        return "Your response should be a list of {$length}items separated by \"{$s}\" (eg: `foo{$s} bar{$s} baz`)";
    }
}
