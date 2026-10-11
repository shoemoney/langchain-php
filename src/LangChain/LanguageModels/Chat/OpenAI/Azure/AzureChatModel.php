<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Azure;

use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Azure;
use LangChain\LanguageModels\Chat\OpenAI\Utils\ConfiguresAzureOpenAI;
use LangChain\Messages\BaseMessage;
use LangChain\Utils\Env;
use LangChain\LanguageModels\Outputs\ChatResult;

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

    /** @var array<string, mixed> SDK request options for this request's clone. */
    protected array $azureRequestOptions = [];

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
            ...$fieldsArg,
            ];
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
     * Request options belong to a clone so suspended streams cannot leak them
     * into another call on the same model.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $client = clone $this;
        $client->azureRequestOptions = (array) ($options['options'] ?? []);

        return $client->azureGenerate($messages, $options, $runManager);
    }

    /** @param list<BaseMessage> $messages
     *  @param array<string, mixed> $options
     */
    private function azureGenerate(array $messages, array $options, ?CallbackManagerForLLMRun $runManager): ChatResult
    {
        return parent::generate($messages, $options, $runManager);
    }

    /** @param list<BaseMessage> $messages
     *  @param array<string, mixed> $options
     *  @return \Generator<int, \LangChain\LanguageModels\Outputs\ChatGenerationChunk>
     */
    protected function streamResponseChunks(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): \Generator
    {
        $client = clone $this;
        $client->azureRequestOptions = (array) ($options['options'] ?? []);

        yield from $client->azureStreamResponseChunks($messages, $options, $runManager);
    }

    /** @param list<BaseMessage> $messages
     *  @param array<string, mixed> $options
     *  @return \Generator<int, \LangChain\LanguageModels\Outputs\ChatGenerationChunk>
     */
    private function azureStreamResponseChunks(array $messages, array $options, ?CallbackManagerForLLMRun $runManager): \Generator
    {
        yield from parent::streamResponseChunks($messages, $options, $runManager);
    }

    /** @return array<string, mixed> */
    private function azureOptions(): array
    {
        return [...(array) ($this->azureConstructorFields['configuration'] ?? []), ...$this->azureRequestOptions];
    }

    /**
     * SDK 7.10 inserts a deployment only for deployment-scoped operations,
     * based on the client's generated root. Per-request baseURL is ignored.
     *
     * @param bool $deploymentScoped Whether the SDK injects a deployment path.
     */
    protected function azureProtocolUrl(string $path, bool $deploymentScoped): string
    {
        $root = $this->azureRoot();
        if ($deploymentScoped && !str_contains($root, '/deployments')) {
            $path = '/deployments/' . ($this->azureOpenAIApiDeploymentName ?? $this->model) . $path;
        }
        if (!$deploymentScoped) {
            // Azure's preview Responses REST route is resource scoped. Keep
            // this provider-compatible correction to the pinned SDK's route.
            $at = strpos($root, '/deployments/');
            $root = $at === false ? $root : substr($root, 0, $at);
        }

        return $root . $path;
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        $options = $this->azureOptions();
        $headers = ['Content-Type' => 'application/json'];
        if ($this->azureADTokenProvider !== null) {
            $token = ($this->azureADTokenProvider)();
            if (!is_string($token) || $token === '') {
                throw new OpenAIException('azureADTokenProvider must return a non-empty string.', 0, '');
            }
            $headers['Authorization'] = 'Bearer ' . $token;
        } elseif ($this->azureOpenAIApiKey === null || $this->azureOpenAIApiKey === '') {
            // Retain the shared missing-credentials error.
            return $headers + $this->azureHeaders();
        }
        $configuration = (array) ($this->azureConstructorFields['configuration'] ?? []);
        $defaults = Azure::getHeadersWithUserAgent((array) ($configuration['defaultHeaders'] ?? []), true, Azure::AZURE_VERSION);
        $requestHeaders = (array) ($options['headers'] ?? []);
        if ($this->azureOpenAIApiKey !== null && $this->azureOpenAIApiKey !== '') {
            $requestHeaders = ['api-key' => $this->azureOpenAIApiKey, ...$requestHeaders];
        }
        // HTTP header names are case-insensitive; request headers win.
        foreach ([...$defaults, ...$requestHeaders] as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, (string) $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            if ($value !== null) {
                $headers[(string) $name] = (string) $value;
            }
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    protected function query(): array
    {
        $options = $this->azureOptions();

        $configuration = (array) ($this->azureConstructorFields['configuration'] ?? []);
        $version = $configuration['apiVersion'] ?? $this->azureOpenAIApiVersion ?? Env::getEnvironmentVariable('OPENAI_API_VERSION');
        $query = [...(array) ($configuration['defaultQuery'] ?? []), ...($version === null ? [] : ['api-version' => $version])];
        if ($this->azureOpenAIApiKey !== null && $this->azureOpenAIApiKey !== '' && $this->azureOpenAIApiVersion !== null) {
            $query = [...$query, ...$this->azureQuery()];
        }

        return [...$query, ...(array) ($options['query'] ?? [])];
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
