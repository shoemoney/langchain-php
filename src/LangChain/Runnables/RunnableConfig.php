<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Per-invocation settings threaded through a runnable chain.
 *
 * Port of `RunnableConfig` from `@langchain_core/runnables`.
 *
 * The config is how callers inject concerns a runnable should not own:
 * callbacks/tracing, abort signals, run metadata, concurrency limits, and
 * recursion limits. It is also how a parent runnable passes state down to its
 * children, which is what makes nested traces correct.
 */
final class RunnableConfig
{
    public int $recursionLimit = 25;

    public bool $verbose = false;

    /** Explicit fields retained when parsing loose config arrays. @var array<string, true> */
    private array $providedFields = [];

    /**
     * @param list<string>              $tags       Trace labels, e.g. ['llm', 'openai'].
     * @param array<string, mixed>      $metadata   Arbitrary values surfaced in traces.
     * @param array<string, mixed>      $runId      Per-leg run ids assigned by the caller.
     * @param list<object>              $callbacks  Callback handlers (tracers, token counters).
     * @param int|null                  $maxConcurrency  Cap on parallel legs in a RunnableParallel.
     * @param int|null                  $recursionLimit  Max depth for self-referential graphs; null uses 25.
     * @param mixed                     $signal     An abort token; checked at each step boundary.
     * @param bool|null                 $verbose    Print the run tree to stdout; null uses false.
     * @param string|null               $runName    Display name in traces.
     * @param string|null               $runIdParent Parent run id, for nesting.
     * @param string|null               $checkpointId    LangGraph checkpoint to resume from.
     * @param string|null               $checkpointMap   LangGraph checkpoint namespace.
     * @param string                    $configurable      Values exposed to tools/prompts.
     * @param array<string, mixed>      $options   Per-call options that are *not* runnable
     *                                  config — the TypeScript `CallOptions extends RunnableConfig`
     *                                  extras: `stop`, `maxRetries`, `tools`, `tool_choice`,
     *                                  `outputVersion`, `thrownErrorString`, and whatever a
     *                                  provider adds.
     * @param mixed                     $toolCall  The tool call that triggered this run, when the
     *                                  runnable is a tool. This is the `ToolRunnableConfig` in the
     *                                  TypeScript source; it is what lets a tool know which
     *                                  `tool_call_id` to stamp on the `ToolMessage` it returns.
     * @param mixed                     $context   Runtime context, forwarded to tools by agents.
     */
    public function __construct(
        public array $tags = [],
        public array $metadata = [],
        public array $runId = [],
        public array $callbacks = [],
        public ?int $maxConcurrency = null,
        ?int $recursionLimit = null,
        public mixed $signal = null,
        ?bool $verbose = null,
        public ?string $runName = null,
        public ?string $runIdParent = null,
        public ?string $checkpointId = null,
        public ?string $checkpointMap = null,
        public array $configurable = [],
        public array $options = [],
        public mixed $toolCall = null,
        public mixed $context = null,
    ) {
        $this->recursionLimit = $recursionLimit ?? 25;
        $this->verbose = $verbose ?? false;
        if ($recursionLimit !== null) {
            $this->providedFields['recursionLimit'] = true;
        }
        if ($verbose !== null) {
            $this->providedFields['verbose'] = true;
        }
    }

