<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

/**
 * Converts OpenRouter SSE stream chunks into protocol-style stream events.
 *
 * Port of `utils/stream_events.ts` (`convertOpenRouterStream`) from
 * `@langchain/openrouter`, together with the `convertOpenAICompletionsStream`
 * it delegates to in `@langchain/core/language_models/openai_completions_stream`.
 * This port has no such core module, so the generic converter lives here as a
 * private step rather than being invented as a new shared class.
 *
 * The event vocabulary is `message-start`, `provider`, `usage`,
 * `content-block-start`, `content-block-delta`, `content-block-finish` and
 * `message-finish`. Upstream types these as `ChatModelStreamEvent`; this port
 * has no such type, so events are plain arrays with the same field names.
 *
 * Two quirks are carried over deliberately:
 *
 *  - OpenRouter spells the reasoning text `delta.reasoning`; the shared
 *    converter reads `delta.reasoning_content`, so {@see self::mapChunk()}
 *    copies the one to the other when only `reasoning` is present.
 *  - The shared converter stamps `model_provider: "openai"` on the final
 *    `responseMetadata` whatever the provider; only the `provider` events carry
 *    `openrouter`.
 */
final class StreamEvents
{
    private function __construct()
    {
    }

    /**
     * @param iterable<array<string, mixed>> $source Decoded OpenRouter stream chunks.
     * @param array{streamUsage?: bool}      $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function convertOpenRouterStream(iterable $source, array $options = []): \Generator
    {
        $mapped = (static function () use ($source): \Generator {
            foreach ($source as $chunk) {
                yield self::mapChunk($chunk);
            }
        })();

        yield from self::convertOpenAICompletionsStream($mapped, $options + ['provider' => 'openrouter']);
    }

    /**
     * `mapOpenRouterChunkToOpenAI`.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function mapChunk(array $data): array
    {
        $choice = $data['choices'][0] ?? null;

        if (
            is_array($choice)
            && is_array($choice['delta'] ?? null)
            && is_string($choice['delta']['reasoning'] ?? null)
            && ($choice['delta']['reasoning_content'] ?? null) === null
        ) {
            $choice['delta']['reasoning_content'] = $choice['delta']['reasoning'];
            $data['choices'] = [$choice];
        }

        return $data;
    }

    /**
     * `convertOpenAICompletionsStream`.
     *
     * @param iterable<array<string, mixed>>                         $source
     * @param array{streamUsage?: bool, provider?: string} $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function convertOpenAICompletionsStream(iterable $source, array $options): \Generator
    {
        $shouldStreamUsage = $options['streamUsage'] ?? true;
        $provider = $options['provider'] ?? 'openai';

        /** @var array<int, array<string, mixed>> $accumulators */
        $accumulators = [];
        /** @var array<string, int> $keyToIndex */
        $keyToIndex = [];
        $nextIndex = 0;
        $messageStarted = false;
        $usageSnapshot = null;
        $finishReason = null;
        $responseMetadata = null;
        $emittedProviderMetadata = false;

        // Returns [index, isNew] for a block key, allocating it on first sight.
        $blockFor = static function (string $key, array $initial) use (&$accumulators, &$keyToIndex, &$nextIndex): array {
            if (isset($keyToIndex[$key])) {
                return [$keyToIndex[$key], false];
            }
            $index = $nextIndex++;
            $keyToIndex[$key] = $index;
            $accumulators[$index] = $initial;

            return [$index, true];
        };

