<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `BytesLineDecoder` from `utils/sse.ts`: split a byte stream into lines on `\n`, `\r` or
 * `\r\n`, buffering a line that a chunk boundary cut in half.
 *
 * A chunk ending in a bare `\r` is held back one chunk, because the next chunk may begin with the
 * `\n` of a CRLF pair split across the network read.
 */
final class BytesLineDecoder
{
    private string $buffer = '';

    private bool $trailingCr = false;

    /**
     * @return list<string> The complete lines this chunk finished.
     */
    public function feed(string $chunk): array
    {
        $text = $chunk;

        if ($this->trailingCr) {
            $text = "\r" . $text;
            $this->trailingCr = false;
        }

        if ($text !== '' && str_ends_with($text, "\r")) {
            $this->trailingCr = true;
            $text = substr($text, 0, -1);
        }

        if ($text === '') {
            return [];
        }

        $trailingNewline = str_ends_with($text, "\r") || str_ends_with($text, "\n");

        $lines = preg_split('/\r\n|\r|\n/', $text);
        if ($trailingNewline) {
            array_pop($lines);
        }

        if (count($lines) === 1 && !$trailingNewline) {
            $this->buffer .= $lines[0];

            return [];
        }

        if ($this->buffer !== '') {
            $lines[0] = $this->buffer . $lines[0];
            $this->buffer = '';
        }

        if (!$trailingNewline && $lines !== []) {
            $this->buffer = (string) array_pop($lines);
        }

        return $lines;
    }

    /**
     * @return list<string> The unterminated final line, if the stream ended mid-line.
     */
    public function flush(): array
    {
        if ($this->buffer === '') {
            return [];
        }

        $line = $this->buffer;
        $this->buffer = '';

        return [$line];
    }

    /**
     * Decode a whole sequence of chunks.
     *
     * @param iterable<string> $chunks
     *
     * @return \Generator<int, string>
     */
    public static function decode(iterable $chunks): \Generator
    {
        $decoder = new self();
        foreach ($chunks as $chunk) {
            yield from $decoder->feed($chunk);
        }
        yield from $decoder->flush();
    }
}
