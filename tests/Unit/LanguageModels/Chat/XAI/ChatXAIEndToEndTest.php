<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Chat\XAI\Tools\LiveSearch as LiveSearchTool;
use LangChain\LanguageModels\Chat\XAI\Tools\WebSearch;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\Prebuilt\ToolsCondition;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * What goes over the wire to xAI, and real chains and graphs running through the client.
 *
 * Every assertion here reads the request body the client actually produced: the cleaning that
 * `completionWithRetry()` does is only visible there.
 */
#[CoversClass(ChatXAI::class)]
final class ChatXAIEndToEndTest extends TestCase
{
    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'grok-3-fast',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Bonjour.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3, 'total_tokens' => 12],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): ChatXAI
    {
        return new ChatXAI($fields + [
            'apiKey' => 'xai-test', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    /** @return array<string, mixed> */
    private static function body(ChatXAI $model, int $request = 0): array
    {
        return (array) json_decode($model->httpClient->requests[$request]['body'], true);
    }

    public function testUnsupportedSamplingParametersAreStrippedFromTheRequest(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: [
            'temperature' => 0.2, 'frequencyPenalty' => 0.5, 'presencePenalty' => 0.4,
        ]);

        $model->invoke('hi');

        $body = self::body($model);
        self::assertSame(0.2, $body['temperature']);
        self::assertArrayNotHasKey('frequency_penalty', $body);
        self::assertArrayNotHasKey('presence_penalty', $body);
        self::assertArrayNotHasKey('logit_bias', $body);
        self::assertArrayNotHasKey('functions', $body);
    }

    public function testCompletionWithRetryStripsTheFourRejectedFieldsAndFillsEmptyContent(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);

        $model->completionWithRetry([
            'model' => 'grok-3',
            'frequency_penalty' => 1, 'presence_penalty' => 1, 'logit_bias' => ['1' => 1], 'functions' => [['name' => 'f']],
            'messages' => [
                ['role' => 'user', 'content' => 'hi'],
                ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'f', 'arguments' => '{}']]]],
                ['role' => 'assistant'],
                ['role' => 'user', 'content' => ''],
            ],
        ]);

        $body = self::body($model);
        self::assertSame(['model', 'messages'], array_keys($body));
        self::assertSame(['hi', '', '', ''], array_column($body['messages'], 'content'));
        self::assertArrayHasKey('tool_calls', $body['messages'][1]);
    }

    public function testTheLiveSearchToolBecomesSearchParametersAndLeavesTools(): void
    {
        $function = ['type' => 'function', 'function' => ['name' => 'lookup', 'description' => 'd', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)]);
        $bound = $model->bindTools([
            LiveSearchTool::create(['maxSearchResults' => 3, 'returnCitations' => true]),
            $function,
        ]);

        $bound->invoke('news?');

        $body = self::body($bound);
        self::assertSame(['mode' => 'auto', 'max_search_results' => 3, 'return_citations' => true], $body['search_parameters']);
        self::assertSame([$function], $body['tools']);
    }

    public function testWhenLiveSearchIsTheOnlyToolTheToolsKeyIsDropped(): void
    {
        $bound = self::model([FakeHttpClient::json(200, self::COMPLETION)])->bindTools([LiveSearchTool::create()]);

        $bound->invoke('news?');

        $body = self::body($bound);
        self::assertArrayNotHasKey('tools', $body);
        self::assertSame(['mode' => 'auto'], $body['search_parameters']);
    }

    public function testAgenticToolsPassThroughToTheRequestUntouched(): void
    {
        $bound = self::model([FakeHttpClient::json(200, self::COMPLETION)])->bindTools([WebSearch::create(['allowedDomains' => ['x.ai']])]);

        $bound->invoke('hi');

        self::assertSame([['type' => 'web_search', 'allowed_domains' => ['x.ai']]], self::body($bound)['tools']);
    }

    public function testBoundSearchParametersOverrideTheInstanceDefaultsOnTheWire(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: [
            'searchParameters' => ['mode' => 'auto', 'max_search_results' => 5],
        ]);
        $bound = $model->bindTools([], ['searchParameters' => ['max_search_results' => 7]]);

        $bound->invoke('one');

        self::assertSame(['mode' => 'auto', 'max_search_results' => 7], self::body($bound)['search_parameters']);
    }

    public function testReasoningContentIsSurfacedOnANonStreamedMessage(): void
    {
        $completion = self::COMPLETION;
        $completion['choices'][0]['message']['reasoning_content'] = 'thinking it over';
        $model = self::model([FakeHttpClient::json(200, $completion)]);

        $message = $model->invoke('hi');

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('thinking it over', $message->additional_kwargs['reasoning_content']);
    }

    public function testAnErrorResponseIsRaisedAsTheProvidersOwnException(): void
    {
        $model = self::model([FakeHttpClient::json(401, ['error' => ['message' => 'bad key']])]);

        $this->expectException(OpenAIException::class);

        $model->invoke('hi');
    }

    // ---- end to end -------------------------------------------------------

    public function testAChainRunsThroughXai(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::COMPLETION)], fields: ['model' => 'grok-3']);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Translate to {language}.'], ['human', '{text}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $answer = $chain->invoke(['language' => 'French', 'text' => 'Hello.']);

        self::assertSame('Bonjour.', $answer);
        self::assertSame('https://api.x.ai/v1/chat/completions', $model->httpClient->requests[0]['url']);
        self::assertSame('grok-3', self::body($model)['model']);
        self::assertSame([
            ['role' => 'system', 'content' => 'Translate to French.'],
            ['role' => 'user', 'content' => 'Hello.'],
        ], self::body($model)['messages']);
    }

    public function testAToolCallingGraphRunsThroughXai(): void
    {
        $weather = tool(
            static fn (array $in): string => 'Sunny in ' . $in['city'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );
        $call = ['id' => 'chatcmpl-a', 'model' => 'grok-3-fast', 'choices' => [[
            'index' => 0, 'finish_reason' => 'tool_calls',
            'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Austin"}']],
            ]],
        ]], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 3, 'total_tokens' => 7]];
        $final = ['id' => 'chatcmpl-b'] + ['choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'It is sunny.']]]] + $call;

        $model = self::model([FakeHttpClient::json(200, $call), FakeHttpClient::json(200, $final)]);
        $withTools = $model->bindTools([$weather, LiveSearchTool::create(['maxSearchResults' => 2])]);

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
        self::assertInstanceOf(ToolMessage::class, $messages[2]);
        self::assertSame('Sunny in Austin', $messages[2]->content);
        self::assertSame('It is sunny.', $messages[3]->content);

        // The function tool was offered in the Chat Completions shape; the live_search tool became search_parameters.
        $first = self::body($model, 0);
        self::assertSame('get_weather', $first['tools'][0]['function']['name']);
        self::assertCount(1, $first['tools']);
        self::assertSame(['mode' => 'auto', 'max_search_results' => 2], $first['search_parameters']);

        // The second request replayed the assistant's tool call and the tool's answer.
        $second = self::body($model, 1);
        self::assertSame(['user', 'assistant', 'tool'], array_column($second['messages'], 'role'));
        self::assertSame('call_1', $second['messages'][2]['tool_call_id']);
        self::assertSame('', $second['messages'][1]['content'] ?? '');
    }
}
