<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangChain\Utils\Js;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * End to end: a real graph runs a tool-calling loop and every model turn is
 * built from the live message state by the Responses converters.
 *
 * The "model" node plays ChatOpenAI's role on the Responses path: it converts the
 * accumulated `messages` channel to `input` and the bound tools to `tools`, and
 * records the encoded request body. It then answers from a script. Unit tests of
 * the converters take messages the test built by hand; here the messages are the
 * ones the graph's reducer produced (ids assigned, tool results appended), which
 * is the shape the converters see in production.
 *
 * Asserted on the ENCODED request body, because that is where `[]` versus `{}`
 * and `undefined` versus `null` become real.
 */
#[CoversClass(ResponsesInput::class)]
#[CoversClass(ResponsesTools::class)]
final class ResponsesInputGraphTest extends TestCase
{
    public function testAToolCallingLoopBuildsEachRequestFromTheLiveGraphState(): void
    {
        $tools = [
            new DynamicStructuredTool(
                ['name' => 'get_time', 'description' => 'Current time', 'schema' => Schema::object([], [])],
                static fn (array $in): string => '12:00',
            ),
            new DynamicStructuredTool(
                ['name' => 'add', 'description' => 'Add two numbers', 'schema' => Schema::object(['a' => Schema::any(), 'b' => Schema::any()], ['a', 'b'])],
                static fn (array $in): string => (string) ($in['a'] + $in['b']),
            ),
        ];
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        /** @var list<string> $requests */
        $requests = [];
        $script = [
            new AIMessage([
                'id' => 'msg_1',
                'content' => [],
                'tool_calls' => [
                    ['id' => 'call_time', 'name' => 'get_time', 'args' => []],
                    ['id' => 'call_add', 'name' => 'add', 'args' => ['a' => 2, 'b' => 3]],
                ],
                'additional_kwargs' => [ResponsesInput::FUNCTION_CALL_IDS_MAP_KEY => ['call_time' => 'fc_time', 'call_add' => 'fc_add']],
            ]),
            new AIMessage(['id' => 'msg_2', 'content' => 'It is 12:00 and 2+3=5.']),
        ];
        $turn = 0;

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('model', function (array $state) use (&$requests, &$turn, $script, $tools): array {
                $requests[] = Js::encode([
                    'model' => 'gpt-5.2',
                    'input' => ResponsesInput::convertMessagesToResponsesInput($state['messages'], false, 'gpt-5.2'),
                    'tools' => ResponsesTools::reduceTools($tools, false, true),
                ]);

                return ['messages' => [$script[$turn++]]];
            })
            ->addNode('tools', static function (array $state) use ($byName): array {
                /** @var AIMessage $last */
                $last = end($state['messages']);
                $results = [];
                foreach ($last->toolCalls as $call) {
                    $results[] = new ToolMessage([
                        'tool_call_id' => $call['id'],
                        'content' => $byName[$call['name']]->invoke($call['args']),
                    ]);
                }

                return ['messages' => $results];
            })
            ->addEdge(Constants::START, 'model')
            ->addConditionalEdges(
                'model',
                static fn (array $state): string => end($state['messages'])->toolCalls !== [] ? 'tools' : Constants::END,
            )
            ->addEdge('tools', 'model')
            ->compile();

        $result = $graph->invoke(['messages' => [
            new SystemMessage('Be terse.'),
            new HumanMessage('What time is it, and what is 2+3?'),
        ]]);

        self::assertSame('It is 12:00 and 2+3=5.', end($result['messages'])->text());
        self::assertCount(2, $requests);

        // Turn 1: instructions are `developer` for a reasoning model; the user text is a plain string item.
        $first = json_decode($requests[0], false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('developer', $first->input[0]->role);
        self::assertSame('user', $first->input[1]->role);
        self::assertCount(2, $first->input);

        // The tools array carries the flattened function tools, and the no-argument
        // tool's `properties` is an object, not a list.
        self::assertSame(['get_time', 'add'], array_map(static fn (object $t): string => $t->name, $first->tools));
        self::assertTrue($first->tools[0]->strict);
        self::assertSame('{}', Js::encode($first->tools[0]->parameters->properties));
        self::assertStringContainsString('"properties":{}', $requests[0]);

        // Turn 2: the assistant tool calls and their results were rebuilt from graph state.
        $second = json_decode($requests[1], false, 512, JSON_THROW_ON_ERROR);
        $types = array_map(static fn (object $item): string => $item->type, $second->input);
        self::assertSame(
            ['message', 'message', 'function_call', 'function_call', 'function_call_output', 'function_call_output'],
            $types,
        );

        $noArgCall = $second->input[2];
        self::assertSame('call_time', $noArgCall->call_id);
        self::assertSame('fc_time', $noArgCall->id, 'the stored item id is replayed when ZDR is off');
        self::assertSame('{}', $noArgCall->arguments, 'a no-argument call is an object, not a list');

        self::assertSame('{"a":2,"b":3}', $second->input[3]->arguments);
        self::assertSame(['call_time', 'call_add'], [$second->input[4]->call_id, $second->input[5]->call_id]);
        self::assertSame(['12:00', '5'], [$second->input[4]->output, $second->input[5]->output]);
    }

    public function testTheSameLoopUnderZdrOmitsStoredItemIds(): void
    {
        $messages = [
            new HumanMessage('hi'),
            new AIMessage([
                'content' => [],
                'tool_calls' => [['id' => 'call_1', 'name' => 'f', 'args' => ['x' => 1]]],
                'additional_kwargs' => [ResponsesInput::FUNCTION_CALL_IDS_MAP_KEY => ['call_1' => 'fc_1']],
            ]),
            new ToolMessage(['tool_call_id' => 'call_1', 'content' => 'ok']),
        ];

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('echo', static fn (array $state): array => ['messages' => [new AIMessage(
                Js::encode(ResponsesInput::convertMessagesToResponsesInput($state['messages'], true, 'gpt-5.2')),
            )]])
            ->addEdge(Constants::START, 'echo')
            ->compile();

        $result = $graph->invoke(['messages' => $messages]);
        $items = json_decode(end($result['messages'])->text(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['message', 'function_call', 'function_call_output'], array_column($items, 'type'));
        self::assertArrayNotHasKey('id', $items[1]);
    }
}
