<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

/**
 * A {@see StructuredOutputParser} whose instructions are a commented schema sketch.
 *
 * Port of `JsonMarkdownStructuredOutputParser` from
 * `@langchain_core/output_parsers/structured`.
 *
 * Same parsing; different prompt. Where the base class prints a JSON Schema
 * document, this one prints the shape as JSON with `//` comments, which is both
 * shorter and clearer to a model. `interpolationDepth` exists because the sketch
 * is full of braces: embedding it in an f-string prompt needs each brace doubled,
 * and this doubles them for you.
 *
 * @extends StructuredOutputParser<mixed>
 */
class JsonMarkdownStructuredOutputParser extends StructuredOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'output_parsers', 'structured'];
    }

    /**
     * @param array{interpolationDepth?: int} $options
     */
    public function getFormatInstructions(array $options = []): string
    {
        $depth = $options['interpolationDepth'] ?? 1;
        if ($depth < 1) {
            throw new \RuntimeException('f string interpolation depth must be at least 1');
        }

        $instruction = str_replace(
            ['{', '}'],
            [str_repeat('{', $depth), str_repeat('}', $depth)],
            $this->schemaToInstruction($this->schema)
        );

        return "Return a markdown code snippet with a JSON object formatted to look like:\n```json\n{$instruction}\n```";
    }

    /**
     * Render one schema node as the JSON sketch a model should imitate.
     *
     * @param array<string, mixed> $schema
     */
    private function schemaToInstruction(array $schema, int $indent = 2): string
    {
        if (isset($schema['type'])) {
            $type = $schema['type'];
            $nullable = false;
            if (is_array($type)) {
                $kept = [];
                foreach ($type as $t) {
                    if ($t === 'null') {
                        $nullable = true;
                        continue;
                    }
                    $kept[] = $t;
                }
                $type = implode(' | ', $kept);
            }

            $description = isset($schema['description']) ? ' // ' . $schema['description'] : '';

            if ($type === 'object' && isset($schema['properties']) && is_array($schema['properties'])) {
                $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];
                $lines = [];
                foreach ($schema['properties'] as $key => $value) {
                    $optional = in_array((string) $key, $required, true) ? '' : ' (optional)';
                    $child = is_array($value) ? $this->schemaToInstruction($value, $indent + 2) : 'string';
                    $lines[] = str_repeat(' ', $indent) . "\"{$key}\": {$child}{$optional}";
                }

                return "{\n" . implode("\n", $lines) . "\n" . str_repeat(' ', $indent - 2) . "}{$description}";
            }

            if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
                return "array[\n" . str_repeat(' ', $indent) . $this->schemaToInstruction($schema['items'], $indent + 2)
                    . "\n" . str_repeat(' ', $indent - 2) . "] {$description}";
            }

            $isNullable = $nullable ? ' (nullable)' : '';

            return "{$type}{$description}{$isNullable}";
        }

        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            return implode("\n" . str_repeat(' ', $indent - 2), array_map(
                fn (mixed $s): string => is_array($s) ? $this->schemaToInstruction($s, $indent) : 'string',
                $schema['anyOf']
            ));
        }

        throw new \RuntimeException('unsupported schema type');
    }
}
