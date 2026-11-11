<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\BaseLanguageModel;
use LangChain\LanguageModels\BaseLLM;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\LLMs\OpenAI;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Load\Load;
use LangChain\Load\Validation;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\CallbackManager;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\Serialized;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeListChatModel;
use LangChain\Utils\Testing\FakeLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Model starts carry the model's public serialization, as upstream's
 * `handleChatModelStart(this.toJSON(), ...)` and `handleLLMStart(this.toJSON(), ...)` do.
 *
 * The four start boundaries are BaseChatModel::generateMessages/stream and
 * BaseLLM::generateStrings/stream; invoke, batch generate, cache hits and event
 * streams all reach them. The record is the model's own `toJson()`, adapted into
 * the typed Serialized container, so provider credential markers, escaped user
 * literals and overridden envelopes reach the handler exactly as serialized.
 * A manager with no handlers skips the record, as source skips `toJSON()` when
 * no manager exists.
 */
#[CoversClass(BaseChatModel::class)]
#[CoversClass(BaseLLM::class)]
final class ModelTraceSerializationTest extends TestCase
{
    private const LITERAL = ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']];

    private const SECRETS = [
        'OPENAI_API_KEY' => 'SYNTH_API_KEY',
        'OPENAI_ORGANIZATION' => 'SYNTH_ORGANIZATION',
        'ANTHROPIC_API_KEY' => 'SYNTH_API_KEY',
        'OPENROUTER_API_KEY' => 'SYNTH_API_KEY',
    ];

    /** Source lc_secrets of the opted-in providers, by serialized key. */
    private const MARKERS = [
        ChatOpenAI::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
        ChatOpenAICompletions::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
        ChatOpenAIResponses::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
        OpenAI::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
        ChatAnthropic::class => ['anthropic_api_key' => 'ANTHROPIC_API_KEY', 'api_key' => 'ANTHROPIC_API_KEY'],
        ChatOpenRouter::class => ['api_key' => 'OPENROUTER_API_KEY'],
    ];

    /** Expected start/end/error counts and fake requests (eager, streaming) per flow. */
    private const FLOWS = [
        'invoke' => [[1, 1, 0], [1, 0]],
        'generate' => [[2, 2, 0], [2, 0]],
        'stream' => [[1, 1, 0], [0, 1]],
        'events v1' => [[1, 1, 0], [0, 1]],
        'events v2' => [[1, 1, 0], [0, 1]],
        'cached' => [[2, 2, 0], [1, 0]],
    ];

