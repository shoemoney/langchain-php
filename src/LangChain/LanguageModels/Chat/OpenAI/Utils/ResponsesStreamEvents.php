<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;

/**
 * Converts raw OpenAI Responses stream events into LangChain
 * `ChatModelStreamEvent`s.
 *
 * Port of `utils/responses_stream_events.ts`. Events are plain arrays keyed by
 * `event` (`message-start`, `content-block-delta`, ...) with the same field
 * names as upstream, the shape {@see \LangChain\LanguageModels\Chat\Anthropic\Utils\StreamEvents}
 * already uses.
 *
 * Content blocks are keyed by WHERE they sit in the response, not by arrival
 * order: `text:<output_index>:<content_index>`, `reasoning:<output_index>:<summary_index>`
 * and `tool:<output_index>`. Two output items that both stream a `content_index`
 * of 0 are therefore two blocks, not one.
 */
final class ResponsesStreamEvents
{
    private function __construct()
    {
    }

    /**
     * `convertOpenAIResponsesStream`.
     *
     * @param iterable<array<string, mixed>>            $source
     * @param array{streamUsage?: bool, provider?: string} $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function convertOpenAIResponsesStream(iterable $source, array $options = []): \Generator
    {
        $shouldStreamUsage = $options['streamUsage'] ?? true;
        $provider = $options['provider'] ?? 'openai';

        /** @var array<int, array<string, mixed>> $blockAccumulators */
        $blockAccumulators = [];
        /** @var array<string, int> $blockKeyToIndex */
        $blockKeyToIndex = [];
        $nextBlockIndex = 0;
        $messageStarted = false;
        $messageId = null;
        $usageSnapshot = null;
        $finishReason = null;
        $responseMetadata = null;
        /** @var array<int, true> $finalized */
        $finalized = [];

        $getOrCreate = static function (string $key, array $initial) use (&$blockKeyToIndex, &$blockAccumulators, &$nextBlockIndex): array {
            if (isset($blockKeyToIndex[$key])) {
                return [$blockKeyToIndex[$key], false];
            }
            $index = $nextBlockIndex++;
            $blockKeyToIndex[$key] = $index;
            $blockAccumulators[$index] = $initial;

            return [$index, true];
        };

        $ensureStart = static function () use (&$messageStarted, &$messageId): array {
            if ($messageStarted) {
                return [];
            }
            $messageStarted = true;

            return [['event' => 'message-start', 'id' => $messageId]];
        };

        $finalizeBlock = static function (int $index) use (&$finalized, &$blockAccumulators): array {
            if (isset($finalized[$index]) || !isset($blockAccumulators[$index])) {
                return [];
            }
            $finalized[$index] = true;

            return [[
                'event' => 'content-block-finish',
                'index' => $index,
                'content' => self::finalizeContentBlock($blockAccumulators[$index]),
            ]];
        };

