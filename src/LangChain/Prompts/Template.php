<?php

declare(strict_types=1);

namespace LangChain\Prompts;

/**
 * Template parsing and interpolation for prompt templates.
 *
 * Port of `@langchain_core/prompts/template`.
 *
 * A template format is two things: an *interpolator* (how to turn a template
 * plus values into a string) and a *parser* (how to turn a template into the
 * list of variable names it reads). Prompt construction needs both, and they
 * are allowed to disagree — a parser that cannot see inside a section still
 * reports the variables a naive scan would find, which is more useful than
 * reporting none.
 *
 * Two formats ship here. `f-string` is Python's `str.format` syntax:
 * `{name}` interpolates, `{{` and `}}` are literal braces, and a lone `{` or `}`
 * is an error — the strictness is the point, because a silently dropped brace
 * in a prompt is a silently different prompt. `mustache` is `{{name}}` with
 * sections; see {@see Mustache}.
 */
final class Template
{
    private function __construct()
    {
    }

    public const F_STRING = 'f-string';

    public const MUSTACHE = 'mustache';

    /**
     * A parsed template is a flat list of literal runs and variable references.
     *
     * @return list<array{type: 'literal', text: string}|array{type: 'variable', name: string}>
     */
    public static function parseFString(string $template): array
    {
        // Split into code points, matching the TypeScript original's index space.
        $chars = $template === ''
            ? []
            : (preg_split('//u', $template, -1, PREG_SPLIT_NO_EMPTY) ?: array_values(str_split($template)));

        $len = count($chars);

        // The scan mirrors CPython's `unicode_format.h`: a literal run stops at
        // the next brace of any kind, so `{{` and `{` both terminate it.
        $nextBracket = static function (string $bracket, int $start) use ($chars, $len): int {
            for ($index = $start; $index < $len; $index++) {
                if (str_contains($bracket, $chars[$index])) {
                    return $index;
                }
            }

            return -1;
        };

        $nodes = [];
        $i = 0;

        while ($i < $len) {
            if ($chars[$i] === '{' && $i + 1 < $len && $chars[$i + 1] === '{') {
                $nodes[] = ['type' => 'literal', 'text' => '{'];
                $i += 2;
            } elseif ($chars[$i] === '}' && $i + 1 < $len && $chars[$i + 1] === '}') {
                $nodes[] = ['type' => 'literal', 'text' => '}'];
                $i += 2;
            } elseif ($chars[$i] === '{') {
                $j = $nextBracket('}', $i);
                if ($j < 0) {
                    throw new \RuntimeException("Unclosed '{' in template.");
                }
                $nodes[] = ['type' => 'variable', 'name' => implode('', \array_slice($chars, $i + 1, $j - $i - 1))];
                $i = $j + 1;
            } elseif ($chars[$i] === '}') {
                throw new \RuntimeException("Single '}' in template.");
            } else {
                $next = $nextBracket('{}', $i);
                $text = $next < 0
                    ? implode('', \array_slice($chars, $i))
                    : implode('', \array_slice($chars, $i, $next - $i));
                $nodes[] = ['type' => 'literal', 'text' => $text];
                $i = $next < 0 ? $len : $next;
            }
        }

        return $nodes;
    }

    /**
     * The nodes of a mustache template.
     *
     * @return list<array{type: 'literal', text: string}|array{type: 'variable', name: string}>
     */
    public static function parseMustache(string $template): array
    {
        return Mustache::parse($template);
    }

