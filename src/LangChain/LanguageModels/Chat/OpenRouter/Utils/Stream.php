<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Utils;

use LangChain\Utils\Http\SseParser;

/**
 * Decodes an OpenRouter server-sent-event stream into chunk objects.
 *
 * Port of `OpenRouterJsonParseStream` from `@langchain/openrouter`
 * (`utils/stream.ts`), the stage that sat after `EventSourceParserStream`.
 * {@see SseParser} is the event-source stage: it yields each event's `data`
 * payload (and drops the `[DONE]` sentinel and comment lines such as
 * `: OPENROUTER PROCESSING`); this stage JSON-decodes it.
 *
 * Malformed or empty events are forwarded as `null` so the downstream reader
 * can skip them without the stream erroring out.
 */
final class Stream
{
    private function __construct()
    {
    }

    /**
     * @param iterable<string> $payloads The `data` payloads of the SSE events.
     *
     * @return \Generator<int, array<string, mixed>|null>
     */
    public static function jsonParse(iterable $payloads): \Generator
    {
        foreach ($payloads as $payload) {
            if ($payload === '') {
                yield null;

                continue;
            }

            $decoded = json_decode($payload, true);

            yield is_array($decoded) ? $decoded : null;
        }
    }
}
