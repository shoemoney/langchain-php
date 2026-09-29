<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

/**
 * The languages {@see RecursiveCharacterTextSplitter} ships a tuned separator
 * hierarchy for.
 *
 * Port of the `SupportedTextSplitterLanguages` constant (and the
 * `SupportedTextSplitterLanguage` type it feeds) from `@langchain/textsplitters`.
 *
 * "Tuned" means the separator list is ordered from the most semantically
 * meaningful break to the least: a function or class definition, then control
 * flow, then a blank line, then any space, then anywhere at all. A recursive
 * splitter walks that list in order and stops at the first separator that
 * actually appears in the text, so ordering *is* the behaviour.
 */
final class Language
{
    public const CPP = 'cpp';

    public const GO = 'go';

    public const JAVA = 'java';

    public const JS = 'js';

    public const PHP = 'php';

    public const PROTO = 'proto';

    public const PYTHON = 'python';

    public const RST = 'rst';

    public const RUBY = 'ruby';

    public const RUST = 'rust';

    public const SCALA = 'scala';

    public const SWIFT = 'swift';

    public const MARKDOWN = 'markdown';

    public const LATEX = 'latex';

    public const HTML = 'html';

    public const SOL = 'sol';

    /**
     * Every supported language name.
     *
     * @var list<string>
     */
    public const SUPPORTED_LANGUAGES = [
        self::CPP,
        self::GO,
        self::JAVA,
        self::JS,
        self::PHP,
        self::PROTO,
        self::PYTHON,
        self::RST,
        self::RUBY,
        self::RUST,
        self::SCALA,
        self::SWIFT,
        self::MARKDOWN,
        self::LATEX,
        self::HTML,
        self::SOL,
    ];

    /** @var array<string, true>|null */
    private static ?array $lookup = null;

    private function __construct()
    {
    }

    public static function isSupported(string $language): bool
    {
        self::$lookup ??= array_fill_keys(self::SUPPORTED_LANGUAGES, true);

        return isset(self::$lookup[$language]);
    }
}
