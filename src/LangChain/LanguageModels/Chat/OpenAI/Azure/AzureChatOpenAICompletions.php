<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;

/**
 * The Chat Completions protocol client for Azure OpenAI.
 *
 * Port of `AzureChatOpenAICompletions` from `azure/chat_models/completions.ts`.
 * The wire format is {@see ChatOpenAICompletions}'; only the endpoint and the
 * authentication differ.
 *
 * @see AzureChatOpenAI the facade that chooses between this and the Responses client.
 */
class AzureChatOpenAICompletions extends ChatOpenAICompletions
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

    protected function url(): string
    {
        return $this->azureRoot() . '/chat/completions';
    }
}
