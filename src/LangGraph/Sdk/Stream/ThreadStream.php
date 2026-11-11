<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;

/**
 * RemoteGraph-required HTTP/SSE ThreadStream surface from SDK stream/index.ts.
 * Subscriptions share one pull-driven connection. Registering a late subscriber
 * rotates its union filter and replays server history; durable event_id dedup is
 * per subscription, with a separate set for thread-level interrupt effects.
 * Projections replay buffered items with independent cursors. Memory grows with
 * observed events for this handle's lifetime, as in the source MultiCursorBuffer.
 * PHP has no background pump, Promise or microtask coalescing: reading any
 * subscription advances the shared connection. Terminal pause happens at the
 * next byte-chunk boundary, after same-chunk trailing events have been delivered.
 * Source's dedicated background lifecycle watcher is folded into this shared
 * union's wildcard lifecycle/input filter. Transport/protocol errors propagate
 * from reads; onError observes unsolicited/fatal stream errors. Async
 * best-effort view pumps are not ported.
 */
final class ThreadStream
{
    public readonly string $threadId;
    public readonly string $assistantId;
    public readonly ThreadRun $run;
    public array $ordering = [];

    private int $nextCommandId;
    private bool $closed = false;
    private bool $dirty = false;
    private bool $freshReplay = false;
    private bool $sourceStarted = false;
    private bool $terminalPending = false;
    private ?\Generator $source = null;
    private ?array $sourceFilter = null;
    private ?int $terminalSeq = null;
    private bool $hasTerminal = false;
    private array $errorListeners = [];
    private int $nextListenerId = 1;
    private array $errorLog = [];
    private ?ThreadProjection $extensionsDispatcher = null;
    private array $subscriptions = [];
    private array $seen = [];
    private bool $isInterrupted = false;
    private array $interruptPayloads = [];
    private ?ThreadProjection $valuesProjection = null;
    private ?ThreadProjection $messagesProjection = null;
    private ?ThreadProjection $subgraphsProjection = null;
    private ?ThreadSubscription $watcher = null;
    private array $extensions = [];
    private array $messages = [];
    private array $messageStarts = [];
    private array $messageHandles = [];
    private ?ThreadMessage $currentMessageStart = null;
    private array $activeMessages = [];
    private array $subgraphs = [];
    private array $toolStarts = [];

    public function __construct(private readonly ProtocolSseTransport $transport, array $options)
    {
        if (empty($options['assistantId'])) {
            throw new \InvalidArgumentException('ThreadStream requires an assistantId option.');
        }
        $this->threadId = $transport->threadId;
        $this->assistantId = $options['assistantId'];
        $this->nextCommandId = $options['startingCommandId'] ?? 1;
        $this->run = new ThreadRun($this);
    }

    public function startRun(array $params): array
    {
        $this->assertOpen();
        $this->isInterrupted = false;
        $this->interruptPayloads = [];
        $this->terminalPending = false;
        $this->terminalSeq = null;
        $this->hasTerminal = false;
        foreach ($this->subscriptions as $subscription) {
            $subscription->paused = false;
        }
        $fork = $params['forkFrom'] ?? null;
        unset($params['forkFrom']);
        if (is_string($fork) && $fork !== '') {
            $params['config']['configurable']['checkpoint_id'] = $fork;
        }
        $params['assistant_id'] = $this->assistantId;
        // Commit run.start before any pull can open the SSE subscriptions.
        $response = $this->transport->send(['id' => $this->nextCommandId++, 'method' => 'run.start', 'params' => $params]);
        if (isset($response['meta']['applied_through_seq'])) {
            $this->ordering['lastAppliedThroughSeq'] = $response['meta']['applied_through_seq'];
        }
        $this->watcher ??= $this->subscribe(['channels' => ['lifecycle', 'input']]);
        $this->values();

        return $response['result'] ?? [];
    }

