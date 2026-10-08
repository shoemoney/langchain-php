<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;

/**
 * An {@see HttpClient} that can also issue the verbs the LangGraph REST API uses besides POST.
 *
 * The shared `HttpClient` seam is POST-only because every model provider is. The LangGraph server is
 * a REST API: `GET /threads/{id}`, `PATCH /runs/crons/{id}`, `PUT /store/items`, `DELETE ...`. This
 * extends the seam rather than replacing it, so the SDK still works against any plain `HttpClient`
 * for the POST endpoints (search, count, create) and only the verbs a plain client cannot carry
 * fail, loudly, with a message naming the verb.
 *
 * Callers pass positionally, never by name (see the note on {@see HttpClient}).
 */
interface MethodHttpClient extends HttpClient
{
    /**
     * Issue a request with any verb and read the whole body.
     *
     * A non-2xx status is returned, not thrown, exactly like {@see HttpClient::post()}.
     *
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse;
}
