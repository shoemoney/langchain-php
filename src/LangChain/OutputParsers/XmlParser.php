<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * A small strict XML reader producing a name/attributes/children/text tree.
 *
 * Port of the SAX event handling in
 * `parseXMLMarkdown` from `@langchain_core/output_parsers/xml`, which uses the
 * bundled `sax-js`. Only what that parser observes is modelled here: open tags,
 * close tags, text, and attributes.
 *
 * Two behaviours are inherited deliberately and are load-bearing:
 *
 *  - **Strictness.** A mismatched close tag is an error, not a silent recovery.
 *  - **Text belongs to the innermost open element.** Whitespace between
 *    children accumulates on the parent, which is harmless because a parent
 *    with children keeps the children and discards its own text.
 *
 * Processing instructions (`<?xml ... ?>`, `<?php ... ?>`), comments, CDATA and
 * doctypes are skipped rather than surfaced — the TypeScript parser has no
 * handler for any of them either.
 *
 * @internal
 */
final class XmlParser
{
    /**
     * @var list<array{name: string, attributes: array<string, string>, children: list<self>, text: string, isSelfClosing: bool}>
     */
    private array $stack = [];

    private ?array $root = null;

    private int $pos = 0;

    private readonly int $length;

    private string $text = '';

    public function __construct(private readonly string $xml)
    {
        $this->length = mb_strlen($xml, 'UTF-8');
    }

    /**
     * Parse the whole document.
     *
     * @return array{name: string, attributes: array<string, string>, children: list<self>, text: string, isSelfClosing: bool}|null
     */
    public function parse(): ?array
    {
        while ($this->pos < $this->length) {
            $char = $this->charAt($this->pos);

            if ($char === '<') {
                $this->readMarkup();
                continue;
            }

            $this->text .= $char;
            $this->pos++;
        }

        if ($this->stack !== []) {
            throw new \RuntimeException('Unexpected end of XML: unclosed tag ' . $this->stack[count($this->stack) - 1]['name']);
        }

        return $this->root;
    }

    private function charAt(int $i): string
    {
        return mb_substr($this->xml, $i, 1, 'UTF-8');
    }

    private function startsWith(string $needle): bool
    {
        return substr($this->xml, $this->pos, mb_strlen($needle, 'UTF-8')) === $needle;
    }

    /**
     * Handle a `<…>` construct.
     */
    private function readMarkup(): void
    {
        if ($this->startsWith('<!--')) {
            $end = strpos($this->xml, '-->', $this->pos);
            $this->pos = $end === false ? $this->length : $end + 3;

            return;
        }

        if ($this->startsWith('<![CDATA[')) {
            $end = strpos($this->xml, ']]>', $this->pos);
            $stop = $end === false ? $this->length : $end;
            $this->text .= mb_substr($this->xml, $this->pos + 9, $stop - $this->pos - 9, 'UTF-8');
            $this->pos = $end === false ? $this->length : $end + 3;

            return;
        }

        if ($this->startsWith('<?')) {
            $end = strpos($this->xml, '?>', $this->pos);
            $this->pos = $end === false ? $this->length : $end + 2;

            return;
        }

        if ($this->startsWith('<!')) {
            $end = strpos($this->xml, '>', $this->pos);
            $this->pos = $end === false ? $this->length : $end + 1;

            return;
        }

        if ($this->startsWith('</')) {
            $this->readCloseTag();

            return;
        }

        $this->readOpenTag();
    }

    private function readCloseTag(): void
    {
        $end = strpos($this->xml, '>', $this->pos);
        $stop = $end === false ? $this->length : $end;
        $name = trim(mb_substr($this->xml, $this->pos + 2, $stop - $this->pos - 2, 'UTF-8'));
        $this->pos = $end === false ? $this->length : $end + 1;

        if ($this->stack === []) {
            throw new \RuntimeException("Unexpected close tag {$name}");
        }

        $open = $this->stack[count($this->stack) - 1]['name'];
        if ($open !== $name) {
            throw new \RuntimeException("Unexpected close tag {$name}, expected {$open}");
        }

        $element = array_pop($this->stack);
        if ($element === null) {
            return;
        }
        $element['text'] = $this->text;
        $this->text = '';

        $this->attach($element);
    }

    private function readOpenTag(): void
    {
        $end = strpos($this->xml, '>', $this->pos);
        $stop = $end === false ? $this->length : $end;
        $body = mb_substr($this->xml, $this->pos + 1, $stop - $this->pos - 1, 'UTF-8');
        $selfClosing = str_ends_with($body, '/');
        if ($selfClosing) {
            $body = substr($body, 0, -1);
        }
        $this->pos = $end === false ? $this->length : $end + 1;

        [$name, $attributes] = $this->parseTagBody($body);

        $element = [
            'name' => $name,
            'attributes' => $attributes,
            'children' => [],
            'text' => '',
            'isSelfClosing' => $selfClosing,
        ];

        if ($selfClosing) {
            // A self-closing element never joins the stack, so it is attached
            // here, complete.
            $this->attach($element);

            return;
        }

        // Text read so far belongs to the element that encloses this one; the
        // text that follows belongs to this one.
        $this->stack[] = $element;
        $this->text = '';
    }

    /**
     * Attach a finished element to its parent, or make it the root.
     *
     * @param array{name: string, attributes: array<string, string>, children: list<array>, text: string, isSelfClosing: bool} $element
     */
    private function attach(array $element): void
    {
        if ($this->stack === []) {
            $this->root = $element;

            return;
        }

        $index = count($this->stack) - 1;
        $this->stack[$index]['children'][] = $element;
    }

    /**
     * Split `<name a="1" b='2'>` into its name and its attributes.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseTagBody(string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new \RuntimeException('Empty tag name');
        }

        if (!preg_match('/^([^\s\/]+)/', $body, $m)) {
            throw new \RuntimeException('Empty tag name');
        }
        $name = $m[1];
        $rest = substr($body, strlen($name));

        $attributes = [];
        $pattern = '/([^\s=\/]+)\s*=\s*"([^"]*)"|([^\s=\/]+)\s*=\s*\'([^\']*)\'/';
        if (preg_match_all($pattern, $rest, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $name = $match[1] !== '' ? $match[1] : ($match[3] ?? '');
                $value = $match[2] ?? '';
                if ($value === '' && isset($match[4])) {
                    $value = $match[4];
                }
                $attributes[$name] = $this->decodeEntities($value);
            }
        }

        return [$name, $attributes];
    }

    private function decodeEntities(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
