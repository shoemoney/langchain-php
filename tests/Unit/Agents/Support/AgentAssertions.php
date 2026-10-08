<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

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
 * Shared helpers for the converted `createAgent` tests.
 */
final class AgentAssertions
{
    private function __construct()
    {
    }

    /** A scripted chat model that does not wait (upstream's default is a 50ms sleep). */
    public static function fakeChat(array $responses = [], array $extra = []): FakeToolCallingChatModel
    {
        return new FakeToolCallingChatModel(['sleep' => 0, 'responses' => $responses] + $extra);
    }

    /**
     * What `toEqual` compares on a message: type, content, name, id, tool calls and (for tool messages) the call id and status.
     *
     * @return array<string, mixed>
     */
    public static function shape(BaseMessage $message): array
    {
        $shape = ['type' => $message->type, 'content' => $message->content, 'name' => $message->name, 'id' => $message->id];
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
        }

        return $shape;
    }

    public static function assertSameMessage(BaseMessage $expected, mixed $actual, string $note = ''): void
    {
        Assert::assertInstanceOf(BaseMessage::class, $actual, $note);
        Assert::assertSame(self::shape($expected), self::shape($actual), $note);
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<string>
     */
    public static function texts(array $messages): array
    {
        return array_map(
            static fn (BaseMessage $m): string => \is_string($m->content) ? $m->content : (string) json_encode($m->content),
            $messages,
        );
    }

    /** @param class-string $class */
    public static function ofType(array $messages, string $class): array
    {
        return array_values(array_filter($messages, static fn (mixed $m): bool => $m instanceof $class));
    }

    /** The `SearchAPI` tool of the upstream test utils: answers `result for <query>`, throws on `error`. */
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
                'schema' => Schema::object(['query' => ['type' => 'string', 'description' => 'The query to search for.']]),
            ],
        );
    }

    /**
     * A tool taking no arguments.
     */
    public static function noArgTool(string $name, callable $fn, bool $returnDirect = false): StructuredTool
    {
        return tool(
            static fn (array $in): mixed => $fn($in),
            ['name' => $name, 'description' => $name, 'schema' => Schema::object([]), 'returnDirect' => $returnDirect],
        );
    }

    /**
     * A tool taking string arguments.
     *
     * @param list<string> $arguments
     */
    public static function stringTool(string $name, array $arguments, callable $fn, string $description = ''): StructuredTool
    {
        $properties = [];
        foreach ($arguments as $argument) {
            $properties[$argument] = ['type' => 'string'];
        }

        return tool(
            static fn (array $in): mixed => $fn($in),
            ['name' => $name, 'description' => $description !== '' ? $description : $name, 'schema' => Schema::object($properties, $arguments)],
        );
    }
}