    /**
     * Build from the loose associative shape callers write. Extra CallOptions
     * keys remain in the options bag, matching the permissive TS config shape.
     *
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(self|array|null $config): ?self
    {
        if ($config === null) {
            return null;
        }
        if ($config instanceof self) {
            return $config;
        }

        $pick = static function (string $key, mixed $default = null) use ($config): mixed {
            // Accept both snake_case and the camelCase the TS SDK uses.
            $alt = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            foreach ([$key, $alt] as $k) {
                if (array_key_exists($k, $config)) {
                    return $config[$k];
                }
            }

            return $default;
        };

        $result = new self(
            tags: (array) $pick('tags', []),
            metadata: (array) $pick('metadata', []),
            runId: (array) $pick('run_id', []),
            callbacks: (array) $pick('callbacks', []),
            maxConcurrency: $pick('max_concurrency') === null ? null : (int) $pick('max_concurrency'),
            recursionLimit: $pick('recursion_limit') === null ? null : (int) $pick('recursion_limit'),
            signal: $pick('signal'),
            verbose: $pick('verbose') === null ? null : (bool) $pick('verbose'),
            runName: $pick('run_name') === null ? null : (string) $pick('run_name'),
            runIdParent: $pick('run_id_parent') === null ? null : (string) $pick('run_id_parent'),
            checkpointId: $pick('checkpoint_id') === null ? null : (string) $pick('checkpoint_id'),
            checkpointMap: $pick('checkpoint_map') === null ? null : (string) $pick('checkpoint_map'),
            configurable: (array) $pick('configurable', []),
            options: (array) $pick('options', []),
            toolCall: $pick('tool_call'),
            context: $pick('context'),
        );
        foreach ($config as $key => $value) {
            $field = self::toCamelCase((string) $key);
            if ($field !== 'providedFields' && property_exists($result, $field)) {
                // Source uses raw options[key] ?? copy[key]; a null must not
                // turn the constructor's non-null default into an explicit reset.
                if ($value !== null) {
                    $result->providedFields[$field] = true;
                }
            } else {
                $result->options[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Copy this config, overriding some fields.
     *
     * Child runnables must derive rather than mutate: the parent's config is
     * shared across parallel legs, and a mutation from one leg would leak into
     * its siblings.
     *
     * Keys may be given in snake_case or camelCase; both map onto the same
     * property, which is what lets a caller pass the loose array the TS SDK
     * accepts and get the same result.
     *
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        $c = clone $this;
        foreach ($overrides as $key => $value) {
            $prop = self::toCamelCase($key);
            if ($value === null) {
                continue;
            }
            if ($prop !== 'providedFields' && property_exists($c, $prop)) {
                $c->{$prop} = $value;
                $c->providedFields[$prop] = true;
            }
        }

        return $c;
    }

    private static function toCamelCase(string $key): string
    {
        if (!str_contains($key, '_')) {
            return $key;
        }

        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }

    /**
     * The config a child runnable inherits: same callbacks, fresh run identity.
     */
    public function forChild(?string $runName = null, ?string $runId = null): self
    {
        return $this->with([
            'run_name' => $runName ?? $this->runName,
            'run_id' => $runId === null ? [] : [$runId],
            'run_id_parent' => $this->runId[0] ?? null,
        ]);
    }

    /**
     * Per-field source merge rules. PHP's options bag represents the extra
     * top-level CallOptions fields, so it spreads independently by option key.
     * Unset nullable object fields do not erase a bound value; explicit array
     * presence is preserved, including empty run-id arrays.
     */
    public static function mergeConfigs(mixed ...$configs): self
    {
        $copy = new self();
        foreach ($configs as $config) {
            if ($config === null) {
                continue;
            }
            $in = is_array($config) ? self::fromArray($config) : $config;
            if (!$in instanceof self) {
                continue;
            }
            $copy->metadata = [...$copy->metadata, ...$in->metadata];
            $copy->configurable = [...$copy->configurable, ...$in->configurable];
            $copy->tags = array_values(array_unique([...$copy->tags, ...$in->tags]));
            $copy->callbacks = [...$copy->callbacks, ...$in->callbacks];
            foreach ($in->options as $key => $value) {
                if ($key === 'timeout' && is_numeric($value) && is_numeric($copy->options[$key] ?? null)) {
                    $copy->options[$key] = min($copy->options[$key], $value);
                    continue;
                }
                $copy->options[$key] = $value ?? ($copy->options[$key] ?? null);
            }
            foreach (['runId', 'maxConcurrency', 'recursionLimit', 'verbose', 'runName', 'runIdParent',
                'checkpointId', 'checkpointMap', 'toolCall', 'context'] as $field) {
                $value = $in->$field;
                if ($value === null) {
                    continue;
                }
                if (!isset($in->providedFields[$field]) && (($field === 'runId' && $value === [])
                    || ($field === 'recursionLimit' && $value === 25) || ($field === 'verbose' && $value === false))) {
                    continue;
                }
                $copy->$field = $value;
                $copy->providedFields[$field] = true;
            }
            if ($in->signal !== null) {
                if (is_callable($copy->signal) && is_callable($in->signal)) {
                    $left = $copy->signal;
                    $right = $in->signal;
                    $aborted = false;
                    $copy->signal = static function () use ($left, $right, &$aborted): bool {
                        $aborted = $aborted || $left() || $right();

                        return $aborted;
                    };
                } else {
                    $copy->signal = $in->signal;
                }
            }
        }

        return $copy;
    }
}