        foreach ($source as $data) {
            if (!$messageStarted) {
                $messageStarted = true;
                yield ['event' => 'message-start', 'id' => $data['id'] ?? null];
            }

            $model = $data['model'] ?? null;
            $serviceTier = $data['service_tier'] ?? null;
            if (!$emittedProviderMetadata && ($model || $serviceTier)) {
                $emittedProviderMetadata = true;
                yield [
                    'event' => 'provider',
                    'provider' => $provider,
                    'name' => 'stream_metadata',
                    'payload' => ['model' => $model, 'service_tier' => $serviceTier],
                ];
            }

            if (!empty($data['usage']) && $shouldStreamUsage) {
                $usageSnapshot = self::buildUsageSnapshot($data['usage']);
                yield ['event' => 'usage', 'usage' => $usageSnapshot];
            }

            $groqUsage = $data['x_groq']['usage'] ?? null;
            if (!empty($groqUsage) && $shouldStreamUsage) {
                $usageSnapshot = [
                    'input_tokens' => $groqUsage['prompt_tokens'] ?? 0,
                    'output_tokens' => $groqUsage['completion_tokens'] ?? 0,
                    'total_tokens' => $groqUsage['total_tokens'] ?? 0,
                ];
                yield ['event' => 'usage', 'usage' => $usageSnapshot];
            }

            $choice = $data['choices'][0] ?? null;
            if (!is_array($choice)) {
                continue;
            }

            if (($choice['finish_reason'] ?? null) !== null) {
                $finishReason = self::mapFinishReason((string) $choice['finish_reason']);
                $responseMetadata = [
                    'model_provider' => 'openai',
                    'model_name' => $data['model'] ?? null,
                    'system_fingerprint' => $data['system_fingerprint'] ?? null,
                    'service_tier' => $data['service_tier'] ?? null,
                    'finish_reason' => $choice['finish_reason'],
                ] + (!empty($data['usage']) ? ['usage' => $data['usage']] : []);
            }

            $delta = $choice['delta'] ?? null;
            if (!is_array($delta)) {
                continue;
            }

            $reasoning = $delta['reasoning_content'] ?? $delta['reasoning'] ?? null;
            if (is_string($reasoning) && $reasoning !== '') {
                [$index, $isNew] = $blockFor('reasoning', ['type' => 'reasoning', 'reasoning' => '']);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => ['type' => 'reasoning', 'reasoning' => '']];
                }
                $accumulators[$index]['reasoning'] .= $reasoning;
                yield [
                    'event' => 'content-block-delta',
                    'index' => $index,
                    'delta' => ['type' => 'reasoning-delta', 'reasoning' => $reasoning],
                ];
            }

            $content = $delta['content'] ?? null;
            if (is_string($content) && $content !== '') {
                [$index, $isNew] = $blockFor('text', ['type' => 'text', 'text' => '']);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => ['type' => 'text', 'text' => '']];
                }
                $accumulators[$index]['text'] .= $content;
                yield [
                    'event' => 'content-block-delta',
                    'index' => $index,
                    'delta' => ['type' => 'text-delta', 'text' => $content],
                ];
            }

            if (is_array($delta['tool_calls'] ?? null)) {
                foreach ($delta['tool_calls'] as $rawToolCall) {
                    $toolIndex = (int) ($rawToolCall['index'] ?? 0);
                    $initial = [
                        'type' => 'tool_call_chunk',
                        'id' => $rawToolCall['id'] ?? null,
                        'name' => $rawToolCall['function']['name'] ?? null,
                        'args' => '',
                        'index' => $toolIndex,
                    ];
                    [$index, $isNew] = $blockFor('tool:' . $toolIndex, $initial);
                    if ($isNew) {
                        yield ['event' => 'content-block-start', 'index' => $index, 'content' => $initial];
                    }

                    if (($rawToolCall['id'] ?? null) !== null) {
                        $accumulators[$index]['id'] = $rawToolCall['id'];
                    }
                    if (($rawToolCall['function']['name'] ?? null) !== null) {
                        $accumulators[$index]['name'] = $rawToolCall['function']['name'];
                    }
                    $accumulators[$index]['args'] .= (string) ($rawToolCall['function']['arguments'] ?? '');

                    $acc = $accumulators[$index];
                    $fields = ['type' => 'tool_call_chunk'];
                    if ($acc['id'] !== null) {
                        $fields['id'] = $acc['id'];
                    }
                    if ($acc['name'] !== null) {
                        $fields['name'] = $acc['name'];
                    }
                    $fields['args'] = $acc['args'];
                    yield ['event' => 'content-block-delta', 'index' => $index, 'delta' => ['type' => 'block-delta', 'fields' => $fields]];
                }
            }

            if (is_array($delta['audio'] ?? null) && $delta['audio'] !== []) {
                $audio = $delta['audio'];
                $initial = [
                    'type' => 'audio',
                    'id' => $audio['id'] ?? null,
                    'data' => '',
                    'mimeType' => 'audio/pcm',
                    'transcript' => $audio['transcript'] ?? '',
                ];
                [$index, $isNew] = $blockFor('audio', $initial);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => $initial];
                }
                if (!empty($audio['transcript'])) {
                    $accumulators[$index]['transcript'] = ($accumulators[$index]['transcript'] ?? '') . $audio['transcript'];
                    yield [
                        'event' => 'content-block-delta',
                        'index' => $index,
                        'delta' => ['type' => 'block-delta', 'fields' => ['type' => 'audio', 'transcript' => $accumulators[$index]['transcript']]],
                    ];
                }
                if (!empty($audio['data'])) {
                    $accumulators[$index]['data'] = ($accumulators[$index]['data'] ?? '') . $audio['data'];
                    yield [
                        'event' => 'content-block-delta',
                        'index' => $index,
                        'delta' => ['type' => 'data-delta', 'data' => $audio['data'], 'encoding' => 'base64'],
                    ];
                }
            }

            if (!empty($delta['function_call'])) {
                yield ['event' => 'provider', 'provider' => $provider, 'name' => 'function_call', 'payload' => $delta['function_call']];
            }

            if (!empty($choice['logprobs'])) {
                yield ['event' => 'provider', 'provider' => $provider, 'name' => 'logprobs', 'payload' => $choice['logprobs']];
            }
        }

        foreach ($accumulators as $index => $accumulated) {
            yield ['event' => 'content-block-finish', 'index' => $index, 'content' => self::finalizeContentBlock($accumulated)];
        }

        $finish = ['event' => 'message-finish', 'reason' => $finishReason];
        if ($usageSnapshot !== null) {
            $finish['usage'] = $usageSnapshot;
        }
        if ($responseMetadata !== null) {
            $finish['responseMetadata'] = $responseMetadata;
        }

        yield $finish;
    }

    /**
     * Port of `finalizeContentBlock` from `@langchain/core/language_models/compat`:
     * a streamed tool call chunk becomes a decoded tool call, or an invalid one
     * when its arguments are not JSON.
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

        $args = (string) ($block['args'] ?? '{}');
        $decoded = json_decode($args, true);
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
            'args' => $decoded,
        ];
    }

    private static function mapFinishReason(string $reason): string
    {
        return match ($reason) {
            'length', 'max_tokens' => 'length',
            'tool_calls', 'function_call' => 'tool_use',
            'content_filter' => 'content_filter',
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
        $inputDetails = array_filter([
            'audio' => $usage['prompt_tokens_details']['audio_tokens'] ?? null,
            'cache_read' => $usage['prompt_tokens_details']['cached_tokens'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
        $outputDetails = array_filter([
            'audio' => $usage['completion_tokens_details']['audio_tokens'] ?? null,
            'reasoning' => $usage['completion_tokens_details']['reasoning_tokens'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        return [
            'input_tokens' => $usage['prompt_tokens'] ?? 0,
            'output_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
        ]
            + ($inputDetails !== [] ? ['input_token_details' => $inputDetails] : [])
            + ($outputDetails !== [] ? ['output_token_details' => $outputDetails] : []);
    }
}
