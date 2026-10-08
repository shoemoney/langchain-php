<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\TogetherAI;

/**
 * An error reported by the Together AI API.
 *
 * Upstream throws a bare `Error` whose message is the fixed prefix followed by
 * the pretty-printed response body. The status is carried as a field so the
 * retry policy ({@see \LangChain\Utils\AsyncCaller}) can tell a 400 it must not
 * repeat from a 503 it may.
 */
final class TogetherAIException extends \RuntimeException
{
    public const MESSAGE_PREFIX = 'Error getting prompt completion from Together AI. ';

    public function __construct(string $message, public readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    /**
     * `JSON.stringify(body, null, 2)` for the failed response, appended to the prefix.
     */
    public static function fromBody(int $status, string $body): self
    {
        $decoded = json_decode($body);
        $pretty = $decoded === null && trim($body) !== 'null'
            ? $body
            : (string) json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        // PHP indents by four spaces and JavaScript by two.
        $pretty = (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $pretty,
        );

        return new self(self::MESSAGE_PREFIX . $pretty, $status);
    }
}
