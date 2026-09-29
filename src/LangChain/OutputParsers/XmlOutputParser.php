<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into nested arrays read from XML.
 *
 * Port of `XMLOutputParser` from `@langchain_core/output_parsers/xml`.
 *
 * The shape mapping is worth stating outright, because it is unusual and it is
 * the whole contract:
 *
 *  - an element with child elements becomes a *list* under its tag name, so
 *    repeated siblings stay distinguishable;
 *  - a leaf element becomes its text under its tag name;
 *  - attributes and text are dropped whenever a child element is present.
 *
 * A markdown fence around the XML is stripped first, and leading whitespace on
 * each line is removed so an indented document parses.
 *
 * @extends BaseCumulativeTransformOutputParser<array<string, mixed>>
 */
class XmlOutputParser extends BaseCumulativeTransformOutputParser
{
    /**
     * The instruction block appended to prompts that want XML back.
     *
     * Port of `XML_FORMAT_INSTRUCTIONS`; `{tags}` is substituted when the parser
     * was given a tag list.
     */
    public const FORMAT_INSTRUCTIONS = 'The output should be formatted as a XML file.
1. Output should conform to the tags below.
2. If tags are not given, make them on your own.
3. Remember to always open and close all the tags.

As an example, for the tags ["foo", "bar", "baz"]:
1. String "<foo>\n   <bar>\n      <baz></baz>\n   </bar>\n</foo>" is a well-formatted instance of the schema.
2. String "<foo>\n   <bar>\n   </foo>" is a badly-formatted instance.
3. String "<foo>\n   <tag>\n   </tag>\n</foo>" is a badly-formatted instance.

Here are the output tags:
```
{tags}
```';

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'XMLOutputParser'];
    }

    /**
     * Optional list of tags the output should conform to.
     *
     * Only used to build the format instructions — never to validate.
     *
     * @var list<string>
     */
    public array $tags = [];

    /**
     * @param array{tags?: list<string>, diff?: bool} $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->tags = $fields['tags'] ?? [];
    }

    /**
     * @param list<array{text: string}> $generations
     * @return array<string, mixed>|null
     */
    public function parsePartialResult(array $generations): mixed
    {
        $first = $generations[0] ?? null;
        if ($first === null) {
            return null;
        }

        return self::parseXmlMarkdown((string) ($first['text'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function parse(string $text, ?RunnableConfig $config = null): array
    {
        return self::parseXmlMarkdown($text);
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return $this->tags !== []
            ? str_replace('{tags}', implode(', ', $this->tags), self::FORMAT_INSTRUCTIONS)
            : self::FORMAT_INSTRUCTIONS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    protected function diffOperations(mixed $prev, mixed $next): ?array
    {
        if (JsonPatch::isFalsy($next)) {
            return null;
        }
        if (JsonPatch::isFalsy($prev)) {
            return [['op' => 'replace', 'path' => '', 'value' => $next]];
        }

        return JsonPatch::compare($prev, $next);
    }

    /**
     * Parse XML — optionally wrapped in a markdown fence — into nested arrays.
     *
     * @return array<string, mixed>
     */
    public static function parseXmlMarkdown(string $s): array
    {
        $cleaned = self::strip($s);

        // A fenced block wins: the prose around it is not part of the document.
        if (preg_match('/```(?:xml)?(.*)```/su', $cleaned, $m) === 1) {
            $cleaned = $m[1];
        }

        $parsed = (new XmlParser($cleaned))->parse();
        if ($parsed === null) {
            return [];
        }

        // A leading XML declaration, if one survived, wraps the real root.
        if ($parsed['name'] === '?xml' && $parsed['children'] !== []) {
            $parsed = $parsed['children'][0];
        }

        return self::parsedResultToArray($parsed);
    }

    /**
     * Remove leading whitespace from every line, then trim the whole string.
     */
    private static function strip(string $text): string
    {
        $lines = array_map(
            static fn (string $line): string => (string) preg_replace('/^\s+/u', '', $line),
            explode("\n", $text)
        );

        return trim(implode("\n", $lines));
    }

    /**
     * Turn one element into `{tagName: children[]}` or `{tagName: text}`.
     *
     * @param array{name: string, attributes: array<string, string>, children: list<array>, text: string, isSelfClosing: bool} $element
     * @return array<string, mixed>
     */
    private static function parsedResultToArray(array $element): array
    {
        if ($element['children'] !== []) {
            return [$element['name'] => array_map(self::parsedResultToArray(...), $element['children'])];
        }

        return [$element['name'] => $element['text']];
    }
}
