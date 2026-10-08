<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Ports "createReactAgent with bound tools" of `prebuilt.test.ts`: for every provider tool style and both graph
 * versions, a model that already carries the agent's tools is used as-is, and a model whose tools disagree with
 * the agent's is refused.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentBoundToolsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function stylesByVersion(): array
    {
        $rows = [];
        foreach (ReactAgentFixtures::versionNames() as $version) {
            foreach (['openai', 'anthropic', 'bedrock', 'google'] as $style) {
                $rows["$version $style"] = [$version, $style];
            }
        }

        return $rows;
    }

    #[DataProvider('stylesByVersion')]
    public function testCanUseBoundToolsAndValidateToolMatching(string $version, string $toolStyle): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage('result')], ['toolStyle' => $toolStyle]);
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1', 'Tool 1 docstring.');
        $tool2 = ReactAgentFixtures::numberTool('tool2', 'Tool 2', 'Tool 2 docstring.');

        // A valid agent constructor: the tools node runs both calls.
        $agent = ReactAgent::create(['llm' => $llm->bindTools([$tool1, $tool2]), 'tools' => [$tool1, $tool2], 'version' => $version]);

        $result = $agent->nodes['tools']->bound->invoke([
            'messages' => [
                new AIMessage([
                    'content' => 'hi?',
                    'tool_calls' => [
                        ReactAgentFixtures::toolCall('tool1', 'some 1', ['someVal' => 2]),
                        ReactAgentFixtures::toolCall('tool2', 'some 2', ['someVal' => 2]),
                    ],
                ]),
            ],
        ]);

        $toolMessages = array_slice($result['messages'], -2);
        self::assertCount(2, $toolMessages);
        foreach ($toolMessages as $toolMessage) {
            self::assertInstanceOf(ToolMessage::class, $toolMessage);
            self::assertContains($toolMessage->content, ['Tool 1: 2', 'Tool 2: 2']);
            self::assertContains($toolMessage->toolCallId, ['some 1', 'some 2']);
        }

        // Mismatching tool lengths.
        try {
            ReactAgent::create(['llm' => $llm->bindTools([$tool1]), 'tools' => [$tool1, $tool2], 'version' => $version])
                ->invoke(['messages' => [new HumanMessage('Hello Input!')]]);
            self::fail('a model bound with fewer tools than the agent must be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString('must match', $e->getMessage());
        }

        // Missing bound tools.
        try {
            ReactAgent::create(['llm' => $llm->bindTools([$tool1]), 'tools' => [$tool2], 'version' => $version])
                ->invoke(['messages' => [new HumanMessage('Hello Input!')]]);
            self::fail('a model bound without the agent\'s tool must be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString("Missing tools 'tool2' in the model.bindTools()", $e->getMessage());
        }
    }

    #[DataProvider('stylesByVersion')]
    public function testAModelBoundWithTheAgentsToolsIsNotBoundAgain(string $version, string $toolStyle): void
    {
        $llm = ReactAgentFixtures::spy(
            [
                new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('tool1', 'c1', ['someVal' => 3])]]),
                new AIMessage('done'),
            ],
            ['toolStyle' => $toolStyle],
        );
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $bound = $llm->bindTools([$tool1]);

        $result = ReactAgent::create(['llm' => $bound, 'tools' => [$tool1], 'version' => $version])
            ->invoke(['messages' => 'go']);

        self::assertSame(['go', '', 'Tool 1: 3', 'done'], ReactAgentFixtures::texts($result['messages']));
        // The only bindTools() call was the caller's own: the agent did not bind again.
        self::assertCount(1, $llm->bindToolsCalls);
    }

    public function testAnAgentWithAnUnboundModelBindsTheToolsItself(): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('x')]);
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');

        ReactAgent::create(['llm' => $llm, 'tools' => [$tool1]])->invoke(['messages' => 'go']);

        // The model that answered was the agent's bound clone, sharing the spy's recorders.
        self::assertCount(1, $llm->invokeCalls);
        self::assertCount(1, $llm->bindToolsCalls);
        self::assertSame('tool1', $llm->bindToolsCalls[0][0]->name);
        self::assertSame([], $llm->kwargs()['tools'] ?? [], 'the caller\'s own model is never mutated');
    }

    public function testAModelInsideASequenceIsBoundInPlaceAndTheRestOfTheSequenceKept(): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage('x')]);
        $tool1 = ReactAgentFixtures::numberTool('tool1', 'Tool 1');
        $sequence = RunnableSequence::from([$llm, RunnableLambda::from(static fn (mixed $m): mixed => $m)]);

        $bound = ReactAgent::bindTools($sequence, [$tool1]);

        self::assertInstanceOf(RunnableSequence::class, $bound);
        self::assertCount(2, $bound->steps);
        self::assertSame(
            [['type' => 'function', 'function' => ['name' => 'tool1']]],
            $bound->steps[0]->kwargs()['tools'],
        );
    }
}
