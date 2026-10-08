<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Supervisor;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use PHPUnit\Framework\Assert;

use function LangChain\Tools\tool;

/** Shared tools and message helpers for the supervisor tests. */
final class SupervisorFixtures
{
    private function __construct()
    {
    }

    public static function add(): StructuredTool
    {
        return tool(
            static fn (array $in): int|float => $in['a'] + $in['b'],
            [
                'name' => 'add',
                'description' => 'Add two numbers.',
                'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b']),
            ],
        );
    }

    public static function webSearch(): StructuredTool
    {
        return tool(
            static fn (array $in): string => "Here are the headcounts for each of the FAANG companies in 2024:\n"
                . "1. **Facebook (Meta)**: 67,317 employees.\n"
                . "2. **Apple**: 164,000 employees.\n"
                . "3. **Amazon**: 1,551,000 employees.\n"
                . "4. **Netflix**: 14,000 employees.\n"
                . '5. **Google (Alphabet)**: 181,269 employees.',
            [
                'name' => 'web_search',
                'description' => 'Search the web for information.',
                'schema' => Schema::object(['query' => ['type' => 'string']], ['query']),
            ],
        );
    }

    /** @return array{name: string, args: array<string, mixed>, id: string, type: string} */
    public static function call(string $name, array $args, string $id): array
    {
        return ['name' => $name, 'args' => $args, 'id' => $id, 'type' => 'tool_call'];
    }

    public static function aiCalling(array ...$calls): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => $calls]);
    }

    /** `toEqual` between a scripted message and the one that reached the state: same kind, text and calls (ids are assigned en route). */
    public static function assertSameMessage(BaseMessage $expected, BaseMessage $actual): void
    {
        Assert::assertSame($expected->getType(), $actual->getType());
        Assert::assertSame($expected->content, $actual->content);
        if ($expected instanceof AIMessage) {
            Assert::assertInstanceOf(AIMessage::class, $actual);
            Assert::assertEquals($expected->toolCalls, $actual->toolCalls);
        }
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<BaseMessage>
     */
    public static function withoutSystem(array $messages): array
    {
        return array_values(array_filter($messages, static fn (BaseMessage $m): bool => $m->getType() !== 'system'));
    }
}
