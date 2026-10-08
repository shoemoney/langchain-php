<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's hosted code interpreter tool, for the Responses API.
 *
 * Port of `tools.codeInterpreter` from `@langchain/openai`. `container` is
 * either an existing container id or the options of an `auto` container
 * (default when omitted).
 */
final class CodeInterpreter
{
    private function __construct()
    {
    }

    /**
     * @param array{container?: string|array{memoryLimit?: '1g'|'4g'|'16g'|'64g', fileIds?: list<string>}} $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options = []): array
    {
        return [
            'type' => 'code_interpreter',
            'container' => self::container($options['container'] ?? null),
        ];
    }

    /**
     * @param string|array<string, mixed>|null $container
     *
     * @return string|array<string, mixed>
     */
    private static function container(string|array|null $container): string|array
    {
        if (is_string($container)) {
            return $container;
        }

        return array_filter([
            'type' => 'auto',
            'file_ids' => $container['fileIds'] ?? null,
            'memory_limit' => $container['memoryLimit'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
