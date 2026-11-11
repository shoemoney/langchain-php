<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\OutputParserException;
use LangChain\Utils\JsonSchemaValidator;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Upstream StandardSchema cases adapted to native JSON Schema and a fake NDJSON transport. */
#[CoversClass(ChatOllama::class)]
#[CoversClass(JsonSchemaValidator::class)]
final class ChatOllamaStructuredValidationTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object', '$defs' => ['name' => ['type' => 'string', 'minLength' => 1]],
        'properties' => ['name' => ['$ref' => '#/$defs/name'], 'age' => ['type' => 'integer', 'minimum' => 18]],
        'required' => ['name', 'age'], 'additionalProperties' => false,
    ];

    private const VALID = ['name' => 'Alice', 'age' => 25];

    private static function http(string $method, mixed $value, string $name = 'extract'): FakeHttpClient
    {
        if ($method === 'functionCalling') {
            $records = [['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => $name, 'arguments' => $value]],
            ]], 'done' => false]];
        } else {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            $cut = intdiv(strlen($json), 2);
            $records = [
                ['message' => ['role' => 'assistant', 'content' => substr($json, 0, $cut)], 'done' => false],
                ['message' => ['role' => 'assistant', 'content' => substr($json, $cut)], 'done' => false],
            ];
        }
        $records[] = [
            'model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true,
            'done_reason' => 'stop', 'prompt_eval_count' => 4, 'eval_count' => 6,
        ];

        return new FakeHttpClient([], str_split(implode('', array_map(static fn (array $record): string => json_encode($record) . "\n", $records)), 7));
    }

    private static function model(FakeHttpClient $http, array $fields = []): ChatOllama
    {
        return new ChatOllama($fields + ['model' => 'llama3', 'httpClient' => $http, 'checkOrPullModel' => false]);
    }

    public static function methods(): iterable
    {
        foreach (['functionCalling', 'jsonMode', 'jsonSchema'] as $method) {
            foreach ([false, true] as $includeRaw) {
                yield $method . ($includeRaw ? ' with raw' : '') => [$method, $includeRaw];
            }
        }
    }

    #[DataProvider('methods')]
    public function testValidOutputPreservesNdjsonRequestAndRawMetadata(string $method, bool $includeRaw): void
    {
        $http = self::http($method, self::VALID, 'GetName');
        $result = self::model($http)->withStructuredOutput(self::SCHEMA, ['method' => $method, 'name' => 'GetName', 'includeRaw' => $includeRaw])->invoke('What?');
        self::assertSame(self::VALID, $includeRaw ? $result['parsed'] : $result);
        if ($includeRaw) {
            self::assertInstanceOf(AIMessage::class, $result['raw']);
            self::assertSame(['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10], $result['raw']->response_metadata['usage_metadata']);
            self::assertSame('ollama', $result['raw']->response_metadata['model_provider']);
        }
        self::assertSame([], $http->requests);
        self::assertCount(1, $http->streamRequests);
        self::assertStringEndsWith('/api/chat', $http->streamRequests[0]['url']);
        $body = $http->lastRequestBody();
        self::assertTrue($body['stream']);
        if ($method === 'functionCalling') {
            self::assertSame(self::SCHEMA, $body['tools'][0]['function']['parameters']);
            self::assertSame('GetName', $body['tools'][0]['function']['name']);
            self::assertArrayNotHasKey('format', $body);
        } else {
            self::assertSame($method === 'jsonMode' ? 'json' : self::SCHEMA, $body['format']);
            self::assertArrayNotHasKey('tools', $body);
        }
    }

    public static function invalid(): iterable
    {
        foreach (self::methods() as $label => [$method, $raw]) {
            yield "$label missing" => [$method, $raw, ['invalid' => true], 'required property'];
            yield "$label type" => [$method, $raw, ['name' => 123, 'age' => 25], 'must be string'];
            yield "$label bound" => [$method, $raw, ['name' => 'Alice', 'age' => 17], 'must be >= 18'];
        }
    }

    #[DataProvider('invalid')]
    public function testInvalidOutputThrowsOrFallsBackWithoutDuplicateHttp(string $method, bool $includeRaw, array $value, string $error): void
    {
        $http = self::http($method, $value);
        $structured = self::model($http)->withStructuredOutput(self::SCHEMA, ['method' => $method, 'includeRaw' => $includeRaw]);
        if ($includeRaw) {
            $result = $structured->invoke('What?');
            self::assertNull($result['parsed']);
            self::assertInstanceOf(AIMessage::class, $result['raw']);
            self::assertSame($value, $method === 'functionCalling' ? $result['raw']->toolCalls[0]['args'] : json_decode($result['raw']->content, true));
        } else {
            try {
                $structured->invoke('What?');
                self::fail('Expected a validation failure.');
            } catch (OutputParserException $exception) {
                self::assertStringContainsString($error, $exception->getMessage());
                self::assertSame('OUTPUT_PARSING_FAILURE', $exception->lcErrorCode);
                self::assertSame($value, json_decode($exception->llmOutput, true));
                if ($error === 'must be string') {
                    self::assertStringContainsString('"path":["name"]', $exception->getMessage());
                }
            }
        }
        self::assertSame([], $http->requests);
        self::assertCount(1, $http->streamRequests);
    }

    public function testReadyMadeFunctionDefinitionValidatesParametersAndPreservesWireShape(): void
    {
        $definition = ['name' => 'lookup', 'description' => 'Look it up', 'parameters' => self::SCHEMA];
        $http = self::http('functionCalling', self::VALID, 'lookup');
        self::assertSame(self::VALID, self::model($http)->withStructuredOutput($definition, ['method' => 'functionCalling'])->invoke('What?'));
        self::assertSame($definition, $http->lastRequestBody()['tools'][0]['function']);
        $invalid = self::http('functionCalling', ['invalid' => true], 'lookup');
        $result = self::model($invalid)->withStructuredOutput($definition, ['method' => 'functionCalling', 'includeRaw' => true])->invoke('What?');
        self::assertNull($result['parsed']);
        self::assertCount(1, $invalid->streamRequests);
    }

    public function testMissingNamedToolRemainsNull(): void
    {
        foreach ([false, true] as $includeRaw) {
            $http = self::http('functionCalling', ['invalid' => true], 'OtherTool');
            $result = self::model($http)->withStructuredOutput(self::SCHEMA, ['method' => 'functionCalling', 'includeRaw' => $includeRaw])->invoke('What?');
            self::assertNull($includeRaw ? $result['parsed'] : $result);
            self::assertCount(1, $http->streamRequests);
        }
    }

    public function testDefaultJsonSchemaValidates(): void
    {
        $http = self::http('jsonSchema', ['invalid' => true]);
        $result = self::model($http)->withStructuredOutput(self::SCHEMA, ['includeRaw' => true])->invoke('What?');
        self::assertNull($result['parsed']);
        self::assertSame(self::SCHEMA, $http->lastRequestBody()['format']);
        self::assertCount(1, $http->streamRequests);
    }

    public function testThinkingRemainsSeparateFromParsedContent(): void
    {
        $records = [
            ['message' => ['role' => 'assistant', 'thinking' => 'Consider ', 'content' => ''], 'done' => false],
            ['message' => ['role' => 'assistant', 'thinking' => 'the name.', 'content' => ''], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => json_encode(self::VALID)], 'done' => true],
        ];
        $http = new FakeHttpClient([], str_split(implode('', array_map(static fn (array $record): string => json_encode($record) . "\n", $records)), 5));
        $result = self::model($http, ['think' => true])->withStructuredOutput(self::SCHEMA, ['includeRaw' => true])->invoke('What?');
        self::assertSame(self::VALID, $result['parsed']);
        self::assertSame('Consider the name.', $result['raw']->additional_kwargs['reasoning_content']);
        self::assertSame(self::VALID, json_decode($result['raw']->content, true));
        self::assertTrue($http->lastRequestBody()['think']);
    }

    public function testNullableContentRootIsValidated(): void
    {
        $valid = self::http('jsonMode', null);
        self::assertNull(self::model($valid)->withStructuredOutput(['type' => 'null'], ['method' => 'jsonMode'])->invoke('What?'));
        $invalid = self::http('jsonMode', null);
        self::assertNull(self::model($invalid)->withStructuredOutput(['type' => 'object'], ['method' => 'jsonMode', 'includeRaw' => true])->invoke('What?')['parsed']);
    }
}
