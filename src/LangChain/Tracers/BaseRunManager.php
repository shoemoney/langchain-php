<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The per-run view of a callback manager.
 *
 * Port of `BaseRunManager` from `@langchain/core/callbacks/manager`.
 *
 * A manager holds many handlers; a run manager is the narrow window onto those
 * handlers for one specific run, carrying that run's id, tags, and metadata.
 * Models and tools receive one of these and call `handleLLMNewToken()` or
 * `handleToolEnd()` without knowing how many observers exist or how they are
 * configured.
 *
 * Two handler lists matter and are easy to conflate. `handlers` is everyone who
 * sees *this* run. `inheritableHandlers` is the subset that child runs also see,
 * which is why `getChild()` seeds from the second list and not the first: a
 * handler attached locally to one LLM call must not start receiving events from
 * a tool that call happens to invoke.
 */
abstract class BaseRunManager
{
    /** @var list<BaseCallbackHandler> Handlers that see this run. */
    public array $handlers;

    /** @var list<BaseCallbackHandler> The subset child runs inherit. */
    public array $inheritableHandlers;

    /** @var list<string> */
    public array $tags;

    /** @var list<string> */
    public array $inheritableTags;

    /** @var array<string, mixed> */
    public array $metadata;

    /** @var array<string, mixed> */
    public array $inheritableMetadata;

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $inheritableMetadata
     */
    public function __construct(
        public readonly string $runId,
        array $handlers = [],
        array $inheritableHandlers = [],
        array $tags = [],
        array $inheritableTags = [],
        array $metadata = [],
        array $inheritableMetadata = [],
        public readonly ?string $parentRunId = null,
    ) {
        $this->handlers = array_values($handlers);
        $this->inheritableHandlers = array_values($inheritableHandlers);
        $this->tags = array_values($tags);
        $this->inheritableTags = array_values($inheritableTags);
        $this->metadata = $metadata;
        $this->inheritableMetadata = $inheritableMetadata;
    }

    /**
     * A manager for a nested run, seeded with the inheritable observers.
     *
     * Note this seeds `handlers` from `inheritableHandlers`, not from the full
     * handler list — that is the whole point of the distinction.
     */
    public function getChild(?string $tag = null): CallbackManager
    {
        $manager = new CallbackManager($this->runId);
        $manager->setHandlers($this->inheritableHandlers);
        $manager->addTags($this->inheritableTags);
        $manager->addMetadata($this->inheritableMetadata);
        if ($tag !== null) {
            $manager->addTags([$tag], false);
        }

        return $manager;
    }

    /**
     * Free-form text within this run.
     */
    public function handleText(string $text): void
    {
        foreach ($this->handlers as $handler) {
            $this->dispatch($handler, 'handleText', [$text, $this->runId, $this->parentRunId, $this->tags]);
        }
    }

    /**
     * Broadcast an application-defined event.
     *
     * @param list<string> $tags
     */
    public function handleCustomEvent(string $eventName, mixed $data, array $tags = [], array $metadata = []): void
    {
        foreach ($this->handlers as $handler) {
            $this->dispatch($handler, 'handleCustomEvent', [$eventName, $data, $this->runId, $this->tags, $this->metadata]);
        }
    }

    /**
     * Invoke one hook on one handler, containing its failures.
     *
     * Port of the `try/catch + handler.raiseError` block that wraps every hook
     * call in the TypeScript manager. A throwing observer is recorded and
     * skipped unless it asked for `raiseError`, in which case the exception
     * propagates — because a handler that opts into raising is making a
     * statement about correctness, not decorating the run.
     *
     * The original logs with `console.warn`. This records instead of emitting a
     * PHP warning on purpose: `E_USER_WARNING` under a strict test runner is a
     * test failure, so a decorated-but-noisy handler would take down the suite
     * that was merely observing it. Read the recorded entries via
     * {@see self::handlerErrors()}.
     *
     * @param list<mixed> $arguments
     */
    protected function dispatch(BaseCallbackHandler $handler, string $method, array $arguments): void
    {
        try {
            $handler->{$method}(...$arguments);
        } catch (\Throwable $e) {
            self::recordHandlerError($handler, $method, $e);
            if ($handler->raiseError) {
                throw $e;
            }
        }
    }

    /** @var list<string> Every swallowed handler failure, for tests and debugging. */
    private static array $handlerErrors = [];

    /**
     * Record a swallowed handler failure without throwing it.
     *
     * Shared with {@see CallbackManager}, which does its own dispatch rather
     * than inheriting this class, so both paths land in the same record.
     */
    public static function recordHandlerError(BaseCallbackHandler $handler, string $method, \Throwable $e): void
    {
        self::$handlerErrors[] = sprintf('Error in handler %s, %s: %s', $handler::class, $method, $e->getMessage());
    }

    /** @return list<string> */
    public static function handlerErrors(): array
    {
        return self::$handlerErrors;
    }

    public static function clearHandlerErrors(): void
    {
        self::$handlerErrors = [];
    }
}
