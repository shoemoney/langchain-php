<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt\Swarm;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tests\Unit\Prebuilt\Supervisor\SupervisorFixtures;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\Swarm\Handoff;
use LangGraph\Prebuilt\Swarm\Swarm;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Port of `langgraph-swarm/src/swarm.test.ts`, plus the handoff and routing checks it does not make. */
#[CoversClass(Swarm::class)]
#[CoversClass(Handoff::class)]
final class SwarmTest extends TestCase
{
    public function testShouldRunInBasicCase(): void
    {
        $call = SupervisorFixtures::call(...);
        $recorded = [
            new AIMessage(['id' => '1', 'content' => '', 'name' => 'Alice', 'tool_calls' => [$call('transfer_to_bob', [], 'call_1LlFyjm6iIhDjdn7juWuPYr4')]]),
            new AIMessage(['id' => '2', 'content' => "Ahoy, matey! Bob the pirate be at yer service. What be ye needin' help with today on the high seas? Arrr!", 'name' => 'Bob']),
            new AIMessage(['id' => '3', 'content' => '', 'name' => 'Bob', 'tool_calls' => [$call('transfer_to_alice', [], 'call_T6pNmo2jTfZEK3a9avQ14f8Q')]]),
            new AIMessage(['id' => '4', 'content' => '', 'name' => 'Alice', 'tool_calls' => [$call('add', ['a' => 5, 'b' => 7], 'call_4kLYO1amR2NfhAxfECkALCr1')]]),
            new AIMessage(['id' => '5', 'content' => 'The sum of 5 and 7 is 12.', 'name' => 'Alice']),
        ];
        $model = ReactAgentFixtures::spy($recorded);

        $alice = ReactAgent::create([
            'llm' => $model,
            'tools' => [SupervisorFixtures::add(), Handoff::createHandoffTool(['agentName' => 'Bob'])],
            'name' => 'Alice',
            'prompt' => 'You are Alice, an addition expert.',
        ]);
        $bob = ReactAgent::create([
            'llm' => $model,
            'tools' => [Handoff::createHandoffTool(['agentName' => 'Alice', 'description' => 'Transfer to Alice, she can help with math'])],
            'name' => 'Bob',
            'prompt' => 'You are Bob, you speak like a pirate.',
        ]);

        $app = Swarm::create(['agents' => [$alice, $bob], 'defaultActiveAgent' => 'Alice'])
            ->compile(['name' => 'swarm_demo', 'checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => '1']);

        $turn1 = $app->invoke(['messages' => [['role' => 'user', 'content' => "i'd like to speak to Bob"]]], $config);
        self::assertCount(4, $turn1['messages']);
        self::assertSame('Successfully transferred to Bob', $turn1['messages'][2]->content);
        self::assertSame($recorded[1]->content, $turn1['messages'][3]->content);
        self::assertSame('Bob', $turn1['activeAgent']);

        $turn2 = $app->invoke(['messages' => [['role' => 'user', 'content' => "what's 5 + 7?"]]], $config);
        $messages = $turn2['messages'];
        self::assertCount(10, $messages);
        self::assertSame('Successfully transferred to Alice', $messages[count($messages) - 4]->content);
        self::assertEquals($recorded[3]->toolCalls, $messages[count($messages) - 3]->toolCalls);
        self::assertSame('12', $messages[count($messages) - 2]->content);
        self::assertSame($recorded[4]->content, $messages[count($messages) - 1]->content);
        self::assertSame('Alice', $turn2['activeAgent']);
    }

    public function testHandoffToolShape(): void
    {
        $tool = Handoff::createHandoffTool(['agentName' => 'Travel Booking Assistant']);

        self::assertSame('transfer_to_travel_booking_assistant', $tool->name);
        self::assertSame("Ask agent 'Travel Booking Assistant' for help", $tool->description);
        self::assertSame(['__handoff_destination' => 'Travel Booking Assistant'], $tool->metadata);
        self::assertSame('Custom', Handoff::createHandoffTool(['agentName' => 'x', 'description' => 'Custom'])->description);
    }

    public function testGetHandoffDestinationsReadsToolMetadata(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('ok')]);
        $agent = ReactAgent::create([
            'llm' => $model,
            'tools' => [SupervisorFixtures::add(), Handoff::createHandoffTool(['agentName' => 'Bob']), Handoff::createHandoffTool(['agentName' => 'Carol'])],
            'name' => 'Alice',
        ]);
        $plain = ReactAgent::create(['llm' => $model, 'tools' => [SupervisorFixtures::add()], 'name' => 'Plain']);

        self::assertSame(['Bob', 'Carol'], Handoff::getHandoffDestinations($agent));
        self::assertSame([], Handoff::getHandoffDestinations($plain));
        self::assertSame([], Handoff::getHandoffDestinations($agent, 'no_such_node'));
    }

