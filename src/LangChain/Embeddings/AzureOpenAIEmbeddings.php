<?php

declare(strict_types=1);

namespace LangChain\Embeddings;

use LangChain\LanguageModels\Chat\OpenAI\Utils\ConfiguresAzureOpenAI;

/**
 * Embeddings from an Azure OpenAI deployment.
 *
 * Port of `AzureOpenAIEmbeddings` from `azure/embeddings.ts`. The batches are
 * {@see OpenAIEmbeddings}'; only the endpoint, the authentication and two
 * defaults differ: `batchSize` is 1, and the deployment may come from
 * `azureOpenAIApiEmbeddingsDeploymentName` or
 * `AZURE_OPENAI_API_EMBEDDINGS_DEPLOYMENT_NAME` ahead of the generic ones.
 *
 * Unlike the chat clients, a missing key is not refused at construction, and
 * `azureOpenAIEndpoint` is not read: upstream does neither.
 */
class AzureOpenAIEmbeddings extends OpenAIEmbeddings
{
    use ConfiguresAzureOpenAI;

    /**
     * @param array<string, mixed> $fields Those of {@see OpenAIEmbeddings} plus the azure* fields.
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->batchSize = (int) ($fields['batchSize'] ?? 1);
        $this->constructAzureFields(
            $fields,
            ['azureOpenAIApiEmbeddingsDeploymentName', 'azureOpenAIApiDeploymentName'],
            ['AZURE_OPENAI_API_EMBEDDINGS_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_DEPLOYMENT_NAME'],
            requireCredentials: false,
        );
        $this->azureOpenAIEndpoint = null;
    }

    protected function url(): string
    {
        return $this->azureRoot() . '/embeddings';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return ['Content-Type' => 'application/json'] + $this->azureHeaders();
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return $this->azureQuery();
    }
}
