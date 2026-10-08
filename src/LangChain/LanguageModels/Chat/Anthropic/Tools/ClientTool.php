<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolException;

use function LangChain\Tools\tool;

/**
 * Builds the client-executed tools (bash, computer, text editor, memory).
 *
 * Upstream builds a `tool()` and then stamps `extras.providerToolDefinition` on it; the model is
 * sent that definition instead of a generated function schema, while the tool's own `schema`
 * still validates the arguments the model sends back.
 */
final class ClientTool
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $definition
     */
    public static function make(
        string $name,
        ?string $description,
        array $schema,
        array $definition,
        ?callable $execute,
    ): StructuredTool {
        $fields = [
            'name' => $name,
            'schema' => $schema,
            'extras' => ['providerToolDefinition' => $definition],
        ];
        if ($description !== null) {
            $fields['description'] = $description;
        }

        return tool(
            $execute ?? static function () use ($name): never {
                throw new ToolException("The {$name} tool was created without an execute callable.");
            },
            $fields,
        );
    }
}
