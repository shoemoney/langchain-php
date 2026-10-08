<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `SSEDecoder` from `utils/sse.ts`: assemble `event:` / `data:` / `id:` / `retry:` lines into
 * stream parts `{id, event, data}`.
 *
 * ## Why this is not {@see \LangChain\Utils\Http\SseParser}
 *
 * `SseParser` yields only the `data:` payload of each event, joined with "\n", and drops the event
 * name and id. The LangGraph protocol is carried in the `event:` field (`values`, `updates`,
 * `error`, `end`...), and reconnection depends on `id:`, so the field-level decoder is a different
 * contract rather than a copy of that one. The two also join multi-line `data:` differently: the
 * SDK concatenates the raw lines (the server never splits a JSON document across data lines), where
 * `SseParser` follows the spec and joins with a newline.
 *
 * Per the SSE spec the last event id persists across events, a `:` comment line is ignored, and an
 * event with no fields at all is not dispatched.
 */
final class SseDecoder
{
    private string $event = '';

    /** @var list<string> */
    private array $data = [];

    private string $lastEventId = '';

    private ?int $retry = null;

    /**
     * Feed ONE line (no terminator). An empty line dispatches the pending event.
     *
     * @return array{id: string|null, event: string, data: mixed}|null
     */
    public function feed(string $line): ?array
    {
        if ($line === '') {
            if ($this->event === '' && $this->data === [] && $this->lastEventId === '' && $this->retry === null) {
                return null;
            }

            $part = $this->part();

            // As per the SSE spec, do not reset lastEventId.
            $this->event = '';
            $this->data = [];
            $this->retry = null;

            return $part;
        }

        if ($line[0] === ':') {
            return null;
        }

        $sep = strpos($line, ':');
        if ($sep === false) {
            return null;
        }

        $field = substr($line, 0, $sep);
        $value = substr($line, $sep + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($field === 'event') {
            $this->event = $value;
        } elseif ($field === 'data') {
            $this->data[] = $value;
        } elseif ($field === 'id') {
            if (!str_contains($value, "\0")) {
                $this->lastEventId = $value;
            }
        } elseif ($field === 'retry') {
            if (preg_match('/^\s*[+-]?\d+/', $value, $m) === 1) {
                $this->retry = (int) $m[0];
            }
        }

        return null;
    }

    /**
     * The stream ended. Like upstream this emits a pending event only if it has a NAME; a pending
     * `data:` with no `event:` and no blank line after it is dropped.
     *
     * @return array{id: string|null, event: string, data: mixed}|null
     */
    public function flush(): ?array
    {
        return $this->event !== '' ? $this->part() : null;
    }

    /**
     * Decode raw network chunks all the way to stream parts.
     *
     * @param iterable<string> $chunks
     *
     * @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    public static function decode(iterable $chunks): \Generator
    {
        $decoder = new self();
        foreach (BytesLineDecoder::decode($chunks) as $line) {
            $part = $decoder->feed($line);
            if ($part !== null) {
                yield $part;
            }
        }
        $last = $decoder->flush();
        if ($last !== null) {
            yield $last;
        }
    }

    /**
     * @return array{id: string|null, event: string, data: mixed}
     */
    private function part(): array
    {
        return [
            'id' => $this->lastEventId !== '' ? $this->lastEventId : null,
            'event' => $this->event,
            'data' => $this->data === [] ? null : json_decode(implode('', $this->data), true, 512, \JSON_THROW_ON_ERROR),
        ];
    }
}
