<?php

declare(strict_types=1);

namespace LangChain\Utils\Http;

/**
 * One HTTP response, whole or in pieces.
 *
 * Header lookup is case-insensitive because the header *names* are protocol
 * (they are lower-case on the wire for HTTP/2 and title-case for HTTP/1.1) and
 * a client that is fussy about it breaks on a perfectly good response.
 *
 * Values are flattened to a single string on the way in. PSR-7's
 * `getHeaders()` returns `array<string, string[]>` — one entry per header line —
 * so a response carrying two `Set-Cookie` headers has an array value. Storing
 * that verbatim made `header()` return an array from a method declared `?string`,
 * which is a `TypeError` on every response the real transport produced. The
 * `FakeHttpClient` used strings, so no test ever saw the shape that actually
 * arrives.
 */
final class HttpResponse
{
    /** @var array<string, string> Lower-cased header name => value. */
    private array $headers;

    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalised = [];
        foreach ($headers as $name => $value) {
            $normalised[strtolower((string) $name)] = is_array($value)
                ? implode(', ', $value)
                : (string) $value;
        }
        $this->headers = $normalised;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The body decoded as JSON.
     *
     * A body that is not JSON is a hard failure, not a null: a provider that
     * answers with an HTML error page has told us something, and swallowing it
     * as "no data" would hide the only evidence there is. The raw body travels
     * along in the exception.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpException(
                'Response body was not valid JSON: ' . $e->getMessage(),
                $this->status,
                $this->body,
            );
        }

        if (!is_array($decoded)) {
            throw new HttpException(
                'Expected a JSON object in the response body, got ' . get_debug_type($decoded) . '.',
                $this->status,
                $this->body,
            );
        }

        return $decoded;
    }
}
