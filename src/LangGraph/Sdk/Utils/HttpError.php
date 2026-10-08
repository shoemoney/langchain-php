<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpResponse;

/**
 * Port of the private `HTTPError` in `utils/async_caller.ts`: a failed request, with the status and
 * body text, and (when asked) the response itself so a caller can read headers off it.
 */
final class HttpError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $text,
        public readonly ?HttpResponse $response = null,
    ) {
        parent::__construct("HTTP {$status}: {$text}", $status);
    }

    public static function fromResponse(HttpResponse $response, bool $includeResponse = false): self
    {
        return new self(
            $response->status,
            $response->body,
            $includeResponse ? $response : null,
        );
    }
}