    public function testHandoffCommandCarriesTheStateTheActiveAgentAndAParentGraph(): void
    {
        $model = ReactAgentFixtures::spy([
            SupervisorFixtures::aiCalling(SupervisorFixtures::call('transfer_to_bob', [], 'call_x')),
        ]);
        $alice = ReactAgent::create([
            'llm' => $model,
            'tools' => [Handoff::createHandoffTool(['agentName' => 'Bob'])],
            'name' => 'Alice',
        ]);

        // Run Alice alone: the tool's Command(graph: PARENT) has no parent here, so it escapes as a ParentCommand.
        try {
            $alice->invoke(['messages' => [['role' => 'user', 'content' => 'hi']]]);
            self::fail('expected the parent command to escape the agent');
        } catch (\LangGraph\Errors\ParentCommand $e) {
            $command = $e->command;
        }

        self::assertSame(Command::PARENT, $command->graph);
        self::assertSame('Bob', $command->goto);
        self::assertSame('Bob', $command->update['activeAgent']);
        $types = array_map(static fn ($m) => $m->getType(), $command->update['messages']);
        self::assertSame(['human', 'ai', 'tool'], $types);
        $updated = $command->update['messages'];
        $last = end($updated);
        self::assertInstanceOf(ToolMessage::class, $last);
        self::assertSame('call_x', $last->toolCallId);
        self::assertSame('transfer_to_bob', $last->name);
    }

    public function testRoutesToTheActiveAgentAndFallsBackToTheDefault(): void
    {
        $model = static fn (string $reply) => ReactAgentFixtures::spy([new AIMessage($reply)]);
        $alice = ReactAgent::create(['llm' => $model('alice here'), 'tools' => [], 'name' => 'Alice']);
        $bob = ReactAgent::create(['llm' => $model('bob here'), 'tools' => [], 'name' => 'Bob']);

        $app = Swarm::create(['agents' => [$alice, $bob], 'defaultActiveAgent' => 'Bob'])->compile();

        $byDefault = $app->invoke(['messages' => [['role' => 'user', 'content' => 'hi']]]);
        self::assertSame('bob here', end($byDefault['messages'])->content);

        $explicit = $app->invoke(['messages' => [['role' => 'user', 'content' => 'hi']], 'activeAgent' => 'Alice']);
        self::assertSame('alice here', end($explicit['messages'])->content);
    }

    public function testAddActiveAgentRouterRejectsAnUnknownDefault(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Default active agent 'Zed' not found in routes Alice,Bob");

        Swarm::addActiveAgentRouter(new StateGraph(Swarm::state()), ['routeTo' => ['Alice', 'Bob'], 'defaultActiveAgent' => 'Zed']);
    }

    public function testSwarmStateDeclaresMessagesAndActiveAgent(): void
    {
        self::assertSame(['messages', 'activeAgent'], Swarm::state()->keys());
    }

    public function testCreateValidatesTheStateSchemaAndTheAgentNames(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('ok')]);
        $named = ReactAgent::create(['llm' => $model, 'tools' => [], 'name' => 'Alice']);
        $anonymous = ReactAgent::create(['llm' => $model, 'tools' => []]);

        try {
            Swarm::create(['agents' => [$named], 'defaultActiveAgent' => 'Alice', 'stateSchema' => \LangGraph\Graph\MessagesAnnotation::root()]);
            self::fail('expected a missing activeAgent key to be refused');
        } catch (\Exception $e) {
            self::assertSame("Missing required key 'activeAgent' in stateSchema", $e->getMessage());
        }

        try {
            Swarm::create(['agents' => [$anonymous], 'defaultActiveAgent' => 'Alice']);
            self::fail('expected an unnamed agent to be refused');
        } catch (\Exception $e) {
            self::assertStringContainsString('Please specify a name', $e->getMessage());
        }

        try {
            Swarm::create(['agents' => [$named, $named], 'defaultActiveAgent' => 'Alice']);
            self::fail('expected a duplicate name to be refused');
        } catch (\Exception $e) {
            self::assertSame("Agent with name 'Alice' already exists. Agent names must be unique.", $e->getMessage());
        }
    }
}
