<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolRuntime;

use function LangChain\Tools\tool;

/**
 * OpenAI's computer use tool, for the Responses API.
 *
 * Port of `tools.computerUse` from `@langchain/openai`. The `execute` callable
 * receives the action array (`type` of screenshot, click, double_click, drag,
 * keypress, move, scroll, type or wait) and the {@see ToolRuntime}, and returns a
 * string or a {@see ToolMessage}. The result is stamped with the id of the
 * `computer_use` tool call on the last AI message in `runtime->state['messages']`
 * and tagged `additional_kwargs.type = "computer_call_output"`.
 */
final class ComputerUse
{
    public const TOOL_NAME = 'computer_use';

    private function __construct()
    {
    }

    /**
     * The `{action: ...}` envelope the model's computer call is parsed into.
     */
    public static function schema(): Schema
    {
        $xy = ['x' => ['type' => 'number'], 'y' => ['type' => 'number']];
        $button = ['type' => 'string', 'enum' => ['left', 'right', 'wheel', 'back', 'forward'], 'default' => 'left'];
        $action = static fn (string $type, array $properties, array $required): array => [
            'type' => 'object',
            'properties' => ['type' => ['const' => $type]] + $properties,
            'required' => ['type', ...$required],
        ];

        return Schema::object([
            'action' => ['anyOf' => [
                $action('screenshot', [], []),
                $action('click', $xy + ['button' => $button], ['x', 'y']),
                $action('double_click', $xy + ['button' => $button], ['x', 'y']),
                $action('drag', ['path' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'properties' => $xy, 'required' => ['x', 'y'],
                ]]], ['path']),
                $action('keypress', ['keys' => ['type' => 'array', 'items' => ['type' => 'string']]], ['keys']),
                $action('move', $xy, ['x', 'y']),
                $action('scroll', $xy + ['scroll_x' => ['type' => 'number'], 'scroll_y' => ['type' => 'number']], ['x', 'y', 'scroll_x', 'scroll_y']),
                $action('type', ['text' => ['type' => 'string']], ['text']),
                $action('wait', ['duration' => ['type' => 'number']], []),
            ]],
        ], ['action']);
    }

    /**
     * @param array{
     *     displayWidth: int,
     *     displayHeight: int,
     *     environment: 'browser'|'mac'|'windows'|'linux'|'ubuntu',
     *     execute: callable(array<string, mixed>, ToolRuntime): (string|ToolMessage)
     * } $options
     */
    public static function create(array $options): StructuredTool
    {
        $execute = $options['execute'];

        return tool(
            static function (array $input, ToolRuntime $runtime) use ($execute): ToolMessage {
                $callId = self::computerCallId($runtime);
                if ($callId === null) {
                    throw new \RuntimeException('Computer use call id not found');
                }

                $result = $execute($input['action'], $runtime);

                if (is_string($result)) {
                    return new ToolMessage([
                        'content' => $result,
                        'tool_call_id' => $callId,
                        'additional_kwargs' => ['type' => 'computer_call_output'],
                    ]);
                }

                $stamped = clone $result;
                $stamped->toolCallId = $callId;
                $stamped->kwargs['tool_call_id'] = $callId;
                $stamped->additional_kwargs = ['type' => 'computer_call_output', ...$result->additional_kwargs];

                return $stamped;
            },
            [
                'name' => self::TOOL_NAME,
                'description' => 'Control a computer interface by executing mouse clicks, keyboard input, scrolling, and other actions.',
                'schema' => self::schema(),
                'extras' => ['providerToolDefinition' => [
                    'type' => 'computer_use_preview',
                    'display_width' => $options['displayWidth'],
                    'display_height' => $options['displayHeight'],
                    'environment' => $options['environment'],
                ]],
            ],
        );
    }

    private static function computerCallId(ToolRuntime $runtime): ?string
    {
        $messages = $runtime->state['messages'] ?? [];
        $last = is_array($messages) && $messages !== [] ? $messages[array_key_last($messages)] : null;
        $toolCalls = is_object($last) && property_exists($last, 'toolCalls') ? $last->toolCalls : [];

        foreach ($toolCalls as $toolCall) {
            if (($toolCall['name'] ?? null) === self::TOOL_NAME) {
                $id = $toolCall['id'] ?? null;

                return is_string($id) && $id !== '' ? $id : null;
            }
        }

        return null;
    }
}
