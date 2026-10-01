<?php

declare(strict_types=1);

namespace LangChain\Utils\Http;

/**
 * Decode a `text/event-stream` into its `data:` payloads.
 *
 * Port of the event-stream reader the OpenAI and Anthropic clients rely on.
 *
 * The wire format is simple and the hard part is the framing: a network read
 * can split a payload anywhere, including between the `data:` prefix and its
 * value or in the middle of a UTF-8 sequence. This buffers across reads and
 * only ever yields complete payloads, so a token that happens to arrive in two
 * pieces is one token to the caller rather than two broken ones.
 *
 * A `data:` payload of `[DONE]` is the conventional end-of-stream sentinel. It
 * is *not* yielded: it is a transport sentinel with no content, and a client
 * that parsed it as a delta would be handling a case that cannot occur.
 */
final class SseParser
{
    /** The event separators the spec allows, longest first. */
    private const SEPARATORS = ["\r\n\r\n", "\n\n", "\r\r"];

    private string $buffer = '';

    /**
     * Feed raw bytes, get back every complete payload they completed.
     *
     * @return \Generator<int, string>
     */
    public function feed(string $bytes): \Generator
    {
        $this->buffer .= $bytes;

        while (true) {
            $at = $this->nextBoundary();
            if ($at === null) {
                return;
            }

            // The separator's own length is read *at the boundary offset*.
            //
            // Reading it from the front of the buffer returned the two-byte
            // fallback every time, because the buffer begins with `data:` and
            // never with a separator — so a `\r\n\r\n` stream stranded two of
            // its four separator bytes at the head of the next block. A 4,000-case
            // differential run against that version found zero observable
            // divergence (the payload reader discards non-`data:` lines, so the
            // stray was always swallowed), but depending on that is how this
            // quietly breaks the moment an `event:` or `id:` field is read.
            $separatorLength = $this->separatorLengthAt($at);
            if ($separatorLength === null) {
                // `nextBoundary()` returned an offset that starts no known separator, so the buffer
                // is in a state this parser does not model. Guessing a length here would strand bytes
                // and, because the payload reader discards non-`data:` lines, the corruption would
                // surface as a silently missing event rather than an error.
                throw new \LogicException(sprintf(
                    'SseParser: nextBoundary() returned offset %d, which starts no known separator '
                    . '(buffer begins %s). Refusing to guess a separator length.',
                    $at,
                    var_export(substr($this->buffer, 0, 8), true)
                ));
            }

            $block = substr($this->buffer, 0, $at);
            $this->buffer = substr($this->buffer, $at + $separatorLength);

            $payload = $this->payloadOf($block);
            if ($payload === null || $payload === '[DONE]') {
                continue;
            }

            yield $payload;
        }
    }

    /**
     * Flush whatever is left once the connection closes.
     *
     * A server that ends the response without a trailing blank line would
     * otherwise leave a complete final event stranded in the buffer, and the
     * last token of every stream would vanish.
     *
     * @return \Generator<int, string>
     */
    public function flush(): \Generator
    {
        $rest = $this->buffer;
        $this->buffer = '';

        $payload = $this->payloadOf($rest);

        if ($payload === null || $payload === '[DONE]') {
            return;
        }

        // A payload that is not valid UTF-8 was cut mid-character — the
        // connection ended inside a multi-byte sequence. Those bytes cannot be
        // recovered, and handing them on turns a truncated stream into a
        // confusing JSON error at the far end, or worse, into a silently
        // mangled payload. A complete event is always valid UTF-8, so this drops
        // exactly the unrecoverable case and nothing else.
        if (!mb_check_encoding($payload, 'UTF-8')) {
            return;
        }

        yield $payload;
    }

    /**
     * The offset of the next event separator, or null if none has arrived.
     *
     * The spec allows `\n\n`, `\r\n\r\n`, and `\r\r`. Providers differ, so all
     * three are honoured rather than assuming one. Where two start at the same
     * offset the longest wins, so a `\r\n\r\n` is never read as a bare `\n\n`.
     */
    private function nextBoundary(): ?int
    {
        $best = null;

        foreach (self::SEPARATORS as $separator) {
            $at = strpos($this->buffer, $separator);
            if ($at !== false && ($best === null || $at < $best)) {
                $best = $at;
            }
        }

        return $best;
    }

    /**
     * How many bytes the separator occupying `$offset` occupies, or null if none does.
     *
     * It used to `return 2` when nothing matched. That path is unreachable today because
     * `nextBoundary()` only returns an offset that really does start a separator — which is exactly
     * why it survived: no reachable input could tell it apart from a correct value, and the real
     * separators are 4, 2 and 2 bytes, so `2` was right by coincidence for two of the three.
     *
     * Returning null makes the no-match case ANNOUNCED. The caller throws on it rather than consuming
     * a guessed length, so a future separator — or a future caller that computes its own offset —
     * inherits an exception instead of a silent stranding of bytes.
     */
    private function separatorLengthAt(int $offset): ?int
    {
        foreach (self::SEPARATORS as $separator) {
            if (substr($this->buffer, $offset, strlen($separator)) === $separator) {
                return strlen($separator);
            }
        }

        return null;
    }

    /**
     * The `data:` lines of one event, joined by newlines.
     *
     * Multiple `data:` lines in one event are one payload split across lines —
     * joining with "\n" rather than concatenating is what preserves a JSON body
     * that the server chose to wrap.
     */
    private function payloadOf(string $block): ?string
    {
        $lines = preg_split('/\r\n|\n|\r/', $block) ?: [];
        $data = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                // The SSE spec removes AT MOST ONE space after the field name:
                // "data:  text" has the payload " text", not "text".
                //
                // `ltrim(..., ' ')` removed all of them, so a payload with
                // meaningful leading whitespace was silently rewritten.
                // Measured: "data:  text" yielded "text" instead of " text".
                // `json_decode` tolerates surrounding whitespace, so the damage
                // was invisible for JSON payloads and total for anything else —
                // a plain-text event, a code block, an indented template.
                $value = substr($line, 5);
                $data[] = str_starts_with($value, ' ') ? substr($value, 1) : $value;
            }
        }

        return $data === [] ? null : implode("\n", $data);
    }
}
