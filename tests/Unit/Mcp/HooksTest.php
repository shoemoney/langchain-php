<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Func\Func;
use LangGraph\Mcp\Hooks;
use LangGraph\Mcp\ValidationException;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ports the hook-semantics cases of `tests/hooks.test.ts` and `tests/hooks.schemas.test.ts` over a
 * {@see FakeMcpClient}.
 *
 * Converted: "preserves MCP artifacts through an awaited afterToolCall", "tool hook results",
 * "negotiated tool invocation policy", the state/runtime and functional-entrypoint-input cases of
 * "Interceptor hooks", and every case of `hooks.schemas.test.ts`.
 *
 * Skipped, with reasons: the stdio/http/sse transport matrix, "headers in beforeToolCall spawn new connection"
 * (dummy HTTP server; header re-binding is covered by `McpToolsTest::testRoutesAHeaderCallThroughTheForkedClient`)
 * and "onProgress and onLog hooks" (needs a transport to deliver server log/progress notifications).
 */
#[CoversClass(Hooks::class)]
final class HooksTest extends McpTestCase
{
    public function testPreservesMcpArtifactsThroughAnAfterToolCall(): void
    {
        $artifacts = [
            ['type' => 'image', 'data' => 'aW1hZ2U=', 'mimeType' => 'image/png'],
            ['type' => 'audio', 'data' => 'YXVkaW8=', 'mimeType' => 'audio/wav'],
            ['type' => 'text', 'text' => 'artifact text'],
            ['type' => 'resource_link', 'uri' => 'memory://linked', 'name' => 'linked'],
            ['type' => 'resource', 'resource' => ['uri' => 'memory://embedded', 'text' => 'embedded']],
        ];
        $client = (new FakeMcpClient([self::echoTool(['inputSchema' => ['type' => 'object', 'properties' => []]])]))
            ->willReturn(['content' => $artifacts]);
        $calls = 0;
        $tool = self::firstTool($client, [
            'outputHandling' => 'artifact',
            'afterToolCall' => static function (array $request) use ($artifacts, &$calls): array {
                ++$calls;
                self::assertSame($artifacts, $request['result'][1]);

                return ['result' => $request['result']];
            },
        ]);

        $output = $tool->invoke(self::toolCall());

        self::assertInstanceOf(ToolMessage::class, $output);
        self::assertSame($artifacts, $output->artifact);
        self::assertSame(1, $calls);
    }

    /** @return array<string, array{0: mixed}> */
    public static function structuredContents(): array
    {
        return ['false' => [false], 'zero' => [0], 'null' => [null], 'empty list' => [[]], 'object' => [['value' => 1]]];
    }

