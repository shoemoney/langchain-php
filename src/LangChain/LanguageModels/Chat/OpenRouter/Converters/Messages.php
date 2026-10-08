<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;

/**
 * Message conversion for OpenRouter.
 *
 * Port of `converters/messages.ts` from `@langchain/openrouter`.
 *
 * OpenRouter's chat API is wire-compatible with OpenAI's, so each function
 * delegates to the Chat Completions converters in
 * {@see Completions} (as upstream delegates to `@langchain/openai`) and then
 * patches the result: reasoning fields are surfaced, and `response_metadata`
 * is restamped with the OpenRouter provider.
 *
 * Differences from the TypeScript original:
 *
 *  - `usage_metadata` is held in `response_metadata['usage_metadata']`, the
 *    place this port keeps it for every provider.
 *  - The `model` argument of {@see self::convertMessagesToOpenRouterParams()} and the
 *    `defaultRole` argument of {@see self::convertOpenRouterDeltaToBaseMessageChunk()} are
 *    accepted for signature parity but unused: the port's Completions converter
 *    has no reasoning-model `developer` role mapping, and only ever produces
 *    an `AIMessageChunk`.
 */
final class Messages
{
    private function __construct()
    {
    }

    /**
     * Convert LangChain messages to the OpenRouter request format.
     *
     * @param list<BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function convertMessagesToOpenRouterParams(array $messages, ?string $model = null): array
    {
        return Completions::convertMessages($messages);
    }

    /**
     * Convert a non-streaming OpenRouter response choice into a message.
     *
     * Reasoning (chain-of-thought) output is preserved on
     * `additional_kwargs.reasoning_content` (flat string, DeepSeek convention)
     * and `additional_kwargs.reasoning_details` (structured array, OpenRouter
     * native shape). Together with the `model_provider: "openrouter"` stamp this
     * lets {@see ContentBlocks} emit standard `reasoning` blocks.
     *
     * @param array<string, mixed> $choice
     * @param array<string, mixed> $rawResponse
     */
    public static function convertOpenRouterResponseToBaseMessage(array $choice, array $rawResponse): AIMessage
    {
        $message = Completions::choiceToMessage($choice, $rawResponse);

        // Reasoning fields the Completions converter doesn't know about. Both
        // are OpenRouter extensions to the Chat Completions response.
        $assistantMessage = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $reasoning = $assistantMessage['reasoning'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            $message->additional_kwargs['reasoning_content'] = $reasoning;
        }
        $details = $assistantMessage['reasoning_details'] ?? null;
        if (is_array($details) && $details !== []) {
            $message->additional_kwargs['reasoning_details'] = $details;
        }

        $message->response_metadata = [
            ...$message->response_metadata,
            'model' => $rawResponse['model'] ?? null,
            'model_provider' => 'openrouter',
            'model_name' => $rawResponse['model'] ?? null,
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];

        return $message;
    }

    /**
     * Convert a streaming delta into a message chunk.
     *
     * Reasoning delta text (`delta.reasoning`) and structured reasoning details
     * (`delta.reasoning_details`) are copied onto `additional_kwargs` so they
     * concatenate across chunks under the standard merge rules: strings are
     * concatenated and arrays are merged by index.
     *
     * @param array<string, mixed> $delta
     * @param array<string, mixed> $rawChunk
     */
    public static function convertOpenRouterDeltaToBaseMessageChunk(array $delta, array $rawChunk, ?string $defaultRole = null): AIMessageChunk
    {
        $chunk = Completions::deltaToChunk($delta, $rawChunk);

        $reasoning = $delta['reasoning'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            $chunk->additional_kwargs['reasoning_content'] = $reasoning;
        }
        $details = $delta['reasoning_details'] ?? null;
        if (is_array($details) && $details !== []) {
            $chunk->additional_kwargs['reasoning_details'] = $details;
        }

        $chunk->response_metadata = [
            ...$chunk->response_metadata,
            'model_provider' => 'openrouter',
        ];

        return $chunk;
    }

    /**
     * Convert OpenRouter usage info to LangChain's `UsageMetadata`, including
     * prompt/completion token detail breakdowns when available.
     *
     * @param array<string, mixed>|null $usage
     *
     * @return array<string, mixed>|null
     */
    public static function convertUsageMetadata(?array $usage = null): ?array
    {
        if ($usage === null) {
            return null;
        }

        $result = [
            'input_tokens' => $usage['prompt_tokens'] ?? null,
            'output_tokens' => $usage['completion_tokens'] ?? null,
            'total_tokens' => $usage['total_tokens'] ?? null,
        ];

        $promptDetails = $usage['prompt_tokens_details'] ?? null;
        if (is_array($promptDetails)) {
            $inputDetails = [];
            if (($promptDetails['cached_tokens'] ?? null) !== null) {
                $inputDetails['cache_read'] = $promptDetails['cached_tokens'];
            }
            if (($promptDetails['audio_tokens'] ?? null) !== null) {
                $inputDetails['audio'] = $promptDetails['audio_tokens'];
            }
            if ($inputDetails !== []) {
                $result['input_token_details'] = $inputDetails;
            }
        }

        $completionDetails = $usage['completion_tokens_details'] ?? null;
        if (is_array($completionDetails)) {
            $outputDetails = [];
            if (($completionDetails['reasoning_tokens'] ?? null) !== null) {
                $outputDetails['reasoning'] = $completionDetails['reasoning_tokens'];
            }
            if ($outputDetails !== []) {
                $result['output_token_details'] = $outputDetails;
            }
        }

        return $result;
    }
}
