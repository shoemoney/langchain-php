<?php

declare(strict_types=1);

namespace LangChain\Utils\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The default {@see HttpClient}, over Guzzle.
 *
 * Constructed lazily and shared per instance: Guzzle's handler stack holds a
 * connection pool, and building a new client per request throws that away,
 * which is the difference between one TLS handshake and one per call.
 */
final class GuzzleHttpClient implements HttpClient
{
    /**
     * How long a stream may return nothing before it is treated as finished.
     *
     * Comfortably longer than any gap a provider puts between tokens — a
     * reasoning model can pause for seconds — and short enough that a dead
     * connection is noticed rather than waited on.
     */
    public const STREAM_SILENCE_LIMIT = 30.0;

    /**
     * Overridable so a test can prove the stall guard fires without waiting the
     * production limit out. Not part of the public contract — it exists so the
     * behaviour is verifiable rather than merely asserted.
     */
    public float $streamSilenceLimit = self::STREAM_SILENCE_LIMIT;

    private ?Client $client = null;

    /**
     * @param array<string, mixed> $options Guzzle client options, passed
     *                                      through untouched.
     */
    public function __construct(
        private readonly array $options = [],
        private readonly float $defaultTimeout = 60.0,
    ) {
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'query' => $query,
                'timeout' => $timeout ?? $this->defaultTimeout,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new HttpException('Request to ' . $url . ' failed: ' . $e->getMessage(), 0, '', $e);
        }

        return new HttpResponse(
            $response->getStatusCode(),
            $response->getHeaders(),
            (string) $response->getBody(),
        );
    }

    /**
     * @return \Generator<int, string>
     */
    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        // `stream` => true hands back the response before the body is read, so
        // the read loop below can yield each chunk as it lands instead of
        // buffering a whole completion.
        //
        // The whole thing is inside the `try`, not just the request: a generator
        // function runs none of its body until it is iterated, so a `try` around
        // the call alone would never see a connect failure — and
        // `HttpException` is the only type a client is expected to catch.
        try {
            $response = $this->client()->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'query' => $query,
                'timeout' => $timeout ?? $this->defaultTimeout,
                'http_errors' => false,
                'stream' => true,
            ]);

            $status = $response->getStatusCode();
            $stream = $response->getBody();

            if ($status < 200 || $status >= 300) {
                // The error arrives on the same stream; read it out so the
                // caller gets the provider's own message rather than a bare
                // status code.
                $errorBody = (string) $stream;
                $stream->close();

                throw new HttpException(
                    'Streaming request to ' . $url . ' failed with status ' . $status . '.',
                    $status,
                    $errorBody,
                );
            }

            try {
                // An empty read is not end-of-stream: a non-blocking stream can
                // report '' with eof() still false, so looping on the boolean
                // alone either spins forever (never yielding, never failing) or
                // — bounded by a *count*, as it first was here — gives up after
                // two reads and truncates a healthy stream mid-answer, since a
                // brief gap between tokens is routinely sub-millisecond.
                //
                // The bound is therefore wall-clock, not iterations: keep
                // polling freely while data is plausibly still coming, and
                // only end the stream once it has been silent long enough that
                // it is no longer "between tokens" but "gone".
                $silentSince = null;

                while (!$stream->eof()) {
                    $chunk = $stream->read(8192);

                    if ($chunk === '' || $chunk === false) {
                        $silentSince ??= microtime(true);

                        if ((microtime(true) - $silentSince) >= $this->streamSilenceLimit) {
                            break;
                        }

                        usleep(1000);

                        continue;
                    }

                    $silentSince = null;

                    yield $chunk;
                }
            } finally {
                $stream->close();
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (GuzzleException $e) {
            throw new HttpException('Stream from ' . $url . ' failed: ' . $e->getMessage(), 0, '', $e);
        }
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->options);
    }
}