    /** @var list<FakeHttpClient> */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $http) {
            self::assertSame([], $http->responses, 'every scripted eager response is consumed, none left over');
        }
    }

    private static function snap(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /** The exact record a handler should see: the public toJson() in the typed container. */
    private static function expectedStart(BaseLanguageModel $model): array
    {
        return self::snap(Serialized::fromArray($model->toJson())->toArray());
    }

    /** @return array{0: FakeHttpClient, 1: BaseLanguageModel} */
    private function provider(string $class, int $eager, int $streams, array $extra = []): array
    {
        $chat = ['id' => 'SYNTH_ID', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-synthetic',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'SYNTH_OUTPUT'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
        $chatSse = [];
        foreach (['SYNTH_', 'OUTPUT'] as $piece) {
            $chatSse[] = 'data: ' . json_encode(['id' => 'SYNTH_ID', 'model' => 'gpt-synthetic', 'choices' => [['index' => 0, 'delta' => ['content' => $piece]]]]) . "\n\n";
        }
        $chatSse[] = 'data: ' . json_encode(['id' => 'SYNTH_ID', 'model' => 'gpt-synthetic', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n";
        $chatSse[] = "data: [DONE]\n\n";
        $events = static fn (array $list): array => array_map(
            static fn (array $e): string => 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n",
            $list,
        );

        $fields = ['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_API_KEY', 'maxRetries' => 0, 'modelKwargs' => ['literal' => self::LITERAL]];
        [$post, $sse] = [$chat, $chatSse];
        switch ($class) {
            case ChatOpenAI::class:
            case ChatOpenAICompletions::class:
                $fields['organization'] = 'SYNTH_ORGANIZATION';
                break;
            case ChatOpenAIResponses::class:
                $fields['organization'] = 'SYNTH_ORGANIZATION';
                $post = ['id' => 'SYNTH_ID', 'object' => 'response', 'status' => 'completed', 'model' => 'gpt-synthetic',
                    'output' => [['type' => 'message', 'id' => 'SYNTH_MSG', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'SYNTH_OUTPUT', 'annotations' => []]]]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]];
                $sse = $events([
                    ['type' => 'response.created', 'response' => ['id' => 'SYNTH_ID', 'model' => 'gpt-synthetic']],
                    ['type' => 'response.output_text.delta', 'delta' => 'SYNTH_OUTPUT', 'content_index' => 0, 'output_index' => 0],
                    ['type' => 'response.completed', 'response' => $post],
                ]);
                break;
            case OpenAI::class:
                $fields['organization'] = 'SYNTH_ORGANIZATION';
                $fields['model'] = 'gpt-3.5-turbo-instruct';
                $post = ['id' => 'SYNTH_ID', 'choices' => [['index' => 0, 'text' => 'SYNTH_OUTPUT', 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];
                $sse = array_map(
                    static fn (string $t): string => 'data: ' . json_encode(['id' => 'SYNTH_ID', 'choices' => [['index' => 0, 'text' => $t, 'finish_reason' => null]]]) . "\n\n",
                    ['SYNTH_', 'OUTPUT'],
                );
                break;
            case ChatAnthropic::class:
                $fields['model'] = 'claude-synthetic';
                unset($fields['modelKwargs']);
                $fields['invocationKwargs'] = ['literal' => self::LITERAL];
                $post = ['id' => 'SYNTH_ID', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-synthetic',
                    'content' => [['type' => 'text', 'text' => 'SYNTH_OUTPUT']], 'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
                $sse = $events([
                    ['type' => 'message_start', 'message' => ['id' => 'SYNTH_ID', 'usage' => ['input_tokens' => 1]]],
                    ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                    ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'SYNTH_OUTPUT']],
                    ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
                    ['type' => 'message_stop'],
                ]);
                break;
        }

        $http = new FakeHttpClient(array_fill(0, $eager, FakeHttpClient::json(200, $post)), $streams > 0 ? $sse : []);
        $this->clients[] = $http;

        return [$http, new $class($extra + $fields + ['httpClient' => $http])];
    }

    /** @param array<string, string> $markers */
    private static function assertSourceRecord(array $start, array $markers): void
    {
        foreach ($markers as $key => $secret) {
            self::assertSame(['lc' => 1, 'type' => 'secret', 'id' => [$secret]], $start['kwargs'][$key] ?? null, $key . ' marker');
        }
        $json = json_encode($start, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SYNTH_API_KEY', $json);
        self::assertStringNotContainsString('SYNTH_ORGANIZATION', $json);
        self::assertStringNotContainsString('SYNTH_EXTRA', $json, 'configurable fields stay out of the model record');
        self::assertSame(1, substr_count($json, Validation::LC_ESCAPED_KEY), 'the user literal is escaped exactly once');
        self::assertSame(count($markers) + 1, substr_count($json, '"type":"secret"'), 'markers plus the one escaped literal');
    }

    public static function providerFlows(): iterable
    {
        foreach (array_keys(self::MARKERS) as $class) {
            foreach (array_keys(self::FLOWS) as $flow) {
                yield substr(strrchr($class, '\\'), 1) . ' ' . $flow => [$class, $flow];
            }
        }
    }

    #[DataProvider('providerFlows')]
    public function testOptedProviderStartsCarryTheirPublicSourceRecord(string $class, string $flow): void
    {
        [[$starts, $ends, $errors], [$eager, $streams]] = self::FLOWS[$flow];
        if ($class === OpenAI::class && $flow === 'generate') {
            // The text completions client sends both prompts in one request and ends one run.
            [[$starts, $ends, $errors], [$eager, $streams]] = [[2, 1, 0], [1, 0]];
        }
        [$http, $model] = $this->provider($class, $eager, $streams, $flow === 'cached' ? ['cache' => new InMemoryCache()] : []);
        $handler = new ModelTraceRecordingHandler();
        $config = new RunnableConfig(callbacks: [$handler], configurable: ['SYNTH_EXTRA' => 'SYNTH_VALUE']);
        $raw = $model->kwargs();
        $cacheKey = (new \ReflectionMethod($model, 'serializedCacheKeyParametersForCall'))->invoke($model, []);
        $chat = $model instanceof BaseChatModel;

        $events = [];
        match ($flow) {
            'invoke' => $model->invoke('SYNTH_INPUT', $config),
            'generate' => $chat
                ? $model->generateMessages([[new HumanMessage('SYNTH_A')], [new HumanMessage('SYNTH_B')]], [], null, $config)
                : $model->generateStrings(['SYNTH_A', 'SYNTH_B'], $config),
            'stream' => iterator_to_array($model->stream('SYNTH_INPUT', $config), false),
            'events v1', 'events v2' => (static function () use ($model, $config, $flow, &$events): void {
                foreach ($model->streamEvents('SYNTH_INPUT', $config, substr($flow, -2)) as $event) {
                    $events[] = is_array($event) ? $event['event'] : $event->event;
                }
            })(),
            'cached' => [$model->invoke('SYNTH_INPUT', $config), $model->invoke('SYNTH_INPUT', $config)],
        };

        $expected = self::expectedStart($model);
        self::assertSame(self::snap($model->toJson()), $expected, 'the typed container keeps the whole public record');
        self::assertSame(array_fill(0, $starts, $expected), $handler->starts);
        self::assertSourceRecord($expected, self::MARKERS[$class]);
        self::assertSame([$starts, $ends, $errors], $handler->counts());
        self::assertCount($starts, array_unique($handler->runIds), 'each start is its own run');
        self::assertSame([$eager, $streams], [count($http->requests), count($http->streamRequests)]);
        if ($events !== []) {
            $kind = $flow === 'events v2' && $chat ? 'chat_model' : 'llm';
            self::assertSame('on_' . $kind . '_start', $events[0]);
            self::assertSame('on_' . $kind . '_end', $events[array_key_last($events)]);
        }
        self::assertSame($raw, $model->kwargs(), 'raw kwargs are not rewritten by tracing');
        self::assertSame($raw['modelKwargs'] ?? $raw['invocationKwargs'], ['literal' => self::LITERAL]);
        self::assertSame($cacheKey, (new \ReflectionMethod($model, 'serializedCacheKeyParametersForCall'))->invoke($model, []));
    }

    public static function optedClasses(): iterable
    {
        foreach (array_keys(self::MARKERS) as $class) {
            yield substr(strrchr($class, '\\'), 1) => [$class];
        }
    }

    /**
     * The traced record is a loadable source record: markers resolve from an
     * explicit map, the literal comes back raw, and re-serialization is stable.
     */
    #[DataProvider('optedClasses')]
    public function testTracedRecordRoundTripsThroughLoad(string $class): void
    {
        [, $model] = $this->provider($class, 1, 0);
        $handler = new ModelTraceRecordingHandler();
        $model->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));

        $restored = Load::load(json_encode($handler->starts[0], JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
        self::assertInstanceOf($class, $restored);
        self::assertSame('SYNTH_API_KEY', (new \ReflectionProperty($restored, 'apiKey'))->getValue($restored));
        self::assertSame($handler->starts[0], self::expectedStart($restored));
        $user = $restored->kwargs()['modelKwargs'] ?? $restored->kwargs()['invocationKwargs'];
        self::assertSame(['literal' => self::LITERAL], $user);
    }

    /** Request body, cache key and raw kwargs are the same whether or not a handler is attached. */
    #[DataProvider('optedClasses')]
    public function testTracingDoesNotChangeTheRequest(string $class): void
    {
        [$plainHttp, $plain] = $this->provider($class, 1, 0);
        [$tracedHttp, $traced] = $this->provider($class, 1, 0);
        $plain->invoke('SYNTH_INPUT');
        $traced->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [new ModelTraceRecordingHandler()]));

        self::assertEquals($plainHttp->requests, $tracedHttp->requests);
        $body = json_encode($tracedHttp->requests, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(Validation::LC_ESCAPED_KEY, $body);
        self::assertStringNotContainsString('"type":"secret","id":["' . array_values(self::MARKERS[$class])[0], $body);
        self::assertSame($plain->kwargs(), $traced->kwargs());
    }

    public function testBoundToolsAndWithConfigTraceTheBoundModelRecord(): void
    {
        [$http, $model] = $this->provider(ChatOpenAI::class, 2, 0);
        $tool = ['type' => 'function', 'function' => ['name' => 'SYNTH_TOOL', 'description' => 'SYNTH_DESCRIPTION', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $bound = $model->bindTools([$tool]);
        $handler = new ModelTraceRecordingHandler();

        $bound->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));
        $model->withConfig(['callbacks' => [$handler]])->invoke('SYNTH_INPUT');

        self::assertSame([self::expectedStart($bound), self::expectedStart($model)], $handler->starts);
        self::assertArrayHasKey('tools', $handler->starts[0]['kwargs']);
        self::assertArrayNotHasKey('tools', $handler->starts[1]['kwargs']);
        self::assertSourceRecord($handler->starts[0], self::MARKERS[ChatOpenAI::class]);
        self::assertSourceRecord($handler->starts[1], self::MARKERS[ChatOpenAI::class]);
        $bodies = array_map(static fn (array $r): array => json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR), $http->requests);
        self::assertSame('SYNTH_TOOL', $bodies[0]['tools'][0]['function']['name'] ?? null);
        self::assertArrayNotHasKey('tools', $bodies[1]);
    }

    public static function envelopes(): iterable
    {
        $record = static fn (int $lc, string $type, array $id, array $kwargs): array => ['lc' => $lc, 'type' => $type, 'id' => $id, 'kwargs' => $kwargs];
        $escaped = ['escaped' => [Validation::LC_ESCAPED_KEY => self::LITERAL]];
        $field = ['SYNTH_FIELD' => 'SYNTH_SERIALIZED'];
        foreach (['invoke', 'generate', 'stream'] as $flow) {
            yield "chat toJson override $flow" => [ModelTraceEnvelopeChat::class, $flow, $record(77, 'not_implemented', ['SYNTH_OVERRIDE', 'chat'], $escaped)];
            yield "llm toJson override $flow" => [ModelTraceEnvelopeLLM::class, $flow, $record(77, 'not_implemented', ['SYNTH_OVERRIDE', 'llm'], $escaped)];
            yield "chat constructor override $flow" => [ModelTraceConstructorChat::class, $flow, $record(1, 'constructor', ['SYNTH_CONSTRUCTOR', 'chat'], $field)];
            yield "llm constructor override $flow" => [ModelTraceConstructorLLM::class, $flow, $record(1, 'constructor', ['SYNTH_CONSTRUCTOR', 'llm'], $field)];
            // Source not_implemented has no kwargs; the typed PHP container adds an empty one.
            yield "chat missing kwargs $flow" => [ModelTraceNotImplementedChat::class, $flow, $record(1, 'not_implemented', ['SYNTH_NOT_IMPLEMENTED', 'chat'], [])];
            yield "llm missing kwargs $flow" => [ModelTraceNotImplementedLLM::class, $flow, $record(1, 'not_implemented', ['SYNTH_NOT_IMPLEMENTED', 'llm'], [])];
        }
    }

    /**
     * An overridden envelope reaches the handler whole: lc, type and id are
     * not rebuilt from the class id or a default constructor shape.
     */
    #[DataProvider('envelopes')]
    public function testOverriddenEnvelopesAreForwardedAtEveryBoundary(string $class, string $flow, array $record): void
    {
        $model = new $class();
        $handler = new ModelTraceRecordingHandler();
        $config = new RunnableConfig(callbacks: [$handler]);

        match ($flow) {
            'invoke' => $model->invoke('SYNTH_INPUT', $config),
            'generate' => $model instanceof BaseChatModel
                ? $model->generateMessages([[new HumanMessage('SYNTH_A')], [new HumanMessage('SYNTH_B')]], [], null, $config)
                : $model->generateStrings(['SYNTH_A', 'SYNTH_B'], $config),
            'stream' => iterator_to_array($model->stream('SYNTH_INPUT', $config), false),
        };

        $starts = $flow === 'generate' ? 2 : 1;
        self::assertSame(array_fill(0, $starts, $record), $handler->starts);
        self::assertSame([$starts, $starts, 0], $handler->counts());
        self::assertSame($flow === 'stream' ? 2 : 0, $handler->tokens);
    }

    public static function defaultSerializers(): iterable
    {
        $azure = ['azureOpenAIApiKey' => 'SYNTH_AZURE_KEY', 'azureOpenAIEndpoint' => 'https://synthetic.invalid',
            'azureOpenAIApiDeploymentName' => 'SYNTH_DEPLOYMENT', 'azureOpenAIApiVersion' => 'SYNTH_VERSION'];
        yield 'azure chat' => [AzureChatOpenAI::class, $azure];
        yield 'xai chat' => [ChatXAI::class, []];
    }

    /**
     * Non-opted providers keep their existing public serializers: Azure's own
     * marker/alias pipeline, xAI's plain escaped kwargs with no new marker.
     */
    #[DataProvider('defaultSerializers')]
    public function testNonOptedProvidersForwardTheirExistingSerializer(string $class, array $extra): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'SYNTH_ID', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-synthetic',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'SYNTH_OUTPUT'], 'finish_reason' => 'stop']]])]);
        $this->clients[] = $http;
        $model = new $class($extra + ['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_API_KEY', 'maxRetries' => 0,
            'modelKwargs' => ['literal' => self::LITERAL], 'httpClient' => $http]);
        $handler = new ModelTraceRecordingHandler();
        $model->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));

        self::assertSame([self::expectedStart($model)], $handler->starts);
        $json = json_encode($handler->starts[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SYNTH_API_KEY', $json);
        self::assertSame(1, substr_count($json, Validation::LC_ESCAPED_KEY));
        if ($class === ChatXAI::class) {
            self::assertSame(self::snap(Validation::escapeKwargs($model->kwargs(), $model)), $handler->starts[0]['kwargs']);
            self::assertSame(1, substr_count($json, '"type":"secret"'), 'only the escaped literal, no xAI marker');
        } else {
            self::assertSame(['lc' => 1, 'type' => 'secret', 'id' => ['AZURE_OPENAI_API_KEY']], $handler->starts[0]['kwargs']['azure_open_ai_api_key']);
            self::assertSame('SYNTH_DEPLOYMENT', $handler->starts[0]['kwargs']['deployment_name']);
            self::assertStringNotContainsString('SYNTH_AZURE_KEY', $json);
        }
    }

    public static function genericModels(): iterable
    {
        foreach (['invoke', 'generate', 'stream', 'events v1', 'events v2', 'cached'] as $flow) {
            yield "chat $flow" => [ModelTraceGenericChat::class, $flow];
            yield "direct llm $flow" => [ModelTraceGenericLLM::class, $flow];
        }
        yield 'fake list chat' => [FakeListChatModel::class, 'invoke'];
        yield 'fake llm' => [FakeLLM::class, 'invoke'];
    }

    /**
     * Default models forward the established escaped kwargs: typed and
     * secret-shaped user values are wrapped once, raw falsy values unchanged,
     * and the raw runtime kwargs keep their native values.
     */
    #[DataProvider('genericModels')]
    public function testGenericModelsForwardTheEscapedDefaultRecord(string $class, string $flow): void
    {
        $model = match ($class) {
            FakeListChatModel::class => new FakeListChatModel(['responses' => ['SYNTH_OUTPUT']]),
            FakeLLM::class => new FakeLLM(['response' => 'SYNTH_OUTPUT']),
            default => new $class($flow === 'cached' ? ['cache' => new InMemoryCache()] : []),
        };
        $handler = new ModelTraceRecordingHandler();
        $config = new RunnableConfig(callbacks: [$handler]);
        $chat = $model instanceof BaseChatModel;

        match ($flow) {
            'invoke' => $model->invoke('SYNTH_INPUT', $config),
            'generate' => $chat
                ? $model->generateMessages([[new HumanMessage('SYNTH_A')], [new HumanMessage('SYNTH_B')]], [], null, $config)
                : $model->generateStrings(['SYNTH_A', 'SYNTH_B'], $config),
            'stream' => iterator_to_array($model->stream('SYNTH_INPUT', $config), false),
            'events v1', 'events v2' => iterator_to_array($model->streamEvents('SYNTH_INPUT', $config, substr($flow, -2)), false),
            'cached' => [$model->invoke('SYNTH_INPUT', $config), $model->invoke('SYNTH_INPUT', $config)],
        };

        $starts = in_array($flow, ['generate', 'cached'], true) ? 2 : 1;
        $expected = self::expectedStart($model);
        self::assertSame(array_fill(0, $starts, $expected), $handler->starts);
        self::assertSame([$starts, $starts, 0], $handler->counts());
        self::assertSame(self::snap(Validation::escapeKwargs($model->kwargs(), $model)), $expected['kwargs']);
        if (!$model instanceof ModelTraceGenericChat && !$model instanceof ModelTraceGenericLLM) {
            return;
        }
        $kwargs = $expected['kwargs'];
        self::assertSame([Validation::LC_ESCAPED_KEY => self::LITERAL], $kwargs['literal']);
        self::assertSame([Validation::LC_ESCAPED_KEY => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_TYPED_LITERAL']]], $kwargs['typedJson']);
        self::assertSame([Validation::LC_ESCAPED_KEY => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_PLAIN_LITERAL']]], $kwargs['typedPlain']);
        self::assertSame(self::snap(new \DateTimeImmutable('2000-01-01T00:00:00Z')), $kwargs['typedDate']);
        self::assertSame(['zero' => 0, 'nothing' => null, 'no' => false, 'empty' => '', 'none' => []], array_intersect_key($kwargs, array_flip(['zero', 'nothing', 'no', 'empty', 'none'])));
        self::assertSame(self::LITERAL, $model->kwargs()['literal'], 'raw kwargs keep the user literal unwrapped');
        self::assertInstanceOf(\DateTimeImmutable::class, $model->kwargs()['typedDate']);
    }

    public function testFailedRequestsAndAbandonedStreamsKeepTheirLifecycle(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(500, ['error' => ['message' => 'SYNTH_FAILURE']])]);
        $failing = new ChatOpenAI(['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_API_KEY', 'maxRetries' => 0, 'httpClient' => $http]);
        $handler = new ModelTraceRecordingHandler();
        try {
            $failing->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));
            self::fail('the scripted 500 must surface');
        } catch (\Throwable $e) {
            self::assertStringContainsString('SYNTH_FAILURE', $e->getMessage());
        }
        self::assertSame([self::expectedStart($failing)], $handler->starts);
        self::assertSame([1, 0, 1], $handler->counts());

        [, $streaming] = $this->provider(ChatOpenAI::class, 0, 1);
        $abandoned = new ModelTraceRecordingHandler();
        foreach ($streaming->stream('SYNTH_INPUT', new RunnableConfig(callbacks: [$abandoned])) as $chunk) {
            break;
        }
        self::assertSame([self::expectedStart($streaming)], $abandoned->starts);
        self::assertSame([1, 1, 0], $abandoned->counts());

        $llm = new ModelTraceGenericLLM();
        $llmAbandoned = new ModelTraceRecordingHandler();
        foreach ($llm->stream('SYNTH_INPUT', new RunnableConfig(callbacks: [$llmAbandoned])) as $chunk) {
            break;
        }
        self::assertSame([self::expectedStart($llm)], $llmAbandoned->starts);
    }

    public static function failingSerializers(): iterable
    {
        foreach ([ModelTraceFailingChat::class, ModelTraceFailingLLM::class] as $class) {
            $kind = $class === ModelTraceFailingChat::class ? 'chat' : 'llm';
            yield "$kind invoke" => [$class, 'invoke'];
            yield "$kind stream" => [$class, 'stream'];
        }
    }

    /**
     * Source builds the start argument from toJSON(), so a throwing serializer
     * fails a traced call before generation instead of being skipped or swallowed.
     */
    #[DataProvider('failingSerializers')]
    public function testSerializerFailureSurfacesAtATracedStart(string $class, string $flow): void
    {
        $model = new $class();
        $handler = new ModelTraceRecordingHandler();
        try {
            $flow === 'invoke'
                ? $model->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]))
                : iterator_to_array($model->stream('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler])), false);
            self::fail('the serializer failure must not be swallowed');
        } catch (ModelTraceSerializationFailure $e) {
            self::assertSame('SYNTH_SERIALIZATION_FAILURE', $e->getMessage());
        }
        self::assertSame(1, $model->serializations);
        self::assertSame(0, $model->generations, 'no generation once the start argument fails');
        self::assertSame([], $handler->starts);
        self::assertSame([0, 0, 0], $handler->counts());
    }

    public static function untracedCalls(): iterable
    {
        // [flow, explicit empty callbacks?, expected handler count per configured manager (null: no manager), generations]
        $flows = [
            ['invoke', false, [0], 1], ['invoke', true, [0], 1],
            ['generate', false, [0], 2], ['generate', true, [0], 2],
            ['batch', false, [0, 0], 2], ['batch', true, [0, 0], 2],
            ['cached', false, [0, 0], 1], ['cached', true, [0, 0], 1],
            ['stream', false, [null], 1], ['stream', true, [0], 1],
        ];
        foreach ([ModelTraceFailingChat::class, ModelTraceFailingLLM::class] as $class) {
            $kind = $class === ModelTraceFailingChat::class ? 'chat' : 'llm';
            foreach ($flows as [$flow, $explicit, $managers, $generations]) {
                $how = $explicit ? 'explicit []' : 'absent';
                // generatePrompts() receives every prompt of an LLM generate at once
                $calls = $kind === 'llm' && $flow === 'generate' ? 1 : $generations;
                yield "$kind $flow, $how callbacks" => [$class, $flow, $explicit, $managers, $calls];
            }
        }
        yield 'llm generateStrings without config' => [ModelTraceFailingLLM::class, 'generateStrings', false, [null], 1];
    }

    /**
     * Source evaluates `this.toJSON()` only when a callback manager exists, and
     * a plain call has none. PHP configures an empty manager for absent and
     * explicit empty callbacks alike, so a start with no handlers skips the
     * record that no handler could observe. The configured manager is recorded
     * to prove the empty-manager path, not the null-manager path, was taken.
     */
    #[DataProvider('untracedCalls')]
    public function testCallsWithoutHandlersNeverConsultTheSerializer(string $class, string $flow, bool $explicit, array $managers, int $generations): void
    {
        $model = new $class($flow === 'cached' ? ['cache' => new InMemoryCache()] : []);
        $config = $explicit ? new RunnableConfig(callbacks: []) : null;
        $chat = $model instanceof BaseChatModel;

        $result = match ($flow) {
            'invoke' => [$model->invoke('SYNTH_INPUT', $config)],
            'generate' => $chat
                ? $model->generateMessages([[new HumanMessage('SYNTH_A')], [new HumanMessage('SYNTH_B')]], [], null, $config)->generations
                : $model->generatePrompt(['SYNTH_A', 'SYNTH_B'], [], $explicit ? [] : null)->generations,
            'generateStrings' => $model->generateStrings(['SYNTH_A', 'SYNTH_B'])->generations,
            'batch' => $model->batch(['SYNTH_A', 'SYNTH_B'], $config),
            'cached' => [$model->invoke('SYNTH_INPUT', $config), $model->invoke('SYNTH_INPUT', $config)],
            'stream' => iterator_to_array($model->stream('SYNTH_INPUT', $explicit ? new RunnableConfig() : null), false),
        };

        self::assertNotSame([], $result);
        self::assertSame($managers, $model->managers);
        self::assertSame(0, $model->serializations, 'no handler, so the record is never built');
        self::assertSame($generations, $model->generations);
    }

    /**
     * A non-empty handler list serializes even when every handler ignores LLM
     * events, as source does: the guard is about handlers, not about who listens.
     */
    public function testIgnoringHandlersStillReceiveASerializedStart(): void
    {
        foreach ([new ModelTraceFailingChat(), new ModelTraceFailingLLM()] as $model) {
            $handler = new ModelTraceRecordingHandler();
            $handler->ignoreLLM = true;
            try {
                $model->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));
                self::fail('a non-empty handler list must build the record');
            } catch (ModelTraceSerializationFailure $e) {
                self::assertSame('SYNTH_SERIALIZATION_FAILURE', $e->getMessage());
            }
            self::assertSame([[1], 1, 0], [$model->managers, $model->serializations, $model->generations]);
            self::assertSame([], $handler->starts);
        }
    }

    public static function metadataHazards(): iterable
    {
        foreach ([ModelTraceGenericChat::class, ModelTraceGenericLLM::class] as $class) {
            $kind = $class === ModelTraceGenericChat::class ? 'chat' : 'llm';
            foreach (['throwing', 'cycle', 'dag', 'counter'] as $hazard) {
                yield "$kind $hazard" => [$class, $hazard];
            }
        }
    }

    /**
     * Ordinary public constructor metadata reaches the serializer only on a
     * traced call. Untraced invoke and configured stream never walk it: a
     * throwing JsonSerializable, a self-referential array, a shared-object DAG
     * and a call counter all stay untouched. With one handler the same values
     * are walked, and a walk failure surfaces before generation, unswallowed.
     */
    #[DataProvider('metadataHazards')]
    public function testPublicMetadataIsOnlyWalkedForTracedCalls(string $class, string $hazard): void
    {
        $counter = new ModelTraceCountingJson();
        $metadata = ['SYNTH_name' => 'SYNTH_VALUE'];
        switch ($hazard) {
            case 'throwing':
                $metadata['SYNTH_hazard'] = new ModelTraceThrowingJson();
                break;
            case 'cycle':
                $metadata['SYNTH_hazard'] = &$metadata;
                break;
            case 'dag':
                $node = $counter;
                for ($depth = 0; $depth < 10; ++$depth) {
                    $node = (object) ['left' => $node, 'right' => $node];
                }
                $metadata['SYNTH_hazard'] = $node;
                break;
            case 'counter':
                $metadata['SYNTH_hazard'] = $counter;
                break;
        }
        $model = new $class(['metadata' => $metadata]);

        $model->invoke('SYNTH_INPUT');
        iterator_to_array($model->stream('SYNTH_INPUT', new RunnableConfig()), false);
        self::assertSame(2, $model->generations);
        self::assertSame(0, $counter->calls, 'untraced calls never walk the metadata');

        $handler = new ModelTraceRecordingHandler();
        try {
            $model->invoke('SYNTH_INPUT', new RunnableConfig(callbacks: [$handler]));
            self::assertContains($hazard, ['dag', 'counter'], 'only valid metadata completes a traced call');
        } catch (ModelTraceSerializationFailure $e) {
            self::assertSame(['throwing', 'SYNTH_METADATA_FAILURE'], [$hazard, $e->getMessage()]);
        } catch (\RuntimeException $e) {
            self::assertSame('cycle', $hazard);
            self::assertStringContainsString('512', $e->getMessage());
        }

        if (in_array($hazard, ['dag', 'counter'], true)) {
            self::assertGreaterThan(0, $counter->calls, 'a traced call walks the metadata');
            self::assertSame(3, $model->generations);
            self::assertSame([1, 1, 0], $handler->counts());
            self::assertSame([self::expectedStart($model)], $handler->starts);
        } else {
            self::assertSame(2, $model->generations, 'no generation once the start argument fails');
            self::assertSame([[], [0, 0, 0]], [$handler->starts, $handler->counts()]);
        }
    }
}

