<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Fireworks;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\Utils\Env;

/**
 * Fireworks chat model.
 *
 * Port of `ChatFireworks` from `@langchain/fireworks`.
 *
 * The Fireworks chat API is OpenAI-compatible with a smaller parameter
 * surface: `frequency_penalty`, `presence_penalty`, `logit_bias` and
 * `functions` are removed from every request instead of being sent and
 * rejected.
 *
 * `baseUrl` (and `configuration['baseURL']`, the TypeScript spelling) is the
 * API *root*, e.g. `https://api.fireworks.ai/inference/v1`; the
 * `/chat/completions` path is appended here, as the OpenAI SDK does upstream.
 * The key is read from `apiKey`, `fireworksApiKey`, the LangSmith Gateway, then
 * `FIREWORKS_API_KEY`.
 */
class ChatFireworks extends ChatOpenAICompletions
{
    public const FIREWORKS_BASE_URL = 'https://api.fireworks.ai/inference/v1';

    public const DEFAULT_FIREWORKS_CHAT_MODEL = 'accounts/fireworks/models/llama-v3p1-8b-instruct';

    private const GATEWAY_URL = 'https://gateway.smith.langchain.com';

    /** Parameters the Fireworks chat endpoint does not accept. */
    private const UNSUPPORTED_REQUEST_PARAMS = ['frequency_penalty', 'presence_penalty', 'logit_bias', 'functions'];

    public ?string $fireworksApiKey = null;

    /**
     * @param string|array<string, mixed> $modelOrFields A model name, or the full field bag.
     * @param array<string, mixed>        $fields        Used when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fields = [])
    {
        $fields = is_string($modelOrFields) ? $fields + ['model' => $modelOrFields] : $modelOrFields;

        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];
        $gateway = self::resolveGatewayConfig(
            $configuration['baseURL'] ?? $configuration['baseUrl'] ?? $fields['baseUrl'] ?? self::environmentBaseUrl(),
        );

        $apiKey = self::firstNonEmpty($fields['apiKey'] ?? null, $fields['fireworksApiKey'] ?? null)
            ?? self::firstNonEmpty($gateway['apiKey'] ?? null, Env::getEnvironmentVariable('FIREWORKS_API_KEY'));

        if ($apiKey === null) {
            throw new \InvalidArgumentException(
                'Fireworks API key not found. Please set the FIREWORKS_API_KEY environment variable '
                . 'or pass the key into "apiKey" or "fireworksApiKey".'
            );
        }

        $baseUrl = $gateway['baseURL'] ?? self::FIREWORKS_BASE_URL;
        unset($fields['configuration'], $fields['fireworksApiKey'], $fields['modelName']);

        parent::__construct(array_merge($fields, [
            'model' => self::firstNonEmpty($fields['model'] ?? null) ?? self::DEFAULT_FIREWORKS_CHAT_MODEL,
            'apiKey' => $apiKey,
            'baseUrl' => self::completionsUrl($baseUrl),
            'streamUsage' => false,
        ]));

        $this->fireworksApiKey = $apiKey;
    }

    public function llmType(): string
    {
        return 'fireworks';
    }

    public function getName(): string
    {
        return 'ChatFireworks';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'fireworks'];
    }

    /**
     * Secret constructor fields and the environment variable each one reads.
     *
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['fireworksApiKey' => 'FIREWORKS_API_KEY', 'apiKey' => 'FIREWORKS_API_KEY'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function getLsParams(array $options = []): array
    {
        return $this->lsParams($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        return ['ls_provider' => 'fireworks'] + parent::lsParams($options);
    }

    /**
     * Port of `completionWithRetry`: drop what Fireworks does not support, then send.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    protected function post(array $params): array
    {
        return parent::post(array_diff_key($params, array_flip(self::UNSUPPORTED_REQUEST_PARAMS)));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function postStream(array $params): \Generator
    {
        yield from parent::postStream(array_diff_key($params, array_flip(self::UNSUPPORTED_REQUEST_PARAMS)));
    }

    /**
     * The API root with the Chat Completions path appended exactly once.
     */
    public static function completionsUrl(string $baseUrl): string
    {
        $root = rtrim($baseUrl, '/');

        return str_ends_with($root, '/chat/completions') ? $root : $root . '/chat/completions';
    }

    private static function environmentBaseUrl(): ?string
    {
        return self::firstNonEmpty(
            Env::getEnvironmentVariable('FIREWORKS_API_BASE'),
            Env::getEnvironmentVariable('FIREWORKS_BASE_URL'),
        );
    }

    /**
     * Port of `resolveLangSmithGatewayConfig` for the `fireworks` provider path.
     *
     * An explicit base URL always wins and carries no gateway key.
     *
     * @return array{baseURL?: string, apiKey?: string}
     */
    private static function resolveGatewayConfig(mixed $baseUrl): array
    {
        if (is_string($baseUrl) && $baseUrl !== '') {
            return ['baseURL' => $baseUrl];
        }

        $flag = Env::getEnvironmentVariable('LANGSMITH_GATEWAY');
        if ($flag === null || $flag === '' || in_array(strtolower($flag), ['false', '0', 'no'], true)) {
            return [];
        }

        $root = in_array(strtolower($flag), ['true', '1', 'yes'], true) ? self::GATEWAY_URL : rtrim($flag, '/');
        $apiKey = self::firstNonEmpty(
            Env::getEnvironmentVariable('LANGSMITH_GATEWAY_API_KEY'),
            Env::getEnvironmentVariable('LANGSMITH_API_KEY'),
        );

        return array_filter(['baseURL' => $root . '/fireworks', 'apiKey' => $apiKey], static fn (mixed $v): bool => $v !== null);
    }

    private static function firstNonEmpty(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
