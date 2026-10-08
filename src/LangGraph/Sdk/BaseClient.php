<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\AsyncCaller;
use LangGraph\Sdk\Utils\Env;
use LangGraph\Sdk\Utils\GuzzleMethodHttpClient;
use LangGraph\Sdk\Utils\Headers;
use LangGraph\Sdk\Utils\InFlightRead;
use LangGraph\Sdk\Utils\MethodHttpClient;

/**
 * Port of `client/base.ts`: the shared request machinery behind every LangGraph SDK sub-client.
 *
 * ## Config
 *
 * The constructor takes the `ClientConfig` as an array, because PHP cannot tell "omitted" from
 * "undefined" any other way and the difference is load-bearing for `apiKey`:
 *
 *  - `apiUrl` (default `http://localhost:8123`; one trailing slash is trimmed)
 *  - `apiKey`: a string uses it; **omitted** auto-loads `LANGGRAPH_API_KEY`, `LANGSMITH_API_KEY`,
 *    `LANGCHAIN_API_KEY` in that order; an explicit **null** disables the lookup
 *  - `callerOptions`: forwarded to {@see AsyncCaller} (defaults `maxRetries` 4, `maxConcurrency` 4)
 *  - `timeoutMs`: default per-request timeout
 *  - `defaultHeaders`: headers on every request
 *  - `onRequest`: `callable(string $url, array $init): array` that may replace the prepared request
 *  - `streamProtocol`: `legacy` (default) — stored for WP-23b's stream clients
 *
 * Known non-exact behaviour: there is no global `langgraph_api:url` / `fetch` override (those are JS
 * `Symbol.for` globals), and `signal` is carried through on the request but never aborts anything.
 */
class BaseClient
{
    public const DEFAULT_API_URL = 'http://localhost:8123';

    public const REGEX_RUN_METADATA = '#(/threads/(?<thread_id>.+))?/runs/(?<run_id>.+)#';

    /**
     * Idempotent reads currently on the wire, shared across every client instance. Entries live only
     * while a request is in flight and carry no TTL, so this can never serve stale data.
     *
     * @var array<string, InFlightRead>
     */
    private static array $inFlightReads = [];

    protected AsyncCaller $asyncCaller;

    protected ?int $timeoutMs;

    protected string $apiUrl;

    /** @var array<string, mixed> */
    protected array $defaultHeaders;

    /** @var (callable(string, array<string, mixed>): array<string, mixed>)|null */
    protected $onRequest;

    protected string $streamProtocol;

    protected HttpClient $http;

    /**
     * @param array<string, mixed> $config See the class docblock.
     * @param HttpClient|null      $http   The transport. Defaults to Guzzle, which carries every verb.
     */
    public function __construct(array $config = [], ?HttpClient $http = null)
    {
        $this->http = $http ?? new GuzzleMethodHttpClient();

        $callerOptions = array_merge(['maxRetries' => 4, 'maxConcurrency' => 4], $config['callerOptions'] ?? []);
        $callerOptions['fetch'] ??= fn (string $url, array $init): HttpResponse => $this->send($url, $init);

        $this->asyncCaller = new AsyncCaller($callerOptions);
        $this->timeoutMs = isset($config['timeoutMs']) ? (int) $config['timeoutMs'] : null;

        $apiUrl = (string) ($config['apiUrl'] ?? '');
        $this->apiUrl = $apiUrl !== '' ? preg_replace('#/$#', '', $apiUrl) : self::DEFAULT_API_URL;
        $this->apiUrl = $this->apiUrl !== '' ? $this->apiUrl : self::DEFAULT_API_URL;
        $this->defaultHeaders = $config['defaultHeaders'] ?? [];
        $this->onRequest = $config['onRequest'] ?? null;
        $this->streamProtocol = $config['streamProtocol'] ?? 'legacy';

        $apiKey = self::getApiKey(array_key_exists('apiKey', $config) ? $config['apiKey'] : false);
        if ($apiKey !== null) {
            $this->defaultHeaders['x-api-key'] = $apiKey;
        }
    }

