<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\Prebuilt\ToolsCondition;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * {@see ChatOpenAI} with the retry sleep removed.
 */
final class RoutingChatOpenAI extends ChatOpenAI
{
    /** @var list<int> */
    public array $slept = [];

    protected function backoff(int $attempt): void
    {
        $this->slept[] = $attempt;
    }
}

/**
 * `useResponsesApi` routing of the {@see ChatOpenAI} facade (`_useResponsesApi`
 * and the `useResponsesApi` cases of upstream `chat_models/tests/index.test.ts`),
 * the `_modelPrefersResponsesAPI` cases, and end-to-end runs through a real
 * chain and a real graph.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatOpenAICompletions::class)]
#[CoversClass(ChatOpenAIResponses::class)]
final class UseResponsesApiTest extends TestCase
{
    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'from completions'], 'finish_reason' => 'stop']],
    ];

    private const RESPONSE = [
        'id' => 'resp_1', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o',
        'output' => [['type' => 'message', 'id' => 'm', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'from responses', 'annotations' => []]]]],
        'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $fields = [], array $stream = []): RoutingChatOpenAI
    {
        return new RoutingChatOpenAI($fields + [
            'model' => 'gpt-4o', 'apiKey' => 'sk-test', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    /** @return array<string, mixed> */
    private static function functionTool(): array
    {
        return ['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object', 'properties' => []]]];
    }

    // ---- routing ----------------------------------------------------------

    public function testDefaultsToChatCompletions(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);

        self::assertFalse($model->shouldUseResponsesApi());
        self::assertSame('from completions', $model->invoke('hi')->content);
        self::assertSame('https://api.openai.com/v1/chat/completions', $model->httpClient->requests[0]['url']);
        self::assertArrayHasKey('messages', $model->httpClient->lastRequestBody());
    }

    public function testTheExplicitFlagSelectsTheResponsesApi(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], ['useResponsesApi' => true]);

        self::assertTrue($model->useResponsesApi);
        self::assertTrue($model->shouldUseResponsesApi());
        self::assertSame('from responses', $model->invoke('hi')->content[0]['text']);
        self::assertSame('https://api.openai.com/v1/responses', $model->httpClient->requests[0]['url']);
        self::assertArrayHasKey('input', $model->httpClient->lastRequestBody());
    }

    public function testTheFlagIsRecordedInKwargsForSerialisation(): void
    {
        self::assertTrue(self::model(fields: ['useResponsesApi' => true])->kwargs()['useResponsesApi']);
        self::assertArrayNotHasKey('useResponsesApi', self::model()->kwargs());
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function responsesOnlyOptions(): iterable
    {
        yield 'built-in tool' => [['tools' => [['type' => 'web_search_preview']]]];
        yield 'tool_search' => [['tools' => [['type' => 'tool_search']]]];
        yield 'openai custom tool' => [['tools' => [['type' => 'custom', 'custom' => ['name' => 'exec']]]]];
        yield 'previous_response_id' => [['previous_response_id' => 'resp_0']];
        yield 'previousResponseId' => [['previousResponseId' => 'resp_0']];
        yield 'text' => [['text' => ['verbosity' => 'low']]];
        yield 'truncation' => [['truncation' => 'auto']];
        yield 'include' => [['include' => ['reasoning.encrypted_content']]];
        yield 'reasoning.summary' => [['reasoning' => ['summary' => 'auto']]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('responsesOnlyOptions')]
    public function testAResponsesOnlyCallOptionRoutesToResponses(array $options): void
    {
        self::assertTrue(self::model()->shouldUseResponsesApi($options));
        self::assertArrayHasKey('max_output_tokens', self::model(fields: ['maxTokens' => 5])->invocationParams($options));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('responsesOnlyOptions')]
    public function testTheSameOptionsRouteWhenBoundWithBindTools(array $options): void
    {
        $bound = isset($options['tools'])
            ? self::model()->bindTools($options['tools'])
            : self::model()->bindTools([], $options);

        self::assertTrue($bound->shouldUseResponsesApi());
    }

    public function testALangChainCustomToolRoutesToResponses(): void
    {
        $custom = tool(static fn (array $a): string => 'x', [
            'name' => 'exec', 'description' => 'd', 'schema' => Schema::object([]),
            'metadata' => ['customTool' => ['name' => 'exec', 'format' => ['type' => 'text']]],
        ]);

        self::assertTrue(self::model()->shouldUseResponsesApi(['tools' => [$custom]]));
        self::assertTrue(self::model()->bindTools([$custom])->shouldUseResponsesApi());
    }

    public function testAConstructorReasoningSummaryRoutesToResponses(): void
    {
        self::assertTrue(self::model(fields: ['reasoning' => ['summary' => 'auto']])->shouldUseResponsesApi());
        self::assertFalse(self::model(fields: ['reasoning' => ['effort' => 'high']])->shouldUseResponsesApi());
    }

    public function testPlainFunctionToolsStayOnChatCompletions(): void
    {
        $model = self::model();

        self::assertFalse($model->shouldUseResponsesApi(['tools' => [self::functionTool()]]));
        self::assertFalse($model->bindTools([self::functionTool()])->shouldUseResponsesApi());
        self::assertFalse($model->shouldUseResponsesApi(['temperature' => 0.2, 'toolChoice' => 'auto']));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function modelNames(): iterable
    {
        yield 'gpt-5.2-pro' => ['gpt-5.2-pro', true];
        yield 'gpt-5.4-pro' => ['gpt-5.4-pro', true];
        yield 'gpt-5.5-pro' => ['gpt-5.5-pro', true];
        yield 'gpt-5.6' => ['gpt-5.6', true];
        yield 'gpt-5.6-mini' => ['gpt-5.6-mini', true];
        yield 'codex' => ['gpt-5-codex', true];
        yield 'codex-mini' => ['codex-mini-latest', true];
        yield 'gpt-4o' => ['gpt-4o', false];
        yield 'gpt-4o-mini' => ['gpt-4o-mini', false];
        yield 'gpt-5' => ['gpt-5', false];
        yield 'o3' => ['o3', false];
    }

    #[DataProvider('modelNames')]
    public function testModelPrefersResponsesApi(string $model, bool $expected): void
    {
        self::assertSame($expected, ChatOpenAI::modelPrefersResponsesApi($model));
        self::assertSame($expected, self::model(fields: ['model' => $model])->shouldUseResponsesApi());
    }

    // ---- the facade carries its state to the delegate ---------------------

    public function testInvocationParamsFollowTheChosenProtocol(): void
    {
        $model = self::model(fields: ['maxTokens' => 7, 'temperature' => 0.5]);

        $completions = $model->invocationParams();
        $responses = $model->invocationParams(['truncation' => 'auto']);

        self::assertSame(7, $completions['max_tokens']);
        self::assertArrayNotHasKey('max_output_tokens', $completions);
        self::assertSame(7, $responses['max_output_tokens']);
        self::assertArrayNotHasKey('max_tokens', $responses);
        self::assertSame(0.5, $responses['temperature']);
    }

    public function testBoundBuiltInToolsReachAResponsesRequest(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)])->bindTools([['type' => 'web_search_preview']], ['temperature' => 0.0]);

        $model->invoke('news?');

        $body = $model->httpClient->lastRequestBody();
        self::assertSame([['type' => 'web_search_preview']], $body['tools']);
        self::assertEquals(0.0, $body['temperature']);
        self::assertArrayHasKey('input', $body);
    }

    public function testBindingDoesNotLeakIntoTheOriginal(): void
    {
        $base = self::model();
        $bound = $base->bindTools([['type' => 'web_search_preview']]);

        self::assertFalse($base->shouldUseResponsesApi());
        self::assertTrue($bound->shouldUseResponsesApi());
        self::assertNotSame($base, $bound);
    }

    public function testStateChangedAfterConstructionIsHonoured(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);
        $model->temperature = 0.25;
        $model->maxTokens = 9;

        $model->invoke('hi');

        $body = $model->httpClient->lastRequestBody();
        self::assertSame(0.25, $body['temperature']);
        self::assertSame(9, $body['max_tokens']);
    }

    public function testTheRetrySleepOfASubclassGovernsBothProtocols(): void
    {
        foreach ([[], ['useResponsesApi' => true]] as $fields) {
            $model = self::model([
                FakeHttpClient::json(429, ['error' => ['message' => 'slow']]),
                FakeHttpClient::json(200, $fields === [] ? self::COMPLETION : self::RESPONSE),
            ], ['maxRetries' => 1] + $fields);

            $model->invoke('hi');

            self::assertSame([1], $model->slept, $fields === [] ? 'completions' : 'responses');
        }
    }

    public function testStreamingRoutesWithTheCall(): void
    {
        $completions = self::model(stream: [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"abc\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);
        $responses = self::model(fields: ['useResponsesApi' => true], stream: [
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\"xyz\",\"content_index\":0,\"output_index\":0}\n\n",
        ]);

        $a = '';
        foreach ($completions->stream('hi') as [, $chunk]) {
            $a .= $chunk->content;
        }
        $b = '';
        foreach ($responses->stream('hi') as [, $chunk]) {
            $b .= $chunk->content[0]['text'];
        }

        self::assertSame('abc', $a);
        self::assertStringEndsWith('/chat/completions', $completions->httpClient->streamRequests[0]['url']);
        self::assertSame('xyz', $b);
        self::assertStringEndsWith('/responses', $responses->httpClient->streamRequests[0]['url']);
    }

    public function testStreamChatModelEventsNeedTheResponsesApi(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Responses API');

        iterator_to_array(self::model()->streamChatModelEvents([new HumanMessage('hi')]), false);
    }

    public function testStreamChatModelEventsRouteToResponses(): void
    {
        $model = self::model(fields: ['useResponsesApi' => true], stream: [
            "data: {\"type\":\"response.created\",\"response\":{\"id\":\"resp_9\",\"model\":\"gpt-4o\"}}\n\n",
        ]);

        $events = iterator_to_array($model->streamChatModelEvents([new HumanMessage('hi')]), false);

        self::assertSame('message-start', $events[0]['event']);
        self::assertSame('resp_9', $events[0]['id']);
    }

    public function testTheServiceTierAndReasoningFieldsAreAcceptedByTheFacade(): void
    {
        $model = self::model(fields: ['model' => 'o3', 'service_tier' => 'flex', 'useResponsesApi' => true, 'reasoning' => ['effort' => 'low']]);

        $params = $model->invocationParams();

        self::assertSame('flex', $params['service_tier']);
        self::assertSame(['effort' => 'low'], $params['reasoning']);
    }

    // ---- end to end -------------------------------------------------------

    public function testAChainRunsThroughTheResponsesApi(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], ['useResponsesApi' => true]);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer as {persona}.'], ['human', '{question}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $answer = $chain->invoke(['persona' => 'a pirate', 'question' => 'ahoy?']);

        self::assertSame('from responses', $answer);
        self::assertSame([
            ['type' => 'message', 'role' => 'system', 'content' => 'Answer as a pirate.'],
            ['type' => 'message', 'role' => 'user', 'content' => 'ahoy?'],
        ], $model->httpClient->lastRequestBody()['input']);
    }

    public function testAToolCallingGraphRunsThroughTheResponsesApi(): void
    {
        $weather = tool(
            static fn (array $in): string => 'Sunny in ' . $in['city'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );
        $call = ['id' => 'resp_a', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-4o', 'output' => [
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"Austin"}'],
        ], 'usage' => ['input_tokens' => 4, 'output_tokens' => 3, 'total_tokens' => 7]];
        $final = ['output' => [['type' => 'message', 'id' => 'm', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'It is sunny.', 'annotations' => []]]]], 'id' => 'resp_b'] + self::RESPONSE;

        $model = self::model([FakeHttpClient::json(200, $call), FakeHttpClient::json(200, $final)], ['useResponsesApi' => true]);
        $withTools = $model->bindTools([$weather]);

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', static fn (array $state): array => ['messages' => [$withTools->invoke($state['messages'])]])
            ->addNode('tools', new ToolNode([$weather]))
            ->addEdge(Constants::START, 'agent')
            ->addConditionalEdges('agent', new ToolsCondition(), ['tools', Constants::END])
            ->addEdge('tools', 'agent')
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage('weather in Austin?')]]);

        $messages = $result['messages'];
        self::assertCount(4, $messages);
        self::assertInstanceOf(AIMessage::class, $messages[1]);
        self::assertInstanceOf(ToolMessage::class, $messages[2]);
        self::assertSame('Sunny in Austin', $messages[2]->content);
        self::assertSame('It is sunny.', $messages[3]->content[0]['text']);

        // The first request offered the tool in the Responses shape...
        $first = json_decode($model->httpClient->requests[0]['body'], true);
        self::assertSame('get_weather', $first['tools'][0]['name']);
        self::assertSame('function', $first['tools'][0]['type']);
        self::assertArrayNotHasKey('function', $first['tools'][0]);

        // ...and the second replayed the model's own function_call item plus the tool's answer.
        $second = json_decode($model->httpClient->requests[1]['body'], true);
        $types = array_column($second['input'], 'type');
        self::assertSame(['message', 'function_call', 'function_call_output'], $types);
        self::assertSame('call_1', $second['input'][1]['call_id']);
        self::assertSame('fc_1', $second['input'][1]['id']);
        self::assertSame('Sunny in Austin', $second['input'][2]['output']);
    }

    public function testAStreamingToolCallingTurnFeedsTheSameGraph(): void
    {
        $weather = tool(
            static fn (array $in): string => 'Rain in ' . $in['city'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_s', 'model' => 'gpt-4o']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_9', 'call_id' => 'call_9', 'name' => 'get_weather', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"city":'],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '"Oslo"}'],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_s', 'model' => 'gpt-4o', 'status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]],
        ];
        $model = self::model(fields: ['useResponsesApi' => true, 'streaming' => true], stream: array_map(
            static fn (array $e): string => 'data: ' . json_encode($e) . "\n\n",
            $events,
        ));

        $ai = $model->bindTools([$weather])->invoke('weather in Oslo?');
        $tools = (new ToolNode([$weather]))->invoke(['messages' => [$ai]]);

        self::assertSame('call_9', $ai->toolCalls[0]['id']);
        self::assertSame('Rain in Oslo', $tools['messages'][0]->content);
    }
}
