<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\LLMs;

use LangChain\LanguageModels\Chat\OpenAI\Utils\ConfiguresAzureOpenAI;

/**
 * The legacy completions endpoint on an Azure OpenAI deployment.
 *
 * Port of `AzureOpenAI` from `azure/llms.ts`. The request and the response are
 * {@see OpenAI}'s; the endpoint, the authentication and the deployment lookup
 * (`azureOpenAIApiCompletionsDeploymentName`, then
 * `AZURE_OPENAI_API_COMPLETIONS_DEPLOYMENT_NAME`, ahead of the generic ones)
 * differ.
 */
class AzureOpenAI extends OpenAI
{
    use ConfiguresAzureOpenAI;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->constructAzureFields(
            $fields,
            ['azureOpenAIApiCompletionsDeploymentName', 'azureOpenAIApiDeploymentName'],
            ['AZURE_OPENAI_API_COMPLETIONS_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_DEPLOYMENT_NAME'],
            apiKey: $this->apiKey,
        );
    }

    protected function url(): string
    {
        return $this->azureRoot() . '/completions';
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

    /**
     * @return array{lc: int, type: string, id: list<string>, kwargs: array<string, mixed>}
     */
    public function toSerializedConstructor(): array
    {
        $json = parent::toSerializedConstructor();
        $json['kwargs'] = $this->azureSerializedKwargs($json['kwargs'], chat: false);

        return $json;
    }
}
