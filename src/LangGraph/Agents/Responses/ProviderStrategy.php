<?php

declare(strict_types=1);

namespace LangGraph\Agents\Responses;

use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\JsonSchemaValidator;
use LangChain\Utils\JsonSchema;

/**
 * Structured output through the provider's native JSON Schema support.
 *
 * Port of `ProviderStrategy` from `langchain/src/agents/responses.ts`.
 */
final class ProviderStrategy
{
    /**
     * Default value for strict mode in providerStrategy: it makes the model's output exactly match the schema.
     *
     * @see https://platform.openai.com/docs/guides/structured-outputs
     */
    private const DEFAULT_STRICT = true;

    /** The schema to use for the provider strategy. */
    public readonly array $schema;

    /** Whether to use strict mode for the provider strategy. */
    public readonly bool $strict;

    /**
     * Either a JSON Schema and a strict flag, or an options array `['schema' => ..., 'strict' => ?bool]`.
     *
     * @param array<string, mixed> $schemaOrOptions
     */
    private function __construct(array $schemaOrOptions, ?bool $strict = null)
    {
        if (isset($schemaOrOptions['schema']) && \is_array($schemaOrOptions['schema']) && !\array_key_exists('type', $schemaOrOptions)) {
            $this->schema = $schemaOrOptions['schema'];
            $this->strict = $schemaOrOptions['strict'] ?? self::DEFAULT_STRICT;
        } else {
            $this->schema = $schemaOrOptions;
            $this->strict = $strict ?? self::DEFAULT_STRICT;
        }
    }

    /**
     * @param mixed $schema a JSON Schema array, a Schema, or a Standard JSON Schema
     */
    public static function fromSchema(mixed $schema, ?bool $strict = null): self
    {
        return new self(JsonSchema::toJsonSchema($schema), $strict);
    }

    /**
     * Parse an AI message according to the schema. If the response is not valid, return null.
     *
     * @return array<string, mixed>|null the parsed response
     */
    public function parse(AIMessage $response): ?array
    {
        // Extract the text content: string content, or (for thinking models) the first non-thought text block.
        $textContent = null;
        $content = $response->content;

        if (\is_string($content)) {
            $textContent = $content;
        } elseif (\is_array($content)) {
            foreach ($content as $block) {
                if (\is_array($block)
                    && ($block['type'] ?? null) === 'text'
                    // Skip reasoning/thought summaries (Gemini represents these as `{ type: "text", thought: true }`).
                    && ($block['thought'] ?? null) !== true
                    && \is_string($block['text'] ?? null)
                ) {
                    $textContent = $block['text'];
                    break;
                }
            }
        }

        if ($textContent === null || $textContent === '') {
            return null;
        }

        try {
            $decoded = json_decode($textContent, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || JsonSchemaValidator::validate($decoded, $this->schema) !== []) {
            return null;
        }

        return $decoded;
    }
}
