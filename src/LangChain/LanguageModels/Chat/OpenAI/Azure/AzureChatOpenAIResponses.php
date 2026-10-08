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
     * The Responses route is not deployment-scoped: a deployment URL
     * (`.../openai/deployments/{name}`) is cut back to `.../openai/responses`.
     * Non-exact: upstream builds this URL in the openai SDK's AzureOpenAI client from a deployment-scoped baseURL.
     */
    protected function url(): string
    {
        $root = $this->azureRoot();
        $at = strpos($root, '/deployments/');

        return ($at === false ? $root : substr($root, 0, $at)) . '/responses';
    }
}
