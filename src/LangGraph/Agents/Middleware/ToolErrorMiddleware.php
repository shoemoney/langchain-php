<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;
use LangGraph\Errors\Guard;

/**
 * Turns tool errors into error ToolMessages whose content a handler chooses.
 *
 * Port of `toolErrorMiddleware` from `langchain/src/agents/middleware/toolError.ts`.
 *
 * `onError` is `fn(\Throwable $error, array $request): string|array|null` (a `ToolErrorHandler`): the string
 * or content blocks it returns become the content of an error ToolMessage, and `null` (upstream's
 * `undefined`) re-throws the original error. `tools` (names or tool instances) restricts the middleware to
 * those tools. Control-flow errors (interrupts and other bubble-ups) never reach the handler.
 *
 * Place it BEFORE a retry middleware in the list to handle the original error once retries are exhausted.
 */
final class ToolErrorMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array{onError: callable, tools?: list<mixed>|null} $config
     * @return array<string, mixed> the middleware
     */
    public static function create(array $config): array
    {
        $onError = $config['onError'];
        $toolFilter = isset($config['tools'])
            ? array_map(static fn (mixed $tool): string => \is_string($tool) ? $tool : self::nameOf($tool), $config['tools'])
            : null;

        return Middleware::create([
            'name' => 'toolErrorMiddleware',
            'wrapToolCall' => static function (array $request, callable $handler) use ($onError, $toolFilter): mixed {
                $toolName = isset($request['tool']) ? self::nameOf($request['tool']) : ($request['toolCall']['name'] ?? '');

                if ($toolFilter !== null && !\in_array($toolName, $toolFilter, true)) {
                    return $handler($request);
                }

                try {
                    return $handler($request);
                } catch (\Throwable $error) {
                    if (Guard::isGraphBubbleUp($error)) {
                        throw $error;
                    }

                    $content = $onError($error, $request);
                    if ($content === null) {
                        throw $error;
                    }

                    return new ToolMessage([
                        'content' => $content,
                        'tool_call_id' => (string) ($request['toolCall']['id'] ?? ''),
                        ...(\is_string($toolName) ? ['name' => $toolName] : []),
                        'additional_kwargs' => ['status' => 'error'],
                    ]);
                }
            },
        ]);
    }

    private static function nameOf(mixed $tool): string
    {
        if (\is_object($tool) && property_exists($tool, 'name') && \is_string($tool->name)) {
            return $tool->name;
        }
        if (\is_object($tool) && method_exists($tool, 'getName')) {
            return (string) $tool->getName();
        }

        return \is_array($tool) ? (string) ($tool['name'] ?? '') : '';
    }
}
