<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Utils\Js;

/**
 * A self-contained Mustache renderer.
 *
 * The TypeScript original delegates to the `mustache` npm package. This is the
 * subset that prompt templating uses and that the upstream tests exercise:
 * variables, unescaped variables, dotted names, sections, inverted sections,
 * and the context stack a section introduces.
 *
 * Two behaviours differ from a general-purpose Mustache implementation, both of
 * them because this renderer exists to *build prompts* rather than to serve
 * HTML:
 *
 *  - **Nothing is HTML-escaped.** The upstream call passes
 *    `escape: (text) => text` so a prompt containing `1 < 2 & 3 > 2` survives
 *    intact.
 *  - **Partials are rejected.** A partial reads a file from disk; a prompt
 *    template must be a value, not a capability, so `{{>name}}` throws rather
 *    than silently rendering nothing.
 */
final class Mustache
{
    private function __construct()
    {
    }

    /**
     * Render a template against a value map.
     *
     * @param array<string, mixed> $values
     */
    public static function render(string $template, array $values): string
    {
        $i = 0;
        $nodes = self::parseNodes($template, $i, null);

        return self::renderNodes($nodes, [$values]);
    }

    /**
     * The full parsed template: literal runs and variable references.
     *
     * This is the shape {@see Template::parseTemplate()} returns. Two details
     * come straight from the upstream parser and matter to callers: a dotted
     * name collapses to its root segment (`{{obj.bar}}` reads `obj`), and a
     * section contributes its own name *and* everything inside it, since the
     * body may read from the section's context.
     *
     * @return list<array{type: 'literal', text: string}|array{type: 'variable', name: string}>
     */
    public static function parse(string $template): array
    {
        $i = 0;
        $nodes = self::parseNodes($template, $i, null);

        $out = [];
        self::flatten($nodes, $out);

        return $out;
    }

    /**
     * Every variable a template reads, flattened and de-duplicated.
     *
     * @return list<string>
     */
    public static function variables(string $template): array
    {
        $names = [];
        foreach (self::parse($template) as $node) {
            if ($node['type'] === 'variable') {
                $names[$node['name']] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $out
     */
    private static function flatten(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if ($node['type'] === 'literal') {
                $out[] = ['type' => 'literal', 'text' => (string) $node['text']];

                continue;
            }

            $name = (string) $node['name'];
            // Only a plain `{{name}}` collapses at the dot; `{{&name}}` and a
            // section keep their full name, as the upstream parser does.
            if ($node['type'] === 'name' && ($node['sigil'] ?? '') === '' && str_contains($name, '.')) {
                $name = explode('.', $name)[0];
            }
            $out[] = ['type' => 'variable', 'name' => $name];

            if (isset($node['children']) && is_array($node['children'])) {
                self::flatten($node['children'], $out);
            }
        }
    }

    /**
     * Parse tokens up to `$len` or until the named closing tag.
     *
     * @return list<array<string, mixed>>
     */
    private static function parseNodes(string $template, int &$i, ?string $stopAt): array
    {
        $nodes = [];
        $len = strlen($template);

        while ($i < $len) {
            $start = strpos($template, '{{', $i);
            if ($start === false) {
                $nodes[] = ['type' => 'literal', 'text' => substr($template, $i)];
                $i = $len;

                break;
            }

            if ($start > $i) {
                $nodes[] = ['type' => 'literal', 'text' => substr($template, $i, $start - $i)];
            }

            // A triple mustache is an unescaped variable, and must be recognised
            // before the double-brace terminator is looked for.
            if (substr($template, $start, 3) === '{{{') {
                $end = strpos($template, '}}}', $start + 3);
                if ($end === false) {
                    $nodes[] = ['type' => 'literal', 'text' => substr($template, $start)];
                    $i = $len;

                    break;
                }
                $nodes[] = [
                    'type' => 'name',
                    'name' => trim(substr($template, $start + 3, $end - $start - 3)),
                    'sigil' => '&',
                ];
                $i = $end + 3;

                continue;
            }

            $end = strpos($template, '}}', $start + 2);
            if ($end === false) {
                $nodes[] = ['type' => 'literal', 'text' => substr($template, $start)];
                $i = $len;

                break;
            }

            $inner = substr($template, $start + 2, $end - $start - 2);
            $i = $end + 2;

            $sigil = '';
            if ($inner !== '' && str_contains('#^/&>', $inner[0])) {
                $sigil = $inner[0];
                $inner = substr($inner, 1);
            }
            $name = trim($inner);

            if ($sigil === '/') {
                if ($stopAt !== null && $name === $stopAt) {
                    return $nodes;
                }
                // An unmatched close tag is literal text, as in Mustache.
                $nodes[] = ['type' => 'literal', 'text' => '{{/' . $name . '}}'];

                continue;
            }

            if ($sigil === '#' || $sigil === '^') {
                $nodes[] = [
                    'type' => $sigil,
                    'name' => $name,
                    'children' => self::parseNodes($template, $i, $name),
                ];

                continue;
            }

            if ($sigil === '>') {
                throw new \RuntimeException(
                    "Mustache partials are not supported by the PHP port: {{>{$name}}}"
                );
            }

            $nodes[] = ['type' => 'name', 'name' => $name, 'sigil' => $sigil === '&' ? '&' : ''];
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<mixed>                $stack The context stack, innermost first.
     */
    private static function renderNodes(array $nodes, array $stack): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type']) {
                'literal' => (string) $node['text'],
                'name' => self::stringify(self::lookup((string) $node['name'], $stack)),
                '#', '^' => self::renderSection($node, $stack),
                default => '',
            };
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<mixed>          $stack
     */
    private static function renderSection(array $node, array $stack): string
    {
        $value = self::lookup((string) $node['name'], $stack);
        $isList = is_array($value) && Js::isList($value);
        /** @var list<array<string, mixed>> $children */
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];

        if ($node['type'] === '^') {
            // Inverted: render when the value is absent, empty, or falsey —
            // but never for a non-empty list or a non-zero number.
            $skip = ($isList && $value !== [])
                || ((is_int($value) || is_float($value)) && $value !== 0 && $value !== 0.0);

            return $skip ? '' : self::renderNodes($children, $stack);
        }

        if ($value === null) {
            return '';
        }
        if ($isList) {
            $out = '';
            foreach ($value as $item) {
                $out .= self::renderNodes($children, [$item, ...$stack]);
            }

            return $out;
        }
        if (is_array($value)) {
            return self::renderNodes($children, [$value, ...$stack]);
        }
        if (is_string($value) || $value === true) {
            return self::renderNodes($children, $stack);
        }

        // false, 0, and other numbers render nothing.
        return '';
    }

    /**
     * Walk the context stack looking for a (possibly dotted) name.
     *
     * @param list<mixed> $stack
     */
    private static function lookup(string $name, array $stack): mixed
    {
        if ($name === '.') {
            return $stack[0] ?? null;
        }

        $parts = explode('.', $name);
        foreach ($stack as $context) {
            if (!is_array($context)) {
                continue;
            }

            $value = $context;
            $found = true;
            foreach ($parts as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];

                    continue;
                }
                $found = false;

                break;
            }

            if ($found) {
                return $value;
            }
        }

        return null;
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => '',
            default => (string) $value,
        };
    }
}