final class ModelTraceRecordingHandler extends BaseCallbackHandler
{
    /** @var list<array<string, mixed>> */
    public array $starts = [];

    /** @var list<string> */
    public array $runIds = [];

    /** @var list<string> */
    public array $lifecycle = [];

    public int $tokens = 0;

    public function __construct()
    {
        parent::__construct(['raiseError' => true]);
    }

    /** @return array{0: int, 1: int, 2: int} start, end and error counts */
    public function counts(): array
    {
        $count = array_count_values($this->lifecycle);

        return [$count['start'] ?? 0, $count['end'] ?? 0, $count['error'] ?? 0];
    }

    public function handleChatModelStart(Serialized $llm, array $messages, string $runId, ?string $parentRunId = null, array $extraParams = [], array $tags = [], array $metadata = [], ?string $runName = null): void
    {
        $this->start($llm, $runId);
    }

    public function handleLLMStart(Serialized $llm, array $prompts, string $runId, ?string $parentRunId = null, array $extraParams = [], array $tags = [], array $metadata = [], ?string $runName = null): void
    {
        $this->start($llm, $runId);
    }

    public function handleLLMNewToken(string $token, array $idx, string $runId, ?string $parentRunId = null, array $tags = [], array $fields = []): void
    {
        ++$this->tokens;
    }

