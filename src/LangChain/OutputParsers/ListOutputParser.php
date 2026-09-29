<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableConfig;

/**
 * A streaming parser that splits text into a list.
 *
 * Port of `ListOutputParser` from `@langchain_core/output_parsers/list`.
 *
 * Splitting a *stream* of text into a list is harder than splitting a finished
 * string: the boundary between two items may straddle a chunk. This class
 * therefore accumulates into a buffer and emits an item only once it knows the
 * item is complete — either because {@see self::parse()} produced more than one
 * piece (keep all but the last) or because a subclass's regular expression
 * matched more than once (same rule, applied to match offsets).
 *
 * Subclasses that set {@see self::$rePattern} override {@see self::parse()}
 * with a `preg_match_all` over that pattern.
 *
 * @extends BaseTransformOutputParser<list<string>>
 */
abstract class ListOutputParser extends BaseTransformOutputParser
{
    /**
     * A pattern whose first capture group identifies one item.
     *
     * Empty for the plain "split on a separator" parsers.
     */
    protected ?string $rePattern = null;

    /**
     * @param iterable<string|BaseMessage> $input
     * @return \Generator<int, list<string>>
     */
    protected function _transform(iterable $input): \Generator
    {
        $buffer = '';

        foreach ($input as $chunk) {
            $buffer .= $chunk instanceof BaseMessage ? $chunk->text() : (string) $chunk;

            if ($this->rePattern === null) {
                $parts = $this->parse($buffer);
                if (count($parts) > 1) {
                    foreach (\array_slice($parts, 0, -1) as $part) {
                        yield [$part];
                    }
                    $buffer = $parts[count($parts) - 1];
                }
                continue;
            }

            $matches = self::matchAll($this->rePattern, $buffer);
            if (count($matches) > 1) {
                $doneIdx = 0;
                foreach (\array_slice($matches, 0, -1) as $match) {
                    yield [$match['group']];
                    $doneIdx += $match['index'] + $match['length'];
                }
                $buffer = mb_substr($buffer, $doneIdx, null, 'UTF-8');
            }
        }

        foreach ($this->parse($buffer) as $part) {
            yield [$part];
        }
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return '';
    }

    /**
     * Every match of `$pattern` in `$subject`, with code-point offsets.
     *
     * `preg_match_all` reports byte offsets; the TypeScript original works in
     * UTF-16 units, so byte offsets are converted before being handed back.
     *
     * @return list<array{group: string, index: int, length: int}>
     */
    protected static function matchAll(string $pattern, string $subject): array
    {
        $count = preg_match_all($pattern, $subject, $matches, PREG_OFFSET_CAPTURE);
        if ($count === false) {
            return [];
        }

        $out = [];
        foreach ($matches[1] ?? [] as $i => [$value, $groupOffset]) {
            // The consumed span runs from the START OF THE WHOLE MATCH, not from
            // the capture group — using the group's offset here would resume the
            // scan in the middle of a match.
            $wholeMatch = (string) ($matches[0][$i][0] ?? $value);
            $wholeMatchOffset = (int) ($matches[0][$i][1] ?? $groupOffset);
            $out[] = [
                'group' => (string) $value,
                'index' => mb_strlen(substr($subject, 0, $wholeMatchOffset), 'UTF-8'),
                'length' => mb_strlen($wholeMatch, 'UTF-8'),
            ];
        }

        return $out;
    }

    /**
     * Split LLM output into a list.
     *
     * @return list<string>
     */
    abstract public function parse(string $text, ?RunnableConfig $config = null): array;
}
