<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk\Support;

use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\Utils\MethodHttpClient;

/**
 * A {@see MethodHttpClient} for the SDK tests: replays scripted responses and records the verb,
 * which {@see FakeHttpClient} cannot (it only knows POST).
 *
 * `$handler` replaces the script with a function of the request, which is how the end-to-end test
 * stands a real graph behind the wire. `$deferred` makes a request issued inside a Fiber hold until
 * `release()`, so two reads genuinely overlap in flight.
 */
final class RecordingTransport implements MethodHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: ?float}> */
    public array $requests = [];

    public bool $deferred = false;

    /** Requests that had reached the wire while every fiber was parked. */
    public int $inFlightSnapshot = 0;

    private bool $released = false;

    /**
     * @param list<HttpResponse>                                                            $responses
     * @param (\Closure(string, string, array<string, string>, ?string): HttpResponse)|null $handler
     */
    public function __construct(
        public array $responses = [],
        private readonly ?\Closure $handler = null,
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];

        if ($this->deferred && \Fiber::getCurrent() !== null) {
            while (!$this->released) {
                \Fiber::suspend();
            }
        }

        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $body);
        }

        return array_shift($this->responses) ?? FakeHttpClient::json(200, []);
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        throw new \LogicException('Streaming is WP-23b.');
    }

    public function release(): void
    {
        $this->released = true;
    }

    /** @return array<string, mixed> */
    public function bodyOf(int $index = 0): array
    {
        return (array) json_decode((string) $this->requests[$index]['body'], true);
    }

    /**
     * Run closures as Fibers that can overlap, then release the transport and finish them all.
     *
     * @param list<\Closure> $tasks
     *
     * @return list<mixed> Each task's return value, in order.
     */
    public function runOverlapping(array $tasks): array
    {
        $this->deferred = true;
        $fibers = array_map(static fn (\Closure $t): \Fiber => new \Fiber($t), $tasks);
        foreach ($fibers as $fiber) {
            $fiber->start();
        }

        // Every fiber is now parked in the transport or waiting on a parked read.
        $this->inFlightSnapshot = count($this->requests);

        $this->release();
        do {
            $running = false;
            foreach ($fibers as $fiber) {
                if (!$fiber->isTerminated()) {
                    $fiber->resume();
                    $running = true;
                }
            }
        } while ($running);

        return array_map(static fn (\Fiber $f): mixed => $f->getReturn(), $fibers);
    }
}
