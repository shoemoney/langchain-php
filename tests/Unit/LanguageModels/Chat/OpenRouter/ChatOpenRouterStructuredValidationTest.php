<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\OutputParserException;
use LangChain\Utils\JsonSchemaValidator;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SerializableSchema upstream cases adapted to this port's supported JSON Schema representation. */
#[CoversClass(ChatOpenRouter::class)]
#[CoversClass(JsonSchemaValidator::class)]
final class ChatOpenRouterStructuredValidationTest extends TestCase
{
    private static function schema(): array
    {
        return [
            'type' => 'object',
            '$defs' => ['profile' => [
                'type' => 'object', 'properties' => ['age' => ['type' => 'integer', 'minimum' => 18]],
                'required' => ['age'], 'additionalProperties' => false,
            ]],
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'profile' => ['$ref' => '#/$defs/profile'],
                'role' => ['enum' => ['reader', 'editor']],
            ],
            'required' => ['name', 'profile', 'role'], 'additionalProperties' => false,
        ];
    }

    private static function valid(): array
    {
        return ['name' => 'Jane', 'profile' => ['age' => 25], 'role' => 'reader'];
    }

    private static function http(string $method, mixed $value, string $name = 'extract'): FakeHttpClient
    {
        $message = ['role' => 'assistant', 'content' => json_encode($value, JSON_THROW_ON_ERROR)];
        if ($method === 'functionCalling') {
            $message['content'] = '';
            $message['tool_calls'] = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($value, JSON_THROW_ON_ERROR)]]];
        }

        return new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'gen-validation', 'model' => 'openai/gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $method === 'functionCalling' ? 'tool_calls' : 'stop']],
        ])]);
    }

    private static function model(FakeHttpClient $http, array $fields = []): ChatOpenRouter
    {
        return new ChatOpenRouter($fields + ['model' => 'openai/gpt-4o-mini', 'apiKey' => 'test-key', 'httpClient' => $http, 'maxRetries' => 0]);
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
    public function testValidOutputAndWireConfiguration(string $method, bool $includeRaw): void
    {
        $http = self::http($method, self::valid(), 'PersonInfo');
        $schema = self::schema();
        $result = self::model($http)->withStructuredOutput($schema, ['method' => $method, 'name' => 'PersonInfo', 'strict' => true, 'includeRaw' => $includeRaw])->invoke('Describe Jane');
        self::assertSame(self::valid(), $includeRaw ? $result['parsed'] : $result);
        self::assertSame(self::schema(), $schema);
        if ($includeRaw) {
            self::assertSame(['raw', 'parsed'], array_keys($result));
            self::assertInstanceOf(AIMessage::class, $result['raw']);
            self::assertSame('gen-validation', $result['raw']->id);
        }
        self::assertCount(1, $http->requests);
        $body = $http->lastRequestBody();
        if ($method === 'functionCalling') {
            self::assertSame($schema, $body['tools'][0]['function']['parameters']);
            self::assertSame('PersonInfo', $body['tool_choice']['function']['name']);
            self::assertTrue($body['tools'][0]['function']['strict']);
        } elseif ($method === 'jsonSchema') {
            self::assertSame($schema, $body['response_format']['json_schema']['schema']);
            self::assertSame('PersonInfo', $body['response_format']['json_schema']['name']);
            self::assertTrue($body['response_format']['json_schema']['strict']);
        } else {
            self::assertSame(['type' => 'json_object'], $body['response_format']);
        }
    }

    public static function invalid(): iterable
    {
        $cases = [
            'required' => [['wrong_field' => 123], 'required property'],
            'nested type' => [['name' => 'Jane', 'profile' => ['age' => '25'], 'role' => 'reader'], 'must be integer'],
            'nested minimum' => [['name' => 'Jane', 'profile' => ['age' => 17], 'role' => 'reader'], 'must be >= 18'],
            'enum' => [['name' => 'Jane', 'profile' => ['age' => 25], 'role' => 'admin'], 'allowed values'],
            'additional property' => [self::valid() + ['extra' => true], 'additional properties'],
        ];
        foreach (self::methods() as $label => [$method, $raw]) {
            foreach ($cases as $case => [$value, $error]) {
                yield "$label $case" => [$method, $raw, $value, $error];
            }
        }
    }

    #[DataProvider('invalid')]
    public function testSchemaFailureThrowsOrReturnsRawWithoutRepeatingHttp(string $method, bool $includeRaw, array $value, string $error): void
    {
        $http = self::http($method, $value);
        $structured = self::model($http)->withStructuredOutput(self::schema(), ['method' => $method, 'includeRaw' => $includeRaw]);
        if ($includeRaw) {
            $result = $structured->invoke('Describe Jane');
            self::assertNull($result['parsed']);
            self::assertInstanceOf(AIMessage::class, $result['raw']);
            self::assertSame('gen-validation', $result['raw']->id);
            if ($method === 'functionCalling') {
                self::assertSame($value, $result['raw']->toolCalls[0]['args']);
            } else {
                self::assertSame($value, json_decode($result['raw']->content, true));
            }
        } else {
            try {
                $structured->invoke('Describe Jane');
                self::fail('Expected schema validation failure.');
            } catch (OutputParserException $exception) {
                self::assertStringContainsString($error, $exception->getMessage());
                self::assertSame('OUTPUT_PARSING_FAILURE', $exception->lcErrorCode);
                self::assertSame($value, json_decode($exception->llmOutput, true));
                if (str_starts_with($error, 'must be')) {
                    self::assertStringContainsString('"path":["profile","age"]', $exception->getMessage());
                }
            }
        }
        self::assertCount(1, $http->requests);
    }

    public function testSchemaNameSelectsMatchingToolAndPreservesAbsentToolResult(): void
    {
        $http = self::http('functionCalling', self::valid(), 'PersonInfo');
        $schema = self::schema() + ['name' => 'PersonInfo'];
        self::assertSame(self::valid(), self::model($http)->withStructuredOutput($schema, ['method' => 'functionCalling'])->invoke('Describe Jane'));
        self::assertArrayNotHasKey('name', $http->lastRequestBody()['tools'][0]['function']['parameters']);
        foreach ([false, true] as $includeRaw) {
            $other = self::http('functionCalling', ['wrong_field' => 123], 'OtherTool');
            $result = self::model($other)->withStructuredOutput(self::schema(), ['method' => 'functionCalling', 'includeRaw' => $includeRaw])->invoke('Describe Jane');
            self::assertNull($includeRaw ? $result['parsed'] : $result);
            self::assertCount(1, $other->requests);
        }
    }

    public function testNullableRootValueIsValidatedAndPreserved(): void
    {
        $http = self::http('jsonMode', null);
        self::assertNull(self::model($http)->withStructuredOutput(['type' => 'null'], ['method' => 'jsonMode'])->invoke('Return null'));
        $invalid = self::http('jsonMode', null);
        $result = self::model($invalid)->withStructuredOutput(['type' => 'object'], ['method' => 'jsonMode', 'includeRaw' => true])->invoke('Return object');
        self::assertNull($result['parsed']);
        self::assertSame('null', $result['raw']->content);
    }

    public function testDefaultMethodAlsoValidatesAndTransportFailurePropagates(): void
    {
        $http = self::http('jsonSchema', ['wrong_field' => 123]);
        try {
            self::model($http)->withStructuredOutput(self::schema())->invoke('Describe Jane');
            self::fail('Expected the inferred method to validate.');
        } catch (OutputParserException) {
            self::assertSame('json_schema', $http->lastRequestBody()['response_format']['type']);
        }
        $failure = new FakeHttpClient([FakeHttpClient::json(500, ['error' => ['message' => 'provider failed', 'code' => 500]])]);
        $this->expectException(\LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterError::class);
        self::model($failure)->withStructuredOutput(self::schema(), ['includeRaw' => true])->invoke('Describe Jane');
    }
}
