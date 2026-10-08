<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\AIMessageChunk;

/**
 * The tracer behind `Runnable::streamEvents()` (schema version "v2").
 *
 * Port of `EventStreamCallbackHandler` from `@langchain/core/tracers/event_stream`.
 *
 * Upstream writes events into a `TransformStream` that a second async task drains. PHP is
 * synchronous, so the stream is a plain queue: each hook appends a {@see StreamEvent}, and
 * `Runnable::streamEvents()` drains the queue after every step of the underlying generator. The
 * observable order is therefore "events appear as soon as the step that caused them returns", which is
 * the closest a single thread gets to upstream's interleaving — and is NOT identical to it. See
 * PORT_STATUS "Known non-exact behaviours".
 *
 * Streamed chunks of a run are reported by {@see self::tapOutputIterable()}, which wraps the run's
 * output stream. Only the first tap on a run emits; any later tap passes chunks through, and a run that
 * already finished (a runnable using the default `stream()`, which delegates to `invoke()`) emits no
 * stream event at all.
 *
 * Differences from upstream, all forced by the language:
 *  - `undefined` and `null` are the same thing, so a legitimately `null` input is reported as absent;
 *  - the stream pairs `[channel, chunk]` of this port are tapped as `chunk`, regardless of channel.
 *
 * @phpstan-type RunInfo array{name: string, tags: list<string>, metadata: array<string, mixed>, runType: string, inputs?: mixed}
 */
class EventStreamCallbackHandler extends BaseTracer
{
    protected bool $autoClose = true;

    /** @var list<string>|null */
    protected ?array $includeNames = null;

    /** @var list<string>|null */
    protected ?array $includeTypes = null;

    /** @var list<string>|null */
    protected ?array $includeTags = null;

    /** @var list<string>|null */
    protected ?array $excludeNames = null;

    /** @var list<string>|null */
    protected ?array $excludeTypes = null;

    /** @var list<string>|null */
    protected ?array $excludeTags = null;

    /** @var array<string, RunInfo> */
    private array $runInfoMap = [];

    /** @var array<string, bool> Run id => whether its tap has finished. Never pruned: it records which runs were tapped. */
    private array $tapped = [];

    /** @var array<string, list<array{0: StreamEvent, 1: RunInfo}>> End events held back until a run's tap completes. */
    private array $pendingEnd = [];

    /** @var list<StreamEvent> */
    private array $queue = [];

    private bool $closed = false;

    /** @var array<string, LLMResult> The result objects, which a tracer `Run` flattens to plain arrays. */
    private array $llmResults = [];

    public string $name = 'event_stream_tracer';

    public bool $preferStreaming = true;

