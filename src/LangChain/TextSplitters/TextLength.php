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

        // An astral character is 2 UTF-16 code units but 1 in `mb_strlen`, so
        // each one is an extra unit.
        //
        // The `/u` pattern makes preg_match_all return FALSE on invalid UTF-8
        // rather than 0, and `int + false` coerces to `int + 0` — so the count
        // silently degraded to the plain mb_strlen figure. Measured on
        // "a\xFF\xFEb": 4, exactly mb_strlen, with the astral contribution
        // dropped for the whole string.
        //
        // That fallback is right — astral characters cannot be counted in text
        // whose encoding is already broken — but it was reaching it by accident,
        // through loose coercion, with nothing saying so. Stated explicitly:
        // a damaged string measures as its code-point count.
        $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);

        return mb_strlen($text, 'UTF-8') + ($astral === false ? 0 : $astral);
    }
}
