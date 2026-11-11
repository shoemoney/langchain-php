<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

/** A discovered immediate child with namespace-scoped projections. */
final class ThreadSubgraph
{
    public ?array $toolStartedEvent = null;
    private ?ThreadProjection $values = null;
    private ?ThreadProjection $messages = null;
    private ?ThreadProjection $subgraphs = null;

    public function __construct(
        private readonly ThreadStream $thread,
        public readonly string $name,
        public readonly int $index,
        public readonly array $namespace,
        public readonly ?array $cause = null,
        public readonly ?string $graphName = null,
    ) {
    }

    public function subscribe(string|array $channels, array $options = []): ThreadSubscription
    {
        if (is_array($channels) && isset($channels['channels'])) {
            $channels['namespaces'] ??= [$this->namespace];
        } else {
            $options['namespaces'] ??= [$this->namespace];
        }

        return $this->thread->subscribe($channels, $options);
    }

    public function values(): ThreadProjection
    {
        return $this->values ??= $this->thread->valuesProjection($this->namespace);
    }

    public function messages(): ThreadProjection
    {
        return $this->messages ??= $this->thread->messagesProjection($this->namespace);
    }

    public function subgraphs(): ThreadProjection
    {
        return $this->subgraphs ??= $this->thread->subgraphsProjection($this->namespace);
    }

    public function output(): mixed
    {
        return $this->values()->value();
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'values' => $this->values(), 'messages' => $this->messages(), 'subgraphs' => $this->subgraphs(),
            'output' => $this->output(), default => throw new \OutOfBoundsException('Unknown subgraph projection: ' . $name),
        };
    }
}
