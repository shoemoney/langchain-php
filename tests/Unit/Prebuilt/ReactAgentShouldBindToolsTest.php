<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports the `_shouldBindTools` describe of `prebuilt.test.ts` (the style matrix and "bindTool with server tools"),
 * and "should bind model with bindTools" for `_bindTools`.
 *
 * A model that has been bound with `bindTools()` is, in this port, the model itself carrying `kwargs()['tools']`
 * (upstream: a `RunnableBinding` with `config.tools`); a `RunnableBinding` that carries tools in its config or kwargs
 * is understood too, and is exercised below.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentShouldBindToolsTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function toolStyles(): array
    {
        return ['openai' => ['openai'], 'anthropic' => ['anthropic'], 'google' => ['google'], 'bedrock' => ['bedrock']];
    }

    private static function identity(): RunnableLambda
    {
        return RunnableLambda::from(static fn (mixed $message): mixed => $message);
    }

    /** @param callable(): mixed $call */
    private function assertRejects(callable $call): void
    {
        try {
            $call();
        } catch (\Exception) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('expected the tool check to throw');
    }

    #[DataProvider('toolStyles')]
    public function testShouldDetermineWhenToBindTools(string $toolStyle): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1', 'Tool 1 docstring.');
        $tool2 = ReactAgentFixtures::numberTool('tool2', 'Tool 2', 'Tool 2 docstring.');
        $model = ReactAgentFixtures::fake([new AIMessage('test')], ['toolStyle' => $toolStyle]);

        // Should bind when a regular model.
        self::assertTrue(ReactAgent::shouldBindTools($model, []));
        self::assertTrue(ReactAgent::shouldBindTools($model, [$tool1]));

        // Should bind when a seq.
        $seq = RunnableSequence::from([$model, self::identity()]);
        self::assertTrue(ReactAgent::shouldBindTools($seq, []));
        self::assertTrue(ReactAgent::shouldBindTools($seq, [$tool1]));

        // Should not bind when a model with tools.
        self::assertFalse(ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool1]));

        // Should not bind when a seq with tools.
        $seqWithTools = RunnableSequence::from([$model->bindTools([$tool1]), self::identity()]);
        self::assertFalse(ReactAgent::shouldBindTools($seqWithTools, [$tool1]));

        // Should raise on invalid inputs.
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$tool1]), []));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool2]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool1, $tool2]));

        // A configurable model.
        $configurableModel = new FakeConfigurableModel(['model' => $model]);

        self::assertTrue(ReactAgent::shouldBindTools($configurableModel, []));
        self::assertTrue(ReactAgent::shouldBindTools($configurableModel, [$tool1]));

        $configurableSeq = RunnableSequence::from([$configurableModel, self::identity()]);
        self::assertTrue(ReactAgent::shouldBindTools($configurableSeq, []));
        self::assertTrue(ReactAgent::shouldBindTools($configurableSeq, [$tool1]));

        self::assertFalse(ReactAgent::shouldBindTools($configurableModel->bindTools([$tool1]), [$tool1]));

        $configurableSeqWithTools = RunnableSequence::from([$configurableModel->bindTools([$tool1]), self::identity()]);
        self::assertFalse(ReactAgent::shouldBindTools($configurableSeqWithTools, [$tool1]));

        $this->assertRejects(fn () => ReactAgent::shouldBindTools($configurableModel->bindTools([$tool1]), []));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($configurableModel->bindTools([$tool1]), [$tool2]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($configurableModel->bindTools([$tool1]), [$tool1, $tool2]));
    }

    public function testTheErrorsNameWhatIsWrong(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $tool2 = ReactAgentFixtures::numberTool('tool2', 'Tool 2');
        $model = ReactAgentFixtures::fake();

        try {
            ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool1, $tool2]);
            self::fail('count mismatch');
        } catch (\Exception $e) {
            self::assertSame('Number of tools in the model.bindTools() and tools passed to createReactAgent must match', $e->getMessage());
        }

        try {
            ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool2]);
            self::fail('name mismatch');
        } catch (\Exception $e) {
            self::assertSame(
                "Missing tools 'tool2' in the model.bindTools().Tools in the model.bindTools() must match the tools passed to createReactAgent.",
                $e->getMessage(),
            );
        }
    }

    public function testShouldHandleBindToolWithServerTools(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $server = ['type' => 'web_search_preview'];
        $model = ReactAgentFixtures::fake([new AIMessage('test')]);

        self::assertTrue(ReactAgent::shouldBindTools($model, [$tool1, $server]));
        self::assertFalse(ReactAgent::shouldBindTools($model->bindTools([$tool1, $server]), [$tool1, $server]));

        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$tool1]), [$tool1, $server]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$server]), [$tool1, $server]));
    }

    public function testABindingCarryingToolsInItsKwargsOrConfigCountsAsBound(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $tool2 = ReactAgentFixtures::numberTool('tool2', 'Tool 2');
        $model = ReactAgentFixtures::fake();
        $spec = [['type' => 'function', 'function' => ['name' => 'tool1']]];

        $viaKwargs = new RunnableBinding($model, ['tools' => $spec]);
        $viaConfig = new RunnableBinding($model, [], ['tools' => $spec]);

        self::assertFalse(ReactAgent::shouldBindTools($viaKwargs, [$tool1]));
        self::assertFalse(ReactAgent::shouldBindTools($viaConfig, [$tool1]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($viaKwargs, [$tool2]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($viaConfig, [$tool1, $tool2]));

        // A binding with no tools at all still wants them.
        self::assertTrue(ReactAgent::shouldBindTools($model->withConfig(['tags' => ['x']]), [$tool1]));
        // A binding around a model that is already bound does not (bindings nest in this port).
        self::assertFalse(ReactAgent::shouldBindTools($model->bindTools([$tool1])->withConfig(['tags' => ['x']]), [$tool1]));
    }

    public function testGoogleStyleToolsAreReadThroughFunctionDeclarations(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $tool2 = ReactAgentFixtures::numberTool('tool2', 'Tool 2');
        $model = ReactAgentFixtures::fake([], ['toolStyle' => 'google']);

        self::assertSame([['functionDeclarations' => [['name' => 'tool1'], ['name' => 'tool2']]]], $model->bindTools([$tool1, $tool2])->kwargs()['tools']);
        self::assertFalse(ReactAgent::shouldBindTools($model->bindTools([$tool1, $tool2]), [$tool1, $tool2]));
        $this->assertRejects(fn () => ReactAgent::shouldBindTools($model->bindTools([$tool1, $tool2]), [$tool1]));
    }

    // ---- _bindTools ------------------------------------------------------

    /**
     * Strip a runnable to the parts that decide its behaviour, so two differently-built bindings can be compared
     * (upstream compares `JSON.parse(JSON.stringify(...))`).
     *
     * @return array<string, mixed>
     */
    private static function describe(RunnableInterface $runnable): array
    {
        if ($runnable instanceof RunnableSequence) {
            return ['sequence' => array_map(self::describe(...), $runnable->steps)];
        }
        if ($runnable instanceof RunnableBinding) {
            $flat = self::describe($runnable->bound);
            $flat['binding'] = array_merge($flat['binding'] ?? [], (array) $runnable->config);

            return $flat;
        }
        if ($runnable instanceof FakeConfigurableModel) {
            return ['configurable' => self::describe($runnable->model()), 'queued' => array_keys($runnable->queuedMethodOperations())];
        }
        if ($runnable instanceof FakeToolCallingChatModel) {
            return ['model' => $runnable->llmType() . ':' . $runnable->toolStyle, 'tools' => $runnable->kwargs()['tools'] ?? null];
        }

        return ['runnable' => $runnable->getName()];
    }

    public function testShouldBindModelWithBindTools(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1', 'Tool 1 docstring.');
        $model = ReactAgentFixtures::fake([new AIMessage('test')]);
        $confModel = new FakeConfigurableModel(['model' => $model]);
        $identity = self::identity();

        $same = static fn (RunnableInterface $actual, RunnableInterface $expected) => self::assertSame(self::describe($expected), self::describe($actual));

        // A regular model.
        $same(ReactAgent::bindTools($model, [$tool1]), $model->bindTools([$tool1]));

        // A model wrapped in withConfig.
        $same(
            ReactAgent::bindTools($model->withConfig(['tags' => ['nostream']]), [$tool1]),
            $model->bindTools([$tool1])->withConfig(['tags' => ['nostream']]),
        );

        // A model wrapped in multiple withConfig.
        $same(
            ReactAgent::bindTools($model->withConfig(['tags' => ['nostream']])->withConfig(['metadata' => ['hello' => 'world']]), [$tool1]),
            $model->bindTools([$tool1])->withConfig(['tags' => ['nostream'], 'metadata' => ['hello' => 'world']]),
        );

        // A configurable model.
        $same(ReactAgent::bindTools($confModel, [$tool1]), $confModel->bindTools([$tool1]));

        // A sequence.
        $same(
            ReactAgent::bindTools(RunnableSequence::from([$model, $identity]), [$tool1]),
            RunnableSequence::from([$model->bindTools([$tool1]), $identity]),
        );

        // A sequence with a configurable model.
        $same(
            ReactAgent::bindTools(RunnableSequence::from([$confModel, $identity]), [$tool1]),
            RunnableSequence::from([$confModel->bindTools([$tool1]), $identity]),
        );

        // A sequence with a configured configurable model.
        $same(
            ReactAgent::bindTools(RunnableSequence::from([$confModel->withConfig(['tags' => ['nostream']]), $identity]), [$tool1]),
            RunnableSequence::from([$confModel->bindTools([$tool1])->withConfig(['tags' => ['nostream']]), $identity]),
        );
    }

    public function testBindingKeepsTheCallersConfigAndKwargs(): void
    {
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $model = ReactAgentFixtures::fake();
        $original = new RunnableBinding($model, ['temperature' => 0], ['tags' => ['t']]);

        $bound = ReactAgent::bindTools($original, [$tool1]);

        self::assertInstanceOf(RunnableBinding::class, $bound);
        self::assertSame(['temperature' => 0], $bound->kwargs);
        self::assertSame(['tags' => ['t']], $bound->config);
        self::assertNotNull($bound->bound->kwargs()['tools'] ?? null);
        self::assertSame([], $model->kwargs()['tools'] ?? [], 'the original model is untouched');
    }

    public function testBindToolsRefusesWhatCannotTakeTools(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must define bindTools method.');

        ReactAgent::bindTools(self::identity(), []);
    }
}
