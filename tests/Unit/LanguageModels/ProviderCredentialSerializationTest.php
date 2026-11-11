<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\Fireworks\ChatFireworks;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\LanguageModels\LLMs\AzureOpenAI;
use LangChain\LanguageModels\LLMs\OpenAI;
use LangChain\Load\ImportMap;
use LangChain\Load\Load;
use LangChain\Load\Validation;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Provider credentials serialize as source secret markers, never as values.
 *
 * Pinned langchainjs 17b6bba: `Serializable.toJSON` escapes the captured kwargs,
 * then replaces each resolved `lc_secrets` field with `{lc:1,type:secret,id}`,
 * then maps keys through `lc_aliases`. Only the exact classes below opt in; every
 * inherited provider (Azure, xAI, DeepSeek, Fireworks, Together) and any user
 * subclass keeps the plain escaped kwargs until its own source contract is mapped.
 *
 * Every credential is synthetic, every transport throws if touched, and the
 * controlled environment variables are set or cleared explicitly per test.
 */
#[CoversClass(BaseLangChain::class)]
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatOpenAICompletions::class)]
#[CoversClass(ChatOpenAIResponses::class)]
#[CoversClass(OpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
#[CoversClass(ChatOpenRouter::class)]
#[CoversClass(ImportMap::class)]
final class ProviderCredentialSerializationTest extends TestCase
{
    private const ENV = ['OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'ANTHROPIC_API_KEY', 'OPENROUTER_API_KEY', 'OPENROUTER_SESSION_ID'];

    private const SECRETS = [
        'OPENAI_API_KEY' => 'SYNTH_RELOADED_OPENAI',
        'OPENAI_ORGANIZATION' => 'SYNTH_RELOADED_ORG',
        'ANTHROPIC_API_KEY' => 'SYNTH_RELOADED_ANTHROPIC',
        'OPENROUTER_API_KEY' => 'SYNTH_RELOADED_ROUTER',
        'SYNTH_LITERAL_ID' => 'SYNTH_MUST_NOT_SUBSTITUTE',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    private static int $transportCalls = 0;

    protected function setUp(): void
    {
        self::$transportCalls = 0;
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
        self::assertSame(0, self::$transportCalls, 'No serialization path may reach the transport.');
    }

    private static function marker(string $id): array
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => [$id]];
    }

