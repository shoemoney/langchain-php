<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * One AI message lifecycle surfaced on `run.messages`: its raw lifecycle events plus the accumulated
 * `text`, `reasoning` and `usage`.
 *
 * Upstream hands out `@langchain/core`'s `ChatModelStream` (a 685-line content-block assembler with
 * promise-valued projections). That class has no PHP counterpart, so this is the thin form the run stream
 * needs: the events are replayable, and the three projections are computed by reading them. Accumulation
 * understands `content-block-delta` events carrying either a `delta` (`text-delta`, `reasoning-delta`) or a
 * whole `content` block (`text`, `reasoning`, `thinking`), and `message-finish` `usage`.
 *
 * `text()`, `reasoning()` and `usage()` read the events to the end, which, on a stream wired to a pulling
 * mux, advances the run until this message has finished.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final class ChatModelStream implements \IteratorAggregate
{
    /**
     * @param StreamChannel<array<string, mixed>> $source
     * @param list<string> $namespace graph namespace of the node that produced this stream
     */
    public function __construct(
        private readonly StreamChannel $source,
        public readonly array $namespace = [],
        public readonly ?string $node = null,
    ) {
    }

    /**
     * The lifecycle events, from the start, each iteration independent.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        return $this->source->iterate();
    }

    /** All text deltas, concatenated. */
    public function text(): string
    {
        return $this->accumulate()['text'];
    }

    /** All reasoning deltas, concatenated. */
    public function reasoning(): string
    {
        return $this->accumulate()['reasoning'];
    }

    /** The usage reported on `message-finish` or `message-start`, if any. */
    public function usage(): ?array
    {
        return $this->accumulate()['usage'];
    }

    /**
     * @return array{text: string, reasoning: string, usage: array<string, mixed>|null}
     */
    private function accumulate(): array
    {
        $text = '';
        $reasoning = '';
        $usage = null;

        foreach ($this->source->iterate() as $event) {
            switch ($event['event'] ?? null) {
                case 'content-block-delta':
                    $delta = $event['delta'] ?? null;
                    $content = $event['content'] ?? null;
                    if (\is_array($delta)) {
                        if (($delta['type'] ?? null) === 'text-delta') {
                            $text .= (string) ($delta['text'] ?? '');
                        } elseif (($delta['type'] ?? null) === 'reasoning-delta') {
                            $reasoning .= (string) ($delta['reasoning'] ?? '');
                        }
                    } elseif (\is_array($content)) {
                        $type = $content['type'] ?? null;
                        if ($type === 'text' && \is_string($content['text'] ?? null)) {
                            $text .= $content['text'];
                        } elseif ($type === 'reasoning' && \is_string($content['reasoning'] ?? null)) {
                            $reasoning .= $content['reasoning'];
                        } elseif ($type === 'thinking' && \is_string($content['thinking'] ?? null)) {
                            $reasoning .= $content['thinking'];
                        }
                    }
                    break;

                case 'message-start':
                case 'message-finish':
                    if (\is_array($event['usage'] ?? null)) {
                        $usage = $event['usage'];
                    }
                    break;
            }
        }

        return ['text' => $text, 'reasoning' => $reasoning, 'usage' => $usage];
    }
}
