<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client\Transport;

/**
 * Incremental parser for `text/event-stream` bodies (WHATWG server-sent events framing).
 */
final class EventStreamParser
{
    private string $buffer = '';

    private string $data = '';

    private bool $hasData = false;

    private string $event = '';

    private ?string $id = null;

    private ?string $lastEventId = null;

    private ?int $retry = null;

    /** The `id:` of the last dispatched event that carried one, for `Last-Event-ID` resumption. */
    public function lastEventId(): ?string
    {
        return $this->lastEventId;
    }

    /** The last `retry:` value in milliseconds the server announced. */
    public function retryMs(): ?int
    {
        return $this->retry;
    }

    /**
     * @return list<array{event: string, data: string, id: string|null}> events completed by `$chunk`
     */
    public function feed(string $chunk): array
    {
        $this->buffer .= $chunk;
        $events = [];

        while (preg_match('/\r\n|\n|\r/', $this->buffer, $match, \PREG_OFFSET_CAPTURE) === 1) {
            [$eol, $offset] = $match[0];
            // A lone trailing CR may be the first half of CRLF: wait for the next chunk.
            if ($eol === "\r" && $offset + 1 === strlen($this->buffer)) {
                break;
            }
            $line = substr($this->buffer, 0, $offset);
            $this->buffer = substr($this->buffer, $offset + strlen($eol));

            $dispatched = $this->line($line);
            if ($dispatched !== null) {
                $events[] = $dispatched;
            }
        }

        return $events;
    }

    /**
     * @return array{event: string, data: string, id: string|null}|null
     */
    private function line(string $line): ?array
    {
        if ($line === '') {
            return $this->dispatch();
        }
        if ($line[0] === ':') {
            return null;
        }

        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        switch ($field) {
            case 'data':
                $this->data .= ($this->hasData ? "\n" : '') . $value;
                $this->hasData = true;
                break;
            case 'event':
                $this->event = $value;
                break;
            case 'id':
                if (!str_contains($value, "\0")) {
                    $this->id = $value;
                }
                break;
            case 'retry':
                if (ctype_digit($value)) {
                    $this->retry = (int) $value;
                }
                break;
        }

        return null;
    }

    /**
     * @return array{event: string, data: string, id: string|null}|null
     */
    private function dispatch(): ?array
    {
        $event = null;
        if ($this->id !== null) {
            $this->lastEventId = $this->id;
        }
        if ($this->hasData) {
            $event = ['event' => $this->event === '' ? 'message' : $this->event, 'data' => $this->data, 'id' => $this->id];
        }
        $this->data = '';
        $this->hasData = false;
        $this->event = '';
        $this->id = null;

        return $event;
    }
}