    public function handleLLMEnd(LLMResult $output, string $runId, ?string $parentRunId = null, array $tags = [], array $extraParams = []): void
    {
        $this->lifecycle[] = 'end';
    }

    public function handleLLMError(\Throwable $error, string $runId, ?string $parentRunId = null, array $tags = [], array $extraParams = []): void
    {
        $this->lifecycle[] = 'error';
    }

    private function start(Serialized $llm, string $runId): void
    {
        $this->starts[] = json_decode(json_encode($llm->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->runIds[] = $runId;
        $this->lifecycle[] = 'start';
    }
}

final class ModelTraceSerializationFailure extends \RuntimeException
{
}

/** User kwargs a serializer must escape: secret-shaped literals, typed values and raw falsy values. */
trait ModelTraceSyntheticKwargs
{
    public int $generations = 0;

    private ?array $syntheticKwargs = null;

    public function kwargs(): array
    {
        return parent::kwargs() + ($this->syntheticKwargs ??= [
            'literal' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']],
            'typedDate' => new \DateTimeImmutable('2000-01-01T00:00:00Z'),
            'typedJson' => new ModelTraceJsonLiteral(),
            'typedPlain' => (object) ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_PLAIN_LITERAL']],
            'zero' => 0, 'nothing' => null, 'no' => false, 'empty' => '', 'none' => [],
        ]);
    }
}

final class ModelTraceJsonLiteral implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_TYPED_LITERAL']];
    }
}

