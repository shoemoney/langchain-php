<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Ports "createReactAgent agent name options" of `prebuilt.test.ts`. */
#[CoversClass(ReactAgent::class)]
final class ReactAgentNameTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    #[DataProvider('versions')]
    public function testCanUseInlineAgentName(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('Hello, how can I help?'), new AIMessage("Hmm, I'm not sure about that.")]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
            'includeAgentName' => 'inline',
            'name' => 'test agent',
        ]);

        $messages = [new HumanMessage('Hello!')];
        $result1 = $agent->invoke(['messages' => $messages]);
        $outputMessage1 = end($result1['messages']);
        $messages[] = $outputMessage1;

        self::assertSame('test agent', $outputMessage1->name);
        self::assertSame('Hello, how can I help?', $outputMessage1->content);

        $result2 = $agent->invoke(['messages' => $messages]);
        $outputMessage2 = end($result2['messages']);

        // The model saw the earlier turn with the name folded into the text, and no name field.
        $lastCall = end($llm->invokeCalls)[0];
        self::assertCount(2, $lastCall);
        self::assertSame($messages[0]->content, $lastCall[0]->content);
        self::assertSame(
            ['type' => 'ai', 'content' => '<name>test agent</name><content>Hello, how can I help?</content>', 'name' => null],
            array_intersect_key(ReactAgentFixtures::shape($lastCall[1]), ['type' => 1, 'content' => 1, 'name' => 1]),
        );

        // The name is applied to the returned output, and the tags are stripped from it.
        self::assertSame('test agent', $outputMessage2->name);
        self::assertSame("Hmm, I'm not sure about that.", $outputMessage2->content);
    }

    #[DataProvider('versions')]
    public function testCanUseInlineAgentNameWithContentBlocks(string $version): void
    {
        $llm = ReactAgentFixtures::spy([
            new AIMessage(['content' => [['type' => 'text', 'text' => 'Hello, how can I help?']]]),
            new AIMessage(['content' => [['type' => 'text', 'text' => "Hmm, I'm not sure about that."]]]),
        ]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
            'includeAgentName' => 'inline',
            'name' => 'test agent',
        ]);

        $messages = [new HumanMessage('Hello!')];
        $result1 = $agent->invoke(['messages' => $messages]);
        $outputMessage1 = end($result1['messages']);
        $messages[] = $outputMessage1;

        self::assertSame('test agent', $outputMessage1->name);
        self::assertSame([['type' => 'text', 'text' => 'Hello, how can I help?']], $outputMessage1->content);

        $result2 = $agent->invoke(['messages' => $messages]);
        $outputMessage2 = end($result2['messages']);

        $lastCall = end($llm->invokeCalls)[0];
        self::assertNull($lastCall[1]->name);
        self::assertSame(
            [['type' => 'text', 'text' => '<name>test agent</name><content>Hello, how can I help?</content>']],
            $lastCall[1]->content,
        );

        self::assertSame('test agent', $outputMessage2->name);
        self::assertSame([['type' => 'text', 'text' => "Hmm, I'm not sure about that."]], $outputMessage2->content);
    }

    #[DataProvider('versions')]
    public function testSetsNameWhenIncludeAgentNameIsUndefined(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('Hello, how can I help?'), new AIMessage("Hmm, I'm not sure about that.")]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
            'name' => 'test agent',
        ]);

        $messages = [new HumanMessage('Hello!')];
        $result1 = $agent->invoke(['messages' => $messages]);
        $outputMessage1 = end($result1['messages']);
        $messages[] = $outputMessage1;

        self::assertSame('test agent', $outputMessage1->name);
        self::assertSame('Hello, how can I help?', $outputMessage1->content);

        $result2 = $agent->invoke(['messages' => $messages]);
        $outputMessage2 = end($result2['messages']);

        // No XML formatting on the input message, and the name travels as the name field.
        $lastCall = end($llm->invokeCalls)[0];
        self::assertSame('test agent', $lastCall[1]->name);
        self::assertSame('Hello, how can I help?', $lastCall[1]->content);

        self::assertSame('test agent', $outputMessage2->name);
        self::assertSame("Hmm, I'm not sure about that.", $outputMessage2->content);
    }

    public function testTheNameSurvivesSerialisationOfTheResponse(): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage('hi')]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [], 'name' => 'test agent']);

        $result = $agent->invoke(['messages' => 'hello']);

        self::assertSame('test agent', end($result['messages'])->kwargs()['name']);
    }
}
