<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Utils\Js;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Encoding a request the port cannot represent must fail locally.
 *
 * Two silent-corruption paths, both now closed:
 *
 *  - `JSON_PARTIAL_OUTPUT_ON_ERROR` substituted `null` for a circular tool
 *    argument and sent the call anyway, so the tool received arguments the
 *    caller never passed. Upstream's `JSON.stringify` *throws* on a circular
 *    structure (`json_output_tools_parsers.ts:100`) — so this was a fidelity
 *    divergence, not merely a preference.
 *  - `(string) json_encode($params)` turned a `false` return into `''`, so an
 *    unrepresentable request went out as an empty body and came back as an
 *    opaque 400. The provider could not distinguish "your request was empty"
 *    from "my client could not encode what you gave me", which made the bug
 *    invisible from the server side.
 */
#[CoversClass(Completions::class)]
final class RequestEncodingTest extends TestCase
{
    public function testACircularToolArgumentIsRefusedNotSilentlyNulled(): void
    {
        $args = ['ok' => 'fine'];
        $args['self'] = &$args; // circular, as a tool argument easily could be

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be encoded as JSON');

        Completions::toolCallToWire(['id' => 'c1', 'name' => 'f', 'args' => $args]);
    }

    /**
     * The three argument shapes still encode correctly.
     *
     * Throwing on everything would "fix" the corruption by refusing all tool
     * calls, so the success cases are pinned alongside the failure one.
     */
    public function testTheThreeArgumentShapesStillEncodeCorrectly(): void
    {
        self::assertSame(
            '{}',
            Completions::toolCallToWire(['id' => 'c', 'name' => 'p', 'args' => []])['function']['arguments'],
            'an empty MAP is {} — not [], which would read as a positional list',
        );
        self::assertSame(
            '{"city":"A"}',
            Completions::toolCallToWire(['id' => 'c', 'name' => 'w', 'args' => ['city' => 'A']])['function']['arguments'],
        );
        self::assertSame(
            '[1,2]',
            Completions::toolCallToWire(['id' => 'c', 'name' => 'p', 'args' => [1, 2]])['function']['arguments'],
            'a genuine empty LIST stays a list',
        );
    }

    /**
     * An assistant turn that cannot be encoded fails before the transport.
     *
     * This is the real send path: replaying tool calls is where the port
     * re-serialises arguments it did not just receive.
     */
    public function testAnUnencodableTurnFailsBeforeTheTransport(): void
    {
        $transport = new AlwaysRefuses();
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $transport, 'maxRetries' => 0]);

        $circular = new \stdClass();
        $circular->self = $circular;

        $assistant = new AIMessage([
            'content' => '',
            'tool_calls' => [['name' => 'f', 'args' => $circular, 'id' => 't1', 'type' => 'tool_call']],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            $model->invoke([
                new HumanMessage('go'),
                $assistant,
                new ToolMessage(['content' => 'ok', 'tool_call_id' => 't1']),
            ]);
        } finally {
            self::assertSame(0, $transport->calls, 'nothing should have been sent');
        }
    }

    /**
     * The shared helper is the JS-shaped sibling of `isList()`, which already
     * lived in the same place for the same reason.
     */
    public function testTheSharedHelperIsJavaScriptShaped(): void
    {
        self::assertTrue(Js::isList([1, 2]));
        self::assertFalse(Js::isList(['a' => 1]));
        self::assertSame('{"a":1}', Js::encode(['a' => 1]));

        $circular = new \stdClass();
        $circular->self = $circular;

        $this->expectException(\InvalidArgumentException::class);
        Js::encode($circular);
    }
}
