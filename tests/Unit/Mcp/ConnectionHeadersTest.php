<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Tests\Unit\Mcp\Client\Fixtures\HttpServerProcess;
use LangGraph\Mcp\Connection\Misc;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\MultiServerMcpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `utils/misc.ts` (`serializeHeaders`, `mergeHeaders`) and the HTTP half of
 * `transport.headers.test.ts`: what the streamable HTTP transport really puts on the wire.
 *
 * The SSE half and every case about a credentials provider replacing a configured header are not
 * ported (neither exists here). What remains is the other half of that contract: a configured
 * `Authorization` is sent, whatever its spelling.
 */
#[CoversClass(Misc::class)]
#[CoversClass(MultiServerMcpClient::class)]
final class ConnectionHeadersTest extends MultiServerTestCase
{
    private static HttpServerProcess $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = HttpServerProcess::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testSerializingNoHeadersIsNull(): void
    {
        self::assertNull(Misc::serializeHeaders(null));
        self::assertNull(Misc::serializeHeaders([]));
    }

    public function testSerializationIgnoresOrderAndNameCase(): void
    {
        $one = Misc::serializeHeaders(['B' => '2', 'a' => '1']);

        self::assertSame($one, Misc::serializeHeaders(['A' => '1', 'b' => '2']));
        self::assertSame('[["a","1"],["b","2"]]', $one);
    }

    public function testSerializationDistinguishesDifferentValues(): void
    {
        self::assertNotSame(Misc::serializeHeaders(['a' => '1']), Misc::serializeHeaders(['a' => '2']));
        self::assertNotSame(Misc::serializeHeaders(['a' => '1']), Misc::serializeHeaders(['b' => '1']));
    }

    public function testSerializationTrimsValuesAndJoinsRepeatedNames(): void
    {
        self::assertSame(Misc::serializeHeaders(['a' => '1']), Misc::serializeHeaders(['a' => '  1 ']));
        self::assertSame('[["accept","x, y"]]', Misc::serializeHeaders(['Accept' => 'x', 'accept' => 'y']));
    }

    public function testMergeLetsLaterSourcesWinCaseInsensitively(): void
    {
        self::assertSame(
            ['a' => '2', 'x-keep' => 'base'],
            Misc::mergeHeaders(['A' => '1', 'X-Keep' => 'base'], ['a' => '2']),
        );
    }

    public function testMergeToleratesMissingSides(): void
    {
        self::assertSame([], Misc::mergeHeaders(null, null));
        self::assertSame(['a' => '1'], Misc::mergeHeaders(['A' => '1'], null));
        self::assertSame(['b' => '2'], Misc::mergeHeaders(null, ['B' => '2']));
    }

    public function testMergeLowerCasesNamesAndKeepsTheOrderOfFirstAppearance(): void
    {
        self::assertSame(['authorization' => 'Bearer x', 'x-api-key' => 'k'], Misc::mergeHeaders(['Authorization' => 'Bearer x'], ['X-API-Key' => 'k']));
    }

    /** @return array<string, array{array<string, string>}> */
    public static function malformedHeaders(): array
    {
        return [
            'space in the name' => [['bad name' => 'x']],
            'empty name' => [['' => 'x']],
            'newline in the value' => [['X-Test' => "a\nb"]],
            'NUL in the value' => [['X-Test' => "a\0b"]],
        ];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('malformedHeaders')]
    public function testMalformedHeadersAreRefused(array $headers): void
    {
        $this->expectException(McpClientError::class);

        Misc::mergeHeaders($headers, null);
    }

    public function testDescribesAnErrorLikeStringOfAnError(): void
    {
        self::assertSame('RuntimeException: Connection failed', Misc::describeError(new \RuntimeException('Connection failed')));
        self::assertSame('plain', Misc::describeError('plain'));
        self::assertSame('[object Object]', Misc::describeError(['status' => 404]));
    }

    /** @return array<string, array{string}> */
    public static function authorizationSpellings(): array
    {
        return ['lower' => ['authorization'], 'canonical' => ['Authorization'], 'upper' => ['AUTHORIZATION']];
    }

    #[DataProvider('authorizationSpellings')]
    public function testAConfiguredAuthorizationIsSentOverHttpInAnySpelling(string $spelling): void
    {
        $client = $this->plain(['servers' => ['svc' => ['mode' => 'legacy', 'url' => self::$server->url('auth'), 'headers' => [$spelling => 'Bearer secret']]]]);

        self::assertCount(11, $client->listTools());

        $authorized = array_values(array_filter(
            self::$server->requests(),
            static fn (array $request): bool => ($request['headers']['authorization'] ?? null) === 'Bearer secret' && str_contains($request['body'], '"initialize"'),
        ));
        self::assertNotEmpty($authorized);
    }

    public function testAWrongAuthorizationIsReportedAsAnAuthenticationFailure(): void
    {
        $client = $this->plain(['servers' => ['http-server' => ['mode' => 'legacy', 'url' => self::$server->url('auth'), 'headers' => ['Authorization' => 'Bearer invalid-token']]]]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertMatchesRegularExpression('/Authentication failed.*HTTP.*server.*http-server/i', $error->getMessage());
        self::assertSame('http-server', $error->serverName);
        self::assertSame(401, $error->cause->status);
    }

    public function testNoAuthorizationAtAllIsAlsoAnAuthenticationFailure(): void
    {
        $client = $this->plain(['servers' => ['svc' => ['mode' => 'legacy', 'url' => self::$server->url('auth')]]]);

        $error = self::thrownBy(static fn () => $client->listTools());

        self::assertStringContainsString('Authentication failed for HTTP server "svc" at ' . self::$server->url('auth'), $error->getMessage());
    }

    public function testCustomHeadersReachTheServerOnEveryToolCall(): void
    {
        $client = $this->plain(['servers' => ['streamable-server' => ['mode' => 'legacy', 'url' => self::$server->url('json'), 'headers' => ['X-API-Key' => 'my-api-key']]]]);

        $tool = null;
        foreach ($client->listTools() as $candidate) {
            if ($candidate->name === 'show_header') {
                $tool = $candidate;
            }
        }
        self::assertNotNull($tool);

        self::assertSame('my-api-key', $tool->invoke(['name' => 'X-API-Key']));
    }

    public function testDiscoveryHeadersAreMergedWithTheConfiguredOnes(): void
    {
        $client = $this->plain(['servers' => ['svc' => ['mode' => 'legacy', 'url' => self::$server->url('json'), 'headers' => ['X-Fixed' => 'configured']]]]);

        $tools = $client->listTools(['svc'], ['headers' => ['X-Tenant' => 'one', 'X-Fixed' => 'override']]);
        $show = array_values(array_filter($tools, static fn ($tool): bool => $tool->name === 'show_header'))[0];

        self::assertSame('one', $show->invoke(['name' => 'x-tenant']));
        self::assertSame('configured', $show->invoke(['name' => 'x-fixed']));
    }
}
