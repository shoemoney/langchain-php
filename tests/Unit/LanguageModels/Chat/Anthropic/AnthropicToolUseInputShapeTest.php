<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\Messages\AIMessage;
use LangChain\Utils\Js;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A no-argument Anthropic tool call must put `{}` on the wire, not `[]`.
 *
 * `'input' => $call['args'] ?? []` left an empty PHP array, and PHP encodes an empty array as `[]` — a
 * JSON ARRAY where Anthropic's schema requires an object. Measured on the real code path:
 *
 *     convertMessage(new AIMessage(['tool_calls' => [['name'=>'lookup','args'=>[],'id'=>'c1','type'=>'tool_call']]]))
 *     -> {"role":"assistant","content":[{"type":"tool_use","id":"c1","name":"lookup","input":[]}]}
 *
 * This is the same "empty object encodes as [] instead of {}" defect the project's hard rules name as
 * having broken a real release, where it is recorded as guarded — the guard covers the OpenAI path,
 * whose `Completions::toolCallToWire()` already applies
 * `is_array($args) && $args !== [] && Js::isList($args) ? $args : (object) $args`.
 *
 * THE FIXTURE IS THE WHOLE LESSON HERE. An earlier attempt at 338 built the message from a RAW content
 * block (`[['type'=>'tool_use', …,'input'=>[]]]`) instead of `tool_calls`. `convertMessage()` gates its
 * tool_use emission on `$message->toolCalls !== []`, so that fixture never reached the line under test:
 * the block in the output was the fixture's own array passed through verbatim, and the test failed
 * identically against FIXED and UNFIXED code. The shape below is the one `ChatAnthropicTest.php:203`
 * and five other files use.
 *
 * These assertions read the ENCODED STRING. The defect does not exist in the array — an empty array is
 * exactly what the caller passed. It is created by the encoder.
 */
#[CoversClass(MessageInputs::class)]
final class AnthropicToolUseInputShapeTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function argShapes(): iterable
    {
        yield 'empty args (the defect)' => [[]];
        yield 'a map of args' => [['q' => 'shoes']];
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('argShapes')]
    public function testToolUseInputEncodesAsAJsonObject(array $args): void
    {
        $message = new AIMessage([
            'tool_calls' => [[
                'name' => 'lookup',
                'args' => $args,
                'id' => 'call_1',
                'type' => 'tool_call',
            ]],
        ]);

        // Guard the fixture itself: if toolCalls is empty the code path under test never runs, and this
        // test would silently assert nothing. That is precisely how 338's version passed a broken line.
        self::assertNotEmpty($message->toolCalls, 'fixture must populate toolCalls or nothing is exercised');

        $json = Js::encode(MessageInputs::convertMessage($message));

        self::assertStringContainsString(
            '"input":{',
            $json,
            'Anthropic requires `input` to be an object; the encoded body was ' . $json
        );
        self::assertStringNotContainsString('"input":[]', $json, '`[]` is a JSON array, not an object');
    }

    /** The args must survive the cast — an emptied `(object)` would satisfy the assertion above. */
    public function testTheArgumentsStillArriveInsideTheObject(): void
    {
        $message = new AIMessage([
            'tool_calls' => [[
                'name' => 'lookup',
                'args' => ['q' => 'shoes'],
                'id' => 'call_1',
                'type' => 'tool_call',
            ]],
        ]);

        $json = Js::encode(MessageInputs::convertMessage($message));

        self::assertStringContainsString('"q":"shoes"', $json);
    }

    /** Control: the OpenAI path already casts, and that is the precedent being mirrored. */
    public function testOpenAiAlreadyCastsAnEmptyArgMapToAnObject(): void
    {
        $fromOpenAi = \LangChain\LanguageModels\Chat\OpenAI\Utils\Completions::toolCallToWire([
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'lookup', 'arguments' => '{}'],
        ]);

        self::assertStringContainsString('"arguments":"{}"', Js::encode($fromOpenAi));
    }
}