    public function subscribe(string|array $paramsOrChannels, array $options = []): ThreadSubscription
    {
        $this->assertOpen();
        $objectForm = is_array($paramsOrChannels) && isset($paramsOrChannels['channels']);
        $params = $objectForm ? $paramsOrChannels
            : [...$options, 'channels' => is_array($paramsOrChannels) ? array_values($paramsOrChannels) : [$paramsOrChannels]];
        $id = 'sse-' . $this->nextCommandId++;
        $unwrap = !$objectForm && $params['channels'] !== []
            && count(array_filter($params['channels'], static fn ($channel): bool => str_starts_with($channel, 'custom:'))) === count($params['channels']);
        $subscription = new ThreadSubscription($id, $params, function (string $id): void {
            unset($this->subscriptions[$id]);
            $this->dirty = true;
        }, fn (): bool => $this->pull(), $unwrap, $this->ordering['lastSeenSeq'] ?? null);
        $this->subscriptions[$id] = $subscription;
        $this->dirty = true;
        $this->freshReplay = true;

        return $subscription;
    }

    /** Advance the one shared connection; used by subscription/message drivers. */
    public function pull(): bool
    {
        if ($this->closed || $this->subscriptions === []) {
            return false;
        }
        if ($this->dirty || $this->source === null) {
            $this->reconcile();
        }
        if ($this->source === null) {
            return false;
        }
        try {
            while (true) {
                if ($this->sourceStarted) {
                    $this->source->next();
                }
                $this->sourceStarted = true;
                if (!$this->source->valid()) {
                    $this->finish();

                    return false;
                }
                $event = $this->source->current();
                if ($event === null) {
                    if ($this->terminalPending) {
                        foreach ($this->subscriptions as $sub) {
                            if ($sub !== $this->watcher && ($sub->endOnRootTerminal || $this->terminalSeq === null
                                || $sub->registeredAfterSeq === null || $sub->registeredAfterSeq < $this->terminalSeq)) {
                                $sub->paused = true;
                            }
                        }
                        $this->terminalPending = false;

                        // A late raw replay may remain active after older views pause.
                        return count(array_filter($this->subscriptions, fn ($sub): bool => $sub !== $this->watcher && !$sub->paused)) > 0;
                    }
                    continue;
                }
                if (($event['type'] ?? null) === 'error') {
                    // HTTP commands settle synchronously in send(). No pending
                    // command matches a frame here; unsolicited no-id errors
                    // are observable side-channel failures, not stream closure.
                    if (($event['id'] ?? null) === null) {
                        $this->fireError(new ProtocolError($event));
                    }
                    continue;
                }
                if (($event['type'] ?? null) !== 'event') {
                    continue;
                }
                $this->receive($event);

                return true;
            }
        } catch (\Throwable $error) {
            $this->fireError($error);
            $this->closed = true;
            $this->transport->close();
            $this->source = null;
            $this->finish($error);
            $this->errorListeners = [];
            throw $error;
        }
    }

    /** A replacement must reach HTTP-ready before releasing the active body. */
    private function reconcile(): void
    {
        $filter = $this->unionFilter();
        if (!$this->freshReplay && $this->sourceFilter !== null && self::filterEqual($filter, $this->sourceFilter)) {
            $this->dirty = false;

            return;
        }
        $this->freshReplay = false;
        $candidate = $this->transport->events($filter, fn (): bool => $this->closed);
        $this->dirty = false;
        try {
            // Transport's first null tick is emitted only after validated headers.
            $candidate->rewind();
        } catch (\Throwable $error) {
            foreach ($this->subscriptions as $id => $sub) {
                if ($this->sourceFilter === null || !self::filterCovers($this->sourceFilter, $sub->params)) {
                    $sub->close($error);
                    unset($this->subscriptions[$id]);
                }
            }
            // The failed pending handle observes its own error from its buffer;
            // readers already covered by the old connection remain usable.
            return;
        }
        $this->source = $candidate;
        $this->sourceFilter = $filter;
        $this->sourceStarted = false;
    }

    private static function filterEqual(array $left, array $right): bool
    {
        if (count($left['channels']) !== count($right['channels']) || array_diff($left['channels'], $right['channels']) !== []
            || ($left['depth'] ?? null) !== ($right['depth'] ?? null)) {
            return false;
        }
        if (!isset($left['namespaces']) || !isset($right['namespaces'])) {
            return isset($left['namespaces']) === isset($right['namespaces']);
        }
        $a = array_map('json_encode', $left['namespaces']);
        $b = array_map('json_encode', $right['namespaces']);
        sort($a);
        sort($b);

        return $a === $b;
    }

