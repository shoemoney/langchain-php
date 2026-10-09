<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\Fireworks\ChatFireworks;
use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\Utils\Env;

/**
 * The provider registry behind `initChatModel`.
 *
 * Port of `MODEL_PROVIDER_CONFIG`, `getChatModelByClassName`, `_initChatModelHelper` and `_inferModelProvider`
 * from `langchain/src/chat_models/universal.ts`.
 *
 * The registry is LIMITED to the providers this port ships a chat client for. Upstream also lists cohere,
 * google, google-vertexai, google-vertexai-web, google-genai, mistralai, mistral, groq, bedrock, aws,
 * cerebras and perplexity; asking for one of them is an "Unsupported" error here, listing what is
 * available. `OpenRouter` is shipped but is not an upstream key, so it is left out too.
 *
 * Upstream resolves a class with a dynamic `import(package)`; PHP has no package-per-provider split, so
 * the lookup is a static map from provider key to class.
 */
final class ModelProviders
{
    /** The gateway the `langsmith` provider talks to unless `LANGSMITH_GATEWAY` or a base URL says otherwise. */
    public const DEFAULT_LANGSMITH_GATEWAY = 'https://gateway.smith.langchain.com';

    /**
     * Provider key to the client class that serves it. `langsmith` is a `ChatOpenAI` pointed at the gateway
     * with the Responses API forced on (see {@see self::langSmithParams()}).
     *
     * @var array<string, array{className: string, class: class-string<BaseChatModel>}>
     */
    public const MODEL_PROVIDER_CONFIG = [
        'openai' => ['className' => 'ChatOpenAI', 'class' => ChatOpenAI::class],
        'anthropic' => ['className' => 'ChatAnthropic', 'class' => ChatAnthropic::class],
        'azure_openai' => ['className' => 'AzureChatOpenAI', 'class' => AzureChatOpenAI::class],
        'langsmith' => ['className' => 'ChatOpenAI', 'class' => ChatOpenAI::class],
        'ollama' => ['className' => 'ChatOllama', 'class' => ChatOllama::class],
        'deepseek' => ['className' => 'ChatDeepSeek', 'class' => ChatDeepSeek::class],
        'xai' => ['className' => 'ChatXAI', 'class' => ChatXAI::class],
        'fireworks' => ['className' => 'ChatFireworks', 'class' => ChatFireworks::class],
        'together' => ['className' => 'ChatTogetherAI', 'class' => ChatTogetherAI::class],
    ];

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function supportedProviders(): array
    {
        return array_keys(self::MODEL_PROVIDER_CONFIG);
    }

    public static function isSupported(string $provider): bool
    {
        return isset(self::MODEL_PROVIDER_CONFIG[$provider]);
    }

    /**
     * Port of `getChatModelByClassName`: the client class for a provider key, or for a class name when no
     * provider is given. Null when nothing matches.
     *
     * With a provider the lookup is direct, which sidesteps class-name collisions (`openai` and `langsmith`
     * both use `ChatOpenAI`).
     *
     * @return class-string<BaseChatModel>|null
     */
    public static function getChatModelByClassName(string $className, ?string $modelProvider = null): ?string
    {
        if ($modelProvider !== null && $modelProvider !== '') {
            return self::MODEL_PROVIDER_CONFIG[$modelProvider]['class'] ?? null;
        }

        foreach (self::MODEL_PROVIDER_CONFIG as $config) {
            if ($config['className'] === $className) {
                return $config['class'];
            }
        }

        return null;
    }

    /**
     * Port of `_inferModelProvider`: guess the provider from the model name.
     *
     * Faithful to upstream, this can name a provider the registry does not carry (`cohere`, `bedrock`,
     * `google-vertexai`, `mistralai`, `perplexity`); {@see self::create()} then reports it as unsupported
     * rather than as un-inferable.
     *
     * @example inferModelProvider('gpt-4') === 'openai'
     * @example inferModelProvider('claude-2') === 'anthropic'
     * @example inferModelProvider('unknown-model') === null
     */
    public static function inferModelProvider(string $modelName): ?string
    {
        foreach (['gpt-3', 'gpt-4', 'gpt-5', 'o1', 'o3', 'o4'] as $prefix) {
            if (str_starts_with($modelName, $prefix)) {
                return 'openai';
            }
        }

        return match (true) {
            str_starts_with($modelName, 'claude') => 'anthropic',
            str_starts_with($modelName, 'command') => 'cohere',
            str_starts_with($modelName, 'accounts/fireworks') => 'fireworks',
            str_starts_with($modelName, 'gemini') => 'google-vertexai',
            str_starts_with($modelName, 'amazon.') => 'bedrock',
            str_starts_with($modelName, 'mistral') => 'mistralai',
            str_starts_with($modelName, 'sonar'), str_starts_with($modelName, 'pplx') => 'perplexity',
            default => null,
        };
    }

