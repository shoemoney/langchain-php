<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * Pull the JSON out of a string, tolerating a markdown code fence.
 *
 * Port of `@langchain/core/utils/json`.
 *
 * Models emit JSON while still writing it, so a strict `json_decode` fails on
 * every prefix of a document. {@see self::parsePartialJson()} delegates to a
 * recursive descent parser that stops at the end of whatever it has, which is
 * what makes streaming structured output possible.
 */
final class JsonUtils
{
    private function __construct()
    {
    }

    /**
     * Pull the JSON out of a string, tolerating a markdown code fence.
     *
     * Handles a fence at the start (` ```json … ``` `), a bare fence, no fence
     * at all, and trailing prose after the closing fence.
     *
     * @param callable(string): mixed $parser
     */
    public static function parseJsonMarkdown(string $s, ?callable $parser = null): mixed
    {
        $parser ??= static fn (string $t): mixed => self::parsePartialJson($t);

        $s = trim($s);

        $firstFence = strpos($s, '```');
        if ($firstFence === false) {
            return $parser($s);
        }

        $after = substr($s, $firstFence + 3);
        if (str_starts_with($after, "json\n")) {
            $after = substr($after, 5);
        } elseif (str_starts_with($after, 'json')) {
            $after = substr($after, 4);
        } elseif (str_starts_with($after, "\n")) {
            $after = substr($after, 1);
        }

        $closing = strpos($after, '```');
        $final = $closing === false ? $after : substr($after, 0, $closing);

        return $parser(trim($final));
    }

    /**
     * Parse JSON that may be truncated, returning null when nothing parses.
     */
    public static function parsePartialJson(string $s): mixed
    {
        try {
            return self::strictParsePartialJson($s);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse complete or truncated JSON.
     *
     * @throws \RuntimeException on malformed input
     */
    public static function strictParsePartialJson(string $s): mixed
    {
        try {
            return json_decode($s, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Not complete JSON — fall through to the partial parser.
        }

        return (new PartialJsonParser(trim($s)))->parse();
    }
}
