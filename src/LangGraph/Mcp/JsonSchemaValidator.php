<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

use LangChain\Utils\JsonSchemaValidator as CoreJsonSchemaValidator;

/** Compatibility entry point for MCP callers of the shared JSON Schema validator. */
final class JsonSchemaValidator
{
    private function __construct()
    {
    }

    /**
     * @return list<array{path: list<int|string>, message: string}> empty when valid
     */
    public static function validate(mixed $value, mixed $schema): array
    {
        return CoreJsonSchemaValidator::validate($value, $schema);
    }
}
