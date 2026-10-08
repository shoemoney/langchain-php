<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

/**
 * Converts a raw Anthropic SSE event stream into LangChain
 * `ChatModelStreamEvent`s.
 *
 * Port of `utils/stream_events.ts` from `@langchain/anthropic`. Events are
 * plain arrays keyed by `event` (`message-start`, `content-block-delta`, ...),
 * with the same field names as upstream.
 */
final class StreamEvents
{
    private function __construct()
    {
    }

    /**
     * `convertAnthropicStream`: raw Anthropic events in, typed events out.
     *
     * @param iterable<array<string, mixed>> $source
     * @param array{streamUsage?: bool}      $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function convertAnthropicStream(iterable $source, array $options = []): \Generator
    {
        $shouldStreamUsage = $options['streamUsage'] ?? true;

        /** @var array<int, array<string, mixed>> $blockAccumulators */
        $blockAccumulators = [];
        $usageSnapshot = null;
        $responseMetadata = [];
        $stopReason = null;

        foreach ($source as $data) {
            $type = $data['type'] ?? null;

            switch ($type) {
                case 'message_start':
                    $message = $data['message'] ?? [];
                    $usage = $message['usage'] ?? null;
                    if ($usage && $shouldStreamUsage) {
                        $usageSnapshot = self::buildUsageSnapshot($usage);
                    }
                    yield ['event' => 'message-start', 'id' => $message['id'] ?? null]
                        + ($usageSnapshot !== null ? ['usage' => $usageSnapshot] : []);
                    yield [
                        'event' => 'provider',
                        'provider' => 'anthropic',
                        'name' => 'message_start',
                        'payload' => ['model' => $message['model'] ?? null, 'id' => $message['id'] ?? null],
                    ];
                    break;

                case 'message_delta':
                    $stopReason = $data['delta']['stop_reason'] ?? null;
                    $deltaUsage = $data['usage'] ?? null;
                    // Gateway providers may add a numeric `cost` to the final usage object.
                    if (is_array($deltaUsage) && isset($deltaUsage['cost']) && (is_int($deltaUsage['cost']) || is_float($deltaUsage['cost']))) {
                        $responseMetadata = ['usage' => ['cost' => $deltaUsage['cost']]];
                    }
                    if ($shouldStreamUsage && is_array($deltaUsage)) {
                        $out = (int) ($deltaUsage['output_tokens'] ?? 0);
                        if ($usageSnapshot === null) {
                            $usageSnapshot = [
                                'input_tokens' => 0,
                                'output_tokens' => $out,
                                'total_tokens' => $out,
                            ];
                        } else {
                            $usageSnapshot = [
                                ...$usageSnapshot,
                                'output_tokens' => $usageSnapshot['output_tokens'] + $out,
                                'total_tokens' => $usageSnapshot['input_tokens'] + $usageSnapshot['output_tokens'] + $out,
                            ];
                        }
                        yield ['event' => 'usage', 'usage' => $usageSnapshot];
                    }
                    if (!empty($data['delta']['context_management'])) {
                        yield [
                            'event' => 'provider',
                            'provider' => 'anthropic',
                            'name' => 'context_management',
                            'payload' => $data['delta']['context_management'],
                        ];
                    }
                    break;

                case 'message_stop':
                    yield [
                        'event' => 'message-finish',
                        'reason' => self::mapStopReason($stopReason),
                        ...($usageSnapshot !== null ? ['usage' => $usageSnapshot] : []),
                        'metadata' => ['model_provider' => 'anthropic'],
                        'responseMetadata' => $responseMetadata,
                    ];
                    break;

                case 'content_block_start':
                    $index = (int) $data['index'];
                    $mapped = self::mapBlockToContentBlock($data['content_block'] ?? [], $index);
                    $blockAccumulators[$index] = $mapped;
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => $mapped];
                    break;

                case 'content_block_delta':
                    $index = (int) $data['index'];
                    if (!isset($blockAccumulators[$index])) {
                        break;
                    }
                    [$contentDelta, $accumulated] = self::applyAnthropicDelta($blockAccumulators[$index], $data['delta'] ?? []);
                    $blockAccumulators[$index] = $accumulated;
                    yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => $contentDelta];
                    break;

                case 'content_block_stop':
                    $index = (int) $data['index'];
                    if (!isset($blockAccumulators[$index])) {
                        break;
                    }
                    yield [
                        'event' => 'content-block-finish',
                        'index' => $index,
                        'content' => self::finalizeBlock($blockAccumulators[$index]),
                    ];
                    unset($blockAccumulators[$index]);
                    break;

