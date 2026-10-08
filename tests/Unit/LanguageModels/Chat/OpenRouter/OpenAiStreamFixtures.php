<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

/**
 * Port of `openai_stream_fixtures.ts` from `@langchain/core/utils/testing`
 * (`openAITextOnlyChunks` and friends), plus a folder that stands in for
 * upstream's `ChatModelStream` promise-backed properties (`text`, `reasoning`,
 * `toolCalls`, `usage`) and the `toHaveStream*` matchers built on them.
 */
final class OpenAiStreamFixtures
{
    /** @return list<array<string, mixed>> */
    public static function textOnlyChunks(string $model = 'test-model'): array
    {
        return [
            ['id' => 'chatcmpl-text', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-text', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-text', 'model' => $model, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function textOnlyChunksWithUsage(string $model = 'test-model'): array
    {
        $chunks = self::textOnlyChunks($model);
        $chunks[count($chunks) - 1]['usage'] = ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12];

        return $chunks;
    }

    /** @return list<array<string, mixed>> */
    public static function reasoningTextChunks(string $model = 'test-model'): array
    {
        return [
            ['id' => 'chatcmpl-reason', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning_content' => 'Let me reason...'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-reason', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['content' => 'Answer.'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-reason', 'model' => $model, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function toolCallChunks(string $model = 'test-model'): array
    {
        return [
            ['id' => 'chatcmpl-tools', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Let me search.'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query"']],
            ]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => $model, 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => ':"weather"}']],
            ]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => $model, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ];
    }

    /**
     * An SSE body, one `data:` event per chunk, split into a single network read.
     *
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<string>
     */
    public static function sseBody(array $chunks): array
    {
        return [implode('', array_map(static fn (array $c): string => 'data: ' . json_encode($c) . "\n\n", $chunks))];
    }

    /**
     * Fold events the way `ChatModelStream` exposes them.
     *
     * @param iterable<array<string, mixed>> $events
     *
     * @return array{text: string, reasoning: string, toolCalls: list<array{name: string, args: mixed}>, usage: array<string, mixed>|null, events: list<array<string, mixed>>}
     */
    public static function fold(iterable $events): array
    {
        $out = ['text' => '', 'reasoning' => '', 'toolCalls' => [], 'usage' => null, 'events' => []];

        foreach ($events as $event) {
            $out['events'][] = $event;

            if ($event['event'] === 'content-block-finish') {
                $content = $event['content'];
                if ($content['type'] === 'text') {
                    $out['text'] .= $content['text'];
                } elseif ($content['type'] === 'reasoning') {
                    $out['reasoning'] .= $content['reasoning'];
                } elseif ($content['type'] === 'tool_call') {
                    $out['toolCalls'][] = ['name' => $content['name'], 'args' => $content['args']];
                }
            }

            if ($event['event'] === 'message-finish' && isset($event['usage'])) {
                $out['usage'] = $event['usage'];
            }
        }

        return $out;
    }
}
