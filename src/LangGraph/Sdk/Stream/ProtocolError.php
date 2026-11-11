<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

/** An error response from the thread-centric protocol. */
final class ProtocolError extends \RuntimeException
{
    public readonly string $protocolCode;

    public function __construct(public readonly array $response)
    {
        $this->protocolCode = is_string($response['error'] ?? null) ? $response['error'] : '';
        parent::__construct((string) ($response['message'] ?? 'Protocol request failed'));
    }
}