    private static function filterCovers(array $coverer, array $target): bool
    {
        if (array_diff($target['channels'], $coverer['channels']) !== []) {
            return false;
        }
        if (!isset($coverer['namespaces'])) {
            return !isset($coverer['depth']) || (isset($target['depth']) && $target['depth'] <= $coverer['depth']);
        }
        if (!isset($target['namespaces'])) {
            return false;
        }
        foreach ($target['namespaces'] as $prefix) {
            $covered = false;
            foreach ($coverer['namespaces'] as $ancestor) {
                if (array_slice($prefix, 0, count($ancestor)) === $ancestor
                    && (!isset($coverer['depth']) || (isset($target['depth'])
                        && count($prefix) - count($ancestor) + $target['depth'] <= $coverer['depth']))) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }

        return true;
    }

    private function unionFilter(): array
    {
        $channels = [];
        $namespaces = [];
        $wildcard = false;
        $bounded = true;
        $depth = 0;
        foreach ($this->subscriptions as $sub) {
            $params = $sub->params;
            $channels = [...$channels, ...$params['channels']];
            if (!isset($params['namespaces'])) {
                $wildcard = true;
            } else {
                foreach ($params['namespaces'] as $ns) {
                    $namespaces[json_encode($ns)] = $ns;
                }
            }
            if (!isset($params['depth'])) {
                $bounded = false;
            } else {
                $depth = max($depth, $params['depth']);
            }
        }
        $filter = ['channels' => array_values(array_unique($channels))];
        if (!$wildcard) {
            $filter['namespaces'] = array_values($namespaces);
        }
        if ($bounded) {
            $filter['depth'] = $depth;
        }

        return $filter;
    }

    private function receive(array $event): void
    {
        $this->currentMessageStart = null;
        if (isset($event['seq'])) {
            $this->ordering['lastSeenSeq'] = max($this->ordering['lastSeenSeq'] ?? $event['seq'], $event['seq']);
        }
        $id = $event['event_id'] ?? null;
        if ($id !== null) {
            $this->ordering['lastEventId'] = $id;
        }
        if ($id === null || !isset($this->seen[$id])) {
            if ($id !== null) {
                $this->seen[$id] = true;
            }
            $this->effects($event);
        }
        $fanned = false;
        foreach ($this->subscriptions as $sub) {
            $fanned = $sub->push($event) || $fanned;
        }
        if ($fanned && $this->hasTerminal && ($this->terminalSeq === null || !isset($event['seq']) || $event['seq'] > $this->terminalSeq)
            && !(($event['method'] ?? '') === 'lifecycle' && ($event['params']['namespace'] ?? []) === [])) {
            foreach ($this->subscriptions as $sub) {
                $sub->paused = false;
            }
            $this->terminalPending = true;
        }
        if ($fanned && ($event['method'] ?? '') === 'lifecycle' && ($event['params']['namespace'] ?? []) === []
            && in_array($event['params']['data']['event'] ?? '', ['completed', 'failed', 'interrupted'], true)) {
            $this->terminalSeq = $event['seq'] ?? null;
            $this->hasTerminal = true;
            $this->terminalPending = true;
        }
    }

    private function effects(array $event): void
    {
        $data = $event['params']['data'] ?? [];
        $ns = $event['params']['namespace'] ?? [];
        if ($event['method'] === 'lifecycle' && ($data['event'] ?? '') === 'interrupted') {
            $this->isInterrupted = true;
        }
        if ($event['method'] === 'input.requested') {
            $this->interruptPayloads[] = ['interruptId' => $data['interrupt_id'] ?? 'interrupt_' . count($this->interruptPayloads),
                'payload' => $data['payload'] ?? null, 'namespace' => $ns];
        }
        if ($event['method'] === 'messages') {
            $base = json_encode([$ns, $event['params']['node'] ?? null]);
            $key = $base . '::' . ($data['run_id'] ?? $data['id'] ?? '');
            if (($data['event'] ?? '') === 'message-start') {
                $this->activeMessages[$base] = $key;
                $handle = new ThreadMessage($data['id'] ?? '', $ns, $event['params']['node'] ?? null,
                    $data['run_id'] ?? null, fn (): bool => $this->pull());
                $this->messages[$key] = $handle;
                // Immutable start-event identity survives reuse of the active
                // namespace/node/run-or-message key and lazy projection reads.
                $this->currentMessageStart = $handle;
                $this->messageHandles[] = $handle;
                if (isset($event['event_id'])) {
                    $this->messageStarts[self::messageStartIdentity($event)] ??= $handle;
                }
            } elseif (!isset($this->messages[$key])) {
                $fallback = $this->activeMessages[$base] ?? null;
                // An unknown explicit run must never mutate another active run.
                if ($fallback !== null && (!isset($data['run_id']) || $this->messages[$fallback]->runId === null)) {
                    $key = $fallback;
                }
            }
            if (isset($this->messages[$key])) {
                $this->messages[$key]->consume($data);
                if (in_array($data['event'] ?? '', ['message-finish', 'error'], true)) {
                    unset($this->messages[$key]);
                    if (($this->activeMessages[$base] ?? null) === $key) {
                        unset($this->activeMessages[$base]);
                    }
                }
            }
        }
        if ($event['method'] === 'tools' && ($data['event'] ?? '') === 'tool-started' && isset($data['tool_call_id'])) {
            $this->toolStarts[$data['tool_call_id']] = $event;
            foreach ($this->subgraphs as $subgraph) {
                if (($subgraph->cause['type'] ?? null) === 'toolCall'
                    && ($subgraph->cause['tool_call_id'] ?? null) === $data['tool_call_id']) {
                    $subgraph->toolStartedEvent = $event;
                }
            }
        }
    }

    public function values(): ThreadProjection
    {
        return $this->valuesProjection ??= $this->valuesProjection([]);
    }

    public function valuesProjection(array $namespace): ThreadProjection
    {
        $params = ['channels' => ['values', 'lifecycle', 'input']];
        if ($namespace !== []) {
            $params['namespaces'] = [$namespace];
        }

        return new ThreadProjection($this->subscribe($params), static fn ($event): array => $event['method'] === 'values'
            ? [self::coerceMessages($event['params']['data'])] : []);
    }

    private static function coerceMessages(mixed $value): mixed
    {
        if (!is_array($value) || !is_array($value['messages'] ?? null)) {
            return $value;
        }
        $value['messages'] = array_map(static function ($message) {
            if (!is_array($message)) {
                return $message;
            }

            return match ($message['type'] ?? null) {
                'ai', 'assistant' => new AIMessage($message), 'human', 'user' => new HumanMessage($message),
                'system' => new SystemMessage($message), 'tool' => new ToolMessage($message),
                'function' => new FunctionMessage($message), 'remove' => new RemoveMessage($message),
                default => $message,
            };
        }, $value['messages']);

        return $value;
    }

    public function messages(): ThreadProjection
    {
        return $this->messagesProjection ??= $this->messagesProjection([]);
    }

    public function messagesProjection(array $namespace, ?string $node = null): ThreadProjection
    {
        $params = ['channels' => ['messages', 'lifecycle', 'input']];
        if ($namespace !== []) {
            $params['namespaces'] = [$namespace];
        }

        return new ThreadProjection($this->subscribe($params), function ($event) use ($node): array {
            if ($event['method'] !== 'messages' || ($event['params']['data']['event'] ?? '') !== 'message-start'
                || ($node !== null && ($event['params']['node'] ?? null) !== $node)) {
                return [];
            }
            $handle = isset($event['event_id']) ? $this->messageStarts[self::messageStartIdentity($event)] ?? null
                : $this->currentMessageStart;

            return $handle === null ? [] : [$handle];
        }, captureOnPush: true);
    }

    private static function messageStartIdentity(array $event): string
    {
        return 'event:' . json_encode($event['event_id'], JSON_THROW_ON_ERROR);
    }

    public function messagesFrom(string $node): ThreadProjection
    {
        return $this->messagesProjection([], $node);
    }

    public function subgraphs(): ThreadProjection
    {
        return $this->subgraphsProjection ??= $this->subgraphsProjection([]);
    }

    public function subgraphsProjection(array $parent): ThreadProjection
    {
        $params = ['channels' => ['tools', 'lifecycle', 'input']];
        if ($parent !== []) {
            $params['namespaces'] = [$parent];
        }
        $discovered = [];

        return new ThreadProjection($this->subscribe($params), function ($event) use ($parent, &$discovered): array {
            $ns = $event['params']['namespace'];
            $data = $event['params']['data'];
            if ($event['method'] !== 'lifecycle' || ($data['event'] ?? '') !== 'started'
                || count($ns) !== count($parent) + 1 || array_slice($ns, 0, count($parent)) !== $parent) {
                return [];
            }
            $key = json_encode($ns);
            if (isset($discovered[$key])) {
                return [];
            }
            $discovered[$key] = true;
            if (!isset($this->subgraphs[$key])) {
                $segment = $ns[count($ns) - 1];
                $position = strrpos($segment, ':');
                $name = $position === false ? $segment : substr($segment, 0, $position);
                $suffix = $position === false ? '' : substr($segment, $position + 1);
                $handle = new ThreadSubgraph($this, $name, ctype_digit($suffix) ? (int) $suffix : 0, $ns,
                    is_array($data['cause'] ?? null) ? $data['cause'] : null,
                    is_string($data['graph_name'] ?? null) ? $data['graph_name'] : null);
                if (($handle->cause['type'] ?? null) === 'toolCall') {
                    $handle->toolStartedEvent = $this->toolStarts[$handle->cause['tool_call_id'] ?? ''] ?? null;
                }
                $this->subgraphs[$key] = $handle;
            }

            return [$this->subgraphs[$key]];
        });
    }

    public function extension(string $name): ThreadProjection
    {
        if ($this->extensionsDispatcher === null) {
            $raw = $this->subscribe(['channels' => ['custom', 'lifecycle', 'input']]);
            $raw->endOnRootTerminal = true;
            $this->extensionsDispatcher = new ThreadProjection($raw,
                static fn ($event): array => $event['method'] === 'custom' ? [$event] : []);
        }

        return $this->extensions[$name] ??= new ThreadProjection($this->extensionsDispatcher,
            static fn ($event): array => ($event['params']['data']['name'] ?? null) === $name
                ? [$event['params']['data']['payload'] ?? null] : []);
    }

    /** Source onError registration; callbacks run synchronously during pulls. */
    public function onError(callable $listener): \Closure
    {
        $id = array_search($listener, $this->errorListeners, true);
        if ($id === false) {
            $id = $this->nextListenerId++;
            $this->errorListeners[$id] = $listener;
        }

        return function () use ($id): void { unset($this->errorListeners[$id]); };
    }

    /** PHP diagnostic history; reading does not drive or terminate the stream. */
    public function errors(): array
    {
        return $this->errorLog;
    }

    private function fireError(\Throwable $error): void
    {
        $this->errorLog[] = $error;
        foreach ($this->errorListeners as $listener) {
            try {
                $listener($error);
            } catch (\Throwable) {
                // Source isolates throwing observers from other listeners.
            }
        }
    }

    public function output(): mixed
    {
        return $this->values()->value();
    }

    public function interrupted(): bool
    {
        return $this->isInterrupted;
    }

    public function interrupts(): array
    {
        return $this->interruptPayloads;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->transport->close();
        $this->source = null;
        $this->finish();
        $this->errorListeners = [];
    }

    private function finish(?\Throwable $error = null): void
    {
        foreach ($this->subscriptions as $subscription) {
            $subscription->close($error);
        }
        foreach ($this->messageHandles as $message) {
            $message->close();
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('ThreadStream closed');
        }
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'values' => $this->values(), 'messages' => $this->messages(), 'subgraphs' => $this->subgraphs(),
            'output' => $this->output(), 'interrupted' => $this->interrupted(), 'interrupts' => $this->interrupts(),
            'extensions' => $this->extensions, 'errors' => $this->errors(), default => throw new \OutOfBoundsException('Unknown thread projection: ' . $name),
        };
    }
}