    /**
     * Port of `_initChatModelHelper`: build the client for a model.
     *
     * @param array<string, mixed> $params constructor fields; a `modelProvider` key is ignored.
     */
    public static function create(?string $model, ?string $modelProvider = null, array $params = []): BaseChatModel
    {
        $provider = ($modelProvider !== null && $modelProvider !== '')
            ? $modelProvider
            : ($model !== null ? self::inferModelProvider($model) : null);

        if ($provider === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unable to infer model provider for { model: %s }, please specify modelProvider directly.',
                $model ?? 'undefined',
            ));
        }

        if (!self::isSupported($provider)) {
            throw new \InvalidArgumentException(sprintf(
                "Unsupported { modelProvider: %s }.\n\nSupported model providers are: %s",
                $provider,
                implode(', ', self::supportedProviders()),
            ));
        }

        unset($params['modelProvider']);
        $class = self::getChatModelByClassName(self::MODEL_PROVIDER_CONFIG[$provider]['className'], $provider);
        if ($class === null) {
            throw new \LogicException(sprintf('No chat model class is registered for "%s".', $provider));
        }

        $fields = array_merge($model !== null ? ['model' => $model] : [], $params);
        if ($provider === 'langsmith') {
            $fields = array_merge($fields, self::langSmithParams($fields));
            // Folded into `baseUrl` / `apiKey` above; the OpenAI client has no `configuration` field.
            unset($fields['configuration']);
        }

        return new $class($fields);
    }

    /**
     * Port of `getLangSmithChatModelParams`: the `langsmith` provider is `ChatOpenAI` aimed at the LangSmith
     * LLM gateway, always over the Responses API.
     *
     * Upstream hands the OpenAI SDK a `configuration.baseURL` root (`.../v1`). `ChatOpenAI` here takes one
     * full endpoint URL, and the Responses API is forced on, so the root has `/responses` appended.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function langSmithParams(array $fields): array
    {
        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];
        $explicitRoot = $configuration['baseURL'] ?? $configuration['baseUrl'] ?? null;
        $gateway = self::resolveLangSmithGatewayConfig(is_string($explicitRoot) ? $explicitRoot : null, 'v1');

        $apiKey = self::firstSet(
            $fields['apiKey'] ?? null,
            $configuration['apiKey'] ?? null,
            $gateway['apiKey'] ?? null,
            self::firstNonEmpty(
                Env::getEnvironmentVariable('LANGSMITH_GATEWAY_API_KEY'),
                Env::getEnvironmentVariable('LANGSMITH_API_KEY'),
            ),
        );

        $root = rtrim($gateway['baseURL'] ?? self::DEFAULT_LANGSMITH_GATEWAY . '/v1', '/');

        $params = [
            'baseUrl' => $root . '/responses',
            'useResponsesApi' => true,
        ];
        if ($apiKey !== null) {
            $params['apiKey'] = $apiKey;
        }

        return $params;
    }

    /**
     * Port of `resolveLangSmithGatewayConfig` (`@langchain/core/utils/gateway`).
     *
     * An explicit base URL always wins and carries no key. Otherwise `LANGSMITH_GATEWAY` switches the
     * gateway on (`true`/`1`/`yes` means the default host, any other value is the host) and the key comes
     * from `LANGSMITH_GATEWAY_API_KEY`, then `LANGSMITH_API_KEY`.
     *
     * @return array{baseURL?: string, apiKey?: string}
     */
    public static function resolveLangSmithGatewayConfig(?string $baseUrl, string $providerPath): array
    {
        if ($baseUrl !== null) {
            return ['baseURL' => $baseUrl];
        }

        $flag = Env::getEnvironmentVariable('LANGSMITH_GATEWAY');
        if ($flag === null || $flag === '' || in_array(strtolower($flag), ['false', '0', 'no'], true)) {
            return [];
        }

        $host = in_array(strtolower($flag), ['true', '1', 'yes'], true)
            ? self::DEFAULT_LANGSMITH_GATEWAY
            : rtrim($flag, '/');

        $resolved = ['baseURL' => $host . '/' . $providerPath];
        $apiKey = self::firstNonEmpty(
            Env::getEnvironmentVariable('LANGSMITH_GATEWAY_API_KEY'),
            Env::getEnvironmentVariable('LANGSMITH_API_KEY'),
        );
        if ($apiKey !== null) {
            $resolved['apiKey'] = $apiKey;
        }

        return $resolved;
    }

    /** JavaScript's `??`: the first candidate that is not null. */
    private static function firstSet(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /** JavaScript's `||` over strings: the first candidate that is not empty. */
    private static function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
