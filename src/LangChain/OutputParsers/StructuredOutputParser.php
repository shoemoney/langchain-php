<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into JSON and check it against a schema.
 *
 * Port of `StructuredOutputParser` from `@langchain_core/output_parsers/structured`.
 *
 * The TypeScript original is constructed with a Zod schema and derives two things
 * from it: the JSON Schema printed into the prompt, and the validation applied to
 * the reply. PHP has neither Zod nor a compile-time notion of an object shape, so
 * this port is constructed with the **JSON Schema** directly and validates with
 * {@see JsonSchemaValidator}; the prompt text is unchanged.
 *
 * The parsing is deliberately forgiving in three specific ways, all of them
 * things models actually do: a ```json fence, a bare ``` fence, a literal
 * newline inside a string value, and any number of ``` sequences *inside* a
 * string value (a markdown-heavy biography must not be mistaken for a fence).
 *
 * @extends BaseOutputParser<mixed>
 */
class StructuredOutputParser extends BaseOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'output_parsers', 'structured'];
    }

    /**
     * @param array<string, mixed> $schema A JSON Schema object schema.
     */
    public function __construct(public readonly array $schema = [])
    {
    }

    /**
     * The PHP counterpart of `fromZodSchema()`: a parser bound to a JSON Schema.
     *
     * @param array<string, mixed> $schema
     */
    public static function fromJsonSchema(array $schema): static
    {
        return new static($schema);
    }

    /**
     * A parser for an object whose properties are all strings.
     *
     * The common "give me a flat object of fields" case, and the one that needs
     * no schema language at all — the field names and their descriptions are
     * the whole specification.
     *
     * @param array<string, string> $schemas Property name => description.
     */
    public static function fromNamesAndDescriptions(array $schemas): static
    {
        $properties = [];
        foreach ($schemas as $name => $description) {
            $properties[(string) $name] = ['type' => 'string', 'description' => $description];
        }

        return new static([
            'type' => 'object',
            'properties' => $properties,
            'required' => array_map(strval(...), array_keys($schemas)),
            'additionalProperties' => false,
            '$schema' => 'http://json-schema.org/draft-07/schema#',
        ]);
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return "You must format your output as a JSON value that adheres to a given \"JSON Schema\" instance.

\"JSON Schema\" is a declarative language that allows you to annotate and validate JSON documents.

For example, the example \"JSON Schema\" instance {{\"properties\": {{\"foo\": {{\"description\": \"a list of test words\", \"type\": \"array\", \"items\": {{\"type\": \"string\"}}}}}}, \"required\": [\"foo\"]}}
would match an object with one required property, \"foo\". The \"type\" property specifies \"foo\" must be an \"array\", and the \"description\" property semantically describes it as \"a list of test words\". The items within \"foo\" must be strings.
Thus, the object {{\"foo\": [\"bar\", \"baz\"]}} is a well-formatted instance of this example \"JSON Schema\". The object {{\"properties\": {{\"foo\": [\"bar\", \"baz\"]}}}} is not well-formatted.

Your output will be parsed and type-checked according to the provided schema instance, so make sure all fields in your output match the schema exactly and there are no trailing commas!

Here is the JSON Schema instance your output must adhere to. Include the enclosing markdown codeblock:
```json
" . $this->encodeSchema($this->schema) . "
```
";
    }

    /**
     * @throws OutputParserException
     */
    public function parse(string $text, ?RunnableConfig $config = null): mixed
    {
        try {
            $json = $this->extractJson($text);
            $escaped = self::escapeNewlines($json);

            $value = json_decode($escaped, true, 512, JSON_THROW_ON_ERROR);
            $errors = JsonSchemaValidator::validate($value, $this->schema);
            if ($errors !== []) {
                throw new \RuntimeException(implode('; ', $errors));
            }

            return $value;
        } catch (\Throwable $e) {
            throw new OutputParserException(
                "Failed to parse. Text: \"{$text}\". Error: {$e->getMessage()}",
                $text
            );
        }
    }

    /**
     * Content blocks of any kind flatten to their concatenated text.
     */
    protected function baseMessageToString(BaseMessage $message): string
    {
        return $message->text();
    }

    /**
     * Pull the JSON payload out of the model's reply.
     *
     * Three cases in order: a fence at the very start, a ` ```json ` fence
     * anywhere, and otherwise the text as-is. JavaScript's `||` treats an empty
     * capture as absent, and so does the empty-string check here.
     */
    protected function extractJson(string $text): string
    {
        $trimmed = trim($text);

        $json = null;
        if (preg_match('/^```(?:json)?\s*([\s\S]*?)```/', $trimmed, $m) === 1 && $m[1] !== '') {
            $json = $m[1];
        }
        if ($json === null && preg_match('/```json\s*([\s\S]*?)```/', $trimmed, $m2) === 1 && $m2[1] !== '') {
            $json = $m2[1];
        }

        return $json ?? $trimmed;
    }

    /**
     * Re-escape newlines that appear *inside* string literals, then drop every
     * remaining newline — a pretty-printed document is one document.
     */
    protected static function escapeNewlines(string $json): string
    {
        $escaped = preg_replace_callback(
            '/"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"/',
            static function (array $m): string {
                return '"' . str_replace("\n", '\\n', $m[1]) . '"';
            },
            $json
        );

        return str_replace("\n", '', (string) $escaped);
    }

    /** @param array<string, mixed> $schema */
    protected function encodeSchema(array $schema): string
    {
        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
