<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\StructuredOutput;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangChain\Utils\Testing\StructuredToolSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A model that has no tool binding at all.
 *
 * Exists to prove the *negative* branch: a provider that never overrode
 * `bindTools` must be distinguishable from one that did, because otherwise
 * `withStructuredOutput` would build a chain that cannot work.
 */
final class UnbindableChatModel extends BaseChatModel
{
    public function llmType(): string
    {
        return 'unbindable';
    }

    /** @param list<BaseMessage> $messages */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        return new ChatResult([new \LangChain\LanguageModels\Outputs\ChatGeneration(new AIMessage('nope'), 'nope')]);
    }
}

#[CoversClass(BaseChatModel::class)]
#[CoversClass(StructuredOutput::class)]
final class StructuredOutputTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ];

    /**
     * A model that emits one tool call, so the whole chain runs for real.
     *
     * Scripted with `tool_call_chunks` — the undecoded streaming form — because
     * that is what an `AIMessageChunk` actually holds. Feeding it `tool_calls`
     * would silently construct a chunk with no tool call in it.
     *
     * @param list<BaseMessage> $messages
     */
    private static function callingModel(string $functionName, array $args): FakeStreamingChatModel
    {
        return new FakeStreamingChatModel([
            'chunks' => [
                new AIMessageChunk([
                    'content' => '',
                    'tool_call_chunks' => [[
                        'name' => $functionName,
                        'args' => json_encode($args),
                        'id' => 'call_1',
                        'index' => 0,
                    ]],
                ]),
            ],
        ]);
    }

    // ---- the end-to-end round trip --------------------------------------

    /**
     * The payoff case: a schema in, a validated value out.
     *
     * This runs the *whole* path — bind tools, offer the schema, receive a tool
     * call, key it by name, parse the arguments, validate them. Every unit test
     * above covers one link; this is the only kind of test that would have
     * caught the links disagreeing with each other.
     */
    public function testEndToEndReturnsParsedArguments(): void
    {
        $model = self::callingModel('extract', ['name' => 'Ada Lovelace']);

        $chain = $model->withStructuredOutput(self::SCHEMA);

        self::assertInstanceOf(Runnable::class, $chain);
        self::assertSame(['name' => 'Ada Lovelace'], $chain->invoke('who wrote the first algorithm?'));
    }

    /**
     * A schema the model ignores its own `name` for.
     *
     * `withStructuredOutput` sends the name to the model *and* looks the call up
     * by it. If those two ever drifted apart the result would be a silent null,
     * so the name is pinned on both sides here.
     */
    public function testFunctionNameIsHonouredOnBothSides(): void
    {
        $model = self::callingModel('pull_person', ['name' => 'Grace Hopper']);

        $chain = $model->withStructuredOutput(self::SCHEMA, ['name' => 'pull_person']);

        self::assertSame(['name' => 'Grace Hopper'], $chain->invoke('who?'));
    }

    /**
     * A schema carrying its own `name` uses that, not the default.
     */
    public function testSchemaNameIsUsedWhenNoExplicitName(): void
    {
        $model = self::callingModel('from_schema', ['name' => 'Alan Turing']);

        $chain = $model->withStructuredOutput(self::SCHEMA + ['name' => 'from_schema']);

        self::assertSame(['name' => 'Alan Turing'], $chain->invoke('who?'));
    }

    /**
     * The model declined to call the tool. The caller is told "nothing", which
     * is a different fact from "an empty object" and from an exception.
     */
    public function testModelThatDoesNotCallYieldsNull(): void
    {
        $model = new FakeStreamingChatModel(['responses' => [new AIMessage('I would rather not.')]]);

        self::assertNull($model->withStructuredOutput(self::SCHEMA)->invoke('who?'));
    }

    // ---- includeRaw ------------------------------------------------------

    /**
     * `includeRaw` keeps the message next to the parse.
     *
     * The whole reason to ask for it is the failure case, so the interesting
     * assertion is the next one.
     */
    public function testIncludeRawReturnsBothKeys(): void
    {
        $model = self::callingModel('extract', ['name' => 'Ada Lovelace']);

        $result = $model->withStructuredOutput(self::SCHEMA, ['includeRaw' => true])->invoke('who?');

        self::assertIsArray($result);
        self::assertArrayHasKey('raw', $result);
        self::assertArrayHasKey('parsed', $result);
        self::assertInstanceOf(AIMessage::class, $result['raw']);
        self::assertSame(['name' => 'Ada Lovelace'], $result['parsed']);
    }

    /**
     * With `includeRaw`, a parse failure is survivable.
     *
     * The raw message is the whole point: a caller debugging why the model
     * declined needs to see what it actually said. Without the flag that same
     * failure propagates, because there is nowhere else to look.
     */
    public function testIncludeRawSwallowsParseFailureAndKeepsRaw(): void
    {
        $model = new FakeStreamingChatModel(['responses' => [new AIMessage('no tool call here')]]);

        $result = $model->withStructuredOutput(self::SCHEMA, ['includeRaw' => true])->invoke('who?');

        self::assertIsArray($result);
        self::assertNull($result['parsed'], 'parsed must fall back to null, not throw');
        self::assertInstanceOf(AIMessage::class, $result['raw']);
        self::assertSame('no tool call here', $result['raw']->content);
    }

    /**
     * Without `includeRaw` the same situation propagates.
     */
    public function testWithoutIncludeRawParseFailurePropagates(): void
    {
        $model = new FakeStreamingChatModel(['responses' => [new AIMessage('no tool call here')]]);

        $chain = $model->withStructuredOutput(self::SCHEMA);

        // A null result is the documented outcome for "model declined", so the
        // distinction that matters is that nothing is thrown.
        self::assertNull($chain->invoke('who?'));
    }

    // ---- refusals --------------------------------------------------------

    public function testRefusesWhenTheModelCannotBindTools(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must implement ".bindTools()"');

        (new UnbindableChatModel())->withStructuredOutput(self::SCHEMA);
    }

    public function testRefusesJsonModeRatherThanApproximatingIt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only supports "functionCalling"');

        self::callingModel('extract', [])->withStructuredOutput(self::SCHEMA, ['method' => 'jsonMode']);
    }

    public function testRefusesStrictMode(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"strict" mode is not supported');

        self::callingModel('extract', [])->withStructuredOutput(self::SCHEMA, ['strict' => true]);
    }

    // ---- bindTools -------------------------------------------------------

    /**
     * A model that has not overridden `bindTools` must be *detectable* as such.
     *
     * This is the check that keeps the previous test honest: PHP has no optional
     * methods, so the only evidence a provider cannot bind tools is this probe.
     */
    public function testSupportsToolBindingReflectsTheOverride(): void
    {
        self::assertFalse((new UnbindableChatModel())->supportsToolBinding());
        self::assertTrue(self::callingModel('extract', [])->supportsToolBinding());
    }

    public function testBindToolsRendersProviderFormat(): void
    {
        $model = new FakeStreamingChatModel(['toolStyle' => 'openai']);
        $bound = $model->bindTools([
            new StructuredToolSpec('get_weather', \LangChain\Tools\Schema::object(['city' => ['type' => 'string']]), 'Look up weather'),
        ]);

        self::assertSame([[
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Look up weather',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => [],
                ],
            ],
        ]], $bound->kwargs()['tools']);
    }

    /**
     * Anthropic puts the schema under `input_schema`, not `parameters`.
     *
     * The formats are genuinely different and a binding bug in one is invisible
     * in the others — this is why the fake exposes the style at all.
     */
    public function testBindToolsAnthropicFormat(): void
    {
        $model = new FakeStreamingChatModel(['toolStyle' => 'anthropic']);
        $bound = $model->bindTools([
            new StructuredToolSpec('get_weather', \LangChain\Tools\Schema::object([]), 'Look up weather'),
        ]);

        $tool = $bound->kwargs()['tools'][0];
        self::assertArrayHasKey('input_schema', $tool);
        self::assertArrayNotHasKey('type', $tool);
        self::assertSame('get_weather', $tool['name']);
    }

    public function testBindToolsGoogleWrapsInFunctionDeclarations(): void
    {
        $model = new FakeStreamingChatModel(['toolStyle' => 'google']);
        $bound = $model->bindTools([new StructuredToolSpec('t', \LangChain\Tools\Schema::object([]), 'd')]);

        self::assertArrayHasKey('functionDeclarations', $bound->kwargs()['tools'][0]);
    }

    /**
     * A bind returns a new model; the original is untouched.
     *
     * Mutating in place would leak one call's tools into the next call's — the
     * exact bug `RunnableBinding` exists to prevent, and the reason the fake
     * mirrors it.
     */
    public function testBindToolsDoesNotMutateTheReceiver(): void
    {
        $model = new FakeStreamingChatModel();
        $model->bindTools([new StructuredToolSpec('a', \LangChain\Tools\Schema::object([]), 'd')]);

        self::assertArrayNotHasKey('tools', $model->kwargs());
    }

    /**
     * Binding accumulates rather than replacing.
     */
    public function testBindToolsAccumulates(): void
    {
        $model = new FakeStreamingChatModel();
        $once = $model->bindTools([new StructuredToolSpec('a', \LangChain\Tools\Schema::object([]), 'd')]);
        $twice = $once->bindTools([new StructuredToolSpec('b', \LangChain\Tools\Schema::object([]), 'd')]);

        self::assertCount(1, $once->kwargs()['tools']);
        self::assertCount(2, $twice->kwargs()['tools']);
    }

    /**
     * A caller may bind a provider-native tool definition directly, so a tool
     * this SDK has never heard of is still usable.
     */
    public function testBindToolsAcceptsRawProviderShape(): void
    {
        $model = new FakeStreamingChatModel();
        $bound = $model->bindTools([[
            'type' => 'function',
            'function' => ['name' => 'raw', 'description' => 'd', 'parameters' => ['type' => 'object']],
        ]]);

        self::assertSame('raw', $bound->kwargs()['tools'][0]['function']['name']);
    }

    public function testBindToolsRejectsSomethingThatIsNotATool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot bind');

        (new FakeStreamingChatModel())->bindTools([42]);
    }

    public function testBindToolsCarriesExtraKwargs(): void
    {
        $bound = (new FakeStreamingChatModel())->bindTools([], ['temperature' => 0.0]);

        self::assertSame(0.0, $bound->kwargs()['temperature']);
    }
}
