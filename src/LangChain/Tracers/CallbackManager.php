<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;

/**
 * Routes run lifecycle events to every interested handler.
 *
 * Port of `CallbackManager` from `@langchain/core/callbacks/manager`.
 *
 * The manager holds four parallel accumulators — handlers, inheritable
 * handlers, tags, metadata — and the `inherit` flag on each mutator decides
 * whether a change is local to this manager or propagates to children. That
 * single flag is what lets a caller scope a tag or a handler to exactly one
 * step of a chain instead of the whole subtree.
 *
 * `configure()` is the entry point components use: it merges inherited and local
 * settings into one manager, or returns null when there is nothing to observe
 * at all. Returning null matters — it is how a chain with no callbacks set
 * avoids allocating managers on every call.
 */
final class CallbackManager
{
    /** @var list<BaseCallbackHandler> */
    public array $handlers = [];

    /** @var list<BaseCallbackHandler> */
    public array $inheritableHandlers = [];

    /** @var list<string> */
    public array $tags = [];

    /** @var list<string> */
    public array $inheritableTags = [];

    /** @var array<string, mixed> */
    public array $metadata = [];

    /** @var array<string, mixed> */
    public array $inheritableMetadata = [];

    public string $name = 'callback_manager';

    public function __construct(
        public ?string $parentRunId = null,
        array $fields = [],
    ) {
        $this->handlers = array_values($fields['handlers'] ?? []);
        $this->inheritableHandlers = array_values($fields['inheritableHandlers'] ?? []);
        $this->tags = array_values($fields['tags'] ?? []);
        $this->inheritableTags = array_values($fields['inheritableTags'] ?? []);
        $this->metadata = $fields['metadata'] ?? [];
        $this->inheritableMetadata = $fields['inheritableMetadata'] ?? [];
    }

    public function getParentRunId(): ?string
    {
        return $this->parentRunId;
    }

    // ---- start hooks ------------------------------------------------------

    /**
     * One run manager per prompt.
     *
     * @param list<string>         $prompts
     * @param list<string>|null    $runId    Caller-supplied id for the FIRST prompt only.
     *                                       Later prompts get fresh ids, because two runs
     *                                       sharing an id would collide in every tracer's
     *                                       run map.
     * @param array<string, mixed> $extraParams
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @return list<CallbackManagerForLLMRun>
     */
    public function handleLLMStart(
        Serialized $llm,
        array $prompts,
        ?string $runId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): array {
        $managers = [];
        foreach (array_values($prompts) as $idx => $prompt) {
            $runId_ = $idx === 0 && $runId !== null ? $runId : RunId::v7();

            // The tracer's run map must be populated BEFORE the general hook
            // runs. A handler invoked in the same tick may already be looking
            // this run up by id, and a run that does not exist yet looks
            // identical to one that was never traced.
            $this->createRunForTracer('LLMStart', $runId_, fn (BaseTracer $t) => $t->createRunForLLMStart(
                $llm,
                [$prompt],
                $runId_,
                $this->parentRunId,
                $extraParams,
                $this->tags,
                $this->metadata,
                $runName,
            ));

            foreach ($this->handlers as $handler) {
                if ($handler->ignoreLLM) {
                    continue;
                }
                $this->dispatch(
                    $handler,
                    'handleLLMStart',
                    [$llm, [$prompt], $runId_, $this->parentRunId, $extraParams, $this->tags, $this->metadata, $runName],
                );
            }

            $managers[] = $this->runManagerForLLM($runId_);
        }

        return $managers;
    }

