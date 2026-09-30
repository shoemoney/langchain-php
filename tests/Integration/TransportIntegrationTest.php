<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The provider clients driven through a REAL Guzzle stack.
 *
 * Everything else in this suite substitutes a fake transport, which is the right
 * trade for testing translation — but it means the seam itself, the PSR-7
 * request that leaves, the SSE bytes that come back, and the middleware stack
 * around them, are never exercised. That boundary is where a mismatch between
 * what the client *intends* to send and what the socket *carries* would live,
 * and nothing in `tests/Unit` could see it.
 *
 * This is why the `integration` testsuite exists, and why CI runs it: an empty
 * testsuite declared in phpunit.xml and never executed is a configuration that
 * looks like coverage and is not.
 */
final class TransportIntegrationTest extends TestCase
{
    /**
     * Requests captured by the Guzzle history middleware, per test.
     *
     * An object rather than a by-reference array: returning `[$model, &$history]`
     * from a function loses the reference when the array is destructured, and
     * the assertion silently reads an empty list.
     *
     * @var \ArrayObject<int, array<string, mixed>>
     */
    private static \ArrayObject $history;

    /**
     * @param list<mixed> $queue Responses in order.
     */
    private static function wired(array $queue): ChatOpenAI
    {
        self::$history = new \ArrayObject();

        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history(self::$history));

        $client = new \LangChain\Utils\Http\GuzzleHttpClient(['handler' => $stack]);

        return new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'httpClient' => $client]);
    }

    /** @return array{request: \Psr\Http\Message\RequestInterface, options: array} */
    private static function sentRequest(int $index): array
    {
        self::assertGreaterThan($index, self::$history->count(), 'no request was sent');
        $entry = self::$history[$index];

        return ['request' => $entry['request'], 'options' => $entry['options']];
    }

    public function testAnInvokeCrossesTheRealTransport(): void
    {
        $model = self::wired([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => 'chatcmpl-x',
                'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'across the wire'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6],
            ])),
        ]);

        $message = $model->invoke([new HumanMessage('hi')]);

        self::assertSame('across the wire', $message->content);
        self::assertSame(6, $message->response_metadata['usage_metadata']['total_tokens']);

        // The request really left: headers, method, and a parseable body.
        self::assertCount(1, self::$history);
        $sent = self::sentRequest(0)['request'];
        self::assertSame('POST', $sent->getMethod());
        self::assertSame('Bearer sk-test', $sent->getHeaderLine('Authorization'));
        self::assertSame('application/json', $sent->getHeaderLine('Content-Type'));

        $body = json_decode((string) $sent->getBody(), true);
        self::assertSame('gpt-4o', $body['model']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], $body['messages']);
    }

    /**
     * The tool-call round trip, over the real transport, ending in a tool
     * result fed back to the provider.
     */
    public function testAToolLoopCrossesTheRealTransport(): void
    {
        $model = self::wired([
            new Response(200, [], (string) json_encode([
                'id' => 'c1', 'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Austin"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4, 'total_tokens' => 14],
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'c2', 'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'It is 72F.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
            ])),
        ]);

        $weather = tool(static fn (array $a): string => '72F', [
            'name' => 'get_weather',
            'description' => 'Look up the weather',
            'schema' => Schema::object(['city' => ['type' => 'string']]),
        ]);

        $first = $model->bindTools([$weather])->invoke([new HumanMessage('weather in Austin?')]);
        self::assertCount(1, $first->toolCalls);
        self::assertSame('get_weather', $first->toolCalls[0]['name']);

        $second = $model->bindTools([$weather])->invoke([
            new HumanMessage('weather in Austin?'),
            $first,
            new ToolMessage(['content' => '72F', 'tool_call_id' => $first->toolCalls[0]['id']]),
        ]);
        self::assertSame('It is 72F.', $second->content);

        // The second request must carry the tool definition AND the result.
        $secondBody = json_decode((string) self::sentRequest(1)['request']->getBody(), true);
        self::assertSame('get_weather', $secondBody['tools'][0]['function']['name']);
        self::assertSame('tool', $secondBody['messages'][2]['role']);
        self::assertSame('72F', $secondBody['messages'][2]['content']);
    }

    /**
     * Structured output, end to end, over the real transport.
     */
    public function testStructuredOutputCrossesTheRealTransport(): void
    {
        $model = self::wired([
            new Response(200, [], (string) json_encode([
                'id' => 'c1', 'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1', 'type' => 'function',
                            'function' => ['name' => 'extract', 'arguments' => '{"name":"Ada Lovelace"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ])),
        ]);

        $result = $model->withStructuredOutput([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ])->invoke('who wrote the first algorithm?');

        self::assertSame(['name' => 'Ada Lovelace'], $result);
    }

    /**
     * A streaming response, as real SSE bytes split across TCP-sized reads.
     *
     * The unit tests hand the parser whole payloads. Here the framing arrives
     * in pieces, which is the only way to prove the parser reassembles an event
     * split mid-JSON.
     */
    public function testAStreamReassemblesEventsSplitAcrossReads(): void
    {
        $sse = "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hel\"}}]}\n\n"
            . "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"lo\"}}]}\n\n"
            . "data: [DONE]\n\n";

        // Hand the body over in small, deliberately awkward pieces.
        $body = '';
        foreach (str_split($sse, 17) as $piece) {
            $body .= $piece;
        }

        $model = self::wired([new Response(200, ['Content-Type' => 'text/event-stream'], $body)]);

        $text = '';
        foreach ($model->stream('hi') as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame('Hello', $text);
    }

    /**
     * A provider error crosses the transport and arrives as the provider's
     * exception, not as a generic HTTP failure.
     */
    public function testAnApiErrorArrivesAsAProviderException(): void
    {
        $model = self::wired([
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => ['message' => 'Incorrect API key provided', 'code' => 'invalid_api_key', 'type' => 'invalid_request_error'],
            ])),
        ]);

        try {
            $model->invoke('hi');
            self::fail('expected a provider exception');
        } catch (OpenAIException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('Incorrect API key', $e->getMessage());
            self::assertSame('invalid_api_key', $e->providerError['code']);
        }
    }
}
