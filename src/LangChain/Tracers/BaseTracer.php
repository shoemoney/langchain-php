<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\BaseMessage;
use LangChain\Schema\Document;
use LangChain\Utils\Js;

/**
 * A callback handler that assembles runs into a tree and files them somewhere.
 *
 * Port of `BaseTracer` from `@langchain/core/tracers/base`.
 *
 * The two-phase protocol is the whole idea. `handle*Start` records a run and
 * leaves it in an in-memory map, because the run is not complete and persisting
 * a half-finished run is worse than persisting none. `_endTrace` then either
 * hands the run up to its still-open parent (where it becomes a child) or, if
 * there is no parent, persists it — which is why the root run is the only one a
 * subclass ever sees in `persistRun()`.
 *
 * That indirection is not incidental. A chain that calls a model and then a
 * tool has three runs; only the chain is persisted, with the other two nested
 * inside it. Persisting on every end would produce three sibling runs and lose
 * the causality.
 *
 * The `_create*` methods are separate from the `handle*` methods because the
 * callback manager must populate the map *before* invoking the general hook.
 * The original comments this as avoiding a race with backgrounded callbacks; in
 * this synchronous port it matters for a simpler reason — a handler running in
 * the same tick may already be looking the run up by id.
 */
abstract class BaseTracer extends BaseCallbackHandler
{
    /** @var array<string, Run> Open runs, keyed by run id. */
    protected array $runMap = [];

    /**
     * Persist a completed root run.
     *
     * @param Run $run A run with no open parent — its children are already nested
     *        inside it.
     */
    abstract protected function persistRun(Run $run): void;

    protected function getRunById(?string $runId): ?Run
    {
        return $runId === null ? null : ($this->runMap[$runId] ?? null);
    }

    /**
     * Render an error the way a trace wants it: message plus stack.
     *
     * Non-throwables pass through their string form, matching the original's
     * three-way branch.
     */
    protected function stringifyError(mixed $error): string
    {
        if ($error instanceof \Throwable) {
            return $error->getMessage() . ($error->getTraceAsString() !== '' ? "\n\n" . $error->getTraceAsString() : '');
        }

        if (is_string($error)) {
            return $error;
        }

        return is_scalar($error) ? (string) $error : get_debug_type($error);
    }

    /**
     * The position this run takes among its siblings.
     *
     * A run with no known parent is 1; otherwise it follows its parent's highest
     * child execution order. Monotonicity matters because dotted order is
     * derived from it and children must sort after parents.
     */
    protected function getExecutionOrder(?string $parentRunId): int
    {
        $parentRun = $parentRunId !== null ? $this->getRunById($parentRunId) : null;
        if ($parentRun === null) {
            return 1;
        }

        return $parentRun->childExecutionOrder + 1;
    }

    /**
     * File the run: attach to an open parent, or persist if it is a root.
     *
     * Also removes the run from the open map either way, so a completed run is
     * never persisted twice and never mistaken for still-open.
     */
    protected function endTrace(Run $run): void
    {
        $parentRun = $run->parentRunId !== null ? $this->getRunById($run->parentRunId) : null;
        if ($parentRun !== null) {
            $parentRun->childExecutionOrder = max($parentRun->childExecutionOrder, $run->childExecutionOrder);
        } else {
            $this->persistRun($run);
        }

        $this->onRunUpdate($run);
        unset($this->runMap[$run->id]);
    }

    // ---- run creation -----------------------------------------------------

    /**
     * @param list<string>         $prompts
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extraParams
     */
    public function createRunForLLMStart(
        Serialized $llm,
        array $prompts,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): Run {
        return $this->addRunToRunMap($this->newRun(
            $runId,
            $parentRunId,
            'llm',
            $llm,
            ['prompts' => $prompts],
            $extraParams,
            $tags,
            $metadata,
            $name,
        ));
    }

