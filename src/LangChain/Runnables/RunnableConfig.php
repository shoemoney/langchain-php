<?php

declare(strict_types=1);

namespace LangChain\Runnables;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\ContentBlock;
use LangChain\Schema\PromptValue;

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
    /**
     * @param list<string>              $tags       Trace labels, e.g. ['llm', 'openai'].
     * @param array<string, mixed>      $metadata   Arbitrary values surfaced in traces.
     * @param array<string, mixed>      $runId      Per-leg run ids assigned by the caller.
     * @param list<object>              $callbacks  Callback handlers (tracers, token counters).
     * @param int|null                  $maxConcurrency  Cap on parallel legs in a RunnableParallel.
     * @param int                       $recursionLimit  Max depth for self-referential graphs.
     * @param mixed                     $signal     An abort token; checked at each step boundary.
     * @param bool                      $verbose    Print the run tree to stdout.
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
        public int $recursionLimit = 25,
        public mixed $signal = null,
        public bool $verbose = false,
        public ?string $runName = null,
        public ?string $runIdParent = null,
        public ?string $checkpointId = null,
        public ?string $checkpointMap = null,
        public array $configurable = [],
        public array $options = [],
        public mixed $toolCall = null,
        public mixed $context = null,
    ) {
    }

    /**
     * Build from the loose associative shape callers write, ignoring keys the
     * active runnable does not understand rather than throwing — the TS version
     * is explicitly permissive here so a config authored for one runnable can
     * be replayed against another.
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

        return new self(
            tags: (array) $pick('tags', []),
            metadata: (array) $pick('metadata', []),
            runId: (array) $pick('run_id', []),
            callbacks: (array) $pick('callbacks', []),
            maxConcurrency: $pick('max_concurrency') === null ? null : (int) $pick('max_concurrency'),
            recursionLimit: (int) $pick('recursion_limit', 25),
            signal: $pick('signal'),
            verbose: (bool) $pick('verbose', false),
            runName: $pick('run_name') === null ? null : (string) $pick('run_name'),
            runIdParent: $pick('run_id_parent') === null ? null : (string) $pick('run_id_parent'),
            checkpointId: $pick('checkpoint_id') === null ? null : (string) $pick('checkpoint_id'),
            checkpointMap: $pick('checkpoint_map') === null ? null : (string) $pick('checkpoint_map'),
            configurable: (array) $pick('configurable', []),
            options: (array) $pick('options', []),
            toolCall: $pick('tool_call'),
            context: $pick('context'),
        );
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
            if ($value === null) {
                continue;
            }
            $prop = self::toCamelCase($key);
            if (property_exists($c, $prop)) {
                $c->{$prop} = $value;
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
     * Fields whose upstream merge rule is plain "a later config overwrites an earlier one".
     *
     * Upstream `mergeConfigs` in `libs/langchain-core/src/runnables/config.ts` iterates
     * `Object.keys(options)`, so only keys ACTUALLY PRESENT are copied. When a config arrives as an
     * array (which is how `StructuredTool::$defaultConfig` is supplied) this method keeps that
     * presence semantics with `array_key_exists()`; a `RunnableConfig` object cannot distinguish an
     * absent key from one set to its default, so all of its fields are treated as present.
     */
    private const LATER_WINS_FIELDS = [
        'runId', 'callbacks', 'maxConcurrency', 'recursionLimit', 'verbose',
        'runName', 'runIdParent', 'checkpointId', 'checkpointMap',
        'options', 'toolCall', 'context',
    ];

    /**
     * Mirrors upstream `mergeConfigs` from `libs/langchain-core/src/runnables/config.ts`.
     *
     * Upstream has no equivalent of a hand-written per-field list, so it inherits every config key by
     * construction. The per-key rules are:
     *
     *  - `metadata` / `configurable`: spread — a key set on either side survives;
     *  - `tags`: union of both, deduplicated, first-seen order preserved;
     *  - `signal`: combined;
     *  - every other key: overwritten by the later config.
     *
     * Upstream's `timeout` rule (`Math.min`) has no target here — `RunnableConfig` has no `timeout`
     * field — and upstream's `signal` combination uses `AbortSignal.any()`, which has no PHP
     * equivalent, so a later `signal` wins. See PORT_STATUS.md "Known non-exact behaviours".
     */
    public static function mergeConfigs(mixed ...$configs): self
    {
        $copy = new self();

        foreach ($configs as $config) {
            if ($config === null) {
                continue;
            }

            $isArray = is_array($config);
            $in = $isArray ? self::fromArray($config) : $config;
            if (! $in instanceof self) {
                continue;
            }

            $copy->metadata = array_merge($copy->metadata, $in->metadata);
            $copy->configurable = array_merge($copy->configurable, $in->configurable);
            $copy->tags = array_values(array_unique(array_merge($copy->tags, $in->tags)));

            if ($in->signal !== null) {
                $copy->signal = $in->signal;
            }

            foreach (self::LATER_WINS_FIELDS as $field) {
                if ($isArray) {
                    if (array_key_exists($field, $config)) {
                        $copy->$field = $in->$field;
                    }

                    continue;
                }

                $copy->$field = $in->$field;
            }
        }

        return $copy;
    }
}
