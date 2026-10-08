<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

/**
 * Incremental parser for newline-delimited JSON.
 *
 * Ollama streams one JSON object per line, NOT server-sent events: there is no
 * `data:` prefix and no blank-line separator. Feeding that to the SSE parser
 * silently yields nothing, because it never sees a `data:` field.
 *
 * Bytes arrive in arbitrary pieces, so a line may be split across feeds; the
 * unterminated tail is held until its newline arrives (or {@see self::flush()}).
 */
final class NdjsonParser
{
    private string $buffer = '';

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function feed(string $bytes): \Generator
    {
        $this->buffer .= $bytes;

        while (($newline = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $newline);
            $this->buffer = substr($this->buffer, $newline + 1);

            $decoded = self::decode($line);
            if ($decoded !== null) {
                yield $decoded;
            }
        }
    }

    /**
     * Emit a final line that was not newline-terminated.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function flush(): \Generator
    {
        $line = $this->buffer;
        $this->buffer = '';

        $decoded = self::decode($line);
        if ($decoded !== null) {
            yield $decoded;
        }
    }

    /**
     * A malformed line is fatal rather than skipped: a stream that has quietly
     * dropped a line has dropped part of the answer.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OllamaException(
                'Received a malformed line from the Ollama stream: ' . $e->getMessage() . '. Line: ' . $line,
                0,
                $line,
            );
        }

        return is_array($decoded) ? $decoded : null;
    }
}
