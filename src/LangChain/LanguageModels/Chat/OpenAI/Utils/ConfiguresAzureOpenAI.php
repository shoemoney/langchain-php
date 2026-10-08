<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Utils\Env;

/**
 * The Azure state, endpoint, authentication and serialization shared by every
 * Azure OpenAI client: the two chat protocol clients and the facade, the legacy
 * completions LLM and the embeddings.
 *
 * Port of `_constructAzureFields`, `_getAzureClientOptions` and
 * `_serializeAzureChat` from `azure/chat_models/common.ts`, together with the
 * identical field blocks repeated in `azure/llms.ts` and `azure/embeddings.ts`.
 * The using class supplies `$baseUrl`, the {@see Azure::getEndpoint()} fallback.
 */
trait ConfiguresAzureOpenAI
{
    public ?string $azureOpenAIApiVersion = null;

    public ?string $azureOpenAIApiKey = null;

    /** Returns a bearer token; consulted on every request, so it may rotate. */
    public ?\Closure $azureADTokenProvider = null;

    public ?string $azureOpenAIApiInstanceName = null;

    public ?string $azureOpenAIApiDeploymentName = null;

    public ?string $azureOpenAIBasePath = null;

    public ?string $azureOpenAIEndpoint = null;

    /**
     * The fields the object was constructed with, in the order given, so the
     * serialized form lists them the way the caller wrote them.
     *
     * @var array<string, mixed>
     */
    public array $azureConstructorFields = [];

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $deploymentFieldKeys Field names that may carry the deployment, first non-empty wins.
     * @param list<string>         $deploymentEnvNames  Environment variables tried when no field is given, first non-empty wins.
     * @param ?string              $apiKey              The non-Azure key already resolved, counted as a credential.
     */
    protected function constructAzureFields(
        array $fields,
        array $deploymentFieldKeys = ['azureOpenAIApiDeploymentName', 'deploymentName'],
        array $deploymentEnvNames = ['AZURE_OPENAI_API_DEPLOYMENT_NAME'],
        bool $requireCredentials = true,
        ?string $apiKey = null,
    ): void {
        $this->azureConstructorFields = $fields;

        $this->azureOpenAIApiKey = self::azureString($fields['azureOpenAIApiKey'] ?? null)
            ?? self::azureString($fields['openAIApiKey'] ?? null)
            ?? self::azureString($fields['apiKey'] ?? null)
            ?? Env::getEnvironmentVariable('AZURE_OPENAI_API_KEY');

        $this->azureOpenAIApiInstanceName = self::azureString($fields['azureOpenAIApiInstanceName'] ?? null)
            ?? Env::getEnvironmentVariable('AZURE_OPENAI_API_INSTANCE_NAME');

        $deployment = null;
        foreach ($deploymentFieldKeys as $key) {
            $deployment = self::azureNonEmpty($fields[$key] ?? null);
            if ($deployment !== null) {
                break;
            }
        }
        if ($deployment === null) {
            foreach ($deploymentEnvNames as $name) {
                $deployment = self::azureNonEmpty(Env::getEnvironmentVariable($name));
                if ($deployment !== null) {
                    break;
                }
            }
        }
        $this->azureOpenAIApiDeploymentName = $deployment;

        $this->azureOpenAIApiVersion = self::azureString($fields['azureOpenAIApiVersion'] ?? null)
            ?? self::azureString($fields['openAIApiVersion'] ?? null)
            ?? Env::getEnvironmentVariable('AZURE_OPENAI_API_VERSION');

        $this->azureOpenAIBasePath = self::azureString($fields['azureOpenAIBasePath'] ?? null)
            ?? Env::getEnvironmentVariable('AZURE_OPENAI_BASE_PATH');

        $this->azureOpenAIEndpoint = self::azureString($fields['azureOpenAIEndpoint'] ?? null)
            ?? Env::getEnvironmentVariable('AZURE_OPENAI_ENDPOINT');

        $provider = $fields['azureADTokenProvider'] ?? null;
        $this->azureADTokenProvider = $provider === null ? null : \Closure::fromCallable($provider);

        if ($requireCredentials
            && ($this->azureOpenAIApiKey === null || $this->azureOpenAIApiKey === '')
            && ($apiKey === null || $apiKey === '')
            && $this->azureADTokenProvider === null) {
            throw new \InvalidArgumentException('Azure OpenAI API key or Token Provider not found');
        }
    }

