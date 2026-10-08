<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\Utils\Env;

/**
 * Endpoint and header helpers shared by the (Azure) OpenAI clients.
 *
 * Port of `utils/azure.ts` from `@langchain/openai`.
 */
final class Azure
{
    /** Upstream's `getHeadersWithUserAgent` default; the Azure clients pass 2.0.0. */
    public const DEFAULT_VERSION = '1.0.0';

    public const AZURE_VERSION = '2.0.0';

    private function __construct()
    {
    }

    /**
     * The endpoint URL for (Azure) OpenAI, from the configuration given.
     *
     * Port of `getEndpoint`. In order:
     *
     *  - key or token provider, plus `azureOpenAIBasePath` and a deployment:
     *    `{basePath}/{deployment}`;
     *  - key or token provider, plus `azureOpenAIEndpoint` and a deployment:
     *    `{endpoint}/openai/deployments/{deployment}`;
     *  - key or token provider alone: the instance name and the deployment are
     *    both required, and give `https://{instance}.openai.azure.com/openai/deployments/{deployment}`;
     *  - otherwise the custom `baseURL`, which may be null.
     *
     * @param array{
     *     azureOpenAIApiDeploymentName?: ?string,
     *     azureOpenAIApiInstanceName?: ?string,
     *     azureOpenAIApiKey?: ?string,
     *     azureADTokenProvider?: ?callable,
     *     azureOpenAIBasePath?: ?string,
     *     baseURL?: ?string,
     *     azureOpenAIEndpoint?: ?string,
     * } $config
     *
     * @throws \InvalidArgumentException when a key is configured without the names needed to build the URL.
     */
    public static function getEndpoint(array $config): ?string
    {
        $deployment = self::filled($config['azureOpenAIApiDeploymentName'] ?? null);
        $instance = self::filled($config['azureOpenAIApiInstanceName'] ?? null);
        $basePath = self::filled($config['azureOpenAIBasePath'] ?? null);
        $endpoint = self::filled($config['azureOpenAIEndpoint'] ?? null);
        $authenticated = self::filled($config['azureOpenAIApiKey'] ?? null) !== null
            || ($config['azureADTokenProvider'] ?? null) !== null;

        if ($authenticated && $basePath !== null && $deployment !== null) {
            return $basePath . '/' . $deployment;
        }
        if ($authenticated && $endpoint !== null && $deployment !== null) {
            return $endpoint . '/openai/deployments/' . $deployment;
        }

        if ($authenticated) {
            if ($instance === null) {
                throw new \InvalidArgumentException('azureOpenAIApiInstanceName is required when using azureOpenAIApiKey');
            }
            if ($deployment === null) {
                throw new \InvalidArgumentException('azureOpenAIApiDeploymentName is a required parameter when using azureOpenAIApiKey');
            }

            return 'https://' . $instance . '.openai.azure.com/openai/deployments/' . $deployment;
        }

        return $config['baseURL'] ?? null;
    }

    /**
     * Header names lowercased, as a `Headers` instance does, with non-string values dropped.
     *
     * Port of `normalizeHeaders`. A list of `[name, value]` pairs is accepted as
     * well as a name-keyed map; the PHP stand-in for the `Headers` instance is a
     * map, which is already normal.
     *
     * @param array<array-key, mixed>|null $headers
     *
     * @return array<string, string>
     */
    public static function normalizeHeaders(?array $headers): array
    {
        $out = [];
        foreach ($headers ?? [] as $name => $value) {
            if (is_array($value) && count($value) === 2 && array_is_list($value) && is_int($name)) {
                [$name, $value] = $value;
            }
            if (is_string($name) && is_string($value)) {
                $out[strtolower($name)] = $value;
            }
        }

        return $out;
    }

    /**
     * The runtime description in the `User-Agent`, e.g. `(php/8.5.0; Darwin; arm64)`.
     *
     * Port of `getFormattedEnv`.
     */
    public static function getFormattedEnv(): string
    {
        return '(' . Env::getEnv() . '/' . PHP_VERSION . '; ' . PHP_OS_FAMILY . '; ' . php_uname('m') . ')';
    }

    /**
     * The headers plus a library `User-Agent`.
     *
     * Port of `getHeadersWithUserAgent`. A caller-supplied user agent is appended
     * to ours and not carried twice: upstream normalises names to lowercase and
     * then looks the value up under `User-Agent`, so its append branch can never
     * be reached and a caller's value would sit beside ours.
     *
     * @param array<array-key, mixed>|null $headers
     *
     * @return array<string, string>
     */
    public static function getHeadersWithUserAgent(?array $headers, bool $isAzure = false, string $version = self::DEFAULT_VERSION): array
    {
        $normalized = self::normalizeHeaders($headers);
        $library = 'langchainjs' . ($isAzure ? '-azure' : '') . '-openai';
        $own = $library . '/' . $version . ' ' . self::getFormattedEnv();

        $existing = $normalized['user-agent'] ?? null;
        unset($normalized['user-agent']);

        return $normalized + ['User-Agent' => $existing !== null && $existing !== '' ? $own . $existing : $own];
    }

    private static function filled(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
