<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\Utils\ResponsesStreamEvents as OpenAIResponsesStreamEvents;

/**
 * Converts xAI Responses API stream events into LangChain `ChatModelStreamEvent`s.
 *
 * Port of `utils/responses_stream_events.ts`. xAI Responses events are
 * wire-compatible with OpenAI Responses stream events, so this hands the
 * stream to the OpenAI converter and only stamps the provider as `xai`.
 */
final class ResponsesStreamEvents
{
    private function __construct()
    {
    }

    /**
     * `convertXAIResponsesStream`.
     *
     * @param iterable<array<string, mixed>> $source  Decoded `XAIResponsesStreamEvent`s.
     * @param array{streamUsage?: bool}      $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public static function convertXAIResponsesStream(iterable $source, array $options = []): \Generator
    {
        yield from OpenAIResponsesStreamEvents::convertOpenAIResponsesStream($source, [...$options, 'provider' => 'xai']);
    }
}
