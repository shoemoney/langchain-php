<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Azure;

use LangChain\Embeddings\AzureOpenAIEmbeddings;
use LangChain\Embeddings\OpenAIEmbeddings;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\LLMs\AzureOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePassthrough;
use LangChain\Schema\Document;
use LangChain\Tools\Schema;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\VectorStores\MemoryVectorStore;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\Prebuilt\ToolsCondition;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The Azure clients and the embeddings running inside real chains and a real
 * graph: one Azure resource is faked, and everything above the transport is the
 * code under test.
 */
#[CoversNothing]
final class AzureEndToEndTest extends TestCase
{
    private const ENV = ['OPENAI_API_KEY', 'AZURE_OPENAI_API_KEY', 'AZURE_OPENAI_ENDPOINT', 'AZURE_OPENAI_API_INSTANCE_NAME', 'AZURE_OPENAI_BASE_PATH'];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /**
     * One Azure resource: embeddings count topic words, chat answers with what
     * it was shown, completions echo.
     *
     * @return HttpClient&object{requests: list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}>}
     */
    private static function resource(): HttpClient
    {
        return new class implements HttpClient {
            public array $requests = [];

            private int $completions = 0;

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];
                $request = json_decode($body, true);

                if (str_ends_with($url, '/embeddings')) {
                    $data = [];
                    foreach ((array) $request['input'] as $i => $text) {
                        $text = strtolower($text);
                        $data[] = ['index' => $i, 'embedding' => [
                            (float) substr_count($text, 'weather') + substr_count($text, 'sun'),
                            (float) substr_count($text, 'building') + substr_count($text, 'tall'),
                            0.01,
                        ]];
                    }

                    return FakeHttpClient::json(200, ['data' => $data]);
                }

                if (str_ends_with($url, '/completions') && !str_ends_with($url, '/chat/completions')) {
                    return FakeHttpClient::json(200, ['choices' => [['index' => 0, 'text' => 'completed: ' . $request['prompt'][0], 'finish_reason' => 'stop']]]);
                }

                $messages = $request['messages'];
                $last = end($messages);
                if (($last['role'] ?? '') === 'tool') {
                    $reply = ['role' => 'assistant', 'content' => 'It is ' . $last['content'] . '.'];
                    $finish = 'stop';
                } elseif (isset($request['tools'])) {
                    $reply = ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                        ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '{"city":"Austin"}']],
                    ]];
                    $finish = 'tool_calls';
                } else {
                    $reply = ['role' => 'assistant', 'content' => 'saw: ' . json_encode($messages)];
                    $finish = 'stop';
                }

                return FakeHttpClient::json(200, ['id' => 'chatcmpl-' . ++$this->completions, 'object' => 'chat.completion', 'model' => $request['model'], 'choices' => [
                    ['index' => 0, 'message' => $reply, 'finish_reason' => $finish],
                ], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]]);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };
    }

    /** @return array<string, mixed> */
    private static function azureFields(HttpClient $http): array
    {
        return [
            'azureOpenAIEndpoint' => 'https://res.openai.azure.com', 'azureOpenAIApiVersion' => '2024-10-21',
            'azureOpenAIApiKey' => 'azure-key', 'httpClient' => $http, 'maxRetries' => 0,
        ];
    }

    public function testARagChainAnswersFromDocumentsEmbeddedAndAskedThroughAzure(): void
    {
        $http = self::resource();
        $embeddings = new AzureOpenAIEmbeddings(self::azureFields($http) + ['azureOpenAIApiDeploymentName' => 'embed', 'azureOpenAIApiInstanceName' => 'res']);
        $store = MemoryVectorStore::fromTexts(
            ['sunny weather is happy weather', 'tall buildings are tall', 'a quiet library'],
            [[], [], []],
            $embeddings,
        );
        $retriever = $store->asRetriever(['k' => 1]);
        $chat = new AzureChatOpenAI('gpt-4o', self::azureFields($http));

        $chain = RunnableParallel::from([
            'context' => $retriever->pipe(RunnableLambda::from(
                static fn (array $docs): string => implode("\n", array_map(static fn (Document $d): string => $d->pageContent, $docs)),
            )),
            'question' => new RunnablePassthrough(),
        ])
            ->pipe(ChatPromptTemplate::fromMessages([['system', 'Answer from this context:\n{context}'], ['human', '{question}']]))
            ->pipe($chat)
            ->pipe(new StringOutputParser());

        $answer = $chain->invoke('what about the weather');

        self::assertStringContainsString('sunny weather is happy weather', $answer);
        self::assertStringNotContainsString('quiet library', $answer);

        $urls = array_values(array_unique(array_column($http->requests, 'url')));
        self::assertSame([
            'https://res.openai.azure.com/openai/deployments/embed/embeddings',
            'https://res.openai.azure.com/openai/deployments/gpt-4o/chat/completions',
        ], $urls);
        foreach ($http->requests as $request) {
            self::assertSame('azure-key', $request['headers']['api-key']);
            self::assertSame(['api-version' => '2024-10-21'], $request['query']);
        }
    }

    public function testAnAzureToolCallingGraphRunsToAnswer(): void
    {
        $http = self::resource();
        $weather = tool(
            static fn (array $in): string => 'sunny in ' . $in['city'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );
        $model = (new AzureChatOpenAI('gpt-4o', self::azureFields($http)))->bindTools([$weather]);

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', static fn (array $state): array => ['messages' => [$model->invoke($state['messages'])]])
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
        self::assertSame('sunny in Austin', $messages[2]->content);
        self::assertSame('It is sunny in Austin.', $messages[3]->content);

        self::assertCount(2, $http->requests);
        foreach ($http->requests as $request) {
            self::assertSame('https://res.openai.azure.com/openai/deployments/gpt-4o/chat/completions', $request['url']);
        }
        self::assertSame('get_weather', json_decode($http->requests[0]['body'], true)['tools'][0]['function']['name']);
    }

    public function testACompletionsLlmComposesWithAPromptAndAParser(): void
    {
        $http = self::resource();
        $llm = new AzureOpenAI(self::azureFields($http) + ['azureOpenAIApiDeploymentName' => 'instruct', 'modelName' => 'gpt-3.5-turbo-instruct']);

        $chain = PromptTemplate::fromTemplate('Name a company that makes {product}.')->pipe($llm)->pipe(new StringOutputParser());

        self::assertSame('completed: Name a company that makes socks.', $chain->invoke(['product' => 'socks']));
        self::assertSame('https://res.openai.azure.com/openai/deployments/instruct/completions', $http->requests[0]['url']);
    }

    public function testPlainOpenAIEmbeddingsFeedTheSameStore(): void
    {
        $http = self::resource();
        $embeddings = new OpenAIEmbeddings(['apiKey' => 'sk', 'httpClient' => $http, 'baseUrl' => 'https://proxy.example/v1', 'maxRetries' => 0]);

        $store = MemoryVectorStore::fromTexts(['sunny weather', 'tall buildings'], [[], []], $embeddings);
        $hits = $store->similaritySearch('weather', 1);

        self::assertSame('sunny weather', $hits[0]->pageContent);
        self::assertSame('https://proxy.example/v1/embeddings', $http->requests[0]['url']);
    }
}
