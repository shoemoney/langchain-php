<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Messages\ToolMessage;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\ToolException as ToolInputParsingException;
use LangGraph\Mcp\ToolException;
use LangGraph\Mcp\ValidationException;
use LangGraph\Mcp\McpTools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ports `tests/tools.test.ts` ("Simplified Tool Adapter Tests") over a {@see FakeMcpClient}.
 *
 * Async/sync pairs upstream (`test.each([false, true])` over a sync vs promise-returning hook) collapse to
 * one case each: PHP callables have no promise form.
 */
#[CoversClass(McpTools::class)]
final class McpToolsTest extends McpTestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidLoaderOptions(): array
    {
        return [
            'negative timeout' => [['defaultToolTimeout' => -1]],
            'unknown key' => [['defaultToolTimeout' => 1000, 'timeoutTypo' => 1000]],
        ];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidLoaderOptions')]
    public function testRejectsInvalidLoaderOptionsBeforeDiscovery(array $options): void
    {
        $client = new FakeMcpClient([]);

        try {
            McpTools::loadMcpTools('test', $client, $options);
            self::fail('Expected invalid options to be refused.');
        } catch (ValidationException) {
            self::assertSame(0, $client->listCalls);
        }
    }

    public function testSchemaParsingPreservesBooleanSchemasAndExtensionValues(): void
    {
        $inputSchema = [
            'type' => 'object',
            'properties' => [
                'anything' => true,
                'never' => false,
                'value' => ['type' => ['string', 'null']],
            ],
            'x-provider' => ['choices' => [1, 'two', null, ['enabled' => true]]],
        ];
        $tool = self::firstTool(new FakeMcpClient([['name' => 'schema', 'inputSchema' => $inputSchema]]));

        self::assertSame($inputSchema, $tool->schema->schema);
        self::assertSame($inputSchema, $tool->schema->toJsonSchema());
    }

    public function testPreservesMalformedConstraintsAndRejectsTheirInputWithoutAWireCall(): void
    {
        $client = new FakeMcpClient([[
            'name' => 'schema',
            'inputSchema' => ['type' => 'object', 'properties' => [], 'allOf' => [['required' => [42]]]],
        ]]);
        $tool = self::firstTool($client);

        // Rejected by the preserved constraint, not by some unrelated failure.
        $this->expectException(ToolInputParsingException::class);

        try {
            $tool->invoke([]);
        } finally {
            self::assertSame([], $client->calls);
        }
    }

    /** @return array<string, array{0: string, 1: bool|null, 2: bool}> */
    public static function incompleteResponses(): array
    {
        return [
            'legacy default' => ['legacy', null, false],
            'legacy explicit opt-in' => ['legacy', true, false],
            'modern default' => ['modern', null, true],
            'modern explicit opt-in' => ['modern', true, true],
            'modern explicit opt-out' => ['modern', false, false],
        ];
    }

    #[DataProvider('incompleteResponses')]
    public function testHandlesAnIncompleteResponse(string $era, ?bool $elicitation, bool $answers): void
    {
        $pending = [
            'resultType' => 'input_required',
            'inputRequests' => [
                'confirm' => [
                    'method' => 'elicitation/create',
                    'params' => [
                        'mode' => 'form',
                        'message' => 'Confirm?',
                        'requestedSchema' => [
                            'type' => 'object',
                            'properties' => ['confirmed' => ['type' => 'boolean']],
                            'required' => ['confirmed'],
                        ],
                    ],
                ],
            ],
            'requestState' => 'opaque-state',
        ];
        $client = (new FakeMcpClient([self::echoTool()], $era))->willReturn($pending);
        $tool = self::firstTool($client, $elicitation === null ? [] : ['elicitation' => $elicitation]);

        // A legacy server never returns `input_required`, so the elicitation path stays out of its way.
        // Outside a graph the modern path reports how to answer; the legacy path refuses the result by name.
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches($answers
            ? '/Invoke it inside a LangGraph/'
            : '/asked for input, which only a modern server with elicitation enabled can answer/');

        try {
            $tool->invoke([]);
        } finally {
            self::assertCount(1, $client->calls);
            $options = $client->optionsOf();
            if ($answers) {
                self::assertSame(['elicitation' => ['form' => [], 'url' => []]], $options['_meta']['io.modelcontextprotocol/clientCapabilities']);
                self::assertTrue($options['allowInputRequired']);
            } else {
                self::assertArrayNotHasKey('_meta', $options);
                self::assertArrayNotHasKey('allowInputRequired', $options);
            }
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function terminalResultsThatViolateTheOutputSchema(): array
    {
        return [
            'content that violates the schema' => [
                ['content' => [['type' => 'text', 'text' => 'done']], 'structuredContent' => ['approved' => 'yes']],
                ['data/approved must be boolean', 'at structuredContent'],
            ],
            'no structured content at all' => [
                ['content' => [['type' => 'text', 'text' => 'done']]],
                ['data must be object', 'at structuredContent'],
            ],
        ];
    }

    /**
     * A conforming server validates its own structured output; these only arise from one that does not.
     *
     * @param array<string, mixed> $result
     * @param list<string>         $errors
     */
    #[DataProvider('terminalResultsThatViolateTheOutputSchema')]
    public function testValidatesTheTerminalResultItselfWhenAToolElicits(array $result, array $errors): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'approve',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean']], 'required' => ['approved']],
        ]], 'modern'))->willReturn($result);
        $tool = self::firstTool($client, ['elicitation' => true]);

        $failure = self::thrownBy(static fn () => $tool->invoke([]));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('MCP tool "approve" on server "test" returned output its schema rejects', $failure->getMessage());
        foreach ($errors as $error) {
            self::assertStringContainsString($error, $failure->getMessage());
        }

        // The schema is withheld from the round so an `input_required` survives.
        self::assertTrue($client->optionsOf()['allowInputRequired']);
        self::assertArrayNotHasKey('outputSchema', $client->optionsOf()['toolDefinition']);
    }

    public function testReportsAClientThatCannotRebindHeaders(): void
    {
        $client = (new FakeMcpClient([self::echoTool()], 'modern'))->willReturn(['content' => []]);
        $tool = self::firstTool(new PlainMcpClient($client), ['beforeToolCall' => static fn (): array => ['headers' => ['X-Tenant' => 'a']]]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('does not support header changes');

        try {
            $tool->invoke([]);
        } finally {
            self::assertSame([], $client->calls);
        }
    }

    public function testRefusesAForkedConnectionThatChangedProtocolEra(): void
    {
        $forked = new FakeMcpClient([], 'legacy');
        $client = (new FakeMcpClient([self::echoTool()], 'modern', fork: $forked))->willReturn(['content' => []]);
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['headers' => ['X-Tenant' => 'a']]]);

        // Tool schemas and the elicitation decision were both fixed at discovery.
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('changed protocol era after tool discovery');

        try {
            $tool->invoke([]);
        } finally {
            self::assertSame([], $forked->calls);
            self::assertSame([['X-Tenant' => 'a']], $client->forkedWith);
        }
    }

    public function testRoutesAHeaderCallThroughTheForkedClient(): void
    {
        $forked = (new FakeMcpClient([], 'modern'))->willReturn(self::text('forked'));
        $client = (new FakeMcpClient([self::echoTool()], 'modern', fork: $forked))->willReturn(self::text('base'));
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['headers' => ['X-Tenant' => 'a']]]);

        self::assertSame('forked', $tool->invoke([]));
        self::assertSame([], $client->calls);
        self::assertCount(1, $forked->calls);
    }

    public function testLeavesAnErrorResultToTheAdapterRatherThanSchemaValidation(): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'approve',
            'inputSchema' => ['type' => 'object'],
            'outputSchema' => ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean']], 'required' => ['approved']],
        ]], 'modern'))->willReturn(['content' => [['type' => 'text', 'text' => 'upstream exploded']], 'isError' => true]);
        $tool = self::firstTool($client, ['elicitation' => true]);

        // An error result carries no structured content by design; a schema violation would bury the
        // server's own message.
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/upstream exploded/');

        $tool->invoke([]);
    }

    // ---- hook return validation -------------------------------------------------------------------------

    private function echoClient(): FakeMcpClient
    {
        return (new FakeMcpClient([self::echoTool(['inputSchema' => ['type' => 'object', 'properties' => []]])]))
            ->willReturn(self::text('original'));
    }

    public function testDoesNotMutateTheArgumentsPreviouslyPassedToAHook(): void
    {
        $observed = null;
        $client = $this->echoClient();
        $tool = self::firstTool($client, [
            'beforeToolCall' => static function (array $request) use (&$observed): array {
                $observed = $request['args'];

                return ['args' => ['value' => 'effective']];
            },
        ]);

        $tool->invoke([]);

        self::assertSame([], $observed);
        self::assertSame('echo', $client->calls[0]['name']);
        self::assertSame(['value' => 'effective'], $client->calls[0]['arguments']);
        self::assertSame('echo', $client->optionsOf()['toolDefinition']['name']);
    }

    public function testRejectsScalarArgumentOverridesBeforeIssuingARequest(): void
    {
        $client = $this->echoClient();
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['args' => 'invalid']]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Invalid input: expected record, received string');

        try {
            $tool->invoke([]);
        } finally {
            self::assertSame([], $client->calls);
        }
    }

    public function testRejectsInvalidEffectiveArgumentsWithAValidationCause(): void
    {
        $client = (new FakeMcpClient([self::echoTool([
            'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'integer', 'minimum' => 1]]],
        ])]));
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['args' => ['value' => -1]]]);

        $failure = self::thrownBy(static fn () => $tool->invoke(['value' => 1]));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('Invalid arguments for MCP tool "echo"', $failure->getMessage());
        self::assertInstanceOf(ValidationException::class, $failure->cause);
        self::assertSame([], $client->calls);
    }

    public function testHandlesProgressObserverFailuresWithoutFailingTheTool(): void
    {
        $seen = [];
        $client = $this->echoClient()->respondWith(static function (string $name, array $args, array $options): array {
            $options['onprogress'](['progress' => 1, 'total' => 1]);

            return self::text('completed');
        });
        $tool = self::firstTool($client, [
            'onProgress' => static function (array $progress, array $context) use (&$seen): never {
                $seen[] = [$progress, $context];

                throw new \RuntimeException('progress observer failed');
            },
        ]);

        self::assertSame('completed', $tool->invoke([]));
        self::assertSame([[['progress' => 1, 'total' => 1], ['type' => 'tool', 'name' => 'echo', 'args' => [], 'server' => 'test']]], $seen);
    }

    public function testValidatesBeforeHooksAfterRunningThem(): void
    {
        $client = $this->echoClient();
        $tool = self::firstTool($client, ['beforeToolCall' => static fn (): array => ['headers' => ['test' => 42]]]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/string/');

        try {
            $tool->invoke([]);
        } finally {
            self::assertSame([], $client->calls);
        }
    }

    public function testValidatesAfterHooksAfterRunningThem(): void
    {
        $client = $this->echoClient();
        $tool = self::firstTool($client, ['afterToolCall' => static fn (): array => ['result' => 42]]);

        // The wire call already happened; only the hook's result is refused.
        try {
            $tool->invoke([]);
            self::fail('Expected the hook result to be refused.');
        } catch (ToolException $e) {
            self::assertStringContainsString('expected string, received number', $e->getMessage());
            self::assertCount(1, $client->calls);
        }
    }

    public function testPreservesSuccessfulModificationsAndFormatsHookFailures(): void
    {
        $client = $this->echoClient();
        $tool = self::firstTool($client, [
            'beforeToolCall' => static fn (): array => ['args' => ['value' => 'effective']],
            'afterToolCall' => static fn (): array => ['result' => 'changed'],
        ]);

        self::assertSame('changed', $tool->invoke([]));
        self::assertSame(['value' => 'effective'], $client->calls[0]['arguments']);

        $invalid = self::firstTool($client, [
            'beforeToolCall' => static function (): void {
                throw ValidationException::of('Invalid input: expected string, received number');
            },
        ]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/string/');
        $invalid->invoke([]);
    }

    // ---- loadMcpTools -----------------------------------------------------------------------------------

    public function testShouldLoadAllToolsFromClient(): void
    {
        $schema = ['type' => 'object', 'properties' => [], 'required' => []];
        $client = new FakeMcpClient([
            ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => $schema],
            ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => $schema],
        ]);

        $tools = McpTools::loadMcpTools('mockServer(should load all tools)', $client);

        self::assertCount(2, $tools);
        self::assertContainsOnlyInstancesOf(DynamicStructuredTool::class, $tools);
        self::assertSame('tool1', $tools[0]->name);
        self::assertSame('tool2', $tools[1]->name);
        self::assertSame('Tool 2', $tools[1]->description);
    }

    public function testShouldValidateToolInputAgainstInputSchema(): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'weather',
            'description' => 'Get the weather for a given city',
            'inputSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        ]]))->respondWith(static fn (string $name, array $args): array => self::text("It is currently 70 degrees and cloudy in {$args['city']}."));

        $tools = McpTools::loadMcpTools('mockServer(should validate)', $client);
        self::assertCount(1, $tools);
        self::assertSame('weather', $tools[0]->name);

        // Invalid input never reaches the server.
        try {
            $tools[0]->invoke(['location' => 'New York']);
            self::fail('Expected invalid input to be refused.');
        } catch (ToolInputParsingException) {
            self::assertSame([], $client->calls);
        }

        self::assertSame('It is currently 70 degrees and cloudy in New York.', $tools[0]->invoke(['city' => 'New York']));
        self::assertSame('weather', $client->calls[0]['name']);
        self::assertSame(['city' => 'New York'], $client->calls[0]['arguments']);
        self::assertSame('weather', $client->optionsOf()['toolDefinition']['name']);
    }

    public function testShouldLoadToolWithNoInputParameters(): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'weather',
            'description' => 'Get the current weather',
            'inputSchema' => ['type' => 'object'],
        ]]))->willReturn(self::text('It is currently 70 degrees and cloudy.'));

        $tools = McpTools::loadMcpTools('mockServer(no params)', $client);

        self::assertCount(1, $tools);
        self::assertSame('It is currently 70 degrees and cloudy.', $tools[0]->invoke([]));
        self::assertSame([], $client->calls[0]['arguments']);
        self::assertSame('weather', $client->calls[0]['name']);
    }

    public function testShouldHandleEmptyToolList(): void
    {
        self::assertSame([], McpTools::loadMcpTools('mockServer(empty)', new FakeMcpClient([])));
    }

    public function testShouldFilterOutToolsWithoutNames(): void
    {
        $schema = ['type' => 'object', 'properties' => [], 'required' => []];
        $client = new FakeMcpClient([
            ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => $schema],
            ['description' => 'No name tool', 'inputSchema' => $schema],
            ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => $schema],
        ]);

        $tools = McpTools::loadMcpTools('mockServer(nameless)', $client);

        self::assertSame(['tool1', 'tool2'], array_map(static fn (DynamicStructuredTool $t): string => $t->name, $tools));
    }

    public function testShouldHandleJsonSchemasWithDefsReferencesPydanticV2Style(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/DataItem'], 'description' => 'List of items'],
                'metadata' => ['$ref' => '#/$defs/Metadata', 'description' => 'Response metadata'],
            ],
            'required' => ['items', 'metadata'],
            '$defs' => [
                'DataItem' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'Item ID'],
                        'name' => ['type' => 'string', 'description' => 'Item name'],
                        'value' => ['type' => 'number', 'description' => 'Item value'],
                    ],
                    'required' => ['id', 'name', 'value'],
                ],
                'Metadata' => [
                    'type' => 'object',
                    'properties' => [
                        'total_count' => ['type' => 'integer', 'description' => 'Total count'],
                        'timestamp' => ['type' => 'string', 'description' => 'Timestamp'],
                    ],
                    'required' => ['total_count', 'timestamp'],
                ],
            ],
        ];
        $client = (new FakeMcpClient([['name' => 'query_data', 'description' => 'Query tool that returns nested response', 'inputSchema' => $schema]]))
            ->respondWith(static fn (string $name, array $args): array => self::text(
                'Received ' . count($args['items']) . ' items with total_count=' . $args['metadata']['total_count'],
            ));

        $tools = McpTools::loadMcpTools('mockServer(defs)', $client);
        self::assertCount(1, $tools);
        self::assertSame('query_data', $tools[0]->name);
        self::assertSame($schema, $tools[0]->schema->schema);

        $input = ['items' => [['id' => '1', 'name' => 'Test', 'value' => 100.0]], 'metadata' => ['total_count' => 1, 'timestamp' => '2024-01-01']];
        self::assertSame('Received 1 items with total_count=1', $tools[0]->invoke($input));
        self::assertSame($input, $client->calls[0]['arguments']);

        // The reference is followed, not ignored: a bad item is refused locally.
        $this->expectException(ToolInputParsingException::class);
        $tools[0]->invoke(['items' => [['id' => 1]], 'metadata' => ['total_count' => 1, 'timestamp' => 'x']]);
    }

    public function testShouldHandleJsonSchemasWithDefinitionsOlderStyle(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['user' => ['$ref' => '#/definitions/User']],
            'required' => ['user'],
            'definitions' => [
                'User' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'email' => ['type' => 'string']],
                    'required' => ['name', 'email'],
                ],
            ],
        ];
        $client = (new FakeMcpClient([['name' => 'create_user', 'description' => 'Create a user', 'inputSchema' => $schema]]))
            ->respondWith(static fn (string $name, array $args): array => self::text('Created user: ' . $args['user']['name']));

        $tools = McpTools::loadMcpTools('mockServer(definitions)', $client);

        self::assertCount(1, $tools);
        self::assertSame('Created user: John', $tools[0]->invoke(['user' => ['name' => 'John', 'email' => 'john@example.com']]));
    }

    public function testShouldHandleDeeplyNestedRefReferences(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['order' => ['$ref' => '#/$defs/Order']],
            'required' => ['order'],
            '$defs' => [
                'Order' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'customer' => ['$ref' => '#/$defs/Customer'],
                        'items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/OrderItem']],
                    ],
                    'required' => ['id', 'customer', 'items'],
                ],
                'Customer' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'address' => ['$ref' => '#/$defs/Address']],
                    'required' => ['name', 'address'],
                ],
                'Address' => [
                    'type' => 'object',
                    'properties' => ['street' => ['type' => 'string'], 'city' => ['type' => 'string']],
                    'required' => ['street', 'city'],
                ],
                'OrderItem' => [
                    'type' => 'object',
                    'properties' => ['product' => ['type' => 'string'], 'quantity' => ['type' => 'integer']],
                    'required' => ['product', 'quantity'],
                ],
            ],
        ];
        $client = (new FakeMcpClient([['name' => 'create_order', 'description' => 'Create an order', 'inputSchema' => $schema]]))
            ->willReturn(self::text('Order created successfully'));

        $tools = McpTools::loadMcpTools('mockServer(nested refs)', $client);
        self::assertCount(1, $tools);

        $order = [
            'id' => 'order-123',
            'customer' => ['name' => 'Jane Doe', 'address' => ['street' => '123 Main St', 'city' => 'Springfield']],
            'items' => [['product' => 'Widget', 'quantity' => 2], ['product' => 'Gadget', 'quantity' => 1]],
        ];
        self::assertSame('Order created successfully', $tools[0]->invoke(['order' => $order]));

        // Three refs deep, a wrong type is still caught.
        $order['customer']['address']['city'] = 42;
        try {
            $tools[0]->invoke(['order' => $order]);
            self::fail('Expected the nested violation to be refused.');
        } catch (ToolInputParsingException) {
            self::assertCount(1, $client->calls);
        }
    }

    public function testShouldLoadToolsWithSpecifiedResponseFormat(): void
    {
        $client = (new FakeMcpClient([[
            'name' => 'tool1',
            'description' => 'Tool 1',
            'inputSchema' => ['type' => 'object', 'properties' => ['input' => ['type' => 'string']], 'required' => ['input']],
        ]]));
        $tools = McpTools::loadMcpTools('mockServer(response format)', $client, []);

        self::assertCount(1, $tools);
        self::assertSame('content_and_artifact', $tools[0]->responseFormat);

        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $embedded = ['type' => 'resource', 'resource' => ['text' => 'Here is your image', 'uri' => 'test-data://test-artifact', 'mimeType' => 'text/plain']];
        $client->willReturn(['content' => [
            ['type' => 'text', 'text' => 'Here is your image'],
            ['type' => 'image', 'data' => $png, 'mimeType' => 'image/png'],
            $embedded,
        ]]);
        $expectedContent = [['type' => 'text', 'text' => 'Here is your image'], ['type' => 'image', 'mimeType' => 'image/png', 'data' => $png]];

        self::assertEquals($expectedContent, $tools[0]->invoke(['input' => 'test input']));

        $message = $tools[0]->invoke(self::toolCall('mcp__mockServer(response format)__tool1', ['input' => 'test input'], 'tool_call_id_123'));

        self::assertInstanceOf(ToolMessage::class, $message);
        self::assertSame('tool_call_id_123', $message->toolCallId);
        self::assertEquals($expectedContent, $message->content);
        self::assertEquals([$embedded], $message->artifact);
    }

    public function testPrefixesToolNamesWithTheServerAndAnAdditionalPrefix(): void
    {
        $client = new FakeMcpClient([self::echoTool()]);

        self::assertSame('echo', McpTools::loadMcpTools('srv', $client)[0]->name);
        self::assertSame('srv__echo', McpTools::loadMcpTools('srv', $client, ['prefixToolNameWithServerName' => true])[0]->name);
        self::assertSame('mcp__echo', McpTools::loadMcpTools('srv', $client, ['additionalToolNamePrefix' => 'mcp'])[0]->name);
        self::assertSame('mcp__srv__echo', McpTools::loadMcpTools('srv', $client, [
            'additionalToolNamePrefix' => 'mcp',
            'prefixToolNameWithServerName' => true,
        ])[0]->name);
    }

    public function testThrowOnLoadErrorFalseSkipsAnInvalidToolAndTrueSurfacesIt(): void
    {
        $client = new FakeMcpClient([
            ['name' => 'broken', 'inputSchema' => 'not a schema'],
            self::echoTool(),
        ]);

        self::assertSame(['echo'], array_map(
            static fn (DynamicStructuredTool $t): string => $t->name,
            McpTools::loadMcpTools('srv', $client, ['throwOnLoadError' => false]),
        ));

        $this->expectException(ValidationException::class);
        McpTools::loadMcpTools('srv', $client);
    }

    public function testCarriesAnnotationsAndTheDefaultTimeoutOntoTheTool(): void
    {
        $client = (new FakeMcpClient([self::echoTool(['annotations' => ['readOnlyHint' => true]])]))
            ->respondWith(static fn (): array => self::text('ok'));
        $tool = self::firstTool($client, ['defaultToolTimeout' => 1500]);

        self::assertSame(['annotations' => ['readOnlyHint' => true]], $tool->metadata);
        $tool->invoke([]);
        self::assertSame(1500, $client->optionsOf()['timeout']);
    }

    public function testPreservesAllOfAndConditionalSchemas(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => false,
            'allOf' => [[
                'if' => ['properties' => ['allDay' => ['const' => true]], 'required' => ['allDay']],
                'then' => ['properties' => [
                    'endDate' => ['description' => 'End date (format yyyy-mm-dd)', 'type' => 'string'],
                    'startDate' => ['description' => 'Start date (format yyyy-mm-dd)', 'type' => 'string'],
                ]],
                'else' => ['properties' => [
                    'endDate' => ['description' => 'End date & time (RFC3339)', 'type' => 'string'],
                    'startDate' => ['description' => 'Start date & time (RFC3339)', 'type' => 'string'],
                ]],
            ]],
            'properties' => [
                'allDay' => ['default' => false, 'description' => 'All day event', 'type' => 'boolean'],
                'summary' => ['description' => 'Title of the event', 'type' => 'string'],
            ],
            'required' => ['summary'],
            'unevaluatedProperties' => false,
        ];
        $client = (new FakeMcpClient([['name' => 'create_event', 'description' => 'Create calendar event', 'inputSchema' => $schema]]))
            ->willReturn(self::text('Event created'));

        $tools = McpTools::loadMcpTools('mockServer(allOf)', $client);

        self::assertCount(1, $tools);
        self::assertSame('create_event', $tools[0]->name);
        self::assertSame($schema, $tools[0]->schema->schema);
        self::assertSame('Event created', $tools[0]->invoke(['summary' => 'Test Event', 'allDay' => true]));
    }

    public function testPreservesAnyOfSchemas(): void
    {
        $schema = [
            'type' => 'object',
            'anyOf' => [
                ['type' => 'object', 'properties' => ['mode' => ['type' => 'string'], 'value' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['mode' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            ],
        ];
        $client = (new FakeMcpClient([['name' => 'configure', 'description' => 'Configure something', 'inputSchema' => $schema]]))
            ->willReturn(self::text('Configured'));

        $tools = McpTools::loadMcpTools('mockServer(anyOf)', $client);

        self::assertCount(1, $tools);
        self::assertSame($schema, $tools[0]->schema->schema);
        // The input satisfies one of the original alternatives.
        self::assertSame('Configured', $tools[0]->invoke(['mode' => 'simple', 'value' => 'test']));
    }

    public function testPreservesExclusiveOneOfSchemas(): void
    {
        $client = new FakeMcpClient([[
            'name' => 'process_payment',
            'description' => 'Process a payment',
            'inputSchema' => [
                'type' => 'object',
                'oneOf' => [
                    ['type' => 'object', 'properties' => ['paymentType' => ['type' => 'string'], 'cardNumber' => ['type' => 'string']]],
                    ['type' => 'object', 'properties' => ['paymentType' => ['type' => 'string'], 'accountNumber' => ['type' => 'string']]],
                ],
            ],
        ]]);
        $tools = McpTools::loadMcpTools('mockServer(oneOf)', $client);
        self::assertCount(1, $tools);

        // Both variants accept this input, and oneOf demands exactly one.
        try {
            $tools[0]->invoke(['paymentType' => 'credit_card', 'cardNumber' => '1234-5678-9012-3456']);
            self::fail('Expected oneOf to be enforced.');
        } catch (ToolInputParsingException) {
            self::assertSame([], $client->calls);
        }
    }

    public function testPreservesSchemaAndUnevaluatedPropertiesInToolSchemas(): void
    {
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
            'unevaluatedProperties' => false,
        ];
        $client = (new FakeMcpClient([['name' => 'greet', 'description' => 'Greet someone', 'inputSchema' => $schema]]))
            ->willReturn(self::text('Hello!'));

        $tools = McpTools::loadMcpTools('mockServer(schema preservation)', $client);

        self::assertSame($schema, $tools[0]->schema->schema);
        self::assertSame('Hello!', $tools[0]->invoke(['name' => 'World']));

        // `unevaluatedProperties: false` is enforced, not just carried.
        $this->expectException(ToolInputParsingException::class);
        $tools[0]->invoke(['name' => 'World', 'extra' => 1]);
    }

    public function testShouldHandleComplexRealWorldSchemaFromBugReport9804(): void
    {
        $dated = static fn (string $description, string $title): array => ['description' => $description, 'title' => $title, 'type' => 'string'];
        $schema = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'additionalProperties' => false,
            'allOf' => [[
                'else' => ['properties' => [
                    'endDate' => $dated('End time (RFC3339 format)', 'End date & time'),
                    'startDate' => $dated('Start time (RFC3339 format)', 'Start date & time'),
                ]],
                'if' => ['properties' => ['allDay' => ['const' => true]], 'required' => ['allDay']],
                'then' => ['properties' => [
                    'endDate' => $dated('End date (yyyy-mm-dd format)', 'End date'),
                    'startDate' => $dated('Start date (yyyy-mm-dd format)', 'Start date'),
                ]],
            ]],
            'properties' => [
                'allDay' => ['default' => false, 'description' => 'All day event', 'title' => 'All day', 'type' => 'boolean'],
                'attendees' => [
                    'description' => 'The attendees of the event',
                    'items' => ['additionalProperties' => false, 'properties' => ['email' => ['type' => 'string'], 'displayName' => ['type' => 'string']], 'type' => 'object'],
                    'type' => 'array',
                ],
                'calendarId' => ['description' => 'The calendar ID', 'type' => 'string'],
                'summary' => ['description' => 'Title of the event', 'type' => 'string'],
                'status' => ['description' => 'Status of the event', 'enum' => ['confirmed', 'tentative', 'cancelled'], 'type' => 'string'],
            ],
            'required' => ['calendarId', 'summary', 'startDate', 'endDate'],
            'type' => 'object',
            'unevaluatedProperties' => false,
        ];
        $client = (new FakeMcpClient([['name' => 'createEvent', 'description' => 'Create calendar event', 'inputSchema' => $schema]]))
            ->willReturn(self::text('Event created successfully'));

        // This must load: it used to fail against OpenAI.
        $tools = McpTools::loadMcpTools('mockServer(google calendar)', $client);
        self::assertCount(1, $tools);
        self::assertSame('createEvent', $tools[0]->name);

        // It loads, but the original additionalProperties constraint rejects the dates.
        try {
            $tools[0]->invoke([
                'calendarId' => 'primary',
                'summary' => 'Team Meeting',
                'startDate' => '2024-01-15T10:00:00Z',
                'endDate' => '2024-01-15T11:00:00Z',
                'allDay' => false,
                'attendees' => [['email' => 'test@example.com', 'displayName' => 'Test User']],
                'status' => 'confirmed',
            ]);
            self::fail('Expected the constraint to be enforced.');
        } catch (ToolInputParsingException) {
            self::assertSame([], $client->calls);
        }
    }

    public function testShouldHandleAllOfWithMultipleSchemasToMerge(): void
    {
        $schema = [
            'type' => 'object',
            'allOf' => [
                ['properties' => ['firstName' => ['type' => 'string']], 'required' => ['firstName']],
                ['properties' => ['lastName' => ['type' => 'string']], 'required' => ['lastName']],
                ['properties' => ['email' => ['type' => 'string']]],
            ],
            'properties' => ['id' => ['type' => 'string']],
        ];
        $client = (new FakeMcpClient([['name' => 'create_user', 'description' => 'Create a user', 'inputSchema' => $schema]]))
            ->willReturn(self::text('User created'));

        $tools = McpTools::loadMcpTools('mockServer(multiple allOf)', $client);

        self::assertCount(1, $tools);
        self::assertSame('User created', $tools[0]->invoke(['id' => '123', 'firstName' => 'John', 'lastName' => 'Doe', 'email' => 'j@example.com']));

        // Every allOf clause binds: a missing lastName is refused.
        $this->expectException(ToolInputParsingException::class);
        $tools[0]->invoke(['id' => '123', 'firstName' => 'John']);
    }
}
