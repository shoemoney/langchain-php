<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;

/**
 * The Responses protocol client for Azure OpenAI.
 *
 * Port of `AzureChatOpenAIResponses` from `azure/chat_models/responses.ts`.
 * The wire format is {@see ChatOpenAIResponses}'; only the endpoint and the
 * authentication differ.
 *
 * @see AzureChatOpenAI the facade that chooses between this and the Completions client.
 */
class AzureChatOpenAIResponses extends ChatOpenAIResponses
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
     * Generated endpoints use Azure's resource-level preview Responses route.
     * This deliberately corrects the pinned SDK's deployment-scoped URL.
     */
    protected function url(): string
    {
        return $this->azureProtocolUrl('/responses', deploymentScoped: false);
    }

    /** @param list<\LangChain\Messages\BaseMessage> $messages
     *  @param array<string, mixed> $options
     *  @return \Generator<int, array<string, mixed>>
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $client = clone $this;
        $client->azureRequestOptions = (array) ($options['options'] ?? []);

        yield from $client->azureStreamChatModelEvents($messages, $options);
    }

    /** @param list<\LangChain\Messages\BaseMessage> $messages
     *  @param array<string, mixed> $options
     *  @return \Generator<int, array<string, mixed>>
     */
    private function azureStreamChatModelEvents(array $messages, array $options): \Generator
    {
        yield from parent::streamChatModelEvents($messages, $options);
    }
}
