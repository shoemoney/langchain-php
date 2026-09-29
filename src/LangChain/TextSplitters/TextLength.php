<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * The default size measure used by {@see TextSplitter}.
 *
 * Port of the `(text) => text.length` default in `@langchain/textsplitters`.
 *
 * JS `String.length` counts UTF-16 *code units*, not Unicode code points, so an
 * astral character — an emoji, a rare CJK ideograph — counts as two. A PHP
 * `mb_strlen()` counts code points and would count it as one, which quietly
 * changes where chunk boundaries land in emoji-heavy text. This port therefore
 * reproduces the JS measure exactly, because a splitter whose chunks silently
 * disagree with the reference implementation's is a bug that only shows up in
 * production retrieval quality.
 */
final class TextLength
{
    private function __construct()
    {
    }

    /**
     * The number of UTF-16 code units in `$text` — the JS `text.length`.
     *
     * Computed as (code points) + (astral code points), since every code point
     * outside the Basic Multilingual Plane needs a surrogate pair.
     */
    public static function utf16CodeUnits(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return mb_strlen($text, 'UTF-8')
            + preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
    }
}