    /**
     * One run manager per message group.
     *
     * The fallback to `handleLLMStart` is the compatibility shim from the
     * original: a handler written against the older, string-only LLM hook still
     * receives chat runs, rendered as a transcript via `getBufferString()`.
     *
     * @param list<list<BaseMessage>> $messages
     * @param array<string, mixed>    $extraParams
     * @param list<string>            $tags
     * @param array<string, mixed>    $metadata
     * @return list<CallbackManagerForLLMRun>
     */
    public function handleChatModelStart(
        Serialized $llm,
        array $messages,
        ?string $runId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): array {
        $managers = [];
        foreach (array_values($messages) as $idx => $group) {
            $runId_ = $idx === 0 && $runId !== null ? $runId : RunId::v7();

            // See handleLLMStart: the run map is populated before any hook runs.
            $this->createRunForTracer('ChatModelStart', $runId_, fn (BaseTracer $t) => $t->createRunForChatModelStart(
                $llm,
                [$group],
                $runId_,
                $this->parentRunId,
                $extraParams,
                $this->tags,
                $this->metadata,
                $runName,
            ));

            foreach ($this->handlers as $handler) {
                if ($handler->ignoreLLM) {
                    continue;
                }
                if ($handler->implements('handleChatModelStart')) {
                    $this->dispatch($handler, 'handleChatModelStart', [
                        $llm, [$group], $runId_, $this->parentRunId, $extraParams, $this->tags, $this->metadata, $runName,
                    ]);
                } elseif ($handler->implements('handleLLMStart')) {
                    $this->dispatch($handler, 'handleLLMStart', [
                        $llm, [MessageUtils::getBufferString($group)], $runId_, $this->parentRunId,
                        $extraParams, $this->tags, $this->metadata, $runName,
                    ]);
                }
            }

            $managers[] = $this->runManagerForLLM($runId_);
        }

        return $managers;
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     */
    public function handleChainStart(
        Serialized $chain,
        array $inputs,
        ?string $runId = null,
        ?string $runType = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        array $extra = [],
    ): CallbackManagerForChainRun {
        $runId ??= RunId::v7();

        $this->createRunForTracer('ChainStart', $runId, fn (BaseTracer $t) => $t->createRunForChainStart(
            $chain,
            $inputs,
            $runId,
            $this->parentRunId,
            $this->tags,
            $this->metadata,
            $runType,
            $runName,
            $extra,
        ));

        foreach ($this->handlers as $handler) {
            if ($handler->ignoreChain) {
                continue;
            }
            $this->dispatch($handler, 'handleChainStart', [
                $chain, $inputs, $runId, $runType, $this->tags, $this->metadata, $runName, $this->parentRunId, $extra,
            ]);        }

        return new CallbackManagerForChainRun(
            $runId,
            $this->handlers,
            $this->inheritableHandlers,
            $this->tags,
            $this->inheritableTags,
            $this->metadata,
            $this->inheritableMetadata,
            $this->parentRunId,
        );
    }

    /**
     * @param array<string, mixed>|string $input
     * @param list<string>                $tags
     * @param array<string, mixed>        $metadata
     */
    public function handleToolStart(
        Serialized $tool,
        array|string $input,
        ?string $runId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $toolCallId = null,
    ): CallbackManagerForToolRun {
        $runId ??= RunId::v7();

        $this->createRunForTracer('ToolStart', $runId, fn (BaseTracer $t) => $t->createRunForToolStart(
            $tool,
            $input,
            $runId,
            $this->parentRunId,
            $this->tags,
            $this->metadata,
            $runName,
        ));

        foreach ($this->handlers as $handler) {
            if ($handler->ignoreAgent) {
                continue;
            }
            // The handler-facing input is always a string; the tracer-facing one
            // keeps the object so structured tool arguments survive into the run
            // record (which the tool tests assert on).
            $this->dispatch($handler, 'handleToolStart', [
                $tool,
                is_string($input) ? $input : json_encode($input, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                $runId,
                $this->parentRunId,
                $this->tags,
                $this->metadata,
                $runName,
                $toolCallId,
            ]);
        }

        return new CallbackManagerForToolRun(
            $runId,
            $this->handlers,
            $this->inheritableHandlers,
            $this->tags,
            $this->inheritableTags,
            $this->metadata,
            $this->inheritableMetadata,
            $this->parentRunId,
        );
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    public function handleRetrieverStart(
        Serialized $retriever,
        string $query,
        ?string $runId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): CallbackManagerForRetrieverRun {
        $runId ??= RunId::v7();

        $this->createRunForTracer('RetrieverStart', $runId, fn (BaseTracer $t) => $t->createRunForRetrieverStart(
            $retriever,
            $query,
            $runId,
            $this->parentRunId,
            $this->tags,
            $this->metadata,
            $runName,
        ));

        foreach ($this->handlers as $handler) {
            if ($handler->ignoreRetriever) {
                continue;
            }
            $this->dispatch($handler, 'handleRetrieverStart', [
                $retriever, $query, $runId, $this->parentRunId, $this->tags, $this->metadata, $runName,
            ]);
        }

        return new CallbackManagerForRetrieverRun(
            $runId,
            $this->handlers,
            $this->inheritableHandlers,
            $this->tags,
            $this->inheritableTags,
            $this->metadata,
            $this->inheritableMetadata,
            $this->parentRunId,
        );
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    public function handleCustomEvent(string $eventName, mixed $data, string $runId, array $tags = [], array $metadata = []): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreCustomEvent) {
                continue;
            }
            $this->dispatch($handler, 'handleCustomEvent', [$eventName, $data, $runId, $this->tags, $this->metadata]);
        }
    }

    // ---- mutation ---------------------------------------------------------

    public function addHandler(BaseCallbackHandler $handler, bool $inherit = true): void
    {
        $this->handlers[] = $handler;
        if ($inherit) {
            $this->inheritableHandlers[] = $handler;
        }
    }

    public function removeHandler(BaseCallbackHandler $handler): void
    {
        $this->handlers = array_values(array_filter($this->handlers, static fn (BaseCallbackHandler $h): bool => $h !== $handler));
        $this->inheritableHandlers = array_values(array_filter($this->inheritableHandlers, static fn (BaseCallbackHandler $h): bool => $h !== $handler));
    }

    /**
     * @param list<BaseCallbackHandler> $handlers
     */
    public function setHandlers(array $handlers, bool $inherit = true): void
    {
        $this->handlers = [];
        $this->inheritableHandlers = [];
        foreach ($handlers as $handler) {
            $this->addHandler($handler, $inherit);
        }
    }

    public function setHandler(BaseCallbackHandler $handler): void
    {
        $this->setHandlers([$handler]);
    }

    /**
     * @param list<string> $tags
     */
    public function addTags(array $tags, bool $inherit = true): void
    {
        // Deduplicate: `addTags` is idempotent in the original so that merging
        // an inherited tag list with a local one cannot double-count.
        $this->removeTags($tags);
        $this->tags = array_values(array_merge($this->tags, $tags));
        if ($inherit) {
            $this->inheritableTags = array_values(array_merge($this->inheritableTags, $tags));
        }
    }

    /**
     * @param list<string> $tags
     */
    public function removeTags(array $tags): void
    {
        $this->tags = array_values(array_filter($this->tags, static fn (string $t): bool => !in_array($t, $tags, true)));
        $this->inheritableTags = array_values(array_filter($this->inheritableTags, static fn (string $t): bool => !in_array($t, $tags, true)));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function addMetadata(array $metadata, bool $inherit = true): void
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        if ($inherit) {
            $this->inheritableMetadata = array_merge($this->inheritableMetadata, $metadata);
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function removeMetadata(array $metadata): void
    {
        foreach (array_keys($metadata) as $key) {
            unset($this->metadata[$key], $this->inheritableMetadata[$key]);
        }
    }

    /**
     * A manager sharing this one's inheritance decisions, plus extra handlers.
     *
     * The per-item inherit flags are re-derived rather than copied, which is what
     * makes a child's view of "inheritable" identical to its parent's — a child
     * must not start re-classifying handlers its parent had already decided
     * about.
     *
     * @param list<BaseCallbackHandler> $additionalHandlers
     */
    public function copy(array $additionalHandlers = [], bool $inherit = true): self
    {
        $manager = new self($this->parentRunId);

        foreach ($this->handlers as $handler) {
            $manager->addHandler($handler, in_array($handler, $this->inheritableHandlers, true));
        }
        foreach ($this->tags as $tag) {
            $manager->addTags([$tag], in_array($tag, $this->inheritableTags, true));
        }
        foreach ($this->metadata as $key => $value) {
            $manager->addMetadata([$key => $value], array_key_exists($key, $this->inheritableMetadata));
        }
        foreach ($additionalHandlers as $handler) {
            $manager->addHandler($handler, $inherit);
        }

        return $manager;
    }

    /**
     * Build a manager holding one handler assembled from a method bag.
     *
     * @param array<string, callable> $methods
     */
    public static function fromMethods(array $methods): self
    {
        $manager = new self();
        $manager->addHandler(CallbackHandler::fromMethods($methods));

        return $manager;
    }

    /**
     * Merge inherited and local callback configuration into one manager.
     *
     * Returns null when there is nothing at all to observe — no handlers, no
     * tags, no metadata, and neither verbose nor tracing enabled. That null is
     * load-bearing: components skip callback plumbing entirely when it is null,
     * which is the common case and the whole reason this method is worth
     * writing.
     *
     * @param list<BaseCallbackHandler>|self|null $inheritableHandlers
     * @param list<BaseCallbackHandler>|self|null $localHandlers
     * @param list<string>|null                    $inheritableTags
     * @param list<string>|null                    $localTags
     * @param array<string, mixed>|null            $inheritableMetadata
     * @param array<string, mixed>|null            $localMetadata
     * @param array{verbose?: bool, tracing?: bool} $options
     */
    public static function configure(
        array|self|null $inheritableHandlers = null,
        array|self|null $localHandlers = null,
        ?array $inheritableTags = null,
        ?array $localTags = null,
        ?array $inheritableMetadata = null,
        ?array $localMetadata = null,
        array $options = [],
    ): ?self {
        $manager = null;

        if ($inheritableHandlers !== null || $localHandlers !== null) {
            if (is_array($inheritableHandlers) || $inheritableHandlers === null) {
                $manager = new self();
                $manager->setHandlers($inheritableHandlers ?? [], true);
            } else {
                $manager = $inheritableHandlers;
            }

            $extra = is_array($localHandlers) ? $localHandlers : ($localHandlers?->handlers ?? []);
            $manager = $manager->copy($extra, false);
        }

        $verboseEnabled = ($options['verbose'] ?? false) === true
            || getenv('LANGCHAIN_VERBOSE') === 'true';
        $tracingEnabled = ($options['tracing'] ?? false) === true
            || getenv('LANGCHAIN_TRACING') !== false
            || getenv('LANGCHAIN_TRACING_V2') !== false;

        if ($verboseEnabled) {
            $manager ??= new self();
            if (!$manager->hasHandlerNamed('console_callback_handler')) {
                $manager->addHandler(new ConsoleCallbackHandler(), true);
            }
        }
        if ($tracingEnabled) {
            $manager ??= new self();
            if (!$manager->hasHandlerNamed('langchain_tracer')) {
                $manager->addHandler(new LangChainTracer(), true);
            }
        }

        if ($inheritableTags !== null || $localTags !== null) {
            if ($manager !== null) {
                $manager->addTags($inheritableTags ?? []);
                $manager->addTags($localTags ?? [], false);
            }
        }
        if ($inheritableMetadata !== null || $localMetadata !== null) {
            if ($manager !== null) {
                $manager->addMetadata($inheritableMetadata ?? []);
                $manager->addMetadata($localMetadata ?? [], false);
            }
        }

        return $manager;
    }

    /**
     * Whether a handler with this name is already attached.
     *
     * Public because it is how a caller checks whether tracing or verbosity is
     * already on — and therefore whether configuring it again would duplicate
     * the handler and double every log line.
     */
    public function hasHandlerNamed(string $name): bool
    {
        foreach ($this->handlers as $handler) {
            if ($handler->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Give every tracer handler the chance to record the run before the
     * user-facing hooks run.
     *
     * The ordering is deliberate and matches the original: a tracer must have
     * its run map populated synchronously, because a later hook in the same
     * tick may already be asking for that run by id. If the tracer recorded
     * inside its own `handleLLMStart`, a backgrounded or re-entrant handler
     * could look up a run that does not exist yet.
     *
     * @param callable(BaseTracer): void $create
     */
    private function createRunForTracer(string $method, string $runId, callable $create): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof BaseTracer) {
                $create($handler);
            }
        }
    }

    private function runManagerForLLM(string $runId): CallbackManagerForLLMRun
    {
        return new CallbackManagerForLLMRun(
            $runId,
            $this->handlers,
            $this->inheritableHandlers,
            $this->tags,
            $this->inheritableTags,
            $this->metadata,
            $this->inheritableMetadata,
            $this->parentRunId,
        );
    }

    /**
     * Invoke one start hook on one handler, containing its failures.
     *
     * @param list<mixed> $arguments
     */
    private function dispatch(BaseCallbackHandler $handler, string $method, array $arguments): void
    {
        try {
            $handler->{$method}(...$arguments);
        } catch (\Throwable $e) {
            if ($handler->raiseError) {
                throw $e;
            }
            BaseRunManager::recordHandlerError($handler, $method, $e);
        }
    }
}