    /**
     * Interpolate an f-string template.
     *
     * A non-string value is JSON-encoded, so a list of documents renders as the
     * JSON a model can read rather than as "Array".
     *
     * @param array<string, mixed> $values
     */
    public static function interpolateFString(string $template, array $values): string
    {
        $out = '';
        foreach (self::parseFString($template) as $node) {
            if ($node['type'] === 'literal') {
                $out .= $node['text'];

                continue;
            }

            $name = $node['name'];
            if (!array_key_exists($name, $values)) {
                throw new \RuntimeException("(f-string) Missing value for input {$name}");
            }

            $value = $values[$name];
            $out .= is_string($value)
                ? $value
                : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $out;
    }

    /**
     * Interpolate a mustache template.
     *
     * @param array<string, mixed> $values
     */
    public static function interpolateMustache(string $template, array $values): string
    {
        return Mustache::render($template, $values);
    }

    /**
     * The interpolator for each supported format.
     *
     * @return array<string, callable(string, array<string, mixed>): string>
     */
    public static function defaultFormatterMapping(): array
    {
        return [
            self::F_STRING => self::interpolateFString(...),
            self::MUSTACHE => self::interpolateMustache(...),
        ];
    }

    /**
     * The parser for each supported format.
     *
     * @return array<string, callable(string): list<array<string, mixed>>>
     */
    public static function defaultParserMapping(): array
    {
        return [
            self::F_STRING => self::parseFString(...),
            self::MUSTACHE => self::parseMustache(...),
        ];
    }

    /**
     * Interpolate a template, tagging any failure as a prompt input error.
     *
     * @param array<string, mixed> $inputValues
     * @throws PromptInputError
     */
    public static function renderTemplate(string $template, string $templateFormat, array $inputValues): string
    {
        $mapping = self::defaultFormatterMapping();
        if (!isset($mapping[$templateFormat])) {
            throw new \InvalidArgumentException(
                "Invalid template format. Got `{$templateFormat}`; should be one of " . implode(', ', array_keys($mapping))
            );
        }

        try {
            return $mapping[$templateFormat]($template, $inputValues);
        } catch (\Throwable $e) {
            throw new PromptInputError($e->getMessage(), 0, $e);
        }
    }

    /**
     * The variable names a template reads.
     *
     * @return list<array{type: 'literal', text: string}|array{type: 'variable', name: string}>
     */
    public static function parseTemplate(string $template, string $templateFormat): array
    {
        $mapping = self::defaultParserMapping();
        if (!isset($mapping[$templateFormat])) {
            throw new \InvalidArgumentException("Invalid template format. Got `{$templateFormat}`");
        }

        return $mapping[$templateFormat]($template);
    }

    /**
     * Every variable name a template reads, in first-appearance order.
     *
     * @return list<string>
     */
    public static function templateVariables(string $template, string $templateFormat): array
    {
        $names = [];
        foreach (self::parseTemplate($template, $templateFormat) as $node) {
            if ($node['type'] === 'variable') {
                $names[$node['name']] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Reject a template whose variables do not match the declared input set.
     *
     * The check runs the template against dummy values, so it catches typos
     * (`{contxt}` when the field is `context`) at construction time rather than
     * three layers into a chain at run time.
     *
     * @param string|list<array<string, mixed>> $template
     * @param list<string>                      $inputVariables
     * @throws \RuntimeException
     */
    public static function checkValidTemplate(string|array $template, string $templateFormat, array $inputVariables): void
    {
        if (!isset(self::defaultFormatterMapping()[$templateFormat])) {
            $validFormats = implode(', ', array_keys(self::defaultFormatterMapping()));
            throw new \RuntimeException("Invalid template format. Got `{$templateFormat}`; should be one of {$validFormats}");
        }

        // Built as a map rather than by dynamic assignment so a user-supplied
        // variable name cannot reach a prototype.
        $dummyInputs = array_fill_keys($inputVariables, 'foo');

        try {
            if (is_array($template)) {
                foreach ($template as $message) {
                    self::validateTemplateBlock($message, $templateFormat, $dummyInputs);
                }

                return;
            }

            self::renderTemplate($template, $templateFormat, $dummyInputs);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Invalid prompt schema: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, string> $dummyInputs
     */
    private static function validateTemplateBlock(array $message, string $templateFormat, array $dummyInputs): void
    {
        $type = $message['type'] ?? null;

        if ($type === 'text' && is_string($message['text'] ?? null)) {
            self::renderTemplate($message['text'], $templateFormat, $dummyInputs);

            return;
        }

        if ($type === 'image_url') {
            $imageUrl = $message['image_url'] ?? null;
            if (is_string($imageUrl)) {
                self::renderTemplate($imageUrl, $templateFormat, $dummyInputs);

                return;
            }
            if (is_array($imageUrl) && is_string($imageUrl['url'] ?? null)) {
                self::renderTemplate($imageUrl['url'], $templateFormat, $dummyInputs);

                return;
            }
        }

        throw new \RuntimeException(
            'Invalid message template received. ' . json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
