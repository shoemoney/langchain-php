<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

use LangChain\Utils\Http\HttpException;

/**
 * An error reported by an Ollama server.
 *
 * Ollama's error body is `{"error": "<message>"}`: a bare string, with no
 * machine-readable type alongside it. The message is kept verbatim because it
 * is the only diagnostic there is ("model 'x' not found, try pulling it first").
 */
final class OllamaException extends HttpException
{
    public static function fromResponse(
        string $body,
        int $status,
        string $url,
        ?\Throwable $previous = null,
    ): self {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        $message = is_string($error) && $error !== ''
            ? $error
            : 'Ollama request to ' . $url . ' failed with status ' . $status . '.';

        return new self($message, $status, $body, $previous);
    }
}
