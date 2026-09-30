<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils\Http;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LangChain\Utils\Http\GuzzleHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

/**
 * A stream that claims more data forever and never delivers any.
 *
 * The shape that used to hang: `eof()` stays false, so the read loop never
 * exits, and `read()` returns an empty string, so the `continue` never
 * advances. Before the fix this was an infinite loop that never yielded and
 * never raised — the worst kind of failure, because the caller is simply stuck.
 */
class StallingStream implements StreamInterface
{
    public int $reads = 0;

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
    }

    public function rewind(): void
    {
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write($string): int
    {
        return 0;
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read($length): string
    {
        $this->reads++;

        return '';
    }

    public function getContents(): string
    {
        return '';
    }

    public function getMetadata($key = null)
    {
        return null;
    }
}

/**
 * A stream that alternates real data with empty reads and never reports EOF.
 */
final class GappingStream extends StallingStream
{
    private array $script;

    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function eof(): bool
    {
        // The script is exhausted: this stream is GAPPING, not stalled.
        //
        // It used to return false forever, which made it a stalled stream that
        // happened to deliver two chunks before going quiet — so the "a brief
        // gap is not a stall" test was really re-testing the stall guard, and
        // only passed because a stall ended silently. When the stall guard
        // started raising, the fixture showed what it had always been.
        return $this->script === [];
    }

    public function read($length): string
    {
        $this->reads++;

        return array_shift($this->script) ?? '';
    }
}

#[CoversClass(GuzzleHttpClient::class)]
final class GuzzleHttpClientTest extends TestCase
{
    private static function client(StreamInterface $body): GuzzleHttpClient
    {
        $mock = new MockHandler([new Response(200, [], $body)]);

        return new GuzzleHttpClient(['handler' => HandlerStack::create($mock)]);
    }

    /**
     * A stalled connection must end the stream, not spin.
     *
     * Regression: `while (!eof()) { $c = read(); if ($c === '') continue; }`
     * loops forever on a stream that is neither at EOF nor delivering. Found by
     * a reviewer's model reading this file; the guard bounds consecutive empty
     * reads, and a healthy blocking stream never produces two in a row.
     */
    public function testAStalledStreamRaisesRatherThanEndingQuietly(): void
    {
        $body = new StallingStream();
        $client = $this->client($body);
        // The production silence limit is 30s; prove the guard fires without
        // spending it.
        $client->streamSilenceLimit = 0.05;

        // This test used to be called `testAStalledStreamTerminates` and
        // asserted that the generator simply ran out — which WAS the bug. A
        // connection that dies mid-answer returned normally, so
        // `BaseChatModel::stream()` reached `handleLLMEnd` with a partial
        // message and the caller got a truncated completion indistinguishable
        // from a finished one. The test pinned the defect, and the name said so.
        $threw = null;
        try {
            iterator_to_array($client->postStream('https://x.test/', [], 'body'), false);
        } catch (\LangChain\Utils\Http\HttpException $e) {
            $threw = $e;
        }

        self::assertNotNull($threw, 'a stalled stream must raise, not end the generator cleanly');
        self::assertStringContainsString('stalled', $threw->getMessage());
        self::assertStringContainsString('https://x.test/', $threw->getMessage());
        self::assertGreaterThan(1, $body->reads, 'the read loop must have polled, not bailed instantly');
    }

    /**
     * A brief gap between chunks is not a stall.
     *
     * The first version of the guard counted consecutive empty reads and ended
     * the stream after two — which on a non-blocking socket, where a gap
     * between tokens is routinely sub-millisecond, truncates a perfectly healthy
     * answer. The guard is wall-clock for exactly this reason.
     */
    public function testABriefGapIsNotTreatedAsAStall(): void
    {
        $body = new GappingStream(['data: {"a":1}', '', '', 'data: {"b":2}']);
        $client = $this->client($body);
        $client->streamSilenceLimit = 5.0;

        $chunks = iterator_to_array($client->postStream('https://x.test/', [], 'body'), false);

        self::assertSame(['data: {"a":1}', 'data: {"b":2}'], $chunks,
            'two empty reads must not end a stream that is still delivering');
    }

    /**
     * A non-2xx on the stream path carries the provider's body.
     */
    public function testAStreamErrorCarriesTheResponseBody(): void
    {
        $mock = new MockHandler([
            new Response(401, [], '{"error":{"message":"invalid api key"}}'),
        ]);
        $client = new GuzzleHttpClient(['handler' => HandlerStack::create($mock)]);

        try {
            iterator_to_array($client->postStream('https://x.test/', [], 'body'), false);
            self::fail('expected an HttpException');
        } catch (\LangChain\Utils\Http\HttpException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('invalid api key', $e->body);
        }
    }

    /**
     * A non-2xx on the eager path is a response, not a throw — the client
     * returns it so the provider layer can read the error object.
     */
    public function testAnEagerErrorIsReturnedNotThrown(): void
    {
        $mock = new MockHandler([new Response(400, [], '{"error":{"message":"bad"}}')]);
        $client = new GuzzleHttpClient(['handler' => HandlerStack::create($mock)]);

        $response = $client->post('https://x.test/', [], 'body');

        self::assertFalse($response->isOk());
        self::assertSame(400, $response->status);
        self::assertStringContainsString('bad', $response->body);
    }
}
