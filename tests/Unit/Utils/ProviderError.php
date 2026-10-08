<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

/**
 * Stand-in for an SDK error: a plain exception that also carries the loose
 * fields the JavaScript originals hang off `Error` (`status`, `response`, `headers`...).
 */
final class ProviderError extends \RuntimeException
{
    /**
     * @param null|array<string, mixed>        $response
     * @param null|array<string, mixed>|object $headers
     * @param null|array<string, mixed>        $error
     */
    public function __construct(
        string $message = '',
        public ?int $status = null,
        public ?int $statusCode = null,
        public ?array $response = null,
        public array|object|null $headers = null,
        public ?array $error = null,
        public ?string $name = null,
        ?string $errorCode = null,
    ) {
        parent::__construct($message);
        if ($errorCode !== null) {
            $this->code = $errorCode;
        }
    }
}
