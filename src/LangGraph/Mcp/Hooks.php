<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

use LangChain\Messages\ToolMessage;
use LangGraph\Pregel\Command;

/**
 * `beforeToolCall` / `afterToolCall` hook contracts and the validators for what they return.
 *
 * Port of `langchain-mcp-adapters/src/hooks.ts` (`toolCallModificationSchema`,
 * `toolCallResultModificationSchema`, `toolResultBeforeSchema`, `toolHooksSchema`). Zod is replaced
 * by hand-written parsers that throw {@see ValidationException} with Zod 4's wording
 * (`Invalid input: expected string, received number`), because the awaited hook result is
 * untrusted: a hook is application code and may return anything.
 *
 * A tool hook set is an array:
 *
 *  - `beforeToolCall`: `callable(array{name, args, serverName} $request, mixed $state, RunnableConfig $config)`
 *    returning `null` or `['headers' => array<string,string>, 'args' => array<string,mixed>]`;
 *  - `afterToolCall`: the same request plus `result` (`[content, artifacts]`), returning `null` or
 *    `['result' => string|Command|ToolMessage|[content, artifacts]]`.
 */
final class Hooks
{
    private function __construct()
    {
    }

    /**
     * Port of `toolHooksSchema`: each hook, if present, must be callable.
     *
     * @param array<string, mixed> $hooks
     *
     * @return array{beforeToolCall?: callable, afterToolCall?: callable}
     */
    public static function parseToolHooks(array $hooks): array
    {
        $parsed = [];
        foreach (['beforeToolCall', 'afterToolCall'] as $name) {
            $hook = $hooks[$name] ?? null;
            if ($hook === null) {
                continue;
            }
            if (!is_callable($hook)) {
                throw ValidationException::of("Expected a {$name} callback", [$name]);
            }
            $parsed[$name] = $hook;
        }

        return $parsed;
    }

    /**
     * Port of `toolCallModificationSchema.optional().parse()`.
     *
     * @return array{headers?: array<string, string>, args?: array<string, mixed>}|null
     */
    public static function parseToolCallModification(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . self::describe($value));
        }

        $parsed = [];
        foreach (['headers', 'args'] as $field) {
            if (!array_key_exists($field, $value) || $value[$field] === null) {
                continue;
            }
            $record = $value[$field];
            if (!is_array($record) || (array_is_list($record) && $record !== [])) {
                throw ValidationException::of('Invalid input: expected record, received ' . self::describe($record), [$field]);
            }
            if ($field === 'headers') {
                foreach ($record as $key => $header) {
                    if (!is_string($header)) {
                        throw ValidationException::of('Invalid input: expected string, received ' . self::describe($header), [$field, $key]);
                    }
                }
            }
            $parsed[$field] = $record;
        }

        return $parsed;
    }

    /**
     * Port of `toolCallResultModificationSchema.optional().parse()`.
     *
     * @return array{result: string|Command|ToolMessage|array{0: string|list<array<string, mixed>>, 1: list<array<string, mixed>>}}|null
     */
    public static function parseToolCallResultModification(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . self::describe($value));
        }
        if (!array_key_exists('result', $value) || $value['result'] === null) {
            throw ValidationException::of('Invalid input: expected string, received undefined', ['result']);
        }

        $result = $value['result'];
        if (is_string($result) || $result instanceof ToolMessage || Command::isCommand($result)) {
            return ['result' => $result];
        }
        if (is_array($result) && array_is_list($result) && count($result) === 2) {
            try {
                return ['result' => self::parseToolResultBefore($result)];
            } catch (ValidationException $e) {
                throw new ValidationException(
                    array_map(
                        static fn (array $issue): array => ['path' => ['result', ...$issue['path']], 'message' => $issue['message']],
                        $e->issues,
                    ),
                    $e->getMessage(),
                );
            }
        }

        throw ValidationException::of('Invalid input: expected string, received ' . self::describe($result), ['result']);
    }

    /**
     * Port of `toolResultBeforeSchema`: a `[content, artifacts]` tuple.
     *
     * Content blocks are extensible records (`type` plus optional `id`, any other field kept);
     * an artifact is either a valid MCP embedded resource or any non-`resource` content block.
     *
     * @return array{0: string|list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function parseToolResultBefore(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            throw ValidationException::of('Invalid input: expected tuple of [content, artifacts], received ' . self::describe($value));
        }
        [$content, $artifacts] = $value;

        if (!is_string($content)) {
            if (!is_array($content) || !array_is_list($content)) {
                throw ValidationException::of('Invalid input: expected string, received ' . self::describe($content), [0]);
            }
            foreach ($content as $index => $block) {
                self::assertContentBlock($block, [0, $index]);
            }
        }

        if (!is_array($artifacts) || !array_is_list($artifacts)) {
            throw ValidationException::of('Invalid input: expected array, received ' . self::describe($artifacts), [1]);
        }
        foreach ($artifacts as $index => $artifact) {
            if (is_array($artifact) && ($artifact['type'] ?? null) === 'resource') {
                if (!self::isEmbeddedResource($artifact)) {
                    throw ValidationException::of('Expected a valid MCP embedded resource', [1, $index]);
                }

                continue;
            }
            self::assertContentBlock($artifact, [1, $index]);
        }

        return [$content, $artifacts];
    }

    /** JavaScript's `typeof`-flavoured name for a value, as Zod reports it. */
    public static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => array_is_list($value) && $value !== [] ? 'array' : 'object',
            is_callable($value) => 'function',
            default => 'object',
        };
    }

    /**
     * @param list<int|string> $path
     */
    private static function assertContentBlock(mixed $block, array $path): void
    {
        if (!is_array($block) || (array_is_list($block) && $block !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . self::describe($block), $path);
        }
        if (!is_string($block['type'] ?? null)) {
            throw ValidationException::of('Invalid input: expected string, received ' . self::describe($block['type'] ?? null), [...$path, 'type']);
        }
        if (array_key_exists('id', $block) && !is_string($block['id'])) {
            throw ValidationException::of('Invalid input: expected string, received ' . self::describe($block['id']), [...$path, 'id']);
        }
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function isEmbeddedResource(array $block): bool
    {
        $resource = $block['resource'] ?? null;
        if (!is_array($resource) || !is_string($resource['uri'] ?? null)) {
            return false;
        }
        $hasText = array_key_exists('text', $resource);
        $hasBlob = array_key_exists('blob', $resource);
        if (!($hasText && is_string($resource['text'])) && !($hasBlob && is_string($resource['blob']))) {
            return false;
        }
        if (array_key_exists('mimeType', $resource) && !is_string($resource['mimeType'])) {
            return false;
        }

        $annotations = $block['annotations'] ?? null;
        if ($annotations !== null) {
            if (!is_array($annotations)) {
                return false;
            }
            $priority = $annotations['priority'] ?? null;
            if ($priority !== null && (!(is_int($priority) || is_float($priority)) || $priority < 0 || $priority > 1)) {
                return false;
            }
            $audience = $annotations['audience'] ?? null;
            if ($audience !== null && (!is_array($audience) || array_diff($audience, ['user', 'assistant']) !== [])) {
                return false;
            }
            if (array_key_exists('lastModified', $annotations) && !is_string($annotations['lastModified'])) {
                return false;
            }
        }

        return true;
    }
}
