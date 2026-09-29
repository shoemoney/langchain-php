<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Render a message for console/debug output.
 *
 * Port of `@langchain/core/messages/format`. The JS original supports
 * `'pretty'` (a class-name header plus the printable fields as indented JSON)
 * and `'raw'` (just the content).
 */
final class MessageFormat
{
    private function __construct()
    {
    }

    /**
     * `pretty` prints the class name and a bounded-depth JSON dump of the
     * printable fields; `raw` prints only the text content. Any other string is
     * treated as a PHP sprintf template receiving the message text, which is
     * how the TS version handles its format strings too.
     */
    public static function convert(BaseMessage $message, string $format = 'pretty'): string
    {
        if ($format === 'raw') {
            return $message->text();
        }

        if ($format === 'pretty') {
            $short = (new \ReflectionClass($message))->getShortName();
            $json = json_encode(
                self::withDepthLimit($message->printableFields(), max(4, 2)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );

            return $short . ' ' . ($json === false ? '{}' : $json);
        }

        return sprintf($format, $message->text());
    }

    /**
     * Replace anything deeper than $limit with a marker, so printing a message
     * that carries a large base64 image does not flood the terminal.
     *
     * @param list<string> $ignoreKeys
     */
    public static function withDepthLimit(mixed $value, int $limit, array $ignoreKeys = []): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($limit <= 0) {
            return \LangChain\Utils\Js::isList($value) ? '[Array]' : '[Object]';
        }
        if (\LangChain\Utils\Js::isList($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::withDepthLimit($item, $limit - 1, $ignoreKeys);
            }

            return $out;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = in_array($key, $ignoreKeys, true)
                ? $item
                : self::withDepthLimit($item, $limit - 1, $ignoreKeys);
        }

        return $out;
    }
}
