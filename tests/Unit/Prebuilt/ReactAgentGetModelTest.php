<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports the `_getModel` describe of `prebuilt.test.ts`.
 *
 * Where upstream asserts `toBe(model)` for a model that was bound with tools, this port asserts the chat model that
 * comes back is the bound clone `bindTools()` returned (a bound model IS the model here), and that it carries the
 * tools. For an unbound model, and anything wrapped around one, identity holds as upstream.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentGetModelTest extends TestCase
{
    private static function identity(): RunnableLambda
    {
        return RunnableLambda::from(static fn (mixed $message): mixed => $message);
    }

    public function testShouldExtractTheModelFromDifferentInputs(): void
    {
        $model = ReactAgentFixtures::fake([new AIMessage('test')]);
        self::assertSame($model, ReactAgent::getModel($model));

        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');

        $modelWithTools = $model->bindTools([$tool1]);
        self::assertSame($modelWithTools, ReactAgent::getModel($modelWithTools));
        self::assertSame($model, ReactAgent::getModel($model->withConfig(['tags' => ['x']])));

        $seq = RunnableSequence::from([$model, self::identity()]);
        self::assertSame($model, ReactAgent::getModel($seq));

        $seqWithTools = RunnableSequence::from([$modelWithTools, self::identity()]);
        self::assertSame($modelWithTools, ReactAgent::getModel($seqWithTools));

        $raisingSeq = RunnableSequence::from([self::identity(), self::identity()]);
        try {
            ReactAgent::getModel($raisingSeq);
            self::fail('a sequence with no model must be refused');
        } catch (\Exception $e) {
            self::assertStringStartsWith('Expected `llm` to be a ChatModel or RunnableBinding', $e->getMessage());
            self::assertStringContainsString('RunnableSequence', $e->getMessage());
        }

        // A configurable model.
        $configurableModel = new FakeConfigurableModel(['model' => $model]);
        self::assertSame($model, ReactAgent::getModel($configurableModel));
        $boundConfigurable = $configurableModel->bindTools([$tool1]);
        self::assertSame($boundConfigurable->model(), ReactAgent::getModel($boundConfigurable));

        $configurableSeq = RunnableSequence::from([$configurableModel, self::identity()]);
        self::assertSame($model, ReactAgent::getModel($configurableSeq));

        $configurableSeqWithTools = RunnableSequence::from([$boundConfigurable, self::identity()]);
        self::assertSame($boundConfigurable->model(), ReactAgent::getModel($configurableSeqWithTools));

        $raisingConfigurableSeq = RunnableSequence::from([self::identity(), self::identity()]);
        $this->expectException(\Exception::class);
        ReactAgent::getModel($raisingConfigurableSeq);
    }

    public function testTheBoundModelCarriesTheTools(): void
    {
        $model = ReactAgentFixtures::fake();
        $bound = $model->bindTools([ReactAgentFixtures::numberTool('tool1', 'Tool 1')]);

        self::assertNotSame($model, $bound);
        self::assertSame([['type' => 'function', 'function' => ['name' => 'tool1']]], ReactAgent::getModel($bound)->kwargs()['tools']);
    }

    public function testANonModelIsRefusedWithItsClassName(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('got ' . RunnableLambda::class);

        ReactAgent::getModel(self::identity());
    }
}
