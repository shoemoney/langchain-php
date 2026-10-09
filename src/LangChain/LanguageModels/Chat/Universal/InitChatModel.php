<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Universal;

/**
 * Initialize a chat model from a model name and provider.
 *
 * Port of `initChatModel` from `langchain/src/chat_models/universal.ts`.
 *
 * ```php
 * $gpt = InitChatModel::init('openai:gpt-4o-mini', ['temperature' => 0.25]);
 * $any = InitChatModel::init(null, ['temperature' => 0, 'configurableFields' => ['model', 'apiKey']]);
 * $any->invoke('hi', new RunnableConfig(configurable: ['model' => 'claude-sonnet-4-5']));
 * ```
 *
 * Security note: `configurableFields => 'any'` lets fields such as `apiKey` and `baseUrl` be altered at run
 * time, which can redirect model requests to another service. Enumerate the fields when the configuration
 * comes from an untrusted source.
 *
 * The provider is read from a `provider:model` prefix (only for a provider the registry carries, so
 * `ollama:qwen2.5:14b` keeps its second colon), else from `modelProvider`, else inferred from the model
 * name. See {@see ModelProviders} for the providers that are available.
 */
final class InitChatModel
{
    private function __construct()
    {
    }

    /**
     * @param string|null $model  The model name, optionally prefixed `provider:`.
     * @param array{
     *     modelProvider?: string,
     *     configurableFields?: list<string>|'any'|null,
     *     configPrefix?: string,
     *     profile?: array<string, mixed>,
     * }&array<string, mixed> $fields Everything else is passed to the client's constructor.
     *
     * @throws \InvalidArgumentException when the provider cannot be inferred or is not supported.
     */
    public static function init(?string $model = null, array $fields = []): ConfigurableModel
    {
        $configurableFields = $fields['configurableFields'] ?? null;
        $configPrefix = (string) ($fields['configPrefix'] ?? '');
        $modelProvider = $fields['modelProvider'] ?? null;
        $profile = $fields['profile'] ?? null;
        $params = array_diff_key($fields, array_flip(['configurableFields', 'configPrefix', 'modelProvider', 'profile']));

        if ($modelProvider === null && $model !== null && str_contains($model, ':')) {
            [$provider, $rest] = explode(':', $model, 2);
            if (ModelProviders::isSupported($provider)) {
                $modelProvider = $provider;
                $model = $rest;
            }
        }

        if (($model === null || $model === '') && $configurableFields === null) {
            $configurableFields = ['model', 'modelProvider'];
        }
        if ($configPrefix !== '' && $configurableFields === null) {
            trigger_error(sprintf(
                '{ configPrefix: %s } has been set but no fields are configurable. Set { configurableFields: [...] } '
                . 'to specify the model params that are configurable.',
                $configPrefix,
            ), E_USER_WARNING);
        }

        if ($configurableFields === null) {
            $configurableModel = new ConfigurableModel(array_filter([
                'defaultConfig' => [...$params, 'model' => $model, 'modelProvider' => $modelProvider],
                'configPrefix' => $configPrefix,
                'profile' => $profile,
            ], static fn (mixed $v): bool => $v !== null));
        } else {
            if ($model !== null && $model !== '') {
                $params['model'] = $model;
            }
            if ($modelProvider !== null && $modelProvider !== '') {
                $params['modelProvider'] = $modelProvider;
            }
            $configurableModel = new ConfigurableModel(array_filter([
                'defaultConfig' => $params,
                'configPrefix' => $configPrefix,
                'configurableFields' => $configurableFields,
                'profile' => $profile,
            ], static fn (mixed $v): bool => $v !== null));
        }

        // Build the default client now, so a bad provider or a missing key fails here. Upstream does this
        // unconditionally; with neither a model nor a provider there is nothing to build until a call
        // supplies them through `configurable`, and upstream would throw a TypeError on `undefined`.
        $defaults = $configurableModel->defaultConfig;
        if (($defaults['model'] ?? null) !== null || ($defaults['modelProvider'] ?? null) !== null) {
            $configurableModel->getModelInstance();
        }

        return $configurableModel;
    }
}
