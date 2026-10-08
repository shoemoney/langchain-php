<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use PHPUnit\Framework\Assert;

use function LangChain\Tools\tool;

/**
 * Shared fixtures for the `createReactAgent` tests: the `SearchAPI` tools and the scripted model of
 * `langgraph-core/src/tests/prebuilt.test.ts`, plus the `_AnyId*Message` comparison.
 */
final class ReactAgentFixtures
{
    private function __construct()
    {
    }

    /** @return list<string> */
    public static function versionNames(): array
    {
        return ['v1', 'v2'];
    }

    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ['v1' => ['v1'], 'v2' => ['v2']];
    }

    /** `SearchAPI`: answers `result for <query>`, throws on `error`. */
    public static function searchApi(): StructuredTool
    {
        return tool(
            static function (array $in): string {
                if (($in['query'] ?? null) === 'error') {
                    throw new \Exception('Error');
                }

                return 'result for ' . ($in['query'] ?? '');
            },
            [
                'name' => 'search_api',
                'description' => 'A simple API that returns the input string.',
                'schema' => Schema::object(['query' => ['type' => 'string']]),
            ],
        );
    }

    /** `SearchAPIWithArtifact`: a `content_and_artifact` tool. */
    public static function searchApiWithArtifact(): StructuredTool
    {
        return tool(
            static fn (array $in): array => ['some response format', '123'],
            [
                'name' => 'search_api',
                'description' => 'A simple API that returns the input string.',
                'schema' => Schema::object(['query' => ['type' => 'string']]),
                'responseFormat' => 'content_and_artifact',
            ],
        );
    }

    /** A tool named `$name` that returns `"$label: <someVal>"`. */
    public static function numberTool(string $name, string $label, string $description = ''): StructuredTool
    {
        return tool(
            static fn (array $in): string => $label . ': ' . $in['someVal'],
            [
                'name' => $name,
                'description' => $description !== '' ? $description : $name . ' docstring.',
                'schema' => Schema::object(['someVal' => ['type' => 'number', 'description' => 'Input value']], ['someVal']),
            ],
        );
    }

    /** @param array<string, mixed> $args */
    public static function toolCall(string $name, string $id, array $args = []): array
    {
        return ['name' => $name, 'id' => $id, 'args' => $args];
    }

    /** A message that asks for one `search_api` call, followed by a final answer. */
    public static function searchThenAnswer(string $first = 'result1', string $second = 'result2', string $query = 'foo'): array
    {
        return [
            new AIMessage(['content' => $first, 'tool_calls' => [self::toolCall('search_api', 'tool_abcd123', ['query' => $query])]]),
            new AIMessage($second),
        ];
    }

    /**
     * A scripted model with no waiting, unless `sleep` says otherwise.
     *
     * @param list<BaseMessage>    $responses
     * @param array<string, mixed> $extra
     */
    public static function fake(array $responses = [], array $extra = []): FakeToolCallingChatModel
    {
        return new FakeToolCallingChatModel(['sleep' => 0, 'responses' => $responses] + $extra);
    }

    /** @param array<string, mixed> $extra */
    public static function spy(array $responses = [], array $extra = []): SpyingToolCallingChatModel
    {
        return new SpyingToolCallingChatModel(['sleep' => 0, 'responses' => $responses] + $extra);
    }

    /**
     * What `_AnyIdHumanMessage` / `_AnyIdAIMessage` / `_AnyIdToolMessage` compare: everything but the id,
     * which only has to exist.
     *
     * @param list<BaseMessage> $expected
     * @param list<BaseMessage> $actual
     */
    public static function assertMessages(array $expected, array $actual, string $message = ''): void
    {
        Assert::assertSame(
            array_map(self::shape(...), $expected),
            array_map(self::shape(...), $actual),
            $message,
        );
        foreach ($actual as $m) {
            Assert::assertNotEmpty($m->id, 'every message in state has an id');
        }
    }

    /** @return array<string, mixed> */
    public static function shape(BaseMessage $message): array
    {
        $shape = [
            'type' => $message->type,
            'content' => $message->content,
            'name' => $message->name,
        ];
        if ($message instanceof AIMessage || $message instanceof AIMessageChunk) {
            $calls = $message instanceof AIMessageChunk ? $message->toMessage()->toolCalls : $message->toolCalls;
            $shape['tool_calls'] = array_map(
                static fn (array $c): array => ['name' => $c['name'], 'id' => $c['id'] ?? null, 'args' => $c['args']],
                $calls,
            );
        }
        if ($message instanceof ToolMessage) {
            $shape['tool_call_id'] = $message->toolCallId;
            $shape['status'] = $message->additional_kwargs['status'] ?? null;
            $shape['artifact'] = $message->artifact;
        }

        return $shape;
    }

    /**
     * The message texts of a result, in order.
     *
     * @param list<BaseMessage> $messages
     * @return list<string>
     */
    public static function texts(array $messages): array
    {
        return array_map(
            static fn (BaseMessage $m): string => is_string($m->content) ? $m->content : (string) json_encode($m->content),
            $messages,
        );
    }
}