    /**
     * Get the API key from the environment.
     *
     * Precedence: an explicit non-empty string, then LANGGRAPH_API_KEY, LANGSMITH_API_KEY,
     * LANGCHAIN_API_KEY. `null` skips the environment lookup entirely; `false` (the PHP stand-in for
     * "argument not passed") performs it.
     */
    public static function getApiKey(string|false|null $apiKey = false): ?string
    {
        if ($apiKey === null) {
            return null;
        }

        if ($apiKey !== false && $apiKey !== '') {
            return $apiKey;
        }

        foreach (['LANGGRAPH', 'LANGSMITH', 'LANGCHAIN'] as $prefix) {
            $envKey = Env::getEnvironmentVariable($prefix . '_API_KEY');
            if ($envKey !== null && $envKey !== '') {
                return preg_replace('/^["\']|["\']$/', '', trim($envKey));
            }
        }

        return null;
    }

    /**
     * Port of `getRunMetadataFromResponse`: read the run (and thread) id from `Content-Location`.
     *
     * @return array{run_id: string, thread_id: string|null}|null
     */
    public static function getRunMetadataFromResponse(HttpResponse $response): ?array
    {
        $contentLocation = $response->header('Content-Location');
        if ($contentLocation === null || $contentLocation === '') {
            return null;
        }

        if (preg_match(self::REGEX_RUN_METADATA, $contentLocation, $m) !== 1 || ($m['run_id'] ?? '') === '') {
            return null;
        }

        $threadId = $m['thread_id'] ?? '';

        return ['run_id' => $m['run_id'], 'thread_id' => $threadId !== '' ? $threadId : null];
    }

    public static function isRecord(mixed $value): bool
    {
        return is_array($value) || is_object($value);
    }

