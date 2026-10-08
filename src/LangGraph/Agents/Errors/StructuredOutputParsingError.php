<?php

declare(strict_types=1);

namespace LangGraph\Agents\Errors;

/**
 * Raised when structured output tool call arguments fail to parse according to the schema.
 *
 * Port of `StructuredOutputParsingError` from `langchain/src/agents/errors.ts`.
 */
class StructuredOutputParsingError extends \Exception
{
    /** @param list<string> $errors */
    public function __construct(public readonly string $toolName, public readonly array $errors)
    {
        $lines = '';
        foreach ($errors as $error) {
            $lines .= "\n  - " . $error;
        }

        parent::__construct("Failed to parse structured output for tool '" . $toolName . "':" . $lines . '.');
    }
}
