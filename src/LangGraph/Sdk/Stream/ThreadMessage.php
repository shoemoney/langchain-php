<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

use LangChain\Messages\AIMessage;
use LangGraph\Stream\StreamChannel;

/** A live message handle assembled from protocol messages events. */
final class ThreadMessage implements \IteratorAggregate
{
    private readonly StreamChannel $events;
    public ?array $error = null;
    private array $blocks = [];
    private ?array $usage = null;
    private ?array $rawUsage = null;
    private ?array $metadata = null;
    private ?string $finishReason = null;
    private array $finishMetadata = [];
    private array $blockAliases = [];

    public function __construct(
        public readonly string $id,
        public readonly array $namespace,
        public readonly ?string $node,
        public readonly ?string $runId,
        callable $driver,
    ) {
        $this->events = StreamChannel::local();
        $this->events->setDriver($driver);
    }

    public function consume(array $data): void
    {
        $this->events->push($data);
        if (is_array($data['usage'] ?? null)) {
            $this->rawUsage = $data['usage'];
            $this->usage = $data['usage'] + ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        }
        $event = $data['event'] ?? '';
        if ($event === 'message-start') {
            $this->metadata = $data['metadata'] ?? null;
        }
        if (str_starts_with($event, 'content-block-')) {
            $index = $data['index'] ?? 0;
            $content = $data['content'] ?? null;
            $delta = $data['delta'] ?? null;
            $type = $content['type'] ?? match ($delta['type'] ?? null) {
                'text-delta' => 'text', 'reasoning-delta' => 'reasoning', 'block-delta' => $delta['fields']['type'] ?? $this->blocks[$index]['type'] ?? null,
                default => $this->blocks[$index]['type'] ?? null,
            };
            $alias = $index . ':' . $type;
            if (isset($this->blockAliases[$alias])) {
                $index = $this->blockAliases[$alias];
            } elseif (isset($this->blocks[$index]) && $this->blocks[$index]['type'] !== $type
                && !self::compatible($this->blocks[$index]['type'], $type)) {
                $index = count($this->blocks);
            }
            $this->blockAliases[$alias] = $index;
            if ($event === 'content-block-start' || $event === 'content-block-finish') {
                if (is_array($content)) {
                    $this->blocks[$index] = $content;
                }
            } elseif ($event === 'content-block-delta') {
                $current = $this->blocks[$index] ?? ['type' => $type];
                if (is_array($content)) {
                    $next = [...$current, ...$content];
                    if (in_array($content['type'] ?? null, ['tool_call_chunk', 'server_tool_call_chunk'], true)) {
                        foreach (['id', 'name'] as $identifier) {
                            if (($content[$identifier] ?? null) === null && isset($current[$identifier])) {
                                $next[$identifier] = $current[$identifier];
                            }
                        }
                    }
                    foreach (['text', 'reasoning', 'args', 'data'] as $field) {
                        if (is_string($content[$field] ?? null)) {
                            $next[$field] = ($current[$field] ?? '') . $content[$field];
                        }
                    }
                    $this->blocks[$index] = $next;
                } elseif (is_array($delta)) {
                    $field = match ($delta['type'] ?? null) {
                        'text-delta' => 'text', 'reasoning-delta' => 'reasoning', 'data-delta' => 'data', default => null,
                    };
                    $this->blocks[$index] = $field === null ? [...$current, ...($delta['fields'] ?? [])]
                        : [...$current, $field => ($current[$field] ?? '') . ($delta[$field] ?? '')];
                }
            }
        }
        if ($event === 'message-finish') {
            $this->finishReason = $data['reason'] ?? null;
            $this->finishMetadata = [...($data['responseMetadata'] ?? []), ...($data['metadata'] ?? [])];
            $this->events->close();
        } elseif ($event === 'error') {
            $this->error = ['message' => $data['message'] ?? 'Message stream failed', 'code' => $data['code'] ?? null];
            // StreamingMessage[ERROR] calls FINISH upstream: retain partial
            // content/usage and settle each view rather than rejecting it.
            $this->events->close();
        }
    }

    private static function compatible(?string $left, ?string $right): bool
    {
        foreach ([['tool_call', 'tool_call_chunk', 'tool_use', 'input_json_delta'], ['server_tool_call', 'server_tool_call_chunk']] as $group) {
            if (in_array($left, $group, true) && in_array($right, $group, true)) {
                return true;
            }
        }

        return false;
    }

    public function getIterator(): \Generator
    {
        yield from $this->events;
    }

    private function drain(): void
    {
        foreach ($this->events as $ignored) {
        }
    }

    public function text(): string
    {
        $this->drain();

        return implode('', array_column(array_filter($this->blocks, static fn ($block): bool => ($block['type'] ?? '') === 'text'), 'text'));
    }

    public function reasoning(): string
    {
        $this->drain();

        return implode('', array_column(array_filter($this->blocks, static fn ($block): bool => ($block['type'] ?? '') === 'reasoning'), 'reasoning'));
    }

    public function usage(): ?array
    {
        $this->drain();

        return $this->usage;
    }

    /** Current assembled blocks; unlike final-value methods this does not pull. */
    public function blocks(): array
    {
        return array_values($this->blocks);
    }

    public function output(): AIMessage
    {
        $this->drain();
        $calls = [];
        foreach ($this->blocks as $block) {
            if (($block['type'] ?? null) === 'tool_call') {
                $calls[] = ['name' => $block['name'] ?? '', 'args' => $block['args'] ?? [], 'id' => $block['id'] ?? null, 'type' => 'tool_call'];
            }
        }

        $context = ['namespace' => $this->namespace];
        foreach (['node' => $this->node, 'run_id' => $this->runId, 'metadata' => $this->metadata, 'usage' => $this->rawUsage] as $key => $value) {
            if ($value !== null) {
                $context[$key] = $value;
            }
        }
        $responseMetadata = $this->finishReason === null ? [] : ['finish_reason' => $this->finishReason];

        return new AIMessage(['id' => $this->id, 'content' => array_values($this->blocks),
            'tool_calls' => $calls, 'usage_metadata' => $this->usage, 'additional_kwargs' => $context,
            'response_metadata' => [...$responseMetadata, ...$this->finishMetadata, 'output_version' => 'v1']]);
    }

    public function close(): void
    {
        $this->events->close();
    }
}
