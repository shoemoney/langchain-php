<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;

/**
 * Build a tool from a closure and a description.
 *
 * Port of the `tool()` factory from `@langchain/core/tools`.
 *
 * ## The one decision this factory makes
 *
 * Which *class* of tool to build:
 *
 *  - a string schema (or none) → {@see DynamicTool}, whose body receives a
 *    bare string;
 *  - anything else → {@see DynamicStructuredTool}, whose body receives the
 *    parsed argument array.
 *
 * The distinction is not cosmetic. A body written `fn(string $s)` handed
 * `{input: "x"}` gets an array where it expected a string, and the failure
 * surfaces inside the tool rather than at the boundary. Deriving the class from
 * the schema means the declared type of the closure and the shape it actually
 * receives cannot drift apart.
 *
 * The default description is `"{$name} tool"`. That is nearly useless as
 * prompt text, but it is a placeholder: a tool with an empty description
 * reliably gets ignored by models, and an obviously-provisional one is easier to
 * notice than a blank.
 *
 * @param callable(mixed, mixed, RunnableConfig|null): mixed $func Receives the
 *        parsed arguments, the tool's run manager, and the call config.
 * @param array{
 *     name: string,
 *     description?: string,
 *     schema?: Schema|array<string, mixed>|null,
 *     responseFormat?: string,
 *     returnDirect?: bool,
 *     verboseParsingErrors?: bool,
 *     metadata?: array<string, mixed>,
 *     extras?: array<string, mixed>,
 *     defaultConfig?: array<string, mixed>|null,
 *     tags?: list<string>,
 *     callbacks?: list<object>,
 *     verbose?: bool
 * } $fields
 */
function tool(callable $func, array $fields): StructuredTool
{
    if (!isset($fields['name']) || !is_string($fields['name']) || $fields['name'] === '') {
        throw new \InvalidArgumentException('A tool requires a non-empty name.');
    }

    $schema = Schema::from($fields['schema'] ?? null);
    $description = $fields['description']
        ?? $schema->description()
        ?? $fields['name'] . ' tool';

    $base = [
        'name' => $fields['name'],
        'description' => $description,
        'schema' => $schema,
        'responseFormat' => $fields['responseFormat'] ?? 'content',
        'returnDirect' => $fields['returnDirect'] ?? false,
        'verboseParsingErrors' => $fields['verboseParsingErrors'] ?? false,
        'metadata' => $fields['metadata'] ?? [],
        'extras' => $fields['extras'] ?? [],
        'defaultConfig' => $fields['defaultConfig'] ?? null,
        'tags' => $fields['tags'] ?? [],
        'callbacks' => $fields['callbacks'] ?? [],
        'verbose' => $fields['verbose'] ?? false,
    ];

    if ($schema->validatesOnlyStrings()) {
        // Wrap the closure so a string schema's body sees a bare string. The
        // `{input: …}` envelope the model sends is unwrapped here, which is
        // the entire reason the two tool classes exist.
        return new DynamicTool($base, static function (mixed $input, mixed $runManager, ?RunnableConfig $config) use ($func): mixed {
            if (is_array($input) && array_key_exists('input', $input)) {
                $input = $input['input'];
            }

            return $func($input, $runManager, $config);
        });
    }

    return new DynamicStructuredTool($base, $func);
}
