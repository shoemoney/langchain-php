<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpResponse;

/**
 * A response whose body has not been read yet: the status and headers, plus the body as it arrives.
 *
 * `chunks` yields raw bytes. It may also yield `null`, meaning "I waited and nothing came": a
 * transport that can poll uses it to hand control back so the idle watchdog can judge the silence
 * (see {@see StreamRetry::idleReconnectStream()}). A transport that can only block simply never
 * yields `null`.
 */
final class StreamResponse
{
    /**
     * @param HttpResponse                $response status and headers (its `body` is empty)
     * @param iterable<string|null>       $chunks
     */
    public function __construct(
        public readonly HttpResponse $response,
        public readonly iterable $chunks,
    ) {
    }
}
