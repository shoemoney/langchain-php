<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessageChunk;

/**
 * The tracer behind `Runnable::streamLog()` (and the "v1" `streamEvents()` schema).
 *
 * Port of `LogStreamCallbackHandler` from `@langchain/core/tracers/log_stream`. It watches the run tree
 * and emits {@see RunLogPatch} instances describing how the run state changes.
 *
 * Upstream writes the patches into a `TransformStream`; here the stream is a queue that the caller
 * drains ({@see self::drain()}) after each step — see {@see EventStreamCallbackHandler} for why, and
 * for the ordering caveat that comes with it.
 *
 * Two of upstream's fields are unreachable in PHP: `final_output: undefined` and
 * `end_time: undefined` are `null` here, so "absent" and "null" read the same.
 */
class LogStreamCallbackHandler extends BaseTracer
{
    public const SCHEMA_ORIGINAL = 'original';

    public const SCHEMA_STREAMING_EVENTS = 'streaming_events';

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

    protected string $schemaFormat = self::SCHEMA_ORIGINAL;

    protected ?string $rootId = null;

    /** @var array<string, string> */
    private array $keyMapByRunId = [];

    /** @var array<string, int> */
    private array $counterMapByRunName = [];

    /** @var list<RunLogPatch> */
    private array $queue = [];

    private bool $closed = false;

    public string $name = 'log_stream_tracer';

    public bool $preferStreaming = true;

    /**
     * @param array{autoClose?: bool, includeNames?: list<string>, includeTypes?: list<string>, includeTags?: list<string>, excludeNames?: list<string>, excludeTypes?: list<string>, excludeTags?: list<string>, schemaFormat?: string} $fields
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
        $this->schemaFormat = $fields['schemaFormat'] ?? $this->schemaFormat;
    }

    /**
     * Queue a patch for the consumer. Upstream's `writer.write`.
     */
    public function write(RunLogPatch $patch): void
    {
        if (!$this->closed) {
            $this->queue[] = $patch;
        }
    }

    /**
     * Stop accepting patches. Upstream's `writer.close`.
     */
    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Empty the queue, oldest patch first.
     *
     * @return \Generator<int, RunLogPatch>
     */
    public function drain(): \Generator
    {
        while ($this->queue !== []) {
            yield array_shift($this->queue);
        }
    }

    protected function persistRun(Run $run): void
    {
        // Legacy hook, called once per whole run tree and therefore of no use here.
    }

    public function includeRun(Run $run): bool
    {
        if ($run->id === $this->rootId) {
            return false;
        }
        $runTags = $run->tags;
        $runName = $run->name();
        $include = $this->includeNames === null && $this->includeTags === null && $this->includeTypes === null;
        if ($this->includeNames !== null) {
            $include = $include || \in_array($runName, $this->includeNames, true);
        }
        if ($this->includeTypes !== null) {
            $include = $include || \in_array($run->runType, $this->includeTypes, true);
        }
        if ($this->includeTags !== null) {
            $include = $include || array_intersect($runTags, $this->includeTags) !== [];
        }
        if ($this->excludeNames !== null) {
            $include = $include && !\in_array($runName, $this->excludeNames, true);
        }
        if ($this->excludeTypes !== null) {
            $include = $include && !\in_array($run->runType, $this->excludeTypes, true);
        }
        if ($this->excludeTags !== null) {
            $include = $include && array_intersect($runTags, $this->excludeTags) === [];
        }

        return $include;
    }

    /**
     * Tap a run's output stream, appending every chunk to that run's `streamed_output` in the log.
     *
     * @param iterable<mixed> $output Yields `[channel, chunk]` pairs, re-yielded untouched.
     *
     * @return \Generator<mixed>
     */
    public function tapOutputIterable(string $runId, iterable $output): \Generator
    {
        foreach ($output as $pair) {
            // The root run is handled in `streamLog()`.
            if ($runId !== $this->rootId) {
                // If we can't find the run, silently ignore it: it was not included in the log.
                $key = $this->keyMapByRunId[$runId] ?? null;
                if ($key !== null && $key !== '') {
                    $this->write(new RunLogPatch(['ops' => [[
                        'op' => 'add',
                        'path' => "/logs/{$key}/streamed_output/-",
                        'value' => \is_array($pair) && \array_key_exists(1, $pair) ? $pair[1] : $pair,
                    ]]]));
                }
            }

            yield $pair;
        }
    }

