<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\Utils\ConfiguresAzureOpenAI;

/**
 * What {@see AzureChatOpenAI}, {@see AzureChatOpenAICompletions} and
 * {@see AzureChatOpenAIResponses} have in common.
 *
 * Port of `azure/chat_models/common.ts`: the alias, secret and serializable-key
 * tables, `getAzureChatOpenAIParams`, and the wiring of the shared Azure
 * endpoint and authentication into the OpenAI transport. The routing between
 * the two protocols is NOT repeated here: the facade inherits it from
 * {@see \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI}, and the two protocol
 * clients inherit the wire formats from their OpenAI counterparts.
 */
trait AzureChatModel
{
    use ConfiguresAzureOpenAI;

    /** `AZURE_ALIASES`: constructor field name to serialized key. */
    public const AZURE_ALIASES = [
        'openAIApiKey' => 'openai_api_key',
        'openAIApiVersion' => 'openai_api_version',
        'openAIBasePath' => 'openai_api_base',
        'deploymentName' => 'deployment_name',
        'azureOpenAIEndpoint' => 'azure_endpoint',
        'azureOpenAIApiVersion' => 'openai_api_version',
        'azureOpenAIBasePath' => 'openai_api_base',
        'azureOpenAIApiDeploymentName' => 'deployment_name',
    ];

    /** `AZURE_SECRETS`: field name to the environment variable that holds it. */
    public const AZURE_SECRETS = [
        'azureOpenAIApiKey' => 'AZURE_OPENAI_API_KEY',
    ];

    /** `AZURE_SERIALIZABLE_KEYS` */
    public const AZURE_SERIALIZABLE_KEYS = [
        'azureOpenAIApiKey',
        'azureOpenAIApiVersion',
        'azureOpenAIBasePath',
        'azureOpenAIEndpoint',
        'azureOpenAIApiInstanceName',
        'azureOpenAIApiDeploymentName',
        'deploymentName',
        'openAIApiKey',
        'openAIApiVersion',
    ];

    /**
     * Port of `getAzureChatOpenAIParams`.
     *
     * A string is a deployment name and doubles as the model, so
     * `new AzureChatOpenAI('gpt-4o', [...])` works; the second argument then
     * carries the rest.
     *
     * @param string|array<string, mixed>|null $modelOrFields
     * @param array<string, mixed>             $fieldsArg
     *
     * @return array<string, mixed>
     */
    public static function getAzureChatOpenAIParams(string|array|null $modelOrFields = null, array $fieldsArg = []): array
    {
        if (is_string($modelOrFields)) {
            return [
                'model' => $modelOrFields,
                'deploymentName' => $modelOrFields,
                'azureOpenAIApiDeploymentName' => $modelOrFields,
            ] + $fieldsArg;
        }

        return $modelOrFields ?? $fieldsArg;
    }

    public function llmType(): string
    {
        return 'azure_openai';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'azure_openai'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        return array_merge(parent::lsParams($options), ['ls_provider' => 'azure']);
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
        $json['kwargs'] = $this->azureSerializedKwargs($json['kwargs']);

        return $json;
    }
}