    private static function throwingTransport(): HttpClient
    {
        return new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                ProviderCredentialSerializationTest::countTransportCall();

                throw new \LogicException('MUST NOT CALL');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                ProviderCredentialSerializationTest::countTransportCall();

                throw new \LogicException('MUST NOT CALL');
            }
        };
    }

    public static function countTransportCall(): void
    {
        ++self::$transportCalls;
    }

    /** @return array<string, mixed> */
    private static function record(BaseLangChain $model): array
    {
        $json = $model->toJson();
        self::assertSame($json, json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        return $json;
    }

    /** @return array<string, mixed> */
    private static function credentialEntries(array $kwargs): array
    {
        return array_filter($kwargs, static fn (mixed $v): bool => is_array($v) && ($v['type'] ?? null) === 'secret' && ($v['lc'] ?? null) === 1);
    }

    private static function load(array $record, array $secrets = self::SECRETS): object
    {
        return Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => $secrets]);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): BaseLangChain, array<string, mixed>, array<string, string>}>
     */
    public static function optedIn(): iterable
    {
        $openAIChat = ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'];
        yield 'ChatOpenAI' => [static fn (array $f): BaseLangChain => new ChatOpenAI($f), ['model' => 'gpt-synthetic', 'organization' => 'SYNTH_ORGANIZATION_LITERAL'], $openAIChat];
        yield 'ChatOpenAICompletions' => [static fn (array $f): BaseLangChain => new ChatOpenAICompletions($f), ['model' => 'gpt-synthetic', 'organization' => 'SYNTH_ORGANIZATION_LITERAL'], $openAIChat];
        yield 'ChatOpenAIResponses' => [static fn (array $f): BaseLangChain => new ChatOpenAIResponses($f), ['model' => 'gpt-synthetic', 'organization' => 'SYNTH_ORGANIZATION_LITERAL'], $openAIChat];
        yield 'OpenAI' => [static fn (array $f): BaseLangChain => new OpenAI($f), ['model' => 'gpt-3.5-turbo-instruct', 'configuration' => ['organization' => 'SYNTH_ORGANIZATION_LITERAL']], $openAIChat];
        yield 'ChatAnthropic' => [static fn (array $f): BaseLangChain => new ChatAnthropic($f), ['model' => 'claude-synthetic'], ['anthropic_api_key' => 'ANTHROPIC_API_KEY', 'api_key' => 'ANTHROPIC_API_KEY']];
        yield 'ChatOpenRouter' => [static fn (array $f): BaseLangChain => new ChatOpenRouter($f), ['model' => 'synthetic/model'], ['api_key' => 'OPENROUTER_API_KEY']];
    }

    /** @param \Closure(array<string, mixed>): BaseLangChain $make */
    #[DataProvider('optedIn')]
    public function testExplicitCredentialsBecomeExactSourceMarkersAndNeverRawValues(\Closure $make, array $fields, array $expected): void
    {
        $model = $make($fields + ['apiKey' => 'SYNTH_EXPLICIT', 'temperature' => 0.5, 'httpClient' => self::throwingTransport()]);
        $json = self::record($model);

        $markers = self::credentialEntries($json['kwargs']);
        $wanted = array_map(self::marker(...), $expected);
        ksort($markers);
        ksort($wanted);
        self::assertSame($wanted, $markers);
        foreach (['apiKey', 'openAIApiKey', 'anthropicApiKey', 'openrouter_api_key', 'httpClient', 'http_client'] as $absent) {
            self::assertArrayNotHasKey($absent, $json['kwargs']);
        }
        $encoded = json_encode($model, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('SYNTH_EXPLICIT', $encoded);
        self::assertStringNotContainsString('SYNTH_ORGANIZATION_LITERAL', $encoded);
        self::assertStringNotContainsString('__lc_escaped__', $encoded, 'generated markers are trusted, never wrapped');

        // Everything that is not a credential is exactly the escaped captured kwargs, in order.
        $plain = Validation::escapeKwargs($model->kwargs(), $model);
        self::assertSame(array_diff_key($plain, $expected + ['organization' => true]), array_diff_key($json['kwargs'], $expected));
    }

    /** @param \Closure(array<string, mixed>): BaseLangChain $make */
    #[DataProvider('optedIn')]
    public function testSelfLoadResolvesOnlyFromSecretsMapAndReSerializesIdentically(\Closure $make, array $fields, array $expected): void
    {
        $model = $make($fields + ['apiKey' => 'SYNTH_EXPLICIT']);
        $first = self::record($model);
        $loaded = self::load($first);
        self::assertSame($model::class, $loaded::class);
        $secret = self::SECRETS[reset($expected)];
        self::assertSame($secret, $loaded->apiKey);
        $second = self::record($loaded);
        // A reloaded credential is captured where the record put it, so the first
        // round may reorder keys (text OpenAI: `configuration.organization` becomes a
        // captured `organization`); from then on the record is a fixed point.
        $sorted = static function (array $record): array {
            ksort($record['kwargs']);

            return $record;
        };
        self::assertSame($sorted($first), $sorted($second));
        self::assertSame($second, self::record(self::load($second)));
    }

    /** @param \Closure(array<string, mixed>): BaseLangChain $make */
    #[DataProvider('optedIn')]
    public function testMissingSecretsMapEntryIsTheExistingDiagnosticNotAnEnvironmentRead(\Closure $make, array $fields, array $expected): void
    {
        $record = self::record($make($fields + ['apiKey' => 'SYNTH_EXPLICIT']));
        foreach (self::ENV as $name) {
            putenv($name . '=SYNTH_ENV_MUST_NOT_LOAD');
        }
        $first = array_key_first($expected);
        $secrets = array_diff_key(self::SECRETS, [$expected[$first] => true]);

        try {
            self::load($record, $secrets);
            self::fail('A marker without a secretsMap entry must not load.');
        } catch (\RuntimeException $e) {
            self::assertSame('Missing secret "' . $expected[$first] . '" at $.kwargs.' . $first, $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment secret resolution is not supported; supply secretsMap.');
        Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS, 'secretsFromEnv' => true]);
    }

    public function testResolvedEnvironmentKeyIsMarkedAndAMissingOneIsOmitted(): void
    {
        putenv('OPENAI_API_KEY=SYNTH_ENV_OPENAI');
        putenv('ANTHROPIC_API_KEY=SYNTH_ENV_ANTHROPIC');
        putenv('OPENROUTER_API_KEY=SYNTH_ENV_ROUTER');
        $fromEnv = [
            new ChatOpenAI(['model' => 'gpt-synthetic']),
            new OpenAI(['model' => 'gpt-3.5-turbo-instruct']),
            new ChatAnthropic(['model' => 'claude-synthetic']),
            new ChatOpenRouter(['model' => 'synthetic/model']),
        ];
        foreach ($fromEnv as $model) {
            self::assertNotSame([], self::credentialEntries(self::record($model)['kwargs']), $model::class);
            self::assertStringNotContainsString('SYNTH_ENV_', json_encode($model, JSON_THROW_ON_ERROR));
        }

        foreach (self::ENV as $name) {
            putenv($name);
        }
        // Source omits an undefined credential; PHP's unresolved null is that state.
        foreach ([new ChatOpenAI(['model' => 'gpt-synthetic']), new OpenAI(['model' => 'gpt-3.5-turbo-instruct']), new ChatAnthropic(['model' => 'claude-synthetic'])] as $model) {
            self::assertSame([], self::credentialEntries(self::record($model)['kwargs']), $model::class);
        }
    }

    public function testCurrentCredentialPropertyDecidesTheMarkerNotTheConstructorSnapshot(): void
    {
        $model = new ChatAnthropic(['model' => 'claude-synthetic']);
        self::assertSame([], self::credentialEntries(self::record($model)['kwargs']));

        $model->apiKey = 'SYNTH_ASSIGNED_LATER';
        self::assertSame(['anthropic_api_key' => self::marker('ANTHROPIC_API_KEY'), 'api_key' => self::marker('ANTHROPIC_API_KEY')], self::credentialEntries(self::record($model)['kwargs']));

        // A defined empty string is still a credential value in source (marked, not omitted).
        $model->apiKey = '';
        self::assertCount(2, self::credentialEntries(self::record($model)['kwargs']));

        // PHP adaptation: without property hooks an assigned null cannot be told apart from
        // "never resolved", so it is treated as source undefined and omitted. Source would
        // still mark a JS null; this is the documented ambiguity, not parity.
        $model->apiKey = null;
        self::assertSame([], self::credentialEntries(self::record($model)['kwargs']));

        $chat = new ChatOpenAI(['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT']);
        self::assertArrayNotHasKey('organization', self::record($chat)['kwargs']);
        $chat->organization = 'SYNTH_ORG_ASSIGNED';
        self::assertSame(self::marker('OPENAI_ORGANIZATION'), self::record($chat)['kwargs']['organization']);

        $router = new ChatOpenRouter(['model' => 'synthetic/model', 'apiKey' => 'SYNTH_EXPLICIT']);
        $router->apiKey = '';
        self::assertSame(['api_key' => self::marker('OPENROUTER_API_KEY')], self::credentialEntries(self::record($router)['kwargs']));
    }

    public function testTextOpenAIBothSourceCredentialSpellingsCollapseToOneMarker(): void
    {
        foreach ([['apiKey' => 'SYNTH_A'], ['openAIApiKey' => 'SYNTH_B'], ['apiKey' => 'SYNTH_A', 'openAIApiKey' => 'SYNTH_B'], ['openAIApiKey' => 'SYNTH_B', 'apiKey' => 'SYNTH_A']] as $fields) {
            $model = new OpenAI(['model' => 'gpt-3.5-turbo-instruct'] + $fields);
            self::assertSame(['openai_api_key' => self::marker('OPENAI_API_KEY')], self::credentialEntries(self::record($model)['kwargs']));
            self::assertSame($fields['apiKey'] ?? $fields['openAIApiKey'], $model->apiKey);
        }

        $organization = new OpenAI(['model' => 'gpt-3.5-turbo-instruct', 'apiKey' => 'SYNTH_A', 'organization' => 'SYNTH_ORG_FIELD']);
        $kwargs = self::record($organization)['kwargs'];
        self::assertSame(self::marker('OPENAI_ORGANIZATION'), $kwargs['organization']);
        self::assertStringNotContainsString('SYNTH_ORG_FIELD', json_encode($kwargs, JSON_THROW_ON_ERROR));
        self::assertSame('SYNTH_RELOADED_ORG', self::load(self::record($organization))->organization);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function anthropicRecords(): iterable
    {
        yield 'canonical then legacy' => [['api_key' => 'SYNTH_CANONICAL', 'anthropic_api_key' => 'SYNTH_LEGACY'], 'SYNTH_CANONICAL'];
        yield 'legacy then canonical' => [['anthropic_api_key' => 'SYNTH_LEGACY', 'api_key' => 'SYNTH_CANONICAL'], 'SYNTH_CANONICAL'];
        yield 'camel canonical then legacy' => [['apiKey' => 'SYNTH_CANONICAL', 'anthropic_api_key' => 'SYNTH_LEGACY'], 'SYNTH_CANONICAL'];
        yield 'legacy then camel canonical' => [['anthropic_api_key' => 'SYNTH_LEGACY', 'apiKey' => 'SYNTH_CANONICAL'], 'SYNTH_CANONICAL'];
        yield 'legacy only' => [['anthropic_api_key' => 'SYNTH_LEGACY'], 'SYNTH_LEGACY'];
        yield 'null canonical falls back' => [['api_key' => null, 'anthropic_api_key' => 'SYNTH_LEGACY'], 'SYNTH_LEGACY'];
        yield 'legacy then null canonical' => [['anthropic_api_key' => 'SYNTH_LEGACY', 'api_key' => null], 'SYNTH_LEGACY'];
    }

    #[DataProvider('anthropicRecords')]
    public function testAnthropicLoadKeepsTwoSourceFieldsWithCanonicalNullishPrecedence(array $kwargs, string $expected): void
    {
        putenv('ANTHROPIC_API_KEY=SYNTH_ENV_MUST_NOT_WIN');
        $loaded = self::load(['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'ChatAnthropic'], 'kwargs' => ['model' => 'claude-synthetic'] + $kwargs]);

        self::assertInstanceOf(ChatAnthropic::class, $loaded);
        self::assertSame($expected, $loaded->apiKey);
        self::assertSame(['anthropic_api_key' => self::marker('ANTHROPIC_API_KEY'), 'api_key' => self::marker('ANTHROPIC_API_KEY')], self::credentialEntries(self::record($loaded)['kwargs']));
    }

    public function testAnthropicConstructorResolvesCanonicalThenLegacyThenEnvironment(): void
    {
        putenv('ANTHROPIC_API_KEY=SYNTH_ENV_ANTHROPIC');
        self::assertSame('SYNTH_CANONICAL', (new ChatAnthropic(['apiKey' => 'SYNTH_CANONICAL', 'anthropicApiKey' => 'SYNTH_LEGACY']))->apiKey);
        self::assertSame('SYNTH_CANONICAL', (new ChatAnthropic(['anthropicApiKey' => 'SYNTH_LEGACY', 'apiKey' => 'SYNTH_CANONICAL']))->apiKey);
        self::assertSame('SYNTH_LEGACY', (new ChatAnthropic(['anthropicApiKey' => 'SYNTH_LEGACY']))->apiKey);
        self::assertSame('SYNTH_LEGACY', (new ChatAnthropic(['apiKey' => null, 'anthropicApiKey' => 'SYNTH_LEGACY']))->apiKey);
        self::assertSame('SYNTH_ENV_ANTHROPIC', (new ChatAnthropic(['apiKey' => null, 'anthropicApiKey' => null]))->apiKey);
        self::assertArrayNotHasKey('anthropicApiKey', (new ChatAnthropic(['anthropicApiKey' => 'SYNTH_LEGACY']))->kwargs());
    }

    public function testAnthropicLoadWithoutAnyCredentialIsStillRejectedRatherThanReadingEnvironment(): void
    {
        putenv('ANTHROPIC_API_KEY=SYNTH_ENV_MUST_NOT_LOAD');
        $this->expectExceptionMessage('Provider loading requires an explicit API key or serialized secret supplied through secretsMap.');
        self::load(['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'ChatAnthropic'], 'kwargs' => ['model' => 'claude-synthetic', 'anthropic_api_key' => null]]);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function sourceRecords(): iterable
    {
        $secret = static fn (string $id): array => ['lc' => 1, 'type' => 'secret', 'id' => [$id]];
        yield 'ChatOpenAI explicit' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAI'], 'kwargs' => ['model' => 'gpt-synthetic', 'openai_api_key' => $secret('OPENAI_API_KEY'), 'temperature' => 0, 'max_tokens' => 11, 'organization' => $secret('OPENAI_ORGANIZATION')]], ChatOpenAI::class, 'SYNTH_RELOADED_OPENAI'];
        yield 'ChatOpenAIResponses environment' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAIResponses'], 'kwargs' => ['model' => 'gpt-synthetic', 'max_tokens' => 11, 'openai_api_key' => $secret('OPENAI_API_KEY'), 'organization' => $secret('OPENAI_ORGANIZATION')]], ChatOpenAIResponses::class, 'SYNTH_RELOADED_OPENAI'];
        yield 'OpenAI legacy-only' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'llms', 'openai', 'OpenAI'], 'kwargs' => ['model' => 'gpt-3.5-turbo-instruct', 'openai_api_key' => $secret('OPENAI_API_KEY'), 'max_tokens' => 11, 'organization' => $secret('OPENAI_ORGANIZATION')]], OpenAI::class, 'SYNTH_RELOADED_OPENAI'];
        yield 'ChatAnthropic legacy-only' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'ChatAnthropic'], 'kwargs' => ['model' => 'claude-synthetic', 'anthropic_api_key' => $secret('ANTHROPIC_API_KEY'), 'max_tokens' => 11, 'api_key' => $secret('ANTHROPIC_API_KEY')]], ChatAnthropic::class, 'SYNTH_RELOADED_ANTHROPIC'];
        // Pinned source cannot reload this record (its inverse alias turns `model` into
        // `modelName`); the fixed PHP adapter keeps `model` and loads it.
        yield 'ChatOpenRouter explicit' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'openrouter', 'ChatOpenRouter'], 'kwargs' => ['model' => 'synthetic/model', 'api_key' => $secret('OPENROUTER_API_KEY'), 'temperature' => 0, 'max_tokens' => 11]], ChatOpenRouter::class, 'SYNTH_RELOADED_ROUTER'];
    }

    #[DataProvider('sourceRecords')]
    public function testPinnedSourceRecordsLoadAndReEmitTheSameMarkerSet(array $record, string $class, string $apiKey): void
    {
        $loaded = self::load($record);
        self::assertInstanceOf($class, $loaded);
        self::assertSame($apiKey, $loaded->apiKey);
        self::assertSame($record['kwargs']['model'], $loaded->model);

        $expected = self::credentialEntries($record['kwargs']);
        $actual = self::credentialEntries(self::record($loaded)['kwargs']);
        ksort($expected);
        ksort($actual);
        self::assertSame($expected, $actual);
    }

    public function testLiteralUserRecordsAreEscapedOnceAndSurviveTwoLoadRounds(): void
    {
        $literals = [
            'secret' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL_ID']],
            'constructor' => ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'HumanMessage'], 'kwargs' => ['content' => 'SYNTH']],
            'wrapper' => ['__lc_escaped__' => ['lc' => 0]],
            'list' => [1, ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL_ID']]],
            7 => 'SYNTH_INT_KEY',
        ];
        $models = [
            new ChatOpenAICompletions(['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT', 'modelKwargs' => $literals]),
            new OpenAI(['model' => 'gpt-3.5-turbo-instruct', 'apiKey' => 'SYNTH_EXPLICIT', 'modelKwargs' => $literals]),
            new ChatOpenRouter(['model' => 'synthetic/model', 'apiKey' => 'SYNTH_EXPLICIT', 'modelKwargs' => $literals]),
            new ChatAnthropic(['model' => 'claude-synthetic', 'apiKey' => 'SYNTH_EXPLICIT', 'invocationKwargs' => $literals]),
        ];
        foreach ($models as $model) {
            $field = $model instanceof ChatAnthropic ? 'invocationKwargs' : 'modelKwargs';
            $first = self::record($model);
            self::assertSame(['__lc_escaped__' => $literals['secret']], $first['kwargs'][$field]['secret']);
            self::assertSame(['__lc_escaped__' => $literals['constructor']], $first['kwargs'][$field]['constructor']);
            self::assertSame(['__lc_escaped__' => $literals['wrapper']], $first['kwargs'][$field]['wrapper']);
            self::assertSame(['__lc_escaped__' => $literals['list'][1]], $first['kwargs'][$field]['list'][1]);
            self::assertSame('SYNTH_INT_KEY', $first['kwargs'][$field][7]);

            $once = self::load($first);
            self::assertSame($literals, $once->{$field}, $model::class . ' restores raw runtime data');
            $second = self::record($once);
            self::assertSame($first, $second);
            $twice = self::load($second);
            self::assertSame($literals, $twice->{$field});
            self::assertSame($first, self::record($twice));
        }
    }

    public function testBoundRawCredentialSpellingsNeverReachTheRecord(): void
    {
        $model = (new ChatOpenAI(['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT']))
            ->bindTools([], ['apiKey' => 'SYNTH_BOUND', 'openai_api_key' => 'SYNTH_BOUND_WIRE', 'organization' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL_ID']]]);
        $kwargs = self::record($model)['kwargs'];

        self::assertSame(self::marker('OPENAI_API_KEY'), $kwargs['openai_api_key']);
        self::assertArrayNotHasKey('apiKey', $kwargs);
        self::assertArrayNotHasKey('organization', $kwargs, 'unresolved organization omits even an escaped bound literal');
        self::assertStringNotContainsString('SYNTH_BOUND', json_encode($kwargs, JSON_THROW_ON_ERROR));
    }

    public function testRuntimeTransportIsOmittedOnSerializationAndRejectedOnLoad(): void
    {
        $model = new ChatOpenRouter(['model' => 'synthetic/model', 'apiKey' => 'SYNTH_EXPLICIT', 'httpClient' => self::throwingTransport(), 'sleeper' => static fn (): never => throw new \LogicException('MUST NOT CALL')]);
        $record = self::record($model);
        self::assertArrayNotHasKey('httpClient', $record['kwargs']);
        self::assertArrayNotHasKey('sleeper', $record['kwargs']);

        $record['kwargs']['http_client'] = ['sentinel' => 'SYNTH_TRANSPORT'];
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime-only constructor field httpClient is not supported by the load registry.');
        self::load($record);
    }

    /** @return iterable<string, array{\Closure(): BaseLangChain}> */
    public static function notOptedIn(): iterable
    {
        $azure = ['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT', 'organization' => 'SYNTH_ORG', 'azureOpenAIApiKey' => 'SYNTH_AZURE', 'azureOpenAIApiInstanceName' => 'synthetic', 'azureOpenAIApiDeploymentName' => 'synthetic', 'azureOpenAIApiVersion' => 'synthetic'];
        yield 'AzureChatOpenAI' => [static fn (): BaseLangChain => new AzureChatOpenAI($azure)];
        yield 'AzureChatOpenAICompletions' => [static fn (): BaseLangChain => new AzureChatOpenAICompletions($azure)];
        yield 'AzureChatOpenAIResponses' => [static fn (): BaseLangChain => new AzureChatOpenAIResponses($azure)];
        yield 'AzureOpenAI' => [static fn (): BaseLangChain => new AzureOpenAI(['model' => 'gpt-3.5-turbo-instruct'] + $azure)];
        yield 'ChatXAI' => [static fn (): BaseLangChain => new ChatXAI(['model' => 'grok-synthetic', 'apiKey' => 'SYNTH_EXPLICIT'])];
        yield 'ChatXAIResponses' => [static fn (): BaseLangChain => new ChatXAIResponses(['model' => 'grok-synthetic', 'apiKey' => 'SYNTH_EXPLICIT'])];
        yield 'ChatTogetherAI' => [static fn (): BaseLangChain => new ChatTogetherAI(['model' => 'synthetic', 'apiKey' => 'SYNTH_EXPLICIT'])];
        yield 'ChatFireworks' => [static fn (): BaseLangChain => new ChatFireworks(['model' => 'synthetic', 'apiKey' => 'SYNTH_EXPLICIT'])];
        yield 'ChatDeepSeek' => [static fn (): BaseLangChain => new ChatDeepSeek(['model' => 'deepseek-chat', 'apiKey' => 'SYNTH_EXPLICIT'])];
        yield 'ChatOpenAI subclass' => [static fn (): BaseLangChain => new class (['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT', 'organization' => 'SYNTH_ORG']) extends ChatOpenAI {}];
        yield 'ChatOpenAICompletions subclass' => [static fn (): BaseLangChain => new class (['model' => 'gpt-synthetic', 'apiKey' => 'SYNTH_EXPLICIT']) extends ChatOpenAICompletions {}];
        yield 'OpenAI subclass' => [static fn (): BaseLangChain => new class (['model' => 'gpt-3.5-turbo-instruct', 'apiKey' => 'SYNTH_EXPLICIT', 'organization' => 'SYNTH_ORG']) extends OpenAI {}];
        yield 'ChatAnthropic subclass' => [static fn (): BaseLangChain => new class (['model' => 'claude-synthetic', 'apiKey' => 'SYNTH_EXPLICIT']) extends ChatAnthropic {}];
        yield 'ChatOpenRouter subclass' => [static fn (): BaseLangChain => new class (['model' => 'synthetic/model', 'apiKey' => 'SYNTH_EXPLICIT']) extends ChatOpenRouter {}];
    }

    /** @param \Closure(): BaseLangChain $make */
    #[DataProvider('notOptedIn')]
    public function testInheritedProvidersAndUserSubclassesKeepTheirExistingSerializer(\Closure $make): void
    {
        $model = $make();
        self::assertNull((new \ReflectionMethod($model, 'credentialSerialization'))->invoke($model));

        $json = self::record($model);
        if (!str_contains($model::class, 'Azure')) {
            self::assertSame(Validation::escapeKwargs($model->kwargs(), $model), $json['kwargs']);
        }
    }
}
