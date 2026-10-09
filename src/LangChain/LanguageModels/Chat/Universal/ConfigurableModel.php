<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model whose provider, model and parameters can be decided per call.
 *
 * Port of `ConfigurableModel` from `langchain/src/chat_models/universal.ts`; build one with
 * {@see InitChatModel::init()}. It holds a default configuration and, on each call, merges in the entries
 * of `RunnableConfig::$configurable` that are allowed to override it, builds the real client for that
 * configuration (once per distinct configuration) and delegates to it.
 *
 * `bindTools()` and `withStructuredOutput()` do not touch a model: they return a NEW wrapper with the call
 * queued, to be replayed on the real client when it is built. Neither mutates the receiver.
 *
 * Because every call delegates to the built client, tracing, streaming and caching are that client's, and
 * the wrapper itself emits no run of its own.
 */
class ConfigurableModel extends BaseChatModel implements ConfigurableModelInterface
{
    /**
     * The parameters used when no configurable value overrides them.
     *
     * @var array<string, mixed>
     */
    public array $defaultConfig = [];

    /**
     * Which `configurable` keys may override the defaults: `'any'` or a list of names.
     *
     * @var list<string>|'any'
     */
    public array|string $configurableFields = 'any';

    /** The prefix (always ending in `_` when set) the configurable keys carry. */
    public string $configPrefix = '';

    /**
     * Methods to replay on the built client, in order. The key is the method name, the value its arguments.
     *
     * @var array<string, mixed>
     */
    public array $queuedMethodOperations = [];

    /** @var array<string, RunnableInterface> */
    private array $modelInstanceCache = [];

    /** @var array<string, mixed>|null */
    private ?array $profileOverride;

    /**
     * @param array{
     *     defaultConfig?: array<string, mixed>,
     *     configurableFields?: list<string>|'any',
     *     configPrefix?: string,
     *     queuedMethodOperations?: array<string, mixed>,
     *     profile?: array<string, mixed>,
     * }&array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->defaultConfig = $fields['defaultConfig'] ?? [];

        $configurable = $fields['configurableFields'] ?? null;
        $this->configurableFields = $configurable === 'any'
            ? 'any'
            : (is_array($configurable) ? array_values($configurable) : ['model', 'modelProvider']);

        $prefix = (string) ($fields['configPrefix'] ?? '');
        $this->configPrefix = $prefix === '' ? '' : (str_ends_with($prefix, '_') ? $prefix : $prefix . '_');

        $this->queuedMethodOperations = $fields['queuedMethodOperations'] ?? $this->queuedMethodOperations;
        $this->profileOverride = $fields['profile'] ?? null;

        $this->metadata = [...$this->metadata, 'ls_integration' => 'langchain_init_chat_model'];
    }

    public function llmType(): string
    {
        return 'chat_model';
    }

    /**
     * @return array<string, mixed>
     */
    public function getQueuedMethodOperations(): array
    {
        return $this->queuedMethodOperations;
    }

    /**
     * Port of `_getModelInstance`: the client for this configuration, with the queued operations applied.
     */
    public function getModelInstance(?RunnableConfig $config = null): RunnableInterface
    {
        $cacheKey = $this->getCacheKey($config);
        if (isset($this->modelInstanceCache[$cacheKey])) {
            return $this->modelInstanceCache[$cacheKey];
        }

        $params = array_merge($this->defaultConfig, $this->modelParams($config));
        $model = $params['model'] ?? null;
        $provider = $params['modelProvider'] ?? null;
        if ($model === null) {
            unset($params['model']);
        }

        $instance = ModelProviders::create(
            \is_string($model) ? $model : null,
            \is_string($provider) ? $provider : null,
            $params,
        );

        foreach ($this->queuedMethodOperations as $method => $args) {
            if (method_exists($instance, $method)) {
                $instance = $instance->{$method}(...array_values($args));
            }
        }

        return $this->modelInstanceCache[$cacheKey] = $instance;
    }

    /**
     * Whether a client for the default configuration has been built. {@see InitChatModel::init()} builds one
     * eagerly so a bad provider or a missing key fails at construction, not at the first call.
     */
    public function hasDefaultModelInstance(): bool
    {
        return isset($this->modelInstanceCache[$this->getCacheKey(null)]);
    }

    /**
     * @param list<\LangChain\Messages\BaseMessage> $messages
     * @param array<string, mixed>                  $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $model = $this->getModelInstance(RunnableConfig::fromArray($options));
        if (!$model instanceof BaseChatModel) {
            throw new \LogicException(sprintf(
                'The queued operations turned the model into a %s, which cannot generate chat results directly.',
                $model::class,
            ));
        }

        // `_generate` is protected on the built client; BaseChatModel is the scope that declares it.
        return \Closure::bind(
            fn (): ChatResult => $this->generate($messages, $options, $runManager),
            $model,
            BaseChatModel::class,
        )();
    }

    /**
     * Queue `bindTools` and return a new wrapper. The receiver is untouched.
     *
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $queued = $this->queuedMethodOperations;
        $queued['bindTools'] = [$tools, $kwargs];

        return $this->withQueuedOperations($queued);
    }

    /**
     * Queue `withStructuredOutput` and return a new wrapper. The receiver is untouched.
     *
     * The wrapper returned is a runnable whose output is the parsed value, once the client is built.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        $queued = $this->queuedMethodOperations;
        $queued['withStructuredOutput'] = [$schema, $config];

        return $this->withQueuedOperations($queued);
    }

    /**
     * @param array<string, mixed> $queued
     */
    private function withQueuedOperations(array $queued): static
    {
        return new static([
            'defaultConfig' => $this->defaultConfig,
            'configurableFields' => $this->configurableFields,
            'configPrefix' => $this->configPrefix,
            'queuedMethodOperations' => $queued,
        ]);
    }

