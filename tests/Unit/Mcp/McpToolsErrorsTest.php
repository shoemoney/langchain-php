<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\Errors;
use LangGraph\Mcp\McpClientError;
use LangGraph\Mcp\ToolException;
use LangGraph\Mcp\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ports `tests/tools.errors.test.ts`.
 *
 * Skipped: "recognizes errors from separately loaded adapter modules" and "recognizes separately loaded client
 * errors" exercise `vi.resetModules()` and brand-based `isInstance`, a duplicate-module-copy problem PHP's single
 * class table cannot have.
 */
#[CoversClass(ToolException::class)]
#[CoversClass(McpClientError::class)]
#[CoversClass(Errors::class)]
final class McpToolsErrorsTest extends McpTestCase
{
    public function testToolExceptionIsRecognisedByTypeNotByNameLookalikes(): void
    {
        $error = new ToolException('Failure', new \Exception('Cause'));

        self::assertTrue(Errors::isToolException($error));
        self::assertFalse(Errors::isToolException(['name' => 'ToolException']));
        self::assertFalse(Errors::isToolException(new \RuntimeException('Unrelated')));
        self::assertFalse(Errors::isToolException(new class ('Unrelated') extends \RuntimeException {
            public string $name = 'ToolException';
        }));
    }

    public function testPreservesTheOriginalValidationErrorAndItsStructuredIssues(): void
    {
        $validation = new ValidationException([['path' => ['count'], 'message' => 'Invalid input: expected number, received string']]);
        $error = new ToolException('Invalid hook result', $validation);

        self::assertSame($validation, $error->cause);
        self::assertSame($validation, $error->getPrevious());
        self::assertSame(['count'], $validation->issues[0]['path']);
    }

    public function testPreservesOrdinaryAndNonErrorCauses(): void
    {
        $cause = new \RuntimeException('Connection failed');

        self::assertSame($cause, (new ToolException('Failure', $cause))->cause);
        self::assertNull((new ToolException('Failure', null))->cause);
        self::assertFalse((new ToolException('Failure', false))->cause);
        self::assertNull((new ToolException('Failure', false))->getPrevious());
    }

    public function testPreservesSemanticErrorEnvelopesAndTransportCausesSeparately(): void
    {
        $result = [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'denied']],
            'structuredContent' => false,
            '_meta' => ['reason' => 'policy'],
        ];
        $client = new FakeMcpClient([self::echoTool()]);
        $tool = self::firstTool($client);

        $client->willReturn($result);
        $semantic = self::thrownBy(static fn () => $tool->invoke([]));
        self::assertInstanceOf(ToolException::class, $semantic);
        self::assertSame($result, $semantic->result);
        self::assertStringContainsString("returned an error: denied", $semantic->getMessage());

        $failure = new \RuntimeException('connection lost');
        $client->willReturn($failure);
        $transport = self::thrownBy(static fn () => $tool->invoke([]));
        self::assertInstanceOf(ToolException::class, $transport);
        self::assertSame($failure, $transport->cause);
        self::assertNull($transport->result);

        $validation = new ValidationException([['path' => ['count'], 'message' => 'Invalid input: expected number, received string']]);
        $client->willReturn($validation);
        $wrapped = self::thrownBy(static fn () => $tool->invoke([]));
        self::assertInstanceOf(ToolException::class, $wrapped);
        self::assertSame('Error calling tool echo: ValidationException: Invalid input: expected number, received string', $wrapped->getMessage());
        self::assertSame($validation, $wrapped->cause);
    }

    public function testRetainsArgumentValidationIssuesAsAValidationError(): void
    {
        $client = new FakeMcpClient([self::echoTool([
            'inputSchema' => ['type' => 'object', 'properties' => ['count' => ['type' => 'number', 'minimum' => 1]]],
        ])]);
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['args' => ['count' => 0]]]);

        $failure = self::thrownBy(static fn () => $tool->invoke(['count' => 1]));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertInstanceOf(ValidationException::class, $failure->cause);
        self::assertSame('data/count must be >= 1', $failure->cause->issues[0]['message']);
        self::assertSame([], $client->calls);
    }

    public function testAnUnknownContentTypeIsReportedByNameWithTheExpectedSet(): void
    {
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(['content' => [['type' => 'hologram']]]);

        $failure = self::thrownBy(static fn () => self::firstTool($client)->invoke([]));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString(
            "MCP tool 'echo' on server 'test' returned unexpected content type \"hologram\". Expected text, image, audio, resource_link, resource.",
            $failure->getMessage(),
        );
    }

    public function testMcpClientErrorPreservesCausesAndRejectsLookalikes(): void
    {
        $cause = new ValidationException([['path' => [], 'message' => 'Invalid input: expected number, received string']]);
        $error = new McpClientError('Failed', 'server', $cause);

        self::assertSame($cause, $error->cause);
        self::assertSame($cause, $error->getPrevious());
        self::assertSame('server', $error->serverName);
        self::assertNotInstanceOf(McpClientError::class, new \RuntimeException('Other'));
        self::assertNull((new McpClientError('No server'))->serverName);
    }

    /** @return array<string, array{0: mixed, 1: int|null}> */
    public static function httpStatuses(): array
    {
        $withStatus = new class ('HTTP failure') extends \Exception {
            public int $status = 401;
        };

        return [
            'status wins over code and message' => [['status' => 401, 'code' => 403, 'message' => 'Failure (HTTP 404)'], 401],
            'non-integer status falls to code' => [['status' => '401', 'code' => 403, 'message' => 'Failure (HTTP 404)'], 403],
            'out-of-range code falls to message' => [['code' => -32603, 'message' => 'Failure (HTTP 404)'], 404],
            'error object with a status' => [$withStatus, 401],
            'lower bound' => [['status' => 100], 100],
            'upper bound' => [['status' => 599], 599],
            'above range' => [['status' => 600], null],
            'below range' => [['code' => 99], null],
            'fractional code' => [['code' => 401.5], null],
            'message status out of range' => [['message' => 'Failure (HTTP 999)'], null],
            'null' => [null, null],
            'a bare string' => ['Failure (HTTP 401)', null],
        ];
    }

    #[DataProvider('httpStatuses')]
    public function testExtractsHttpStatusWithExistingPrecedence(mixed $error, ?int $status): void
    {
        self::assertSame($status, Errors::getHttpErrorCode($error));
    }

    public function testRecognisesAnAuthenticationErrorWithinTheCauseChainDepth(): void
    {
        $unauthorised = ['status' => 401];
        $wrapped = new McpClientError('SSE fallback failed', 'srv', new McpClientError('HTTP failed', 'srv', new class ('401') extends \Exception {
            public int $status = 401;
        }));

        self::assertTrue(Errors::isAuthenticationError($unauthorised));
        self::assertTrue(Errors::isAuthenticationError($wrapped));
        self::assertFalse(Errors::isAuthenticationError(new \RuntimeException('boom')));
        self::assertStringContainsString(
            'Authentication failed for HTTP server "srv" at https://x.test/mcp.',
            Errors::createAuthenticationErrorMessage('srv', 'https://x.test/mcp', 'HTTP', 'nope'),
        );
    }
}
