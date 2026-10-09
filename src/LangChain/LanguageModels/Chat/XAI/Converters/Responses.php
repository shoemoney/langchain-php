<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Converters;

use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Utils\Js;

/**
 * Converters between LangChain messages and the xAI Responses API.
 *
 * Port of `converters/responses.ts` from `@langchain/xai`. Deliberately much
 * smaller than the OpenAI converters: xAI input is text and images only, an
 * assistant turn is replayed as plain text, and tool calls are not round-tripped.
 *
 * `usage_metadata` has no property of its own on a port message, so it rides in
 * `response_metadata['usage_metadata']` like every other provider in this SDK.
 *
 * @phpstan-import-type XAIResponse from XAIResponsesTypes
 * @phpstan-import-type XAIResponsesInputItem from XAIResponsesTypes
 * @phpstan-import-type XAIResponsesOutputItem from XAIResponsesTypes
 * @phpstan-import-type XAIResponsesStreamEvent from XAIResponsesTypes
 * @phpstan-import-type XAIResponsesUsage from XAIResponsesTypes
 */
final class Responses
{
    private function __construct()
    {
    }

    /**
     * Converts a single LangChain message to an xAI Responses API input item.
     *
     * @return XAIResponsesInputItem
     */
    public static function convertMessageToResponsesInput(BaseMessage $message): array
    {
        if ($message->type === BaseMessage::ROLE_HUMAN) {
            $content = $message->content;

            return [
                'role' => 'user',
                'content' => is_string($content) ? $content : array_map(self::humanPart(...), $content),
            ];
        }

        if ($message->type === BaseMessage::ROLE_SYSTEM) {
            $content = $message->content;

            return [
                'role' => 'system',
                'content' => is_string($content)
                    ? $content
                    : implode('', array_map(
                        static fn (mixed $part): string => is_string($part) ? $part : (string) ($part['text'] ?? ''),
                        $content,
                    )),
            ];
        }

        if ($message->type === BaseMessage::ROLE_AI) {
            return [
                'type' => 'message',
                'role' => 'assistant',
                'text' => is_string($message->content) ? $message->content : '',
            ];
        }

        // Default fallback.
        return [
            'role' => 'user',
            'content' => is_string($message->content) ? $message->content : Js::encode($message->content),
        ];
    }

    /**
     * Converts a list of LangChain messages to xAI Responses API input items.
     *
     * @param list<BaseMessage> $messages
     *
     * @return list<XAIResponsesInputItem>
     */
    public static function convertMessagesToResponsesInput(array $messages): array
    {
        return array_map(self::convertMessageToResponsesInput(...), array_values($messages));
    }

    /**
     * @return array<string, mixed>
     */
    private static function humanPart(mixed $part): array
    {
        if (is_string($part)) {
            return ['type' => 'input_text', 'text' => $part];
        }
        $type = is_array($part) ? ($part['type'] ?? null) : null;

        if ($type === 'text') {
            return ['type' => 'input_text', 'text' => $part['text']];
        }

        if ($type === 'image_url') {
            $imageUrl = $part['image_url'] ?? null;

            return [
                'type' => 'input_image',
                'image_url' => is_string($imageUrl) ? $imageUrl : ($imageUrl['url'] ?? null),
                'detail' => 'auto',
            ];
        }

        return ['type' => 'input_text', 'text' => ''];
    }

    /**
     * Converts xAI usage statistics to LangChain `UsageMetadata`.
     *
     * @param XAIResponsesUsage|null $usage
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int, input_token_details: array<string, int>, output_token_details: array<string, int>}
     */
    public static function convertUsageToUsageMetadata(?array $usage): array
    {
        $cached = $usage['input_tokens_details']['cached_tokens'] ?? null;
        $reasoning = $usage['output_tokens_details']['reasoning_tokens'] ?? null;

        return [
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'input_token_details' => $cached !== null ? ['cache_read' => (int) $cached] : [],
            'output_token_details' => $reasoning !== null ? ['reasoning' => (int) $reasoning] : [],
        ];
    }

    /**
     * Concatenated `output_text` content of a response's `message` items.
     *
     * @param list<XAIResponsesOutputItem> $output
     */
    public static function extractTextFromOutput(array $output): string
    {
        $parts = [];
        foreach ($output as $item) {
            if (($item['type'] ?? null) !== 'message' || !is_array($item['content'] ?? null)) {
                continue;
            }
            foreach ($item['content'] as $contentItem) {
                if (($contentItem['type'] ?? null) === 'output_text') {
                    $parts[] = (string) $contentItem['text'];
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * Converts an xAI response to a LangChain `AIMessage`.
     *
     * @param XAIResponse $response
     */
    public static function convertResponseToAIMessage(array $response): AIMessage
    {
        $text = self::extractTextFromOutput($response['output'] ?? []);

        // An absent member is left out, as `JSON.stringify` would leave an `undefined` one.
        $responseMetadata = array_filter([
            'model_provider' => 'xai',
            'model' => $response['model'] ?? null,
            'created_at' => $response['created_at'] ?? null,
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'object' => $response['object'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        if (!empty($response['incomplete_details'])) {
            $responseMetadata['incomplete_details'] = $response['incomplete_details'];
        }

        $responseMetadata['usage_metadata'] = self::convertUsageToUsageMetadata($response['usage'] ?? null);

        return new AIMessage([
            'content' => $text,
            'response_metadata' => $responseMetadata,
            'additional_kwargs' => !empty($response['reasoning']) ? ['reasoning' => $response['reasoning']] : [],
        ]);
    }

    /**
     * Converts an xAI streaming event to a `ChatGenerationChunk`, or null when
     * the event produces no chunk.
     *
     * @param XAIResponsesStreamEvent $event
     */
    public static function convertStreamEventToChunk(array $event): ?ChatGenerationChunk
    {
        $responseMetadata = ['model_provider' => 'xai'];
        $type = $event['type'] ?? null;

        if ($type === 'response.output_text.delta') {
            return new ChatGenerationChunk(
                new AIMessageChunk(['content' => $event['delta'], 'response_metadata' => $responseMetadata]),
                (string) $event['delta'],
            );
        }

        if ($type === 'response.created') {
            $responseMetadata['id'] = $event['response']['id'] ?? null;
            $responseMetadata['model'] = $event['response']['model'] ?? null;

            return new ChatGenerationChunk(
                new AIMessageChunk([
                    'content' => '',
                    'response_metadata' => array_filter($responseMetadata, static fn (mixed $v): bool => $v !== null),
                ]),
                '',
            );
        }

        if ($type === 'response.completed') {
            $aiMessage = self::convertResponseToAIMessage($event['response']);

            return new ChatGenerationChunk(
                new AIMessageChunk([
                    'content' => '',
                    'response_metadata' => [...$responseMetadata, ...$aiMessage->response_metadata],
                ]),
                '',
            );
        }

        return null;
    }
}
