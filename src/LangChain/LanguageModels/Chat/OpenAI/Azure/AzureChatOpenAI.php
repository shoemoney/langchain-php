<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * The Azure OpenAI chat model.
 *
 * Port of `AzureChatOpenAI` from `azure/chat_models/index.ts`: a
 * {@see ChatOpenAI} whose two protocol clients are the Azure ones, so the
 * choice between Chat Completions and Responses is the facade's, unchanged
 * ({@see ChatOpenAI::shouldUseResponsesApi()}).
 *
 * The first argument may be a deployment name, which doubles as the model:
 *
 * ```php
 * new AzureChatOpenAI('gpt-4o', [
 *     'azureOpenAIEndpoint' => 'https://example.openai.azure.com',
 *     'azureOpenAIApiVersion' => '2024-08-01-preview',
 *     'azureOpenAIApiKey' => $key,
 * ]);
 * ```
 *
 * Not ported: `_getStructuredOutputMethod`'s "gpt-4o prefers functionCalling".
 * {@see \LangChain\LanguageModels\BaseChatModel::withStructuredOutput()} here
 * supports function calling only, which is what upstream selects for that case.
 */
class AzureChatOpenAI extends ChatOpenAI
{
    use AzureChatModel;

    /**
     * @param string|array<string, mixed>|null $deploymentOrFields A deployment name, or the fields.
     * @param array<string, mixed>             $fields             The fields when a deployment name is given.
     */
    public function __construct(string|array|null $deploymentOrFields = null, array $fields = [])
    {
        $fields = self::getAzureChatOpenAIParams($deploymentOrFields, $fields);
        parent::__construct($fields);
        $this->constructAzureFields($fields, apiKey: $this->apiKey);
    }

    /**
     * The Azure protocol client for this call, carrying this instance's state.
     *
     * Which protocol is the inherited {@see self::shouldUseResponsesApi()}.
     *
     * @param array<string, mixed> $options
     */
    private function azureDelegate(array $options): AzureChatOpenAICompletions|AzureChatOpenAIResponses
    {
        $fields = $this->azureConstructorFields;
        $delegate = $this->shouldUseResponsesApi($options)
            ? new AzureChatOpenAIResponses($fields)
            : new AzureChatOpenAICompletions($fields);

        $delegate->adoptStateFrom($this);
        $delegate->adoptAzureStateFrom($this);
        $delegate->backoffHandler = fn (int $attempt) => $this->backoff($attempt);

        return $delegate;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        return $this->azureDelegate($options)->invocationParams($options, $extra);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        return $this->azureDelegate($options)->generate($messages, $options, $runManager);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        yield from $this->azureDelegate($options)->streamResponseChunks($messages, $options, $runManager);
    }

    /**
     * Typed stream events; available when this call routes to the Responses API.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws \LogicException on the Chat Completions protocol.
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $delegate = $this->azureDelegate($options);
        if (!$delegate instanceof AzureChatOpenAIResponses) {
            throw new \LogicException(
                'streamChatModelEvents() is only available on the Responses API; set useResponsesApi or '
                . 'use a Responses-only option.'
            );
        }

        yield from $delegate->streamChatModelEvents($messages, $options);
    }
}
