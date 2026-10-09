<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\LanguageModels\Chat\XAI\Tools\WebSearch;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What goes over the wire to xAI's `/responses` endpoint, and real chains and graphs running through the client.
 */
#[CoversClass(ChatXAIResponses::class)]
final class ChatXAIResponsesEndToEndTest extends TestCase
{
    private const RESPONSE = [
        'id' => 'resp_1', 'object' => 'response', 'created_at' => 1, 'model' => 'grok-3', 'status' => 'completed',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Bonjour.']]]],
        'usage' => ['input_tokens' => 9, 'output_tokens' => 3, 'total_tokens' => 12],
    ];

    /**
     * @param list<\LangChain\Utils\Http\HttpResponse> $responses
     * @param list<string>                              $stream
     * @param array<string, mixed>                      $fields
     */
    private static function model(array $responses = [], array $stream = [], array $fields = []): ChatXAIResponses
    {
        return new ChatXAIResponses($fields + [
            'apiKey' => 'xai-test', 'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses, $stream),
        ]);
    }

    /** @return array<string, mixed> */
    private static function body(ChatXAIResponses $model, int $request = 0): array
    {
        return (array) json_decode($model->httpClient->requests[$request]['body'], true);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function sse(array $events): array
    {
        return array_map(
            static fn (array $e): string => 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n",
            $events,
        );
    }

    public function testInvokePostsToTheXaiResponsesEndpointWithTheKey(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $message = $model->invoke('hi');

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame('Bonjour.', $message->content);
        $request = $model->httpClient->requests[0];
        self::assertSame('https://api.x.ai/v1/responses', $request['url']);
        self::assertSame('Bearer xai-test', $request['headers']['Authorization']);
        self::assertSame('grok-3', self::body($model)['model']);
        self::assertSame([['role' => 'user', 'content' => 'hi']], self::body($model)['input']);
        self::assertFalse(self::body($model)['stream']);
    }

    public function testACustomBaseUrlIsUsed(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], fields: ['baseURL' => 'https://proxy.example.com/v1/']);

        $model->invoke('hi');

        self::assertSame('https://proxy.example.com/v1/responses', $model->httpClient->requests[0]['url']);
    }

    public function testTheMessageCarriesTheXaiMetadataAndUsage(): void
    {
        $message = self::model([FakeHttpClient::json(200, self::RESPONSE)])->invoke('hi');

        self::assertSame('xai', $message->response_metadata['model_provider']);
        self::assertSame('resp_1', $message->response_metadata['id']);
        self::assertSame(12, $message->response_metadata['usage_metadata']['total_tokens']);
    }

    public function testXaiBuiltInToolsReachTheWireUnchanged(): void
    {
        $tool = WebSearch::create(['allowedDomains' => ['wikipedia.org']]);
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)], fields: ['searchParameters' => ['mode' => 'on']]);

        $model->bindTools([$tool, ['type' => 'x_search', 'allowed_x_handles' => ['xai']]])->invoke('hi');

        $body = self::body($model);
        // `bindTools()` returns a clone that shares the scripted transport.
        self::assertSame([$tool, ['type' => 'x_search', 'allowed_x_handles' => ['xai']]], $body['tools']);
        self::assertSame(['mode' => 'on'], $body['search_parameters']);
    }

    public function testBindToolsRejectsFunctionTools(): void
    {
        $model = self::model([]);

        $this->expectException(\InvalidArgumentException::class);
        $model->bindTools([static fn (int $a): int => $a]);
    }

    public function testBindToolsRejectsFunctionTypeArrays(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::model([])->bindTools([['type' => 'function', 'name' => 'add', 'parameters' => []]]);
    }

    public function testWithStructuredOutputThrowsLikeUpstream(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Chat model must implement ".bindTools()" to use withStructuredOutput.');
        self::model([])->withStructuredOutput(['type' => 'object', 'properties' => []]);
    }

    public function testNonStreamingLlmOutputCarriesEstimatedTokenUsage(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $result = $model->generateMessages([[new \LangChain\Messages\HumanMessage('hi')]]);

        self::assertSame(['promptTokens' => 9, 'completionTokens' => 3, 'totalTokens' => 12], $result->llmOutput['estimatedTokenUsage'] ?? null);
        self::assertArrayNotHasKey('tokenUsage', $result->llmOutput);
    }

    public function testCallOptionsAndPriorTurnsAreConverted(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $model->invoke(
            [new HumanMessage('one'), new AIMessage('two'), new HumanMessage('three')],
            new \LangChain\Runnables\RunnableConfig(options: ['previous_response_id' => 'resp_0', 'reasoning' => ['effort' => 'high']]),
        );

        $body = self::body($model);
        self::assertSame('resp_0', $body['previous_response_id']);
        self::assertSame(['effort' => 'high'], $body['reasoning']);
        self::assertSame([
            ['role' => 'user', 'content' => 'one'],
            ['type' => 'message', 'role' => 'assistant', 'text' => 'two'],
            ['role' => 'user', 'content' => 'three'],
        ], $body['input']);
    }

    public function testAnErrorResponseIsRaisedAsTheProvidersOwnException(): void
    {
        $model = self::model([FakeHttpClient::json(401, ['error' => ['message' => 'bad key']])]);

        $this->expectException(OpenAIException::class);

        $model->invoke('hi');
    }

    public function testAChainRunsThroughXaiResponses(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Translate to {language}.'], ['human', '{text}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $answer = $chain->invoke(['language' => 'French', 'text' => 'Hello.']);

        self::assertSame('Bonjour.', $answer);
        self::assertSame([
            ['role' => 'system', 'content' => 'Translate to French.'],
            ['role' => 'user', 'content' => 'Hello.'],
        ], self::body($model)['input']);
    }

    public function testAGraphRunsThroughXaiResponses(): void
    {
        $model = self::model([FakeHttpClient::json(200, self::RESPONSE)]);

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', static fn (array $state): array => ['messages' => [$model->invoke($state['messages'])]])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage('say hello in French')]]);

        self::assertCount(2, $result['messages']);
        self::assertSame('Bonjour.', $result['messages'][1]->content);
    }

    public function testStreamingFoldsTheEventsIntoOneMessage(): void
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_s', 'model' => 'grok-3']],
            ['type' => 'response.output_text.delta', 'delta' => 'Bon', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'delta' => 'jour.', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => self::RESPONSE + []],
        ];
        $model = self::model(stream: self::sse($events), fields: ['streaming' => true]);

        $message = $model->invoke('hi');

        self::assertSame('Bonjour.', $message->content);
        self::assertSame('https://api.x.ai/v1/responses', $model->httpClient->streamRequests[0]['url']);
        self::assertTrue(((array) json_decode($model->httpClient->streamRequests[0]['body'], true))['stream']);
        self::assertSame(12, $message->response_metadata['usage_metadata']['total_tokens']);
    }

    public function testStreamYieldsTheTextChunksAsTheyArrive(): void
    {
        $events = [
            ['type' => 'response.output_text.delta', 'delta' => 'a', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'delta' => 'b', 'content_index' => 0, 'output_index' => 0],
        ];
        $model = self::model(stream: self::sse($events));

        $texts = [];
        foreach ($model->stream('hi') as [, $chunk]) {
            $texts[] = $chunk->content;
        }

        self::assertSame(['a', 'b'], $texts);
    }

    public function testAStreamedErrorEventIsRaised(): void
    {
        $model = self::model(stream: self::sse([['type' => 'error', 'code' => 'server_error', 'message' => 'boom']]));

        $this->expectException(OpenAIException::class);

        iterator_to_array($model->stream('hi'));
    }
}
