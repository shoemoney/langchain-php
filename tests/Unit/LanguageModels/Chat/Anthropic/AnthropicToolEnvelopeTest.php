<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A tool that reaches Anthropic with no `input_schema` is still sent, still well-formed, and the model
 * is simply never told what arguments it takes — so it either invents arguments or emits nothing usable.
 *
 * `convertTool()` short-circuited on `isset($tool['input_schema']) || isset($tool['name'])`, and the `||`
 * meant the FLATTENED OpenAI shape `['name' => …, 'parameters' => …]` returned unchanged. The
 * normalisation immediately below it — which unwraps the `function` envelope, guards an empty name and
 * lifts `parameters` into `input_schema` — was never reached for that shape.
 *
 * These assertions read the value that will be serialised, because that is the only place the defect is
 * observable: the array still has a `name`, so a test that only checked "the tool was returned" passes
 * while the wire payload is wrong.
 */
#[CoversClass(MessageInputs::class)]
final class AnthropicToolEnvelopeTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function shapesMissingInputSchema(): iterable
    {
        yield 'flattened openai, name + parameters' => [
            ['name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
        ];
        yield 'name + schema' => [
            ['name' => 'lookup', 'schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]],
        ];
        yield 'name + description only (defaults to empty object schema)' => [
            ['name' => 'lookup', 'description' => 'looks someone up'],
        ];
    }

    /**
     * @param array<string, mixed> $tool
     */
    #[DataProvider('shapesMissingInputSchema')]
    public function testAToolAlwaysReachesAnthropicWithAnInputSchema(array $tool): void
    {
        $out = MessageInputs::convertTool($tool);

        self::assertArrayHasKey(
            'input_schema',
            $out,
            'Anthropic requires input_schema; without it the model is never told the tool arguments'
        );
        self::assertSame('lookup', $out['name']);
        self::assertArrayNotHasKey(
            'parameters',
            $out,
            'the OpenAI `parameters` key must be lifted into `input_schema`, not sent alongside it'
        );
    }

    /** The parameters must actually arrive, not just the key. */
    public function testTheParametersAreLiftedNotDiscarded(): void
    {
        $schema = ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]];

        $out = MessageInputs::convertTool(['name' => 'lookup', 'parameters' => $schema]);

        self::assertSame($schema, $out['input_schema']);
    }

    /** Control: a genuinely provider-shaped tool must pass through untouched. */
    public function testAProviderShapedToolPassesThroughUnchanged(): void
    {
        $tool = ['name' => 'lookup', 'description' => 'd', 'input_schema' => ['type' => 'object']];

        self::assertSame($tool, MessageInputs::convertTool($tool));
    }

    /** Control: the nested `function` envelope already worked before this fix — pin it. */
    public function testTheNestedFunctionEnvelopeStillWorks(): void
    {
        $out = MessageInputs::convertTool([
            'type' => 'function',
            'function' => [
                'name' => 'lookup',
                'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            ],
        ]);

        self::assertArrayHasKey('input_schema', $out);
        self::assertSame('lookup', $out['name']);
    }
}
