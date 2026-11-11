<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Utils\Config;

/**
 * PHP return-type and root-tracing adapter for source Pregel.withConfig's clone.
 *
 * The clone retains graph-bound defaults. PHP's generic binding must see those
 * defaults to emit its existing root callbacks, but its flattened tracing config
 * must not become the graph's caller config. An internal options snapshot retains
 * that origin and is consumed before engine execution; it never reaches nodes or
 * checkpoints. The PHP result remains a binding, without graph helper delegation.
 */
final class PregelConfigBinding extends RunnableBinding
{
    private const CALL_CONFIG = '__pregel_binding_call_config';

    public function __construct(Pregel $graph, private readonly ?RunnableConfig $defaults = null, array $configFactories = [])
    {
        parent::__construct($graph, config: $defaults === null ? null : get_object_vars($defaults), configFactories: $configFactories);
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return self::invokeWithConfig($this->bound, $input, $this->prepareCall($config));
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        return self::streamWithConfig($this->bound, $input, $this->prepareCall($config));
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return $this->bound->batch($inputs, $this->prepareCall($config), $options);
    }

    public function streamEvents(
        mixed $input,
        ?RunnableConfig $config = null,
        string $version = 'v2',
        array $streamOptions = [],
        ?string $encoding = null,
    ): \Generator {
        return $this->bound->streamEvents($input, $this->prepareCall($config), $version, $streamOptions, $encoding);
    }

    /** @param array<string, mixed> $config */
    public function withConfig(array $config): self
    {
        return $this->bound->withConfig($config);
    }

    /** @param array{onStart?: callable, onEnd?: callable, onError?: callable} $listeners */
    public function withListeners(array $listeners): self
    {
        return new self($this->bound, $this->defaults, [self::listenerConfigFactory($listeners)]);
    }

    private function prepareCall(?RunnableConfig $config): RunnableConfig
    {
        $public = Config::prepareGraphCall($config, $this->defaults);

        $trace = RunnableConfig::mergeConfigs($this->config, $public, $this->kwargs);
        $results = [];
        foreach ($this->configFactories as $factory) {
            // Match generic factory dispatch, but insert the private snapshot
            // afterward so factories/listener configs never receive that key.
            $results[] = $factory(clone $trace);
        }
        $merged = RunnableConfig::mergeConfigs($trace, ...$results);

        return $merged->with(['options' => array_replace($merged->options, [self::CALL_CONFIG => ['public' => $public, 'trace' => $trace]])]);
    }

    /** @internal Consumed only at the owned graph boundary, before LG composition. */
    public static function restoreCall(?RunnableConfig $config): ?RunnableConfig
    {
        $snapshot = $config?->options[self::CALL_CONFIG] ?? null;
        if (!is_array($snapshot) || !($snapshot['public'] ?? null) instanceof RunnableConfig || !($snapshot['trace'] ?? null) instanceof RunnableConfig) {
            return $config;
        }
        $public = clone $snapshot['public'];
        $trace = $snapshot['trace'];
        // Factories and event adapters may append callbacks or override fields
        // after tracing config is merged. Carry only those subsequent changes
        // back to the call, rather than relabel bound defaults as caller values.
        $public->callbacks = [...$public->callbacks, ...array_slice($config->callbacks, count($trace->callbacks))];
        // Generic LC tracing merges dedupe tags; the original public graph
        // call does not. Preserve its duplicates when restoring provenance.
        $public->tags = [...$public->tags, ...array_values(array_diff($config->tags, $trace->tags))];
        foreach (['metadata', 'configurable', 'options'] as $field) {
            foreach ($config->$field as $key => $value) {
                if ($field === 'options' && $key === self::CALL_CONFIG) {
                    continue;
                }
                if (!array_key_exists($key, $trace->$field) || $value !== $trace->{$field}[$key]) {
                    $public->{$field}[$key] = $value;
                }
            }
        }
        $overrides = [];
        foreach (['maxConcurrency', 'recursionLimit', 'verbose', 'runName', 'checkpointId', 'checkpointMap', 'toolCall', 'context', 'signal'] as $field) {
            if ($config->$field !== $trace->$field) {
                $overrides[$field] = $config->$field;
            }
        }
        // Root tracing supplies fresh child identity independently of defaults.
        return $public->with([...$overrides, 'runId' => $config->runId, 'runIdParent' => $config->runIdParent]);
    }
}