    /**
     * Build the URL and request init for `$path`.
     *
     * Options: `method`, `headers`, `json`, `params`, `timeoutMs` (an explicit null disables the
     * configured timeout for this request), `signal`, `withResponse`, `dedupe`.
     *
     * `params` become repeated `key=value` pairs: a null is skipped, a list is one pair per element,
     * and a non-string/number value (a bool, a map) is JSON-encoded.
     *
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: array{method?: string, headers: array<string, string>, body?: string, signal?: mixed, timeoutMs: int|null}}
     */
    protected function prepareFetchOptions(string $path, array $options = []): array
    {
        $init = ['headers' => Headers::merge($this->defaultHeaders, $options['headers'] ?? null)];

        if (isset($options['method'])) {
            $init['method'] = $options['method'];
        }

        if (($options['json'] ?? null) !== null) {
            $init['body'] = $options['json'] === []
                ? '{}'
                : json_encode($options['json'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $init['headers'] = Headers::merge($init['headers'], ['content-type' => 'application/json']);
        }

        if (array_key_exists('timeoutMs', $options)) {
            $init['timeoutMs'] = $options['timeoutMs'];
        } else {
            $init['timeoutMs'] = $this->timeoutMs;
        }

        if (array_key_exists('signal', $options)) {
            $init['signal'] = $options['signal'];
        }

        $url = $this->apiUrl . $path;
        $pairs = [];
        foreach ($options['params'] ?? [] as $key => $value) {
            if ($value === null) {
                continue;
            }
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                if ($item === null) {
                    continue;
                }
                $pairs[] = urlencode((string) $key) . '=' . urlencode(self::paramString($item));
            }
        }
        if ($pairs !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . implode('&', $pairs);
        }

        return [$url, $init];
    }

    /**
     * Issue a request and decode the JSON body (null for a 202/204).
     *
     * With `withResponse` it returns `[body, HttpResponse]` instead.
     *
     * `dedupe` (opt-in, reads only) shares one in-flight request between identical callers. It is
     * skipped when the caller wants the raw response, supplied a `signal`, or an `onRequest` hook is
     * configured — the hook can inject credentials that are invisible to the dedupe key. The key
     * covers method, URL, body and EVERY prepared header, so two tenants never share a response.
     *
     * Sharing needs concurrency, which plain PHP lacks. It is cooperative: a caller running inside a
     * `Fiber` waits (suspending) for the in-flight read; a caller outside a Fiber cannot wait and
     * issues its own request. Known non-exact behaviour.
     *
     * @param array<string, mixed> $options
     */
    protected function fetch(string $path, array $options = []): mixed
    {
        [$url, $init] = $this->prepareFetchOptions($path, $options);

        $canDedupe = ($options['dedupe'] ?? false) === true
            && ($options['withResponse'] ?? false) !== true
            && ($options['signal'] ?? null) === null
            && $this->onRequest === null;

        if ($canDedupe) {
            $key = ($init['method'] ?? 'GET') . ' ' . $url . ' ' . ($init['body'] ?? '') . ' ' . self::serializeHeaders($init['headers']);
            $existing = self::$inFlightReads[$key] ?? null;

            if ($existing !== null && \Fiber::getCurrent() !== null) {
                while (!$existing->settled) {
                    \Fiber::suspend();
                }
                if ($existing->error !== null) {
                    throw $existing->error;
                }

                return $existing->result;
            }

            if ($existing === null) {
                $entry = new InFlightRead();
                self::$inFlightReads[$key] = $entry;
                try {
                    [$body] = $this->performFetchWithResponse($url, $init);
                    $entry->result = $body;

                    return $body;
                } catch (\Throwable $e) {
                    $entry->error = $e;
                    throw $e;
                } finally {
                    $entry->settled = true;
                    if ((self::$inFlightReads[$key] ?? null) === $entry) {
                        unset(self::$inFlightReads[$key]);
                    }
                }
            }
        }

        [$body, $response] = $this->performFetchWithResponse($url, $init);

        return ($options['withResponse'] ?? false) === true ? [$body, $response] : $body;
    }

    /**
     * Test hook: the registry must be empty once every read has settled.
     */
    public static function inFlightReadCount(): int
    {
        return count(self::$inFlightReads);
    }

    /**
     * @param array<string, mixed> $init
     *
     * @return array{0: mixed, 1: HttpResponse}
     */
    private function performFetchWithResponse(string $url, array $init): array
    {
        $finalInit = $this->onRequest !== null ? ($this->onRequest)($url, $init) : $init;

        $response = $this->asyncCaller->fetch($url, $finalInit);

        if ($response->status === 202 || $response->status === 204) {
            return [null, $response];
        }

        try {
            $body = json_decode($response->body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HttpException('Response body was not valid JSON: ' . $e->getMessage(), $response->status, $response->body, $e);
        }

        return [$body, $response];
    }

    /**
     * The one place a prepared request meets the transport. POST works on any {@see HttpClient};
     * the other verbs need a {@see MethodHttpClient}. Arguments are positional on purpose.
     *
     * @param array<string, mixed> $init
     */
    protected function send(string $url, array $init): HttpResponse
    {
        $method = strtoupper($init['method'] ?? 'GET');
        $headers = $init['headers'] ?? [];
        $body = $init['body'] ?? null;
        $timeout = isset($init['timeoutMs']) ? $init['timeoutMs'] / 1000 : null;

        if ($this->http instanceof MethodHttpClient) {
            return $this->http->request($method, $url, $headers, $body, $timeout);
        }

        if ($method === 'POST') {
            return $this->http->post($url, $headers, $body ?? '', [], $timeout);
        }

        throw new \LogicException(sprintf(
            'The LangGraph SDK needs %s %s, but %s implements only POST. Pass a %s (GuzzleMethodHttpClient is the default).',
            $method,
            $url,
            $this->http::class,
            MethodHttpClient::class,
        ));
    }

    /** @param array<string, string> $headers */
    private static function serializeHeaders(array $headers): string
    {
        $normalized = Headers::merge($headers);

        return implode("\n", array_map(
            static fn (string $name, string $value): string => $name . ':' . $value,
            array_keys($normalized),
            $normalized,
        ));
    }

    private static function paramString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Keep the entries of `$payload` named in `$map`, renamed to their wire keys.
     *
     * A key that is ABSENT is omitted from the request; a key present with null is sent as JSON
     * null. That is the JS `undefined` / `null` distinction, which a PHP array can only express by
     * key presence — and `crons.update({endTime: null})` clears an end time while omitting it leaves
     * it alone.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $map     camelCase => snake_case
     *
     * @return array<string, mixed>
     */
    protected static function wireFields(array $payload, array $map): array
    {
        $out = [];
        foreach ($map as $camel => $snake) {
            if (array_key_exists($camel, $payload)) {
                $out[$snake] = $payload[$camel];
            }
        }

        return $out;
    }

    /**
     * Drop null entries: upstream's `x ?? undefined`.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected static function defined(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The request option that carries a caller's `signal`, if they passed one.
     *
     * @param array<string, mixed> $options
     *
     * @return array{signal?: mixed}
     */
    protected static function signalOf(array $options): array
    {
        return array_key_exists('signal', $options) ? ['signal' => $options['signal']] : [];
    }
}