    /**
     * @param array{autoClose?: bool, includeNames?: list<string>, includeTypes?: list<string>, includeTags?: list<string>, excludeNames?: list<string>, excludeTypes?: list<string>, excludeTags?: list<string>} $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->autoClose = $fields['autoClose'] ?? true;
        $this->includeNames = $fields['includeNames'] ?? null;
        $this->includeTypes = $fields['includeTypes'] ?? null;
        $this->includeTags = $fields['includeTags'] ?? null;
        $this->excludeNames = $fields['excludeNames'] ?? null;
        $this->excludeTypes = $fields['excludeTypes'] ?? null;
        $this->excludeTags = $fields['excludeTags'] ?? null;
    }

    /**
     * Empty the queue, oldest event first.
     *
     * @return \Generator<int, StreamEvent>
     */
    public function drain(): \Generator
    {
        while ($this->queue !== []) {
            yield array_shift($this->queue);
        }
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    protected function persistRun(Run $run): void
    {
        // Legacy hook, called once per whole run tree and therefore of no use here.
    }

    /**
     * @param RunInfo $run
     */
    public function includeRun(array $run): bool
    {
        $runTags = $run['tags'];
        $include = $this->includeNames === null && $this->includeTags === null && $this->includeTypes === null;
        if ($this->includeNames !== null) {
            $include = $include || \in_array($run['name'], $this->includeNames, true);
        }
        if ($this->includeTypes !== null) {
            $include = $include || \in_array($run['runType'], $this->includeTypes, true);
        }
        if ($this->includeTags !== null) {
            $include = $include || array_intersect($runTags, $this->includeTags) !== [];
        }
        if ($this->excludeNames !== null) {
            $include = $include && !\in_array($run['name'], $this->excludeNames, true);
        }
        if ($this->excludeTypes !== null) {
            $include = $include && !\in_array($run['runType'], $this->excludeTypes, true);
        }
        if ($this->excludeTags !== null) {
            $include = $include && array_intersect($runTags, $this->excludeTags) === [];
        }

        return $include;
    }

    /**
     * Tap a run's output stream, emitting one `on_<type>_stream` event per chunk.
     *
     * `$output` yields this port's `[channel, chunk]` pairs and is re-yielded untouched.
     *
     * @param iterable<mixed> $output
     *
     * @return \Generator<mixed>
     */
    public function tapOutputIterable(string $runId, iterable $output): \Generator
    {
        $iterator = $output instanceof \Iterator ? $output : new \IteratorIterator($output instanceof \Traversable ? $output : new \ArrayIterator($output));
        $iterator->rewind();
        if (!$iterator->valid()) {
            return;
        }
        $first = $iterator->current();

        $runInfo = $this->runInfoMap[$runId] ?? null;
        // Run has finished, don't issue any stream events. This is what a runnable on the default
        // `stream()` looks like: it calls `invoke()`, ends the run, and only then hands out its chunk.
        if ($runInfo === null) {
            yield $first;
            while (true) {
                $iterator->next();
                if (!$iterator->valid()) {
                    return;
                }
                yield $iterator->current();
            }
        }

        if (isset($this->tapped[$runId])) {
            // Not the first to tap: just pass through.
            yield $first;
            while (true) {
                $iterator->next();
                if (!$iterator->valid()) {
                    return;
                }
                yield $iterator->current();
            }
        }

        $this->tapped[$runId] = false;
        try {
            $this->send($this->streamEvent($runId, $runInfo, $first), $runInfo);
            yield $first;
            while (true) {
                $iterator->next();
                if (!$iterator->valid()) {
                    break;
                }
                $chunk = $iterator->current();
                // Don't emit tool and retriever stream events past the first.
                if ($runInfo['runType'] !== 'tool' && $runInfo['runType'] !== 'retriever') {
                    $this->send($this->streamEvent($runId, $runInfo, $chunk), $runInfo);
                }
                yield $chunk;
            }
        } finally {
            $this->tapped[$runId] = true;
            $this->flushPendingEnd($runId);
        }
    }

    /**
     * @param RunInfo $runInfo
     */
    private function streamEvent(string $runId, array $runInfo, mixed $pair): StreamEvent
    {
        $chunk = \is_array($pair) && \array_key_exists(1, $pair) ? $pair[1] : $pair;
        if ($runInfo['runType'] === 'llm' && \is_string($chunk)) {
            $chunk = new GenerationChunk($chunk);
        }

        return new StreamEvent(
            "on_{$runInfo['runType']}_stream",
            $runInfo['name'],
            $runId,
            $runInfo['tags'],
            $runInfo['metadata'],
            ['chunk' => $chunk],
        );
    }

    /**
     * @param RunInfo $run
     */
    public function send(StreamEvent $payload, array $run): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->includeRun($run)) {
            $this->queue[] = $payload;
        }
    }

    /**
     * An end event waits for its run's tap to finish, so it can never overtake the last stream event.
     *
     * @param RunInfo $run
     */
    public function sendEndEvent(StreamEvent $payload, array $run): void
    {
        if (isset($this->tapped[$payload->runId]) && $this->tapped[$payload->runId] === false) {
            $this->pendingEnd[$payload->runId][] = [$payload, $run];

            return;
        }
        $this->send($payload, $run);
    }

    private function flushPendingEnd(string $runId): void
    {
        $pending = $this->pendingEnd[$runId] ?? [];
        unset($this->pendingEnd[$runId]);
        foreach ($pending as [$payload, $run]) {
            $this->send($payload, $run);
        }
    }

    /**
     * Release anything held back and stop accepting events.
     *
     * Upstream waits for every tap promise and then closes the writer; the taps are generators here, so
     * "waiting" means flushing whatever an abandoned tap never got to release.
     */
    public function finish(): void
    {
        foreach (array_keys($this->pendingEnd) as $runId) {
            $this->tapped[$runId] = true;
            $this->flushPendingEnd((string) $runId);
        }
        $this->closed = true;
    }

    public function handleLLMEnd(
        LLMResult $output,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $extraParams = [],
    ): void {
        // `Run::$outputs` carries the flattened `toArray()` form, which has no message objects. Keep the
        // real result so a chat model's end event can report the message.
        $this->llmResults[$runId] = $output;
        parent::handleLLMEnd($output, $runId, $parentRunId, $tags, $extraParams);
    }

    protected function onLLMStart(Run $run): void
    {
        $runName = $run->name();
        $runType = isset($run->inputs['messages']) ? 'chat_model' : 'llm';
        $runInfo = [
            'tags' => $run->tags,
            'metadata' => $run->extra['metadata'] ?? [],
            'name' => $runName,
            'runType' => $runType,
            'inputs' => $run->inputs,
        ];
        $this->runInfoMap[$run->id] = $runInfo;
        $this->send(
            new StreamEvent("on_{$runType}_start", $runName, $run->id, $run->tags, $runInfo['metadata'], ['input' => $run->inputs]),
            $runInfo,
        );
    }

    /**
     * @param array{chunk?: mixed} $kwargs
     */
    protected function onLLMNewToken(Run $run, string $token, array $kwargs = []): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        if ($runInfo === null) {
            throw new \RuntimeException("onLLMNewToken: Run ID {$run->id} not found in run map.");
        }
        // Top-level streaming events are covered by tapOutputIterable.
        if (\count($this->runInfoMap) === 1) {
            return;
        }

        $chunk = $kwargs['chunk'] ?? null;
        if ($runInfo['runType'] === 'chat_model') {
            $eventName = 'on_chat_model_stream';
            $chunk = $chunk instanceof ChatGenerationChunk
                ? $chunk->message
                : new AIMessageChunk(['content' => $token, 'id' => "run-{$run->id}"]);
        } elseif ($runInfo['runType'] === 'llm') {
            $eventName = 'on_llm_stream';
            $chunk ??= new GenerationChunk($token);
        } else {
            throw new \RuntimeException("Unexpected run type {$runInfo['runType']}");
        }

        $this->send(
            new StreamEvent($eventName, $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], ['chunk' => $chunk]),
            $runInfo,
        );
    }

    protected function onLLMEnd(Run $run): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        unset($this->runInfoMap[$run->id]);
        $result = $this->llmResults[$run->id] ?? null;
        unset($this->llmResults[$run->id]);
        if ($runInfo === null) {
            throw new \RuntimeException("onLLMEnd: Run ID {$run->id} not found in run map.");
        }

        if ($runInfo['runType'] === 'chat_model') {
            $output = null;
            foreach ($result?->generations ?? [] as $generation) {
                $first = $generation[0] ?? null;
                if ($first instanceof ChatGeneration) {
                    $output = $first->message;
                    break;
                }
            }
            $eventName = 'on_chat_model_end';
        } elseif ($runInfo['runType'] === 'llm') {
            $output = [
                'generations' => $run->outputs['generations'] ?? null,
                'llmOutput' => $run->outputs['llmOutput'] ?? [],
            ];
            $eventName = 'on_llm_end';
        } else {
            throw new \RuntimeException("onLLMEnd: Unexpected run type: {$runInfo['runType']}");
        }

        $this->sendEndEvent(
            new StreamEvent($eventName, $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], [
                'output' => $output,
                'input' => $runInfo['inputs'] ?? null,
            ]),
            $runInfo,
        );
    }

    protected function onChainStart(Run $run): void
    {
        $runName = $run->name();
        $runType = $run->runType;
        $runInfo = [
            'tags' => $run->tags,
            'metadata' => $run->extra['metadata'] ?? [],
            'name' => $runName,
            'runType' => $runType,
        ];

        $eventData = [];
        // Workaround for runnable core code not sending input when transform streaming.
        if (($run->inputs['input'] ?? null) === '' && \count($run->inputs) === 1) {
            $runInfo['inputs'] = [];
        } elseif (isset($run->inputs['input'])) {
            $eventData['input'] = $run->inputs['input'];
            $runInfo['inputs'] = $run->inputs['input'];
        } else {
            $eventData['input'] = $run->inputs;
            $runInfo['inputs'] = $run->inputs;
        }

        $this->runInfoMap[$run->id] = $runInfo;
        $this->send(
            new StreamEvent("on_{$runType}_start", $runName, $run->id, $run->tags, $runInfo['metadata'], $eventData),
            $runInfo,
        );
    }

    protected function onChainEnd(Run $run): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        unset($this->runInfoMap[$run->id]);
        if ($runInfo === null) {
            throw new \RuntimeException("onChainEnd: Run ID {$run->id} not found in run map.");
        }

        $inputs = $run->inputs !== [] ? $run->inputs : ($runInfo['inputs'] ?? []);
        $outputs = ($run->outputs['output'] ?? null) ?? $run->outputs;
        $data = ['output' => $outputs, 'input' => $inputs];
        if (\is_array($inputs) && !empty($inputs['input']) && \count($inputs) === 1) {
            $data['input'] = $inputs['input'];
            $runInfo['inputs'] = $inputs['input'];
        }

        $this->sendEndEvent(
            new StreamEvent("on_{$run->runType}_end", $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], $data),
            $runInfo,
        );
    }

    protected function onToolStart(Run $run): void
    {
        $runName = $run->name();
        $runInfo = [
            'tags' => $run->tags,
            'metadata' => $run->extra['metadata'] ?? [],
            'name' => $runName,
            'runType' => 'tool',
            'inputs' => $run->inputs,
        ];
        $this->runInfoMap[$run->id] = $runInfo;
        $this->send(
            new StreamEvent('on_tool_start', $runName, $run->id, $run->tags, $runInfo['metadata'], ['input' => $run->inputs]),
            $runInfo,
        );
    }

    protected function onToolEnd(Run $run): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        unset($this->runInfoMap[$run->id]);
        if ($runInfo === null) {
            throw new \RuntimeException("onToolEnd: Run ID {$run->id} not found in run map.");
        }

        $output = \array_key_exists('output', $run->outputs) ? $run->outputs['output'] : $run->outputs;
        $this->sendEndEvent(
            new StreamEvent('on_tool_end', $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], [
                'output' => $output,
                'input' => $runInfo['inputs'] ?? null,
            ]),
            $runInfo,
        );
    }

    protected function onToolError(Run $run): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        unset($this->runInfoMap[$run->id]);
        if ($runInfo === null) {
            throw new \RuntimeException("onToolEnd: Run ID {$run->id} not found in run map.");
        }

        $this->sendEndEvent(
            new StreamEvent('on_tool_error', $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], [
                'input' => $runInfo['inputs'] ?? null,
                'error' => $run->error,
            ]),
            $runInfo,
        );
    }

    protected function onRetrieverStart(Run $run): void
    {
        $runName = $run->name();
        $inputs = ['query' => $run->inputs['query'] ?? null];
        $runInfo = [
            'tags' => $run->tags,
            'metadata' => $run->extra['metadata'] ?? [],
            'name' => $runName,
            'runType' => 'retriever',
            'inputs' => $inputs,
        ];
        $this->runInfoMap[$run->id] = $runInfo;
        $this->send(
            new StreamEvent('on_retriever_start', $runName, $run->id, $run->tags, $runInfo['metadata'], ['input' => $inputs]),
            $runInfo,
        );
    }

    protected function onRetrieverEnd(Run $run): void
    {
        $runInfo = $this->runInfoMap[$run->id] ?? null;
        unset($this->runInfoMap[$run->id]);
        if ($runInfo === null) {
            throw new \RuntimeException("onRetrieverEnd: Run ID {$run->id} not found in run map.");
        }

        $this->sendEndEvent(
            new StreamEvent('on_retriever_end', $runInfo['name'], $run->id, $runInfo['tags'], $runInfo['metadata'], [
                'output' => $run->outputs['documents'] ?? $run->outputs,
                'input' => $runInfo['inputs'] ?? null,
            ]),
            $runInfo,
        );
    }

    public function handleCustomEvent(
        string $eventName,
        mixed $data,
        string $runId,
        array $tags = [],
        array $metadata = [],
    ): void {
        $runInfo = $this->runInfoMap[$runId] ?? null;
        if ($runInfo === null) {
            throw new \RuntimeException("handleCustomEvent: Run ID {$runId} not found in run map.");
        }

        $this->send(
            new StreamEvent('on_custom_event', $eventName, $runId, $runInfo['tags'], $runInfo['metadata'], $data),
            $runInfo,
        );
    }
}
