<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\JsonRpc;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonRpc::class)]
final class JsonRpcTest extends TestCase
{
    public function testARequestCarriesIdMethodAndParams(): void
    {
        self::assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'x']],
            JsonRpc::request(7, 'tools/call', ['name' => 'x']),
        );
    }

    public function testEmptyParamsAreOmitted(): void
    {
        self::assertSame(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], JsonRpc::request(1, 'tools/list'));
        self::assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JsonRpc::notification('notifications/initialized'));
    }

    public function testAnEmptyResultEncodesAsAnObject(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":3,"result":{}}', JsonRpc::encode(JsonRpc::response(3)));
    }

    public function testErrorResponsesCarryCodeMessageAndOptionalData(): void
    {
        $error = JsonRpc::errorResponse(2, -32601, 'nope', ['a' => 1]);

        self::assertSame(['code' => -32601, 'message' => 'nope', 'data' => ['a' => 1]], $error['error']);
        self::assertArrayNotHasKey('data', JsonRpc::errorResponse(2, -1, 'm')['error']);
    }

    public function testMessagesAreClassifiedByShape(): void
    {
        self::assertTrue(JsonRpc::isRequest(['id' => 1, 'method' => 'ping']));
        self::assertFalse(JsonRpc::isRequest(['method' => 'ping']));
        self::assertTrue(JsonRpc::isNotification(['method' => 'notifications/x']));
        self::assertFalse(JsonRpc::isNotification(['id' => 1, 'method' => 'ping']));
        self::assertTrue(JsonRpc::isResponse(['id' => 1, 'result' => []]));
        self::assertTrue(JsonRpc::isResponse(['id' => 1, 'error' => ['code' => 1, 'message' => 'm']]));
        self::assertFalse(JsonRpc::isResponse(['id' => 1, 'method' => 'ping']));
    }

    public function testEncodeKeepsSlashesAndUnicodeAndNeverEmitsANewline(): void
    {
        $json = JsonRpc::encode(['text' => "line1\nline2 / é"]);

        self::assertStringNotContainsString("\n", $json);
        self::assertStringContainsString('/ é', $json);
    }

    public function testDecodeRejectsAScalar(): void
    {
        $this->expectException(\JsonException::class);

        JsonRpc::decode('42');
    }
}
