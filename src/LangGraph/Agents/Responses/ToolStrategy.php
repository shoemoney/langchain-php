<?php

declare(strict_types=1);

namespace LangGraph\Agents\Responses;

use LangChain\OutputParsers\JsonSchemaValidator;
use LangChain\Utils\JsonSchema;
use LangGraph\Agents\Errors\StructuredOutputParsingError;

/**
 * Information for tracking structured output tool metadata.
 *
 * Port of `ToolStrategy` from `langchain/src/agents/responses.ts`. It holds everything needed to handle a structured
 * response generated via a tool call: the original JSON Schema, the function tool offered to the model, and the options
 * (`toolMessageContent`, `handleError`) that shape the conversation around it.
 *
 * Zod schemas do not exist in PHP, so the schema is a JSON Schema array, a {@see \LangChain\Tools\Schema}, or a Standard
 * JSON Schema (`['~standard' => ['jsonSchema' => ['input' => fn]]]`).
 */
final class ToolStrategy
{
    /** The global counter used to generate unique tool names. */
    private static int $bindingIdentifier = 0;

    /**
     * @param array<string, mixed>                                        $schema  the original JSON Schema provided for structured output
     * @param array{type: 'function', function: array<string, mixed>}     $tool    the tool used to parse the tool call arguments
     * @param array{toolMessageContent?: string, handleError?: mixed}|null $options the options to use for the tool output
     */
    private function __construct(
        public readonly array $schema,
        public readonly array $tool,
        public readonly ?array $options = null,
    ) {
    }

    public function name(): string
    {
        return (string) $this->tool['function']['name'];
    }

    /**
     * @param mixed                                                        $schema a JSON Schema array, a Schema, a Standard JSON Schema, or a function definition
     * @param array{toolMessageContent?: string, handleError?: mixed}|null $outputOptions
     */
    public static function fromSchema(mixed $schema, ?array $outputOptions = null): self
    {
        // It is required for tools to have a name so the tool call can be mapped to the right tool when parsing.
        $getFunctionName = static fn (mixed $name = null): string => \is_string($name) ? $name : 'extract-' . (++self::$bindingIdentifier);

        if (ResponseFormats::isSerializableSchema($schema) || $schema instanceof \LangChain\Tools\Schema) {
            $asJsonSchema = JsonSchema::toJsonSchema($schema);
            $tool = [
                'type' => 'function',
                'function' => [
                    'name' => $getFunctionName($asJsonSchema['title'] ?? null),
                    'strict' => false,
                    'description' => $asJsonSchema['description'] ?? "Tool for extracting structured output from the model's response.",
                    'parameters' => $asJsonSchema,
                ],
            ];

            return new self($asJsonSchema, $tool, $outputOptions);
        }

        if (!\is_array($schema)) {
            throw new \InvalidArgumentException('ToolStrategy::fromSchema expects a JSON Schema array, a Schema or a Standard JSON Schema.');
        }

        if (\is_string($schema['name'] ?? null) && \is_array($schema['parameters'] ?? null)) {
            $functionDefinition = $schema;
        } else {
            $functionDefinition = [
                'name' => $getFunctionName($schema['title'] ?? null),
                'description' => \is_string($schema['description'] ?? null) ? $schema['description'] : '',
                'parameters' => ($schema['schema'] ?? null) ?: $schema,
            ];
        }

        $tool = ['type' => 'function', 'function' => $functionDefinition];

        return new self(JsonSchema::toJsonSchema($schema), $tool, $outputOptions);
    }

    /**
     * Parse tool arguments according to the schema.
     *
     * @param array<string, mixed> $toolArgs the arguments from the tool call
     * @return array<string, mixed> the parsed response
     *
     * @throws StructuredOutputParsingError if the response is not valid
     */
    public function parse(array $toolArgs): array
    {
        // PHP decodes `{}` to `[]`, which the validator reads as an array: judge empty arguments by `required` alone.
        $errors = $toolArgs === []
            ? array_map(static fn (mixed $key): string => "$: missing required property '" . $key . "'", (array) ($this->schema['required'] ?? []))
            : JsonSchemaValidator::validate($toolArgs, $this->schema);
        if ($errors !== []) {
            throw new StructuredOutputParsingError($this->name(), $errors);
        }

        return $toolArgs;
    }
}