    #[DataProvider('structuredContents')]
    public function testPreservesStructuredDataThroughANoOpHook(mixed $structuredContent): void
    {
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'structuredContent' => $structuredContent,
            '_meta' => ['private' => 'metadata'],
        ]);
        $tool = self::firstTool($client, ['afterToolCall' => static fn (array $request): array => ['result' => $request['result']]]);

        $output = $tool->invoke(self::toolCall());

        self::assertInstanceOf(ToolMessage::class, $output);
        self::assertSame('ok', $output->content);
        self::assertContainsEquals(['type' => 'mcp_structured_content', 'data' => $structuredContent], $output->artifact);
        self::assertContainsEquals(['type' => 'mcp_meta', 'data' => ['private' => 'metadata']], $output->artifact);
    }

    public function testPreservesNativeToolMessageIdentityAndErrorStatus(): void
    {
        $message = new ToolMessage([
            'content' => 'denied',
            'tool_call_id' => 'original',
            'additional_kwargs' => ['status' => 'error'],
            'artifact' => ['reason' => 'policy'],
        ]);
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $tool = self::firstTool($client, ['afterToolCall' => static fn (): array => ['result' => $message]]);

        self::assertSame($message, $tool->invoke(self::toolCall()));
    }

    public function testPreservesNativeCommands(): void
    {
        $command = new Command(update: ['approved' => true]);
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $tool = self::firstTool($client, ['afterToolCall' => static fn (): array => ['result' => $command]]);

        self::assertSame($command, $tool->invoke([]));
    }

    public function testDoesNotWrapGraphInterrupt(): void
    {
        $interrupt = new GraphInterrupt([['value' => 'approval', 'id' => 'approval']]);
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $tool = self::firstTool($client, ['beforeToolCall' => static function () use ($interrupt): never {
            throw $interrupt;
        }]);

        $thrown = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertSame($interrupt, $thrown);
        self::assertSame([], $client->calls);
    }

    public function testPreservesResourceProvenanceWithoutReadingResources(): void
    {
        $content = [
            ['type' => 'resource', 'resource' => ['uri' => 'memory://embedded', 'text' => 'embedded', 'mimeType' => 'text/plain', '_meta' => ['private' => true]]],
            ['type' => 'resource_link', 'uri' => 'memory://reference', 'name' => 'reference', '_meta' => ['private' => true]],
        ];
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(['content' => $content]);
        $tool = self::firstTool($client, [
            'outputHandling' => 'content',
            'afterToolCall' => static fn (array $request): array => ['result' => $request['result']],
        ]);

        $output = $tool->invoke(self::toolCall());

        self::assertEquals(array_map(static fn (array $data): array => ['type' => 'mcp_content', 'data' => $data], $content), $output->artifact);
        $visible = (string) json_encode($output->content, JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString('private', $visible);
        self::assertStringContainsString('memory://embedded', $visible);
        // The only client calls ever made are `tools/call`: nothing dereferenced a URI.
        self::assertCount(1, $client->calls);
    }

    public function testDoesNotInventStructuredOutputWhenItIsAbsent(): void
    {
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $tool = self::firstTool($client, ['afterToolCall' => static fn (array $request): array => ['result' => $request['result']]]);

        $output = $tool->invoke(self::toolCall());

        self::assertSame('ok', $output->content);
        self::assertSame([], $output->artifact);
    }

    /** @return array<string, array{0: bool, 1: string, 2: bool}> */
    public static function negotiatedPolicies(): array
    {
        return [
            'legacy client with elicitation=false' => [false, 'legacy', false],
            'modern client with elicitation=false' => [false, 'modern', false],
            'legacy client with elicitation=true' => [true, 'legacy', false],
            'modern client with elicitation=true' => [true, 'modern', true],
        ];
    }

    /**
     * Explicit policy remains modern-only, so the interrupt path engages on exactly one of these four.
     */
    #[DataProvider('negotiatedPolicies')]
    public function testNegotiatedToolInvocationPolicy(bool $elicitation, string $era, bool $durable): void
    {
        $client = (new FakeMcpClient([self::echoTool()], $era))->willReturn(self::text('done'));
        $before = 0;
        $tool = self::firstTool($client, [
            'beforeToolCall' => static function () use (&$before): array {
                ++$before;

                return ['args' => ['effective' => true]];
            },
            'logLevel' => 'info',
            'elicitation' => $elicitation,
        ]);
        $client->eraCalls = 0;

        if ($durable) {
            self::assertSame('done', $tool->invoke([]));
            self::assertCount(1, $client->calls);
            $client->calls = [];
            $before = 0;

            $graph = Func::entrypoint(
                ['name' => 'policy-test', 'checkpointer' => new MemorySaver()],
                static fn (): mixed => $tool->invoke([]),
            );
            self::assertSame('done', $graph->invoke([], new RunnableConfig(configurable: ['thread_id' => 'policy'])));
        } else {
            self::assertSame('done', $tool->invoke([]));
        }

        // The era was read once, at discovery, never per call.
        self::assertSame(0, $client->eraCalls);
        self::assertSame(1, $before);
        self::assertCount(1, $client->calls);
        self::assertSame('echo', $client->calls[0]['name']);
        self::assertSame(['effective' => true], $client->calls[0]['arguments']);

        $meta = $client->optionsOf()['_meta'] ?? null;
        if ($era === 'modern') {
            $expected = ['io.modelcontextprotocol/logLevel' => 'info'];
            if ($durable) {
                $expected['io.modelcontextprotocol/clientCapabilities'] = ['elicitation' => ['form' => [], 'url' => []]];
            }
            self::assertSame($expected, $meta);
        } else {
            self::assertNull($meta);
        }
    }

    public function testHooksHaveAccessToStateAndRuntimeWithoutAGraph(): void
    {
        $states = [];
        $runtimes = [];
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $record = static function (array $request, mixed $state, RunnableConfig $runtime) use (&$states, &$runtimes): void {
            $states[] = $state;
            $runtimes[] = $runtime;
        };
        $tool = self::firstTool($client, ['beforeToolCall' => $record, 'afterToolCall' => $record]);

        $tool->invoke([]);

        self::assertSame([[], []], $states);
        self::assertCount(2, $runtimes);
        self::assertInstanceOf(RunnableConfig::class, $runtimes[0]);
        self::assertSame(25, $runtimes[0]->recursionLimit);
        self::assertSame([], $runtimes[0]->tags);
    }

    /** @return array<string, array{0: mixed}> */
    public static function entrypointInputs(): array
    {
        return [
            'list' => [['retained-input']],
            'string' => ['text'],
            'zero' => [0],
            'false' => [false],
            'object' => [['value' => 1]],
        ];
    }

    #[DataProvider('entrypointInputs')]
    public function testHooksPreserveArbitraryFunctionalEntrypointInputs(mixed $input): void
    {
        $observed = [];
        $client = (new FakeMcpClient([self::echoTool()]))->willReturn(self::text('ok'));
        $record = static function (array $request, mixed $state) use (&$observed): void {
            $observed[] = $state;
        };
        $tool = self::firstTool($client, ['beforeToolCall' => $record, 'afterToolCall' => $record]);

        $workflow = Func::entrypoint('hook-input', static function (mixed $in) use ($tool): mixed {
            $tool->invoke([]);

            return $in;
        });
        $workflow->invoke($input);

        self::assertSame([$input, $input], $observed);
    }

    // ---- hooks.schemas.test.ts ----------------------------------------------------------------------------

    public function testHookResultParsingPreservesProviderExtensionsAndLegacyArtifactBlocks(): void
    {
        $modification = ['result' => [
            [['type' => 'provider_block', 'provider' => ['value' => 42]]],
            [['type' => 'image', 'source_type' => 'base64', 'data' => 'aGVsbG8=', 'mime_type' => 'image/png']],
        ]];

        self::assertSame($modification, Hooks::parseToolCallResultModification($modification));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function resourceExtensions(): array
    {
        return ['text' => [['text' => 'hello']], 'blob' => [['blob' => 'aGVsbG8=']]];
    }

    /** @param array<string, string> $content */
    #[DataProvider('resourceExtensions')]
    public function testPreservesMcpResourceExtensions(array $content): void
    {
        $modification = ['result' => ['result', [[
            'type' => 'resource',
            'resource' => ['uri' => 'file:///test.txt', ...$content, 'extension' => ['value' => 42]],
            'annotations' => ['priority' => 0.5, 'extension' => 'annotation'],
            'extension' => 'resource',
        ]]]];

        self::assertSame($modification, Hooks::parseToolCallResultModification($modification));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function malformedResults(): array
    {
        $resource = static fn (array $resource, array $extra = []): array => ['result' => ['result', [['type' => 'resource', 'resource' => $resource] + $extra]]];

        return [
            'non-object content block' => [['result' => [[42], []]]],
            'null artifact' => [['result' => ['result', [null]]]],
            'resource with no uri' => [$resource([])],
            'numeric uri' => [$resource(['uri' => 42, 'text' => 'hello'])],
            'numeric text' => [$resource(['uri' => 'file:///test.txt', 'text' => 42])],
            'numeric blob' => [$resource(['uri' => 'file:///test.txt', 'blob' => 42])],
            'string priority' => [$resource(['uri' => 'file:///test.txt', 'text' => 'hello'], ['annotations' => ['priority' => 'high']])],
            'artifact without a type' => [['result' => ['result', [['data' => 'missing type']]]]],
            'numeric id' => [['result' => [[['type' => 'text', 'id' => 42]], []]]],
        ];
    }

    /** @param array<string, mixed> $modification */
    #[DataProvider('malformedResults')]
    public function testRejectsMalformedResultBlocks(array $modification): void
    {
        $this->expectException(ValidationException::class);

        Hooks::parseToolCallResultModification($modification);
    }

    // ---- parser contracts the tools rely on ------------------------------------------------------------------

    public function testVoidHookResultsParseToNull(): void
    {
        self::assertNull(Hooks::parseToolCallModification(null));
        self::assertNull(Hooks::parseToolCallResultModification(null));
        self::assertSame([], Hooks::parseToolCallModification([]));
    }

    public function testToolCallModificationKeepsOnlyHeadersAndArgs(): void
    {
        self::assertSame(
            ['headers' => ['X-A' => 'b'], 'args' => ['k' => 1]],
            Hooks::parseToolCallModification(['headers' => ['X-A' => 'b'], 'args' => ['k' => 1], 'stray' => true]),
        );
    }

    public function testParseToolHooksRequiresCallables(): void
    {
        $hook = static fn (): null => null;

        self::assertSame(['beforeToolCall' => $hook], Hooks::parseToolHooks(['beforeToolCall' => $hook]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Expected a afterToolCall callback');
        Hooks::parseToolHooks(['afterToolCall' => 'not a function at all']);
    }
}
