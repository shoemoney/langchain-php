<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\Transport\StdioTransport;
use LangGraph\Mcp\McpClientError;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(StdioTransport::class)]
final class StdioTransportTest extends ClientTestCase
{
    public function testItSpeaksNewlineDelimitedJsonToAChildProcess(): void
    {
        $transport = $this->stdioTransport();
        $transport->start();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $reply = $transport->receive(5);

        self::assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $reply);
    }

    public function testReceiveReturnsNullWhenNothingArrivesInTime(): void
    {
        $transport = $this->stdioTransport();
        $transport->start();

        self::assertNull($transport->receive(0.1));
    }

    public function testTheConfiguredEnvironmentReachesTheChild(): void
    {
        $transport = $this->stdioTransport([], ['env' => ['FIXTURE_VAR' => 'from-config']]);
        $transport->start();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'check_env', 'arguments' => ['varName' => 'FIXTURE_VAR']]]);

        self::assertSame('from-config', $transport->receive(5)['result']['content'][0]['text']);
    }

    public function testTheParentEnvironmentIsNotInherited(): void
    {
        putenv('FIXTURE_PARENT_ONLY=leak');
        try {
            $transport = $this->stdioTransport();
            $transport->start();
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'check_env', 'arguments' => ['varName' => 'FIXTURE_PARENT_ONLY']]]);

            self::assertSame('NOT_SET', $transport->receive(5)['result']['content'][0]['text']);
        } finally {
            putenv('FIXTURE_PARENT_ONLY');
        }
    }

    public function testTheWorkingDirectoryIsHonoured(): void
    {
        $dir = realpath(sys_get_temp_dir());
        $transport = $this->stdioTransport([], ['cwd' => $dir]);
        $transport->start();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cwd', 'arguments' => new \stdClass()]]);

        self::assertSame($dir, realpath($transport->receive(5)['result']['content'][0]['text']));
    }

    public function testStderrCanBeCaptured(): void
    {
        $transport = $this->stdioTransport([], ['stderr' => 'pipe', 'env' => ['FIXTURE_STDERR' => 'hello-from-stderr']]);
        $transport->start();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $transport->receive(5);

        self::assertStringContainsString('hello-from-stderr', $transport->stderrOutput());
    }

    public function testAChildThatExitsSurfacesAsAnErrorWithItsExitCodeAndStderr(): void
    {
        $transport = $this->stdioTransport(['crashy', 'crash'], ['stderr' => 'pipe']);
        $transport->start();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $error = self::thrownBy(static fn () => $transport->receive(5));

        self::assertInstanceOf(McpClientError::class, $error);
        self::assertStringContainsString('closed the connection', $error->getMessage());
        self::assertStringContainsString('fixture crashed', $error->getMessage());
    }

    public function testCloseReapsTheChildAndIsIdempotent(): void
    {
        $transport = $this->stdioTransport();
        $transport->start();
        self::assertTrue($transport->isRunning());

        $transport->close();
        $transport->close();

        self::assertFalse($transport->isRunning());
    }

    public function testCloseTerminatesAChildThatIgnoresStdinEof(): void
    {
        $transport = new StdioTransport(['command' => \PHP_BINARY, 'args' => ['-r', 'sleep(30);'], 'stderr' => 'ignore']);
        $transport->start();
        $started = microtime(true);

        $transport->close();

        self::assertFalse($transport->isRunning());
        self::assertLessThan(10, microtime(true) - $started);
    }

    public function testHeadersAreNotSupported(): void
    {
        $this->expectException(McpClientError::class);

        $this->stdioTransport()->withHeaders(['X-A' => '1']);
    }

    public function testACommandIsRequired(): void
    {
        $this->expectException(McpClientError::class);

        new StdioTransport(['command' => '']);
    }

    public function testSendingBeforeStartFails(): void
    {
        $this->expectException(McpClientError::class);

        $this->stdioTransport()->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    }

    private static function thrownBy(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected a throwable.');
    }
}