    protected function onRunCreate(Run $run): void
    {
        if ($this->rootId === null) {
            $this->rootId = $run->id;
            $this->write(new RunLogPatch(['ops' => [[
                'op' => 'replace',
                'path' => '',
                'value' => [
                    'id' => $run->id,
                    'name' => $run->name(),
                    'type' => $run->runType,
                    'streamed_output' => [],
                    'final_output' => null,
                    'logs' => [],
                ],
            ]]]));
        }

        if (!$this->includeRun($run)) {
            return;
        }

        $runName = $run->name();
        $this->counterMapByRunName[$runName] = ($this->counterMapByRunName[$runName] ?? 0) + 1;
        $count = $this->counterMapByRunName[$runName];
        $this->keyMapByRunId[$run->id] = $count === 1 ? $runName : "{$runName}:{$count}";

        $logEntry = [
            'id' => $run->id,
            'name' => $runName,
            'type' => $run->runType,
            'tags' => $run->tags,
            'metadata' => $run->extra['metadata'] ?? [],
            'start_time' => self::isoTimestamp($run->startTime),
            'streamed_output' => [],
            'streamed_output_str' => [],
            'final_output' => null,
            'end_time' => null,
        ];

        if ($this->schemaFormat === self::SCHEMA_STREAMING_EVENTS) {
            $logEntry['inputs'] = $this->standardizedInputs($run);
        }

        $this->write(new RunLogPatch(['ops' => [[
            'op' => 'add',
            'path' => "/logs/{$this->keyMapByRunId[$run->id]}",
            'value' => $logEntry,
        ]]]));
    }

    protected function onRunUpdate(Run $run): void
    {
        try {
            $runName = $this->keyMapByRunId[$run->id] ?? null;
            if ($runName === null) {
                return;
            }

            $ops = [];
            if ($this->schemaFormat === self::SCHEMA_STREAMING_EVENTS) {
                $ops[] = ['op' => 'replace', 'path' => "/logs/{$runName}/inputs", 'value' => $this->standardizedInputs($run)];
            }
            $ops[] = ['op' => 'add', 'path' => "/logs/{$runName}/final_output", 'value' => $this->standardizedOutputs($run)];
            if ($run->endTime !== null) {
                $ops[] = ['op' => 'add', 'path' => "/logs/{$runName}/end_time", 'value' => self::isoTimestamp($run->endTime)];
            }
            $this->write(new RunLogPatch(['ops' => $ops]));
        } finally {
            if ($run->id === $this->rootId) {
                $this->write(new RunLogPatch(['ops' => [[
                    'op' => 'replace',
                    'path' => '/final_output',
                    'value' => $this->standardizedOutputs($run),
                ]]]));
                if ($this->autoClose) {
                    $this->close();
                }
            }
        }
    }

    /**
     * @param array{chunk?: mixed} $kwargs
     */
    protected function onLLMNewToken(Run $run, string $token, array $kwargs = []): void
    {
        $runName = $this->keyMapByRunId[$run->id] ?? null;
        if ($runName === null) {
            return;
        }

        $isChatModel = isset($run->inputs['messages']);
        if ($isChatModel) {
            $chunk = $kwargs['chunk'] ?? null;
            $streamedOutputValue = $chunk instanceof ChatGenerationChunk
                ? $chunk
                : new AIMessageChunk(['id' => "run-{$run->id}", 'content' => $token]);
        } else {
            $streamedOutputValue = $token;
        }

        $this->write(new RunLogPatch(['ops' => [
            ['op' => 'add', 'path' => "/logs/{$runName}/streamed_output_str/-", 'value' => $token],
            ['op' => 'add', 'path' => "/logs/{$runName}/streamed_output/-", 'value' => $streamedOutputValue],
        ]]));
    }

    /**
     * The inputs of a run in the standardized (named-argument) form; `null` means "not yet known".
     */
    private function standardizedInputs(Run $run): mixed
    {
        if ($this->schemaFormat === self::SCHEMA_ORIGINAL) {
            throw new \LogicException(
                'Do not assign inputs with original schema drop the key for now. '
                . 'When inputs are added to streamLog they should be added with '
                . 'standardized schema for streaming events.',
            );
        }

        $inputs = $run->inputs;

        if (\in_array($run->runType, ['retriever', 'llm', 'prompt'], true)) {
            return $inputs;
        }

        if (\count($inputs) === 1 && ($inputs['input'] ?? null) === '') {
            return null;
        }

        // New style chains nest an additional `input` key so the inputs are always a dict; unpack it.
        return $inputs['input'] ?? null;
    }

    private function standardizedOutputs(Run $run): mixed
    {
        $outputs = $run->outputs;
        if ($this->schemaFormat === self::SCHEMA_ORIGINAL) {
            // The old schema, without standardizing anything.
            return $outputs;
        }

        if (\in_array($run->runType, ['retriever', 'llm', 'prompt'], true)) {
            return $outputs;
        }

        if (\count($outputs) === 1 && isset($outputs['output'])) {
            return $outputs['output'];
        }

        return $outputs;
    }

    /**
     * `new Date(ms).toISOString()`.
     */
    private static function isoTimestamp(float $milliseconds): string
    {
        $ms = (int) $milliseconds;

        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . \sprintf('.%03dZ', $ms % 1000);
    }
}
