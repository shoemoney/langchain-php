<?php

declare(strict_types=1);

namespace LangChain\Utils\Http;

/**
 * The one place this SDK performs network I/O.
 *
 * Every provider client talks to a provider through this interface rather than
 * through Guzzle directly. That is not an abstraction for its own sake: a model
 * client that constructs its own HTTP client cannot be tested without a socket,
 * and a chat model's logic is almost entirely in *translating* — messages in,
 * wire format, response back out. Tests need to exercise that translation
 * against a known payload, which means they need to supply the payload.
 *
 * Implementations must not throw on a non-2xx status. An HTTP error is a
 * response the caller has to interpret — a provider puts a machine-readable
 * error object in the body — so it is returned like any other response and
 * {@see HttpResponse::isOk()} reports on it.
 *
 * ## Parameter names are NOT part of the contract
 *
 * Implementations may name their parameters anything they like. Callers in
 * this SDK pass **positionally**, never by name, precisely because a PHP named
 * argument binds to the *implementing* class's parameter name: a caller writing
 * `->post($url, $h, $body, timeout: 30.0)` makes every implementation that
 * spells it `$t` fail at runtime with "Unknown named parameter", even though it
 * satisfies the interface. Keep it that way when adding a caller.
 */
interface HttpClient
{
    /**
     * Issue a POST and read the whole body.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query    Appended to the URL as a query string.
     */
    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse;

    /**
     * Issue a POST and yield the body incrementally.
     *
     * Yields raw bytes exactly as they arrive. Decoding the provider's framing
     * (server-sent events, newline-delimited JSON) is the client's job, not the
     * transport's, because the framing differs per provider and is part of the
     * protocol this SDK is porting.
     *
         * Unlike {@see self::post()}, a non-2xx status on the STREAM path is
     * raised rather than returned: the body is read and attached to the thrown
     * {@see HttpException}, because a stream that has failed has nothing to
     * return it in. Callers see a `HttpException` here and a `HttpResponse` in
     * `post()`, which is deliberate but worth knowing when writing a transport.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     *
     * @return \Generator<int, string>
     */
    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator;
}