class ModelTraceGenericChat extends BaseChatModel
{
    use ModelTraceSyntheticKwargs;

    public function llmType(): string
    {
        return 'SYNTH_chat';
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        ++$this->generations;

        return new ChatResult([new ChatGeneration(new AIMessage('SYNTH_OUTPUT'), 'SYNTH_OUTPUT')]);
    }

    protected function streamResponseChunks(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): \Generator
    {
        ++$this->generations;
        foreach (['SYNTH_', 'OUTPUT'] as $token) {
            yield new ChatGenerationChunk(new AIMessageChunk($token), $token);
            $runManager?->handleLLMNewToken($token);
        }
    }
}

class ModelTraceGenericLLM extends BaseLLM
{
    use ModelTraceSyntheticKwargs;

    public function llmType(): string
    {
        return 'SYNTH_llm';
    }

    protected function generatePrompts(array $prompts, array $options = [], ?CallbackManagerForLLMRun $runManager = null): LLMResult
    {
        ++$this->generations;

        return new LLMResult(array_map(static fn (): array => [new Generation('SYNTH_OUTPUT')], $prompts));
    }

    protected function streamResponseChunks(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): \Generator
    {
        ++$this->generations;
        foreach (['SYNTH_', 'OUTPUT'] as $token) {
            yield new GenerationChunk($token);
            $runManager?->handleLLMNewToken($token);
        }
    }
}