    /**
     * Copy another Azure client's resolved state onto this one.
     *
     * How the facade hands a state change made after construction to the
     * protocol client it routes to. Public because a trait's methods are
     * scoped to the class using it, and the facade and its delegates are
     * siblings, not ancestors of one another.
     */
    public function adoptAzureStateFrom(object $other): void
    {
        foreach ([
            'azureOpenAIApiVersion', 'azureOpenAIApiKey', 'azureADTokenProvider', 'azureOpenAIApiInstanceName',
            'azureOpenAIApiDeploymentName', 'azureOpenAIBasePath', 'azureOpenAIEndpoint', 'azureConstructorFields',
        ] as $property) {
            $this->{$property} = $other->{$property};
        }
    }

    /**
     * The resolved (Azure) endpoint, before a protocol path is appended.
     *
     * @throws OpenAIException when nothing can name an endpoint.
     */
    protected function azureRoot(): string
    {
        try {
            $root = Azure::getEndpoint([
                'azureOpenAIApiDeploymentName' => $this->azureOpenAIApiDeploymentName,
                'azureOpenAIApiInstanceName' => $this->azureOpenAIApiInstanceName,
                'azureOpenAIApiKey' => $this->azureOpenAIApiKey,
                'azureOpenAIBasePath' => $this->azureOpenAIBasePath,
                'azureADTokenProvider' => $this->azureADTokenProvider,
                'baseURL' => $this->baseUrl,
                'azureOpenAIEndpoint' => $this->azureOpenAIEndpoint,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new OpenAIException($e->getMessage(), 0, '', previous: $e);
        }

        if ($root === null || $root === '') {
            throw new OpenAIException(
                'No Azure OpenAI endpoint. Set azureOpenAIEndpoint, azureOpenAIBasePath, azureOpenAIApiInstanceName or baseUrl.',
                0,
                '',
            );
        }

        return rtrim($root, '/');
    }

    /**
     * `User-Agent` and credentials.
     *
     * The key goes in `api-key`, as the Azure SDK client sends it; a token
     * provider yields `Authorization: Bearer`. The provider is called per
     * request, never cached, because a managed-identity token expires.
     *
     * @return array<string, string>
     */
    protected function azureHeaders(): array
    {
        $headers = Azure::getHeadersWithUserAgent(null, true, Azure::AZURE_VERSION);

        if ($this->azureOpenAIApiKey !== null && $this->azureOpenAIApiKey !== '') {
            return $headers + ['api-key' => $this->azureOpenAIApiKey];
        }

        if ($this->azureADTokenProvider !== null) {
            return $headers + ['Authorization' => 'Bearer ' . ($this->azureADTokenProvider)()];
        }

        throw new OpenAIException(
            'No Azure OpenAI credentials. Pass azureOpenAIApiKey or azureADTokenProvider, or set AZURE_OPENAI_API_KEY.',
            0,
            '',
        );
    }

    /**
     * @return array<string, string>
     */
    protected function azureQuery(): array
    {
        return $this->azureOpenAIApiVersion === null ? [] : ['api-version' => $this->azureOpenAIApiVersion];
    }

    /**
     * The constructor kwargs of the serialized form, with Azure's key spellings.
     *
     * The keys the Azure fields serialize under are upstream's `lc_aliases`;
     * the key is replaced by a secret reference so the credential never reaches a
     * trace or a checkpoint. `$chat` adds `_serializeAzureChat`'s rewriting of
     * the endpoint and deployment out of a base path or an instance name.
     *
     * @param array<string, mixed> $baseKwargs What the base class records.
     *
     * @return array<string, mixed>
     */
    protected function azureSerializedKwargs(array $baseKwargs, bool $chat = true): array
    {
        $aliases = [
            'openAIApiKey' => 'openai_api_key',
            'apiKey' => 'openai_api_key',
            'openAIApiVersion' => 'openai_api_version',
            'openAIBasePath' => 'openai_api_base',
            'deploymentName' => 'deployment_name',
            'azureOpenAIEndpoint' => 'azure_endpoint',
            'azureOpenAIApiVersion' => 'openai_api_version',
            'azureOpenAIBasePath' => 'openai_api_base',
            'azureOpenAIApiDeploymentName' => 'deployment_name',
            'azureOpenAIApiKey' => 'azure_open_ai_api_key',
            'azureOpenAIApiInstanceName' => 'azure_open_ai_api_instance_name',
        ];
        $secrets = [
            'azureOpenAIApiKey' => 'AZURE_OPENAI_API_KEY',
            'openAIApiKey' => 'OPENAI_API_KEY',
            'apiKey' => 'OPENAI_API_KEY',
        ];

        $kwargs = [];
        foreach ($this->azureConstructorFields as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (isset($aliases[$key])) {
                $kwargs[$aliases[$key]] = isset($secrets[$key])
                    ? ['lc' => 1, 'type' => 'secret', 'id' => [$secrets[$key]]]
                    : $value;
            } elseif (array_key_exists($key, $baseKwargs)) {
                $kwargs[$key] = $baseKwargs[$key];
            }
        }
        // Whatever else the base recorded, such as tools attached by `bindTools()`.
        $kwargs += $baseKwargs;

        unset(
            $kwargs['azure_openai_base_path'],
            $kwargs['azure_openai_api_deployment_name'],
            $kwargs['azure_openai_api_key'],
            $kwargs['azure_openai_api_version'],
            $kwargs['azure_open_ai_base_path'],
        );

        if (!$chat) {
            return $kwargs;
        }

        $basePathParts = $this->azureOpenAIBasePath === null
            ? []
            : explode('/openai/deployments/', $this->azureOpenAIBasePath);

        if (empty($kwargs['azure_endpoint']) && $this->azureOpenAIEndpoint) {
            $kwargs['azure_endpoint'] = $this->azureOpenAIEndpoint;
        }
        if (empty($kwargs['azure_endpoint']) && count($basePathParts) === 2 && str_starts_with($basePathParts[0], 'http')) {
            $kwargs['azure_endpoint'] = $basePathParts[0];
        }
        if (empty($kwargs['azure_endpoint']) && $this->azureOpenAIApiInstanceName) {
            $kwargs['azure_endpoint'] = 'https://' . $this->azureOpenAIApiInstanceName . '.openai.azure.com/';
        }
        if (empty($kwargs['deployment_name']) && $this->azureOpenAIApiDeploymentName) {
            $kwargs['deployment_name'] = $this->azureOpenAIApiDeploymentName;
        }
        if (empty($kwargs['deployment_name']) && count($basePathParts) === 2) {
            $kwargs['deployment_name'] = $basePathParts[1];
        }

        if (!empty($kwargs['azure_endpoint']) && !empty($kwargs['deployment_name']) && !empty($kwargs['openai_api_base'])) {
            unset($kwargs['openai_api_base']);
        }
        // Upstream deletes the spelling `azure_openai_api_instance_name`, which is
        // not the one the instance name serializes under, so it survives. Kept.
        if (!empty($kwargs['azure_openai_api_instance_name']) && !empty($kwargs['azure_endpoint'])) {
            unset($kwargs['azure_openai_api_instance_name']);
        }

        return $kwargs;
    }

    private static function azureString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function azureNonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