        foreach ($source as $event) {
            $type = $event['type'] ?? null;
            $item = is_array($event['item'] ?? null) ? $event['item'] : [];
            $itemType = $item['type'] ?? null;

            if ($type === 'response.created') {
                $messageId = $event['response']['id'] ?? null;
                yield from $ensureStart();
                yield [
                    'event' => 'provider',
                    'provider' => $provider,
                    'name' => 'response.created',
                    'payload' => ['model' => $event['response']['model'] ?? null, 'id' => $event['response']['id'] ?? null],
                ];
                continue;
            }

            if ($type === 'response.output_text.delta') {
                yield from $ensureStart();
                $key = 'text:' . ($event['output_index'] ?? '') . ':' . ($event['content_index'] ?? '');
                [$index, $isNew] = $getOrCreate($key, ['type' => 'text', 'text' => '']);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => ['type' => 'text', 'text' => '']];
                }
                $delta = (string) ($event['delta'] ?? '');
                $blockAccumulators[$index]['text'] = ($blockAccumulators[$index]['text'] ?? '') . $delta;
                yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => ['type' => 'text-delta', 'text' => $delta]];
                continue;
            }

            if ($type === 'response.reasoning_summary_text.delta') {
                yield from $ensureStart();
                $key = 'reasoning:' . ($event['output_index'] ?? '') . ':' . ($event['summary_index'] ?? '');
                [$index, $isNew] = $getOrCreate($key, ['type' => 'reasoning', 'reasoning' => '']);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => ['type' => 'reasoning', 'reasoning' => '']];
                }
                $delta = (string) ($event['delta'] ?? '');
                $blockAccumulators[$index]['reasoning'] = ($blockAccumulators[$index]['reasoning'] ?? '') . $delta;
                yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => ['type' => 'reasoning-delta', 'reasoning' => $delta]];
                continue;
            }

            if ($type === 'response.output_item.added' && ($itemType === 'function_call' || $itemType === 'custom_tool_call')) {
                yield from $ensureStart();
                $key = 'tool:' . ($event['output_index'] ?? '');
                $isCustom = $itemType === 'custom_tool_call';
                $initialArgs = (string) ($isCustom ? ($item['input'] ?? '') : ($item['arguments'] ?? ''));
                [$index, $isNew] = $getOrCreate($key, [
                    'type' => 'tool_call_chunk',
                    'id' => $item['call_id'] ?? null,
                    'name' => $item['name'] ?? null,
                    'args' => $initialArgs,
                    'index' => $event['output_index'] ?? null,
                    ...($isCustom ? ['isCustomTool' => true] : []),
                ]);
                if ($isNew) {
                    yield [
                        'event' => 'content-block-start',
                        'index' => $index,
                        'content' => [
                            'type' => 'tool_call_chunk',
                            'id' => $item['call_id'] ?? null,
                            'name' => $item['name'] ?? null,
                            'args' => $initialArgs,
                            'index' => $event['output_index'] ?? null,
                        ],
                    ];
                }
                if ($initialArgs !== '') {
                    yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => self::toolDelta($blockAccumulators[$index])];
                }
                continue;
            }

            if ($type === 'response.function_call_arguments.delta' || $type === 'response.custom_tool_call_input.delta') {
                yield from $ensureStart();
                $key = 'tool:' . ($event['output_index'] ?? '');
                [$index, $isNew] = $getOrCreate($key, [
                    'type' => 'tool_call_chunk',
                    'args' => '',
                    'index' => $event['output_index'] ?? null,
                    ...($type === 'response.custom_tool_call_input.delta' ? ['isCustomTool' => true] : []),
                ]);
                if ($isNew) {
                    yield [
                        'event' => 'content-block-start',
                        'index' => $index,
                        'content' => ['type' => 'tool_call_chunk', 'args' => '', 'index' => $event['output_index'] ?? null],
                    ];
                }
                $blockAccumulators[$index]['args'] = ($blockAccumulators[$index]['args'] ?? '') . (string) ($event['delta'] ?? '');
                yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => self::toolDelta($blockAccumulators[$index])];
                continue;
            }

            if ($type === 'response.output_item.done' && ($itemType === 'function_call' || $itemType === 'custom_tool_call')) {
                yield from $ensureStart();
                $key = 'tool:' . ($event['output_index'] ?? '');
                $args = (string) ($itemType === 'function_call' ? ($item['arguments'] ?? '') : ($item['input'] ?? ''));
                [$index, $isNew] = $getOrCreate($key, [
                    'type' => 'tool_call_chunk',
                    'id' => $item['call_id'] ?? null,
                    'name' => $item['name'] ?? null,
                    'args' => $args,
                    'index' => $event['output_index'] ?? null,
                ]);
                if ($isNew) {
                    yield [
                        'event' => 'content-block-start',
                        'index' => $index,
                        'content' => [
                            'type' => 'tool_call_chunk',
                            'id' => $item['call_id'] ?? null,
                            'name' => $item['name'] ?? null,
                            'args' => $args,
                            'index' => $event['output_index'] ?? null,
                        ],
                    ];
                } else {
                    $blockAccumulators[$index]['args'] = $args;
                    $blockAccumulators[$index]['id'] = $item['call_id'] ?? null;
                    $blockAccumulators[$index]['name'] = $item['name'] ?? null;
                }
                yield from $finalizeBlock($index);
                continue;
            }

            if ($type === 'response.completed' || $type === 'response.incomplete') {
                yield from $ensureStart();
                $response = is_array($event['response'] ?? null) ? $event['response'] : [];
                $messageId = $response['id'] ?? null;
                $finishReason = self::mapResponseStatusToFinishReason($response['status'] ?? null, (string) $type);
                $responseMetadata = [
                    'model_provider' => $provider,
                    'id' => $response['id'] ?? null,
                    'model' => $response['model'] ?? null,
                    'status' => $response['status'] ?? null,
                ];
                if ($shouldStreamUsage && !empty($response['usage'])) {
                    $usageSnapshot = ResponsesOutput::convertResponsesUsageToUsageMetadata($response['usage']);
                    yield ['event' => 'usage', 'usage' => $usageSnapshot];
                }
                continue;
            }

            if ($type === 'response.image_generation_call.partial_image') {
                continue;
            }

            yield from $ensureStart();
            yield ['event' => 'provider', 'provider' => $provider, 'name' => $type, 'payload' => $event];
        }

        if (!$messageStarted) {
            yield ['event' => 'message-start'];
        }

        foreach (array_keys($blockAccumulators) as $index) {
            if (!isset($finalized[$index])) {
                yield from $finalizeBlock($index);
            }
        }

        yield [
            'event' => 'message-finish',
            'reason' => $finishReason,
            ...($usageSnapshot !== null ? ['usage' => $usageSnapshot] : []),
            ...($responseMetadata !== null ? ['responseMetadata' => $responseMetadata] : []),
        ];
    }

    /**
     * `finalizeContentBlock`: a tool-call chunk is parsed into a tool call; one
     * whose arguments are not JSON becomes an invalid tool call.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function finalizeContentBlock(array $block): array
    {
        if (($block['type'] ?? null) !== 'tool_call_chunk') {
            return $block;
        }

        $args = (string) ($block['args'] ?? '');
        $parsed = json_decode($args === '' ? '{}' : $args, true);

        if (json_last_error() !== \JSON_ERROR_NONE) {
            return [
                'type' => 'invalid_tool_call',
                'id' => $block['id'] ?? null,
                'name' => $block['name'] ?? null,
                'args' => $block['args'] ?? null,
                'error' => 'Failed to parse tool call arguments as JSON',
            ];
        }

        return [
            'type' => 'tool_call',
            'id' => $block['id'] ?? null,
            'name' => $block['name'] ?? null,
            'args' => $parsed,
        ];
    }

    /**
     * @param array<string, mixed> $acc
     *
     * @return array<string, mixed>
     */
    private static function toolDelta(array $acc): array
    {
        return [
            'type' => 'block-delta',
            'fields' => [
                'type' => 'tool_call_chunk',
                ...(($acc['id'] ?? null) !== null ? ['id' => $acc['id']] : []),
                ...(($acc['name'] ?? null) !== null ? ['name' => $acc['name']] : []),
                'args' => $acc['args'] ?? '',
            ],
        ];
    }

    private static function mapResponseStatusToFinishReason(?string $status, string $eventType): string
    {
        if ($eventType === 'response.incomplete') {
            return 'length';
        }

        return $status === 'incomplete' ? 'length' : 'stop';
    }
}