final class ModelTraceEnvelopeChat extends ModelTraceGenericChat
{
    public function toJson(): array
    {
        return ['lc' => 77, 'type' => 'not_implemented', 'id' => ['SYNTH_OVERRIDE', 'chat'],
            'kwargs' => ['escaped' => [Validation::LC_ESCAPED_KEY => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']]]]];
    }
}

final class ModelTraceEnvelopeLLM extends ModelTraceGenericLLM
{
    public function toJson(): array
    {
        return ['lc' => 77, 'type' => 'not_implemented', 'id' => ['SYNTH_OVERRIDE', 'llm'],
            'kwargs' => ['escaped' => [Validation::LC_ESCAPED_KEY => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']]]]];
    }
}

final class ModelTraceConstructorChat extends ModelTraceGenericChat
{
    public function toSerializedConstructor(): array
    {
        return ['lc' => 1, 'type' => 'constructor', 'id' => ['SYNTH_CONSTRUCTOR', 'chat'], 'kwargs' => ['SYNTH_FIELD' => 'SYNTH_SERIALIZED']];
    }
}

final class ModelTraceConstructorLLM extends ModelTraceGenericLLM
{
    public function toSerializedConstructor(): array
    {
        return ['lc' => 1, 'type' => 'constructor', 'id' => ['SYNTH_CONSTRUCTOR', 'llm'], 'kwargs' => ['SYNTH_FIELD' => 'SYNTH_SERIALIZED']];
    }
}

final class ModelTraceNotImplementedChat extends ModelTraceGenericChat
{
    public function toJson(): array
    {
        return ['lc' => 1, 'type' => 'not_implemented', 'id' => ['SYNTH_NOT_IMPLEMENTED', 'chat']];
    }
}

final class ModelTraceNotImplementedLLM extends ModelTraceGenericLLM
{
    public function toJson(): array
    {
        return ['lc' => 1, 'type' => 'not_implemented', 'id' => ['SYNTH_NOT_IMPLEMENTED', 'llm']];
    }
}

final class ModelTraceThrowingJson implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        throw new ModelTraceSerializationFailure('SYNTH_METADATA_FAILURE');
    }
}

final class ModelTraceCountingJson implements \JsonSerializable
{
    public int $calls = 0;

    public function jsonSerialize(): mixed
    {
        ++$this->calls;

        return 'SYNTH_COUNTED';
    }
}

/** Records the handler count of every manager a model configures, null when there is none. */
trait ModelTraceManagerProbe
{
    public int $serializations = 0;

    /** @var list<int|null> */
    public array $managers = [];

    protected function configureCallbacks(?RunnableConfig $config, array $metadata, array $invocationParams): ?CallbackManager
    {
        $manager = parent::configureCallbacks($config, $metadata, $invocationParams);
        $this->managers[] = $manager === null ? null : count($manager->handlers);

        return $manager;
    }
}

final class ModelTraceFailingChat extends ModelTraceGenericChat
{
    use ModelTraceManagerProbe;

    public function toJson(): array
    {
        ++$this->serializations;

        throw new ModelTraceSerializationFailure('SYNTH_SERIALIZATION_FAILURE');
    }
}

final class ModelTraceFailingLLM extends ModelTraceGenericLLM
{
    use ModelTraceManagerProbe;

    public function toJson(): array
    {
        ++$this->serializations;

        throw new ModelTraceSerializationFailure('SYNTH_SERIALIZATION_FAILURE');
    }
}