    /**
     * @param list<list<BaseMessage>> $messages
     * @param list<string>             $tags
     * @param array<string, mixed>     $metadata
     * @param array<string, mixed>     $extraParams
     */
    public function createRunForChatModelStart(
        Serialized $llm,
        array $messages,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): Run {
        return $this->addRunToRunMap($this->newRun(
            $runId,
            $parentRunId,
            'llm',
            $llm,
            ['messages' => $messages],
            $extraParams,
            $tags,
            $metadata,
            $name,
        ));
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $extra
     */
    public function createRunForChainStart(
        Serialized $chain,
        array $inputs,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runType = null,
        ?string $name = null,
        array $extra = [],
    ): Run {
        return $this->addRunToRunMap($this->newRun(
            $runId,
            $parentRunId,
            $runType ?? 'chain',
            $chain,
            $inputs,
            $extra,
            $tags,
            $metadata,
            $name,
        ));
    }

    /**
     * @param array<string, mixed>|string $input
     * @param list<string>                $tags
     * @param array<string, mixed>        $metadata
     */
    public function createRunForToolStart(
        Serialized $tool,
        array|string $input,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): Run {
        return $this->addRunToRunMap($this->newRun(
            $runId,
            $parentRunId,
            'tool',
            $tool,
            is_string($input) ? ['input' => $input] : $input,
            [],
            $tags,
            $metadata,
            $name,
        ));
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    public function createRunForRetrieverStart(
        Serialized $retriever,
        string $query,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): Run {
        return $this->addRunToRunMap($this->newRun(
            $runId,
            $parentRunId,
            'retriever',
            $retriever,
            ['query' => $query],
            [],
            $tags,
            $metadata,
            $name,
        ));
    }

    /**
     * Build a fresh run and register it in the open-run map.
     *
     * Metadata is folded into `extra` rather than kept beside it because that
     * is where a trace consumer looks for it — LangSmith's schema has no
     * top-level `metadata` on a run.
     *
     * @param array<string, mixed> $inputs
     * @param array<string, mixed> $extraParams
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     */
    private function newRun(
        string $runId,
        ?string $parentRunId,
        string $runType,
        Serialized $component,
        array $inputs,
        array $extraParams,
        array $tags,
        array $metadata,
        ?string $name,
    ): Run {
        $executionOrder = $this->getExecutionOrder($parentRunId);
        $startTime = (float) (int) (microtime(true) * 1000);

        $extra = $extraParams;
        if ($metadata !== []) {
            $extra['metadata'] = $metadata;
        }
        if ($name !== null) {
            $extra['__name'] = $name;
        }

        $run = new Run(
            id: $runId,
            parentRunId: $parentRunId,
            startTime: $startTime,
            serialized: $component->toArray(),
            inputs: $inputs,
            executionOrder: $executionOrder,
            childExecutionOrder: $executionOrder,
            runType: $runType,
            extra: $extra,
            tags: $tags,
        );
        $run->pushEvent('start');

        return $run;
    }

    /**
     * Register a run, nesting it under its parent when that parent is open.
     *
     * The orphan branch is the subtle one. A child whose parent is not in the
     * map (because the parent already ended, or a handler was attached mid-run)
     * cannot be given a dotted order — and a run with a `parent_run_id` but no
     * `dotted_order` is rejected outright by LangSmith with a 400. So the
     * parent link is dropped and the run is shown as an isolated root. That is
     * a lossy but loadable outcome; the alternative is an unpersistable one.
     */
    public function addRunToRunMap(Run $run): Run
    {
        $currentDottedOrder = Run::dottedOrder($run->startTime, $run->id, $run->executionOrder);
        $parentRun = $this->getRunById($run->parentRunId);

        if ($run->parentRunId !== null) {
            if ($parentRun !== null) {
                $parentRun->childRuns[] = $run;
                $parentRun->childExecutionOrder = max($parentRun->childExecutionOrder, $run->childExecutionOrder);
                $run->traceId = $parentRun->traceId;

                if ($parentRun->dottedOrder !== null) {
                    $run->dottedOrder = $parentRun->dottedOrder . '.' . $currentDottedOrder;
                    $run->serializedStartTime = Run::microsecondPrecisionDatestring($run->startTime, $run->executionOrder);
                }
            } else {
                $run->parentRunId = null;
            }
        }

        if ($run->dottedOrder === null) {
            $run->traceId = $run->id;
            $run->dottedOrder = $currentDottedOrder;
            $run->serializedStartTime = Run::microsecondPrecisionDatestring($run->startTime, $run->executionOrder);
        }

        $this->runMap[$run->id] = $run;

        return $run;
    }

    // ---- hooks ------------------------------------------------------------

    /**
     * @param list<string>         $prompts
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     * @param array<string, mixed> $metadata
     */
    public function handleLLMStart(
        Serialized $llm,
        array $prompts,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): void {
        $run = $this->getRunById($runId)
            ?? $this->createRunForLLMStart($llm, $prompts, $runId, $parentRunId, $extraParams, $tags, $metadata, $runName);

        $this->onRunCreate($run);
        $this->onLLMStart($run);
    }

    /**
     * @param list<list<BaseMessage>> $messages
     * @param list<string>             $tags
     * @param array<string, mixed>     $extraParams
     * @param array<string, mixed>     $metadata
     */
    public function handleChatModelStart(
        Serialized $llm,
        array $messages,
        string $runId,
        ?string $parentRunId = null,
        array $extraParams = [],
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
    ): void {
        $run = $this->getRunById($runId)
            ?? $this->createRunForChatModelStart($llm, $messages, $runId, $parentRunId, $extraParams, $tags, $metadata, $runName);

        $this->onRunCreate($run);
        $this->onLLMStart($run);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMEnd(
        LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'llm') {
            throw new \RuntimeException('No LLM run to end.');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->outputs = $output->toArray();
        $run->pushEvent('end');
        $run->extra = array_merge($run->extra, $extraParams);

        $this->onLLMEnd($run);
        $this->endTrace($run);
    }

    /**
     * @param list<string>         $tags
     * @param array<string, mixed> $extraParams
     */
    public function handleLLMError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'llm') {
            throw new \RuntimeException('No LLM run to end.');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->error = $this->stringifyError($error);
        $run->pushEvent('error');
        $run->extra = array_merge($run->extra, $extraParams);

        $this->onLLMError($run);
        $this->endTrace($run);
    }

    /**
     * @param array<string, mixed> $inputs
     * @param list<string>         $tags
     * @param array<string, mixed> $metadata
     *
     * The parameter order follows {@see BaseCallbackHandler::handleChainStart()},
     * not the order the callback manager happens to have the values in. Matching
     * the handler is what matters: a tracer that declares a different order
     * silently receives a parent's tags where a run type belongs.
     */
    public function handleChainStart(
        Serialized $chain,
        array $inputs,
        string $runId,
        ?string $runType = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $parentRunId = null,
        array $extra = [],
    ): void {
        $run = $this->getRunById($runId)
            ?? $this->createRunForChainStart($chain, $inputs, $runId, $parentRunId, $tags, $metadata, $runType, $runName);

        $this->onRunCreate($run);
        $this->onChainStart($run);
    }

    /**
     * @param array<string, mixed>       $outputs
     * @param array{inputs?: mixed}      $kwargs
     * @param list<string>               $tags
     */
    public function handleChainEnd(
        array $outputs,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        $run = $this->getRunById($runId);
        if ($run === null) {
            throw new \RuntimeException('No chain run to end.');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->outputs = self::coerceToDict($outputs, 'output');
        $run->pushEvent('end');
        if (isset($kwargs['inputs'])) {
            $run->inputs = self::coerceToDict($kwargs['inputs'], 'input');
        }

        $this->onChainEnd($run);
        $this->endTrace($run);
    }

    /**
     * @param array{inputs?: mixed} $kwargs
     * @param list<string>         $tags
     */
    public function handleChainError(
        \Throwable $error,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $kwargs = [],
    ): void {
        $run = $this->getRunById($runId);
        if ($run === null) {
            throw new \RuntimeException('No chain run to end.');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->error = $this->stringifyError($error);
        $run->pushEvent('error');
        if (isset($kwargs['inputs'])) {
            $run->inputs = self::coerceToDict($kwargs['inputs'], 'input');
        }

        $this->onChainError($run);
        $this->endTrace($run);
    }

    /**
     * @param array<string, mixed>|string $input
     * @param list<string>                $tags
     * @param array<string, mixed>        $metadata
     */
    public function handleToolStart(
        Serialized $tool,
        array|string $input,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $toolCallId = null,
    ): void {
        $run = $this->getRunById($runId)
            ?? $this->createRunForToolStart($tool, $input, $runId, $parentRunId, $tags, $metadata, $runName);

        $this->onRunCreate($run);
        $this->onToolStart($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleToolEnd(mixed $output, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'tool') {
            throw new \RuntimeException('No tool run to end');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->outputs = ['output' => $output];
        $run->pushEvent('end');

        $this->onToolEnd($run);
        $this->endTrace($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleToolError(\Throwable $error, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'tool') {
            throw new \RuntimeException('No tool run to end');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->error = $this->stringifyError($error);
        $run->pushEvent('error');

        $this->onToolError($run);
        $this->endTrace($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleRetrieverStart(
        Serialized $retriever,
        string $query,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $name = null,
    ): void {
        $run = $this->getRunById($runId)
            ?? $this->createRunForRetrieverStart($retriever, $query, $runId, $parentRunId, $tags, $metadata, $name);

        $this->onRunCreate($run);
        $this->onRetrieverStart($run);
    }

    /**
     * @param list<Document> $documents
     * @param list<string>   $tags
     */
    public function handleRetrieverEnd(array $documents, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'retriever') {
            throw new \RuntimeException('No retriever run to end');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->outputs = ['documents' => $documents];
        $run->pushEvent('end');

        $this->onRetrieverEnd($run);
        $this->endTrace($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleRetrieverError(\Throwable $error, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'retriever') {
            throw new \RuntimeException('No retriever run to end');
        }

        $run->endTime = (float) (int) (microtime(true) * 1000);
        $run->error = $this->stringifyError($error);
        $run->pushEvent('error');

        $this->onRetrieverError($run);
        $this->endTrace($run);
    }

    /**
     * @param array{prompt?: int, completion?: int} $idx
     * @param list<string>                          $tags
     * @param array{chunk?: mixed}                  $fields
     */
    public function handleLLMNewToken(
        string $token,
        array $idx,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $fields = [],
    ): void {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'llm') {
            throw new \RuntimeException('Invalid "runId" provided to "handleLLMNewToken" callback.');
        }

        $run->pushEvent('new_token', ['token' => $token, 'idx' => $idx, 'chunk' => $fields['chunk'] ?? null]);

        $this->onLLMNewToken($run, $token, ['chunk' => $fields['chunk'] ?? null]);
    }

    /**
     * @param list<string> $tags
     */
    public function handleText(string $text, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        // Text is chatty and best-effort: a run of the wrong type simply has no
        // log to append to, and that is not an error worth raising.
        if ($run === null || $run->runType !== 'chain') {
            return;
        }

        $run->pushEvent('text', ['text' => $text]);
        $this->onText($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleAgentAction(array $action, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'chain') {
            return;
        }

        $run->actions[] = $action;
        $run->pushEvent('agent_action', ['action' => $action]);
        $this->onAgentAction($run);
    }

    /**
     * @param list<string> $tags
     */
    public function handleAgentEnd(array $action, string $runId, ?string $parentRunId = null, array $tags = []): void
    {
        $run = $this->getRunById($runId);
        if ($run === null || $run->runType !== 'chain') {
            return;
        }

        $run->pushEvent('agent_end', ['action' => $action]);
        $this->onAgentEnd($run);
    }

    // ---- subclass extension points ---------------------------------------
    //
    // The TypeScript original declares these as optional methods and calls them
    // through optional chaining. PHP has no such thing, so they are concrete
    // no-ops here and a subclass overrides the ones it needs. Behaviourally
    // identical, and it keeps every hook on the base class where the manager
    // can find it.

    protected function onRunCreate(Run $run): void
    {
    }

    protected function onRunUpdate(Run $run): void
    {
    }

    protected function onLLMStart(Run $run): void
    {
    }

    protected function onLLMEnd(Run $run): void
    {
    }

    protected function onLLMError(Run $run): void
    {
    }

    protected function onChainStart(Run $run): void
    {
    }

    protected function onChainEnd(Run $run): void
    {
    }

    protected function onChainError(Run $run): void
    {
    }

    protected function onToolStart(Run $run): void
    {
    }

    protected function onToolEnd(Run $run): void
    {
    }

    protected function onToolError(Run $run): void
    {
    }

    protected function onAgentAction(Run $run): void
    {
    }

    protected function onAgentEnd(Run $run): void
    {
    }

    protected function onRetrieverStart(Run $run): void
    {
    }

    protected function onRetrieverEnd(Run $run): void
    {
    }

    protected function onRetrieverError(Run $run): void
    {
    }

    protected function onText(Run $run): void
    {
    }

    /**
     * @param array{chunk?: mixed} $kwargs
     */
    protected function onLLMNewToken(Run $run, string $token, array $kwargs = []): void
    {
    }

    /**
     * Wrap a non-object value under a single key so a run always has an
     * `outputs` object.
     *
     * A chain that returns a bare string or a list has no fields to record; the
     * original files it as `{output: ...}` rather than as a top-level scalar or
     * array, because the run schema requires an object. A JSON *object* passes
     * through untouched — that is the common case and must not be nested.
     */
    private static function coerceToDict(mixed $value, string $defaultKey): array
    {
        if ($value === null || $value === '') {
            return [$defaultKey => $value];
        }
        if (is_array($value) && !Js::isList($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        return [$defaultKey => $value];
    }
}