    /**
     * Port of `_modelParams`: the `configurable` entries that carry this model's prefix, prefix removed, and
     * limited to `configurableFields` unless that is `'any'`.
     *
     * @param RunnableConfig|array<string, mixed>|null $config
     *
     * @return array<string, mixed>
     */
    public function modelParams(RunnableConfig|array|null $config = null): array
    {
        $configurable = $config instanceof RunnableConfig ? $config->configurable : (array) ($config['configurable'] ?? []);

        $params = [];
        foreach ($configurable as $key => $value) {
            $key = (string) $key;
            if (str_starts_with($key, $this->configPrefix)) {
                $params[$this->removePrefix($key, $this->configPrefix)] = $value;
            }
        }

        if ($this->configurableFields !== 'any') {
            $params = array_filter(
                $params,
                fn (string $key): bool => \in_array($key, $this->configurableFields, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $params;
    }

    public function removePrefix(string $str, string $prefix): string
    {
        return str_starts_with($str, $prefix) ? substr($str, \strlen($prefix)) : $str;
    }

    /**
     * Bind a config to this model.
     *
     * Port of `withConfig`: the `configurable` entries that belong to the model are folded into the default
     * configuration of a fresh wrapper, and the whole config is bound around it.
     *
     * @param array<string, mixed> $config
     */
    public function withConfig(array $config): RunnableBinding
    {
        $newConfigurableModel = new static([
            'defaultConfig' => array_merge($this->defaultConfig, $this->modelParams($config)),
            'configurableFields' => $this->configurableFields,
            'configPrefix' => $this->configPrefix,
            'queuedMethodOperations' => $this->queuedMethodOperations,
        ]);

        return new RunnableBinding($newConfigurableModel, [], $config);
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $this->getModelInstance($config)->invoke($input, $config ?? new RunnableConfig());
    }

    /**
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->getModelInstance($config)->stream($input, $config ?? new RunnableConfig());
    }

    /**
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        yield from $this->getModelInstance($config)->transform($input, $config ?? new RunnableConfig());
    }

    /**
     * @param array<string, mixed> $streamOptions
     *
     * @return \Generator<int, \LangChain\Tracers\RunLogPatch>
     */
    public function streamLog(mixed $input, ?RunnableConfig $config = null, array $streamOptions = []): \Generator
    {
        $model = $this->getModelInstance($config);
        if (!$model instanceof Runnable) {
            throw new \LogicException(sprintf('%s does not support streamLog().', $model::class));
        }

        yield from $model->streamLog($input, $config ?? new RunnableConfig(), $streamOptions);
    }

    /**
     * The built client is resolved lazily, when the stream is first read, as upstream does.
     *
     * @param array<string, mixed> $streamOptions
     *
     * @return \Generator<int, \LangChain\Tracers\StreamEvent|string>
     */
    public function streamEvents(
        mixed $input,
        ?RunnableConfig $config = null,
        string $version = 'v2',
        array $streamOptions = [],
        ?string $encoding = null,
    ): \Generator {
        return (function () use ($input, $config, $version, $streamOptions, $encoding): \Generator {
            $model = $this->getModelInstance($config);
            if (!$model instanceof Runnable) {
                throw new \LogicException(sprintf('%s does not support streamEvents().', $model::class));
            }

            yield from $model->streamEvents($input, $config ?? new RunnableConfig(), $version, $streamOptions, $encoding);
        })();
    }

    /**
     * Port of `get profile()`: the override given at construction, else the built default client's profile.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        if ($this->profileOverride !== null) {
            return $this->profileOverride;
        }

        $instance = $this->modelInstanceCache[$this->getCacheKey(null)] ?? null;

        return $instance !== null && method_exists($instance, 'profile') ? (array) $instance->profile() : [];
    }

    /**
     * Port of `_getCacheKey`: the whole config is stringified, `__pregel_*` keys of `configurable` skipped.
     * Fields still at their default are left out, so no config and an untouched `RunnableConfig` share a key
     * (upstream's `{}`). `callbacks`, `signal` and the run ids are per-call objects or identifiers that never
     * pick a client; keying them would give every call its own instance, so they are skipped.
     */
    public function getCacheKey(?RunnableConfig $config = null): string
    {
        $toStringify = [];
        if ($config !== null) {
            $defaults = new RunnableConfig();
            foreach (get_object_vars($config) as $field => $value) {
                if (\in_array($field, ['callbacks', 'signal', 'runId', 'runIdParent'], true)
                    || $value === ($defaults->{$field} ?? null)) {
                    continue;
                }
                if ($field === 'configurable') {
                    $value = array_filter(
                        $value,
                        static fn (int|string $key): bool => !str_starts_with((string) $key, '__pregel_'),
                        ARRAY_FILTER_USE_KEY,
                    );
                    if ($value === []) {
                        continue;
                    }
                }
                $toStringify[$field] = $value;
            }
        }

        return (string) json_encode(self::keyable($toStringify), JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** Objects have no stable JSON form; identity stands in for them. */
    private static function keyable(mixed $value): mixed
    {
        if (\is_object($value)) {
            return $value::class . '#' . spl_object_id($value);
        }
        if (\is_array($value)) {
            return array_map(self::keyable(...), $value);
        }

        return $value;
    }
}
