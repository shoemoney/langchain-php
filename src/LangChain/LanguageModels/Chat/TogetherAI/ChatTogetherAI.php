<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\TogetherAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\Utils\Env;

/**
 * Together AI chat model.
 *
 * Port of `ChatTogetherAI` from `@langchain/together-ai`.
 *
 * The Together AI chat API is OpenAI-compatible, minus `frequency_penalty`,
 * `presence_penalty`, `logit_bias` and `functions`, which are removed from every
 * request. As with the other OpenAI-compatible wrappers, `baseUrl` (or
 * `configuration['baseURL']`) is the API *root* and `/chat/completions` is
 * appended here. The key is read from `apiKey`, `togetherAIApiKey`, then
 * `TOGETHER_AI_API_KEY`.
 */
class ChatTogetherAI extends ChatOpenAICompletions
{
    public const TOGETHER_AI_BASE_URL = 'https://api.together.xyz/v1/';

    public const DEFAULT_TOGETHER_AI_CHAT_MODEL = 'mistralai/Mixtral-8x7B-Instruct-v0.1';

    /** Parameters the Together AI chat endpoint does not accept. */
    private const UNSUPPORTED_REQUEST_PARAMS = ['frequency_penalty', 'presence_penalty', 'logit_bias', 'functions'];

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        $apiKey = self::firstNonEmpty($fields['apiKey'] ?? null, $fields['togetherAIApiKey'] ?? null)
            ?? self::firstNonEmpty(Env::getEnvironmentVariable('TOGETHER_AI_API_KEY'));

        if ($apiKey === null) {
            throw new \InvalidArgumentException(
                'Together AI API key not found. Please set the TOGETHER_AI_API_KEY environment variable '
                . 'or pass the key into the "apiKey" field.'
            );
        }

        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];
        $root = (string) ($configuration['baseURL'] ?? $configuration['baseUrl'] ?? $fields['baseUrl'] ?? self::TOGETHER_AI_BASE_URL);
        unset($fields['configuration'], $fields['togetherAIApiKey']);

        parent::__construct(array_merge($fields, [
            'model' => self::firstNonEmpty($fields['model'] ?? null) ?? self::DEFAULT_TOGETHER_AI_CHAT_MODEL,
            'apiKey' => $apiKey,
            'baseUrl' => self::completionsUrl($root),
        ]));
    }

    public function llmType(): string
    {
        return 'togetherAI';
    }

    public function getName(): string
    {
        return 'ChatTogetherAI';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'together_ai'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['togetherAIApiKey' => 'TOGETHER_AI_API_KEY', 'apiKey' => 'TOGETHER_AI_API_KEY'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcAliases(): array
    {
        return ['togetherAIApiKey' => 'together_ai_api_key', 'apiKey' => 'together_ai_api_key'];
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
        return ['ls_provider' => 'together'] + parent::lsParams($options);
    }

    /**
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
