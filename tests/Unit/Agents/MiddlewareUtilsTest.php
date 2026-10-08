<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangGraph\Agents\Middleware\Types;
use LangGraph\Agents\Middleware\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/utils.test.ts` (`countTokensApproximately`), plus the other helpers of
 * `middleware/utils.ts` and the runtime parts of `middleware/types.ts`, which upstream tests only through the
 * middleware that use them.
 */
#[CoversClass(Utils::class)]
#[CoversClass(Types::class)]
final class MiddlewareUtilsTest extends TestCase
{
    private static function getWeather(): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Weather in ' . $in['location'],
            ['name' => 'get_weather', 'description' => 'Get the weather for a location.', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );
    }

    public function testShouldIncreaseTokenCountWhenALangChainToolIsProvided(): void
    {
        $messages = [new HumanMessage('Hello')];
        $baseCount = Utils::countTokensApproximately($messages);

        $countWithTool = Utils::countTokensApproximately($messages, [self::getWeather()]);

        self::assertGreaterThan($baseCount, $countWithTool);
    }

    public function testShouldIncreaseTokenCountWhenADictToolSchemaIsProvided(): void
    {
        $messages = [new HumanMessage('Hello')];
        $baseCount = Utils::countTokensApproximately($messages);

        $toolSchema = [
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get the weather for a location.',
                'parameters' => ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            ],
        ];

        self::assertGreaterThan($baseCount, Utils::countTokensApproximately($messages, [$toolSchema]));
    }

    public function testShouldIncreaseTokenCountWithMultipleTools(): void
    {
        $messages = [new HumanMessage('Hello')];
        $getTime = tool(
            static fn (array $in): string => 'Time in ' . $in['timezone'],
            ['name' => 'get_time', 'description' => 'Get the current time in a timezone.', 'schema' => Schema::object(['timezone' => ['type' => 'string']], ['timezone'])],
        );

        $countWithOneTool = Utils::countTokensApproximately($messages, [self::getWeather()]);
        $countWithMultiple = Utils::countTokensApproximately($messages, [self::getWeather(), $getTime]);

        self::assertGreaterThan($countWithOneTool, $countWithMultiple);
    }

    public function testShouldEqualBaseCountWhenToolsIsNull(): void
    {
        $messages = [new HumanMessage('Hello')];

        self::assertSame(Utils::countTokensApproximately($messages), Utils::countTokensApproximately($messages, null));
    }

    public function testShouldEqualBaseCountWhenToolsIsAnEmptyArray(): void
    {
        $messages = [new HumanMessage('Hello')];

        self::assertSame(Utils::countTokensApproximately($messages), Utils::countTokensApproximately($messages, []));
    }

    public function testCountsTextBlocksToolCallsAndToolCallIds(): void
    {
        // 4 characters per token, rounded up: "Hello" (5) is 2 tokens.
        self::assertSame(2, Utils::countTokensApproximately([new HumanMessage('Hello')]));
        self::assertSame(0, Utils::countTokensApproximately([]));

        $blocks = new HumanMessage([['type' => 'text', 'text' => 'abcd'], ['type' => 'image_url', 'image_url' => 'x'], ['type' => 'text', 'text' => 'efgh']]);
        self::assertSame(2, Utils::countTokensApproximately([$blocks]));

        $withCalls = new AIMessage(['content' => '', 'tool_calls' => [['id' => 'c1', 'name' => 'x', 'args' => []]]]);
        self::assertGreaterThan(0, Utils::countTokensApproximately([$withCalls]));

        // The tool_call_id of a tool message counts.
        self::assertSame(1, Utils::countTokensApproximately([new ToolMessage(['content' => '', 'tool_call_id' => 'abcd'])]));
    }

    public function testHookHelpersReadBothHookForms(): void
    {
        $fn = static fn (): string => 'ran';

        self::assertSame($fn, Utils::getHookFunction($fn));
        self::assertSame($fn, Utils::getHookFunction(['hook' => $fn, 'canJumpTo' => ['end']]));
        self::assertNull(Utils::getHookConstraint($fn));
        self::assertNull(Utils::getHookConstraint(null));
        self::assertSame(['end'], Utils::getHookConstraint(['hook' => $fn, 'canJumpTo' => ['end']]));
        self::assertNull(Utils::getHookConstraint(['hook' => $fn]));
    }

    public function testCalculateRetryDelayBacksOffAndCaps(): void
    {
        $config = ['backoffFactor' => 2, 'initialDelayMs' => 100, 'maxDelayMs' => 1000, 'jitter' => false];

        self::assertSame(100, Utils::calculateRetryDelay($config, 0));
        self::assertSame(200, Utils::calculateRetryDelay($config, 1));
        self::assertSame(400, Utils::calculateRetryDelay($config, 2));
        // Capped at maxDelayMs.
        self::assertSame(1000, Utils::calculateRetryDelay($config, 10));
        // A zero factor is a constant delay.
        self::assertSame(100, Utils::calculateRetryDelay([...$config, 'backoffFactor' => 0], 5));
        // The provider's hint is a floor.
        self::assertSame(750, Utils::calculateRetryDelay($config, 0, 750));
        self::assertSame(200, Utils::calculateRetryDelay($config, 1, 50));
    }

    public function testCalculateRetryDelayJitterStaysWithinAQuarter(): void
    {
        $config = ['backoffFactor' => 1, 'initialDelayMs' => 1000, 'maxDelayMs' => 5000, 'jitter' => true];

        for ($i = 0; $i < 50; $i++) {
            $delay = Utils::calculateRetryDelay($config, 0);
            self::assertGreaterThanOrEqual(750, $delay);
            self::assertLessThanOrEqual(1250, $delay);
        }
    }

    public function testGetRetryAfterMsReadsAPropertyOrMethod(): void
    {
        self::assertNull(Utils::getRetryAfterMs('error'));
        self::assertNull(Utils::getRetryAfterMs(new \Exception('x')));
        self::assertSame(1500, Utils::getRetryAfterMs((object) ['retryAfterMs' => 1500]));
        self::assertNull(Utils::getRetryAfterMs((object) ['retryAfterMs' => -1]));
        self::assertNull(Utils::getRetryAfterMs((object) ['retryAfterMs' => '10']));

        $withMethod = new class () extends \Exception {
            public function getRetryAfterMs(): int
            {
                return 20;
            }
        };
        self::assertSame(20, Utils::getRetryAfterMs($withMethod));
    }

    public function testSleepWaitsAtLeastTheRequestedTime(): void
    {
        $start = microtime(true);
        Utils::sleep(20);

        self::assertGreaterThanOrEqual(0.018, microtime(true) - $start);
    }

    public function testTypesRecognisesMiddleware(): void
    {
        self::assertTrue(Types::isMiddleware(['name' => 'a']));
        self::assertTrue(Types::isMiddleware((object) ['name' => 'a']));
        self::assertFalse(Types::isMiddleware(['nope' => true]));
        self::assertFalse(Types::isMiddleware('a'));
        self::assertSame(['model', 'tools', 'end'], Types::JUMP_TO_TARGETS);
    }

    public function testParseContextAppliesDefaultsDropsUnknownKeysAndRequiresRequiredOnes(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'number', 'default' => 42]],
            'required' => ['a'],
        ];

        self::assertSame(['a' => 'x', 'b' => 42], Utils::parseContext($schema, ['a' => 'x', 'other' => 1]));
        self::assertSame([], Utils::parseContext(null, ['a' => 1]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid context for middleware "m": a: Required');

        Utils::parseContext($schema, null, 'm');
    }

    public function testConcatSystemMessageFollowsTheUpstreamMerge(): void
    {
        $base = new \LangChain\Messages\SystemMessage(['content' => 'Base', 'id' => 'sys-1', 'additional_kwargs' => ['a' => 1]]);

        $withString = Utils::concatSystemMessage($base, ' more');
        self::assertSame('Base more', $withString->text());
        self::assertSame('sys-1', $withString->id);
        self::assertSame(['a' => 1], $withString->additional_kwargs);

        $other = new \LangChain\Messages\SystemMessage(['content' => ' other', 'name' => 'o', 'additional_kwargs' => ['b' => 2]]);
        $withMessage = Utils::concatSystemMessage($base, $other);
        self::assertSame('Base other', $withMessage->text());
        self::assertSame('sys-1', $withMessage->id);
        self::assertSame('o', $withMessage->name);
        self::assertSame(['a' => 1, 'b' => 2], $withMessage->additional_kwargs);

        // The receiver is untouched.
        self::assertSame('Base', $base->text());
        self::assertSame('string', Utils::typeOf('x'));
        self::assertSame('undefined', Utils::typeOf(null));
        self::assertSame('number', Utils::typeOf(1.5));
        self::assertSame('object', Utils::typeOf([]));
        self::assertSame('function', Utils::typeOf(static fn () => 1));
        self::assertSame('boolean', Utils::typeOf(false));
    }
}
