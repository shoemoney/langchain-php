<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

/**
 * Converts Ollama chat stream chunks into protocol-style stream events.
 *
 * Port of `utils/stream_events.ts` (`convertOllamaStream`).
 *
 * The event vocabulary is `message-start`, `usage`, `content-block-start`,
 * `content-block-delta`, `content-block-finish` and `message-finish`. Upstream
 * types these as `ChatModelStreamEvent`; this port has no such type, so events
 * are plain arrays with the same field names.
 */
final class OllamaStreamEvents
{
    private function __construct()
    {
    }

    /**
     * @param iterable<array<string, mixed>>    $source  Decoded Ollama stream chunks.
     * @param array{streamUsage?: bool, think?: bool} $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function convert(iterable $source, array $options = []): \Generator
    {
        $shouldStreamUsage = $options['streamUsage'] ?? true;
        $preferThinking = $options['think'] ?? false;

        /** @var array<int, array<string, mixed>> $accumulators */
        $accumulators = [];
        /** @var array<string, int> $keyToIndex */
        $keyToIndex = [];
        $nextIndex = 0;
        $messageStarted = false;
        $usageSnapshot = null;
        $finishReason = null;

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

        foreach ($source as $chunk) {
            if (!$messageStarted) {
                $messageStarted = true;
                yield ['event' => 'message-start'];
            }

            if ($shouldStreamUsage) {
                $input = (int) ($chunk['prompt_eval_count'] ?? 0);
                $output = (int) ($chunk['eval_count'] ?? 0);
                if ($input > 0 || $output > 0) {
                    $usageSnapshot = [
                        'input_tokens' => $input,
                        'output_tokens' => $output,
                        'total_tokens' => $input + $output,
                    ];
                    yield ['event' => 'usage', 'usage' => $usageSnapshot];
                }
            }

            $doneReason = $chunk['done_reason'] ?? null;
            if (is_string($doneReason) && $doneReason !== '') {
                $finishReason = self::mapDoneReason($doneReason);
            }

            $message = (array) ($chunk['message'] ?? []);

            $thinking = $message['thinking'] ?? null;
            if ($preferThinking && is_string($thinking) && $thinking !== '') {
                [$index, $isNew] = $blockFor('reasoning', ['type' => 'reasoning', 'reasoning' => '']);
                if ($isNew) {
                    yield ['event' => 'content-block-start', 'index' => $index, 'content' => ['type' => 'reasoning', 'reasoning' => '']];
                }
                $accumulators[$index]['reasoning'] .= $thinking;
                yield [
                    'event' => 'content-block-delta',
                    'index' => $index,
                    'delta' => ['type' => 'reasoning-delta', 'reasoning' => $thinking],
                ];
            }

            $content = $message['content'] ?? null;
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

            $toolCalls = $message['tool_calls'] ?? null;
            if (is_array($toolCalls)) {
                foreach (array_values($toolCalls) as $i => $call) {
                    $name = $call['function']['name'] ?? null;
                    $arguments = $call['function']['arguments'] ?? [];
                    $args = is_string($arguments)
                        ? $arguments
                        : json_encode($arguments === [] ? new \stdClass() : $arguments, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

                    $initial = ['type' => 'tool_call_chunk', 'name' => $name, 'args' => '', 'index' => $i];
                    [$index, $isNew] = $blockFor('tool:' . $i, $initial);
                    if ($isNew) {
                        yield ['event' => 'content-block-start', 'index' => $index, 'content' => $initial];
                    }
                    $accumulators[$index]['name'] = $name;
                    $accumulators[$index]['args'] = $args;
                    yield [
                        'event' => 'content-block-delta',
                        'index' => $index,
                        'delta' => [
                            'type' => 'block-delta',
                            'fields' => ['type' => 'tool_call_chunk', 'name' => $name, 'args' => $args],
                        ],
                    ];
                }
            }
        }

        foreach ($accumulators as $index => $accumulated) {
            yield ['event' => 'content-block-finish', 'index' => $index, 'content' => self::finalizeContentBlock($accumulated)];
        }

        $finish = ['event' => 'message-finish', 'reason' => $finishReason];
        if ($usageSnapshot !== null) {
            $finish['usage'] = $usageSnapshot;
        }
        $finish['responseMetadata'] = ['model_provider' => 'ollama'];

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

        $decoded = json_decode((string) ($block['args'] ?? '') === '' ? '{}' : (string) $block['args'], true);
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

    private static function mapDoneReason(string $reason): string
    {
        return match ($reason) {
            'length' => 'length',
            default => 'stop',
        };
    }
}
