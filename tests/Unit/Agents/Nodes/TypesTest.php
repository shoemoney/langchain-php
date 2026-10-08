<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangChain\Messages\SystemMessage;
use LangGraph\Agents\Nodes\Types;
use LangGraph\Agents\Runtime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `nodes/types.ts` is one TypeScript interface (`ModelRequest`) and has no upstream test; these pin the
 * request shape this port gives it.
 */
#[CoversClass(Types::class)]
final class TypesTest extends TestCase
{
    public function testModelRequestFillsTheFieldsUpstreamMarksRequired(): void
    {
        $request = Types::modelRequest(['model' => 'm']);

        self::assertSame('m', $request['model']);
        self::assertSame([], $request['messages']);
        self::assertSame('', $request['systemPrompt']);
        self::assertSame([], $request['tools']);
        self::assertSame([], $request['state']);
        self::assertInstanceOf(Runtime::class, $request['runtime']);
    }

    public function testModelRequestRejectsUnknownFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ModelRequest field(s): nope');

        Types::modelRequest(['nope' => 1]);
    }

    public function testOverrideIsASpreadThatLeavesTheOriginalAlone(): void
    {
        $request = Types::modelRequest(['systemMessage' => new SystemMessage('a'), 'toolChoice' => Types::TOOL_CHOICE_AUTO]);

        $changed = Types::overrideModelRequest($request, ['toolChoice' => Types::TOOL_CHOICE_REQUIRED, 'modelSettings' => ['headers' => ['x' => 'y']]]);

        self::assertSame('required', $changed['toolChoice']);
        self::assertSame(['headers' => ['x' => 'y']], $changed['modelSettings']);
        self::assertSame($request['systemMessage'], $changed['systemMessage']);
        self::assertSame('auto', $request['toolChoice']);
    }
}
