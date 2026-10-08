<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * A {@see MethodHttpClient} that can also hand back a response BEFORE its body has been read.
 *
 * {@see MethodHttpClient::request()} reads the whole body, and the shared `HttpClient::postStream()`
 * is POST-only and returns no headers, but the LangGraph stream endpoints need both a GET
 * (`joinStream`) and the response headers (`Content-Location`, `Location`) ahead of the first event.
 * This is the seam for that. A client that does not implement it still works: the SDK falls back to
 * `request()` and delivers the buffered body as one chunk, which parses identically but is not
 * incremental.
 *
 * As with `post()`, a non-2xx status is returned, not thrown, and callers pass positionally.
 */
interface StreamingMethodHttpClient extends MethodHttpClient
{
    /**
     * @param array<string, string> $headers
     */
    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse;
}