                default:
                    yield [
                        'event' => 'provider',
                        'provider' => 'anthropic',
                        'name' => $type,
                        'payload' => $data,
                    ];
                    break;
            }
        }
    }

    private static function mapStopReason(?string $stopReason): string
    {
        return match ($stopReason) {
            'tool_use' => 'tool_use',
            'max_tokens' => 'length',
            default => 'stop',
        };
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array<string, mixed>
     */
    private static function buildUsageSnapshot(array $usage): array
    {
        $cacheCreation = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $totalInput = (int) ($usage['input_tokens'] ?? 0) + $cacheCreation + $cacheRead;
        $output = (int) ($usage['output_tokens'] ?? 0);

        return [
            'input_tokens' => $totalInput,
            'output_tokens' => $output,
            'total_tokens' => $totalInput + $output,
            'input_token_details' => ['cache_creation' => $cacheCreation, 'cache_read' => $cacheRead],
        ];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function mapBlockToContentBlock(array $block, int $index): array
    {
        return match ($block['type'] ?? null) {
            'text' => ['type' => 'text', 'text' => $block['text'] ?? '', 'index' => $index],
            'thinking' => ['type' => 'reasoning', 'reasoning' => $block['thinking'] ?? '', 'index' => $index],
            'tool_use' => [
                'type' => 'tool_call_chunk',
                'id' => $block['id'] ?? null,
                'name' => $block['name'] ?? null,
                'args' => '',
                'index' => $index,
            ],
            'server_tool_use' => [
                'type' => 'server_tool_call_chunk',
                'id' => $block['id'] ?? null,
                'name' => $block['name'] ?? null,
                'args' => '',
                'index' => $index,
            ],
            default => ['type' => 'non_standard', 'value' => $block, 'index' => $index],
        };
    }

    /**
     * Map a `content_block_delta` to a content-block delta and fold it into the
     * accumulated block.
     *
     * @param array<string, mixed> $accumulated
     * @param array<string, mixed> $delta
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function applyAnthropicDelta(array $accumulated, array $delta): array
    {
        switch ($delta['type'] ?? null) {
            case 'text_delta':
                return [
                    ['type' => 'text-delta', 'text' => $delta['text']],
                    [...$accumulated, 'text' => ($accumulated['text'] ?? '') . $delta['text']],
                ];

            case 'thinking_delta':
                return [
                    ['type' => 'reasoning-delta', 'reasoning' => $delta['thinking']],
                    [...$accumulated, 'reasoning' => ($accumulated['reasoning'] ?? '') . $delta['thinking']],
                ];

            case 'input_json_delta':
                $newArgs = ($accumulated['args'] ?? '') . $delta['partial_json'];

                return [
                    ['type' => 'block-delta', 'fields' => ['type' => $accumulated['type'], 'args' => $newArgs]],
                    [...$accumulated, 'args' => $newArgs],
                ];

            case 'citations_delta':
                $annotations = [...($accumulated['annotations'] ?? []), $delta['citation']];

                return [
                    ['type' => 'block-delta', 'fields' => ['type' => $accumulated['type'], 'annotations' => $annotations]],
                    [...$accumulated, 'annotations' => $annotations],
                ];

            case 'signature_delta':
                return [
                    ['type' => 'block-delta', 'fields' => ['type' => $accumulated['type'], 'signature' => $delta['signature']]],
                    [...$accumulated, 'signature' => $delta['signature']],
                ];

            case 'compaction_delta':
                $value = [...($accumulated['value'] ?? []), 'compaction' => $delta];

                return [
                    ['type' => 'block-delta', 'fields' => ['type' => 'non_standard', 'value' => $value]],
                    [...$accumulated, 'value' => $value],
                ];

            default:
                return [
                    ['type' => 'block-delta', 'fields' => ['type' => $accumulated['type'], ...$delta]],
                    $accumulated,
                ];
        }
    }

    /**
     * @param array<string, mixed> $accumulated
     *
     * @return array<string, mixed>
     */
    private static function finalizeBlock(array $accumulated): array
    {
        $type = $accumulated['type'] ?? null;

        if ($type === 'tool_call_chunk' || $type === 'server_tool_call_chunk') {
            $args = (string) ($accumulated['args'] ?? '');
            $parsed = json_decode($args === '' ? '{}' : $args, true);

            if (json_last_error() !== \JSON_ERROR_NONE) {
                return [
                    'type' => 'invalid_tool_call',
                    'id' => $accumulated['id'] ?? null,
                    'name' => $accumulated['name'] ?? null,
                    'args' => $accumulated['args'] ?? null,
                    'error' => 'Failed to parse tool call arguments as JSON',
                ];
            }

            return [
                'type' => $type === 'tool_call_chunk' ? 'tool_call' : 'server_tool_call',
                'id' => $accumulated['id'] ?? null,
                'name' => $accumulated['name'] ?? null,
                'args' => $parsed,
            ];
        }

        unset($accumulated['index']);

        return $accumulated;
    }
}
