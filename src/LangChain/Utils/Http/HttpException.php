<?php

declare(strict_types=1);

namespace LangChain\Utils\Http;

/**
 * A request that never produced a usable response.
 *
 * Carries the status and the raw body because a provider's error message is in
 * one or both, and a bare "request failed" is unactionable for whoever has to
 * decide whether it is a bad key, a bad model name, or a rate limit.
 */
class HttpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
