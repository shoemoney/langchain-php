<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Load;

use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Load\ImportMap;
use LangChain\Load\Load;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bounded ingress of the inherited OPENAI marker emitted by pinned DeepSeek.
 *
 * Source pin: langchainjs 17b6bba8343bdd3f3dfc5c59578e0b72337ec740.
 * Twelve literal first records below are copied from source-r2-verified.json,
 * SHA256 db620e3b5ec00f354a78c792a345b778f7f7e25fc9ea8e39f176580c9d3d80c1.
 * That retained harness evaluated actual constructor/Serializable/Load bodies
 * with finite neutral dependency seams: twelve constructions, two source Loads,
 * and zero transport attempts. It was not a fully installed upstream SDK run.
 *
 * PHP accepts ingress but does not activate DeepSeek credential serialization:
 * its ordinary second Load still rejects. Both mixed providers share OPENAI;
 * supplied organization re-emits raw. A later invocation would send that bearer
 * and OpenAI-Organization header to DeepSeek or the record endpoint (static
 * inference only). No requests, privacy or source-fidelity closure is claimed.
 */
#[CoversClass(ImportMap::class)]
#[CoversClass(Load::class)]
#[CoversClass(ChatDeepSeek::class)]
final class DeepSeekSourceIngressTest extends TestCase
{
    public const GUARD = 'Provider loading requires an explicit API key or serialized secret supplied through secretsMap.';

    private const ID = ['langchain', 'chat_models', 'deepseek', 'ChatDeepSeek'];

    private const ENV = ['OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'DEEPSEEK_API_KEY', 'TOGETHER_AI_API_KEY', 'XAI_API_KEY', 'FIREWORKS_API_KEY'];

    /** @var array<string, array{string|false, bool, mixed}> */
    private array $savedEnvironment = [];

    /** @var list<FakeHttpClient> */
    private array $clients = [];

    /** @var array<string, array{string, string, string, bool}> */
    private const SOURCE_RECORDS = [
        'explicit' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', false],
        'env-only' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', false],
        'top-org' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', false],
        'config-org' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com","organization":"SYNTH_CONFIG_ORG"},"organization":{"lc":1,"type":"secret","id":["OPENAI_ORGANIZATION"]}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', true],
        'env-org' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"},"organization":{"lc":1,"type":"secret","id":["OPENAI_ORGANIZATION"]}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', true],
        'raw-configuration' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://synthetic.invalid/v1","apiKey":"SYNTH_NESTED_KEY","defaultHeaders":{"authorization":"SYNTH_HEADER"},"literal":{"__lc_escaped__":{"lc":1,"type":"secret","id":["SYNTH_LITERAL"]}}},"model_kwargs":{"literal":{"__lc_escaped__":{"lc":1,"type":"secret","id":["SYNTH_LITERAL"]}}}}}', 'https://synthetic.invalid/v1/chat/completions', 'SYNTH_MODEL', false],
        'empty-key' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', false],
        'null-key' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', false],
        'model-alias-collision' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_ALIAS_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_ALIAS_MODEL', false],
        'string-overload' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_OVERLOAD_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com"}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_OVERLOAD_MODEL', false],
        'false-org' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com","organization":false},"organization":{"lc":1,"type":"secret","id":["OPENAI_ORGANIZATION"]}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', true],
        'object-org' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","deepseek","ChatDeepSeek"],"kwargs":{"model":"SYNTH_MODEL","openai_api_key":{"lc":1,"type":"secret","id":["OPENAI_API_KEY"]},"configuration":{"baseURL":"https://api.deepseek.com","organization":{"synthetic":"ORG"}},"organization":{"lc":1,"type":"secret","id":["OPENAI_ORGANIZATION"]}}}', 'https://api.deepseek.com/chat/completions', 'SYNTH_MODEL', true],
    ];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->savedEnvironment[$name] = [getenv($name), array_key_exists($name, $_ENV), $_ENV[$name] ?? null];
            putenv($name);
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->clients as $client) {
                self::assertSame([], $client->requests);
                self::assertSame([], $client->streamRequests);
            }
        } finally {
            foreach ($this->savedEnvironment as $name => [$value, $existed, $envValue]) {
                putenv($value === false ? $name : $name . '=' . $value);
                if ($existed) {
                    $_ENV[$name] = $envValue;
                } else {
                    unset($_ENV[$name]);
                }
            }
        }
    }

    /**
     * Fixed before/after data. Committed assertions use ONLY the candidate column.
     * The baseline column is asserted by the separately authorized immutable-
     * preimage process driver, which directly calls this public dataset.
     * Last writer remains the rule; five rows intentionally change outcomes.
     * Legacy-later is a retained PHP extension, not source equality.
     *
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function orderedIngressRows(): array
    {
        return [
            'canonical then wire' => [['apiKey' => 'SYNTH_CANON', 'openai_api_key' => 'SYNTH_WIRE'], 'SYNTH_CANON', 'SYNTH_WIRE'],
            'wire then canonical' => [['openai_api_key' => 'SYNTH_WIRE', 'apiKey' => 'SYNTH_CANON'], 'SYNTH_CANON', 'SYNTH_CANON'],
            'generic then wire' => [['api_key' => 'SYNTH_SNAKE', 'openai_api_key' => 'SYNTH_WIRE'], 'SYNTH_SNAKE', 'SYNTH_WIRE'],
            'wire then generic' => [['openai_api_key' => 'SYNTH_WIRE', 'api_key' => 'SYNTH_SNAKE'], 'SYNTH_SNAKE', 'SYNTH_SNAKE'],
            'legacy then wire' => [['deepseek_api_key' => 'SYNTH_LEGACY', 'openai_api_key' => 'SYNTH_WIRE'], 'SYNTH_LEGACY', 'SYNTH_WIRE'],
            'wire then legacy' => [['openai_api_key' => 'SYNTH_WIRE', 'deepseek_api_key' => 'SYNTH_LEGACY'], 'SYNTH_LEGACY', 'SYNTH_LEGACY'],
            'canonical then empty wire' => [['apiKey' => 'SYNTH_CANON', 'openai_api_key' => ''], 'SYNTH_CANON', 'REJECT'],
            'canonical then null wire' => [['apiKey' => 'SYNTH_CANON', 'openai_api_key' => null], 'SYNTH_CANON', 'REJECT'],
        ];
    }

    /** @param array<string, mixed> $orderedKwargs */
    #[DataProvider('orderedIngressRows')]
    public function testOrderedIngressUsesFixedCandidateOutcome(array $orderedKwargs, string $baseline, string $candidate): void
    {
        $record = self::wire(['model' => 'SYNTH_MODEL'] + $orderedKwargs);
        if ($candidate === 'REJECT') {
            self::assertRejected($record, self::GUARD);
        } else {
            $model = self::mustLoad($record);
            self::assertSame(ChatDeepSeek::class, $model::class);
            self::assertSame($candidate, $model->apiKey);
            self::assertNull($model->httpClient);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidLaterRows(): iterable
    {
        foreach (['apiKey', 'api_key', 'deepseek_api_key'] as $key) {
            foreach (['empty' => '', 'null' => null, 'false' => false, 'int' => 7] as $label => $invalid) {
                yield $key . ' then ' . $label => [$key, $invalid];
            }
        }
    }

    #[DataProvider('invalidLaterRows')]
    public function testLaterInvalidWireRejectsEarlierValidKey(string $key, mixed $invalid): void
    {
        self::setSyntheticProviderEnvironment();
        self::assertRejected(self::wire(['model' => 'SYNTH_MODEL', $key => 'SYNTH_EARLY', 'openai_api_key' => $invalid]), self::GUARD);
    }

    #[DataProvider('invalidLaterRows')]
    public function testLaterValidWireRescuesInvalidCanonicalGenericOrLegacy(string $key, mixed $invalid): void
    {
        $model = self::mustLoad(self::wire(['model' => 'SYNTH_MODEL', $key => $invalid, 'openai_api_key' => 'SYNTH_RESCUED']));
        self::assertSame(ChatDeepSeek::class, $model::class);
        self::assertSame('SYNTH_RESCUED', $model->apiKey);
        self::assertNull($model->httpClient);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rejectedCredentialRows(): iterable
    {
        yield 'missing' => [[]];
        foreach (['empty' => '', 'null' => null, 'false' => false, 'int' => 7] as $label => $value) {
            yield $label => [['openai_api_key' => $value]];
        }
        yield 'unsupported open_ai spelling' => [['open_ai_api_key' => 'SYNTH_UNSUPPORTED']];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('rejectedCredentialRows')]
    public function testGuardRejectsMissingBadOrUnsupportedKeysDespiteSyntheticEnvironment(array $fields): void
    {
        self::setSyntheticProviderEnvironment();
        self::assertRejected(self::wire(['model' => 'SYNTH_MODEL'] + $fields), self::GUARD);
    }

    public function testCanonicalGenericAndLegacyCredentialsRemainLoadable(): void
    {
        foreach (['apiKey', 'api_key', 'deepseek_api_key'] as $key) {
            $model = self::mustLoad(self::wire(['model' => 'SYNTH_MODEL', $key => 'SYNTH_EXPLICIT']));
            self::assertSame(ChatDeepSeek::class, $model::class);
            self::assertSame('SYNTH_EXPLICIT', $model->apiKey);
            self::assertNull($model->httpClient);
        }
    }

    /**
     * Literal pinned source records, independent of current provider construction.
     * Public for the immutable-preimage driver; baseline rejects each first record.
     * Source load-one/load-two evidence remains in the pinned artifact above.
     *
     * @return array<string, array{array<string, mixed>, string, string, bool}>
     */
    public static function sourceRecords(): array
    {
        $records = [];
        foreach (self::SOURCE_RECORDS as $name => [$json, $endpoint, $model, $organization]) {
            $records[$name] = [json_decode($json, true, 512, JSON_THROW_ON_ERROR), $endpoint, $model, $organization];
        }

        return $records;
    }

    /** @param array<string, mixed> $record */
    #[DataProvider('sourceRecords')]
    public function testPinnedFirstSourceRecordsLoadButPhpReemissionCannotLoadAgain(array $record, string $endpoint, string $expectedModel, bool $organization): void
    {
        self::assertSame(self::ID, $record['id']);
        self::assertSame(self::marker('OPENAI_API_KEY'), $record['kwargs']['openai_api_key']);
        foreach (['SYNTH_O1', 'SYNTH_O2'] as $openAIKey) {
            $secrets = ['OPENAI_API_KEY' => $openAIKey, 'DEEPSEEK_API_KEY' => 'SYNTH_DISTINCT_DEEPSEEK', 'OPENAI_ORGANIZATION' => 'SYNTH_ORG'];
            $model = self::mustLoad($record, $secrets);
            self::assertSame(ChatDeepSeek::class, $model::class);
            self::assertSame($openAIKey, $model->apiKey);
            self::assertSame($endpoint, $model->baseUrl);
            self::assertSame($expectedModel, $model->model);
            self::assertNull($model->httpClient);
            self::assertSame(self::ID, $model::lcId());
            $emitted = $model->toSerializedConstructor();
            self::assertSame($emitted, $model->toJson());
            self::assertSame($emitted, $model->toSerialized());
            self::assertSame($emitted, json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
            self::assertSame(self::ID, $emitted['id']);
            foreach (['apiKey', 'api_key', 'deepseek_api_key', 'openai_api_key', 'configuration'] as $key) {
                self::assertArrayNotHasKey($key, $emitted['kwargs']);
            }
            self::assertStringNotContainsString($openAIKey, json_encode($emitted, JSON_THROW_ON_ERROR));
            if ($organization) {
                self::assertSame(self::marker('OPENAI_ORGANIZATION'), $record['kwargs']['organization']);
                self::assertSame('SYNTH_ORG', $model->organization);
                self::assertSame('SYNTH_ORG', $emitted['kwargs']['organization']);
                self::assertSame('SYNTH_ORG', $model->toSerialized()['kwargs']['organization']);
            } else {
                self::assertNull($model->organization);
                self::assertArrayNotHasKey('organization', $emitted['kwargs']);
            }
            if (array_key_exists('model_kwargs', $record['kwargs'])) {
                self::assertSame(['literal' => self::marker('SYNTH_LITERAL')], $model->modelKwargs);
            }
            self::assertRejected($emitted, self::GUARD, $secrets);
        }
        self::assertRejected($record, 'Missing secret "OPENAI_API_KEY" at $.kwargs.openai_api_key', ['DEEPSEEK_API_KEY' => 'SYNTH_DISTINCT_DEEPSEEK']);
    }

    /**
     * OpenAI is hand-built canonical wire. DeepSeek is the retained source first
     * record (explicit/default or raw-configuration/record endpoint), unrewritten.
     * Both markers intentionally consult the same OPENAI secret id.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function mixedRecords(): array
    {
        $sources = self::sourceRecords();
        $openAI = ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAI'], 'kwargs' => ['model' => 'SYNTH_OPENAI_MODEL', 'openai_api_key' => self::marker('OPENAI_API_KEY')]];

        return [
            'default endpoint' => [['openai' => $openAI, 'deepseek' => $sources['explicit'][0]], $sources['explicit'][1]],
            'record endpoint' => [['openai' => $openAI, 'deepseek' => $sources['raw-configuration'][0]], $sources['raw-configuration'][1]],
        ];
    }

    /** @param array<string, mixed> $record */
    #[DataProvider('mixedRecords')]
    public function testMixedGraphSharesOpenAISecretAndIgnoresDeepSeekSecret(array $record, string $endpoint): void
    {
        $both = ['OPENAI_API_KEY' => 'SYNTH_OPENAI_ONLY', 'DEEPSEEK_API_KEY' => 'SYNTH_DEEPSEEK_ONLY'];
        foreach ([$both, ['OPENAI_API_KEY' => 'SYNTH_OPENAI_ONLY']] as $secrets) {
            $before = $record;
            try {
                $loaded = self::load($record, $secrets);
            } catch (\RuntimeException $exception) {
                self::fail('Mixed source ingress must load: ' . $exception->getMessage());
            }
            self::assertSame($before, $record);
            self::assertSame(ChatOpenAI::class, $loaded['openai']::class);
            self::assertSame(ChatDeepSeek::class, $loaded['deepseek']::class);
            self::assertSame('SYNTH_OPENAI_ONLY', $loaded['openai']->apiKey);
            self::assertSame('SYNTH_OPENAI_ONLY', $loaded['deepseek']->apiKey);
            self::assertNull($loaded['openai']->httpClient);
            self::assertNull($loaded['deepseek']->httpClient);
            self::assertSame($endpoint, $loaded['deepseek']->baseUrl);
        }
        self::assertRejected($record, 'Missing secret "OPENAI_API_KEY" at $.openai.kwargs.openai_api_key', ['DEEPSEEK_API_KEY' => 'SYNTH_DEEPSEEK_ONLY']);
    }

    /** @return array<string, array{list<string>}> */
    public static function siblings(): array
    {
        return [
            'TogetherAI' => [['langchain', 'chat_models', 'together_ai', 'ChatTogetherAI']],
            'XAI' => [['langchain', 'chat_models', 'xai', 'ChatXAI']],
            'Fireworks' => [['langchain', 'chat_models', 'fireworks', 'ChatFireworks']],
        ];
    }

    /** @param list<string> $id */
    #[DataProvider('siblings')]
    public function testSourceOpenAIKeyAliasDoesNotExpandToSiblingFactories(array $id): void
    {
        $record = self::wire(['model' => 'SYNTH_MODEL', 'openai_api_key' => self::marker('OPENAI_API_KEY')], $id);
        self::assertRejected($record, self::GUARD, ['OPENAI_API_KEY' => 'SYNTH_OPENAI_ONLY']);
    }

    public function testLiteralOuterEscapeUnwrapsOnceAndNestedWireStaysOpaque(): void
    {
        $literal = ['__lc_escaped__' => self::wire(['openai_api_key' => self::marker('SYNTH_NEVER_RESOLVE')])];
        $outer = ['__lc_escaped__' => $literal];
        self::assertSame($literal, self::load($outer));
        $unknown = ['lc' => 1, 'type' => 'constructor', 'id' => ['synthetic', 'Unknown'], 'kwargs' => []];
        self::assertSame($unknown, self::load(['__lc_escaped__' => $unknown]));
        $raw = ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_NEVER_RESOLVE']];
        $model = self::mustLoad(self::wire(['apiKey' => 'SYNTH_EXPLICIT', 'model_kwargs' => ['__lc_escaped__' => ['raw' => $raw]], 'configuration' => ['baseURL' => 'https://synthetic.invalid/v1', 'raw' => ['__lc_escaped__' => $raw]]]));
        self::assertSame(['raw' => $raw], $model->modelKwargs);
        self::assertSame('https://synthetic.invalid/v1/chat/completions', $model->baseUrl);
        self::assertArrayNotHasKey('configuration', $model->toJson()['kwargs']);
    }

    public function testFixedRegistryLongestNamespaceAndDepthRefusalsRemain(): void
    {
        self::assertRejected(self::wire(['apiKey' => 'SYNTH_EXPLICIT'], ['synthetic', 'Unknown']), 'Invalid namespace for serialized constructor.');
        self::assertRejected(self::wire([], ['langchain_core', 'messages', 'tool', 'AIMessage']), 'Invalid identifier AIMessage in langchain_core/messages/tool.');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum recursion depth (0) exceeded during deserialization at $.kwargs.');
        Load::load(json_encode(self::wire(['apiKey' => 'SYNTH_EXPLICIT']), JSON_THROW_ON_ERROR), ['maxDepth' => 0]);
    }

    public function testEnvironmentSecretAndDynamicImportsRemainRefused(): void
    {
        foreach (['secretsFromEnv' => true, 'importMap' => [], 'optionalImportsMap' => [], 'optionalImportEntrypoints' => []] as $option => $value) {
            try {
                Load::load('{}', [$option => $value]);
                self::fail('Unsupported dynamic/environment option must fail.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($option === 'secretsFromEnv' ? 'Environment secret resolution is not supported; supply secretsMap.' : "Dynamic {$option} is not supported; the PHP load registry is fixed.", $exception->getMessage());
            }
        }
    }

    public function testRuntimeHookCannotEnterFactoryEvenWithValidWireKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime-only constructor field httpClient is not supported by the load registry.');
        self::load(self::wire(['openai_api_key' => 'SYNTH_WIRE', 'http_client' => 'SYNTH_CALLABLE']));
    }

    public function testDirectConstructorAndBindRemainNotOptedAndNeverUseTransport(): void
    {
        $client = new FakeHttpClient();
        $this->clients[] = $client;
        $model = new ChatDeepSeek(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_DIRECT', 'httpClient' => $client]);
        self::assertSame(['apiKey' => 'DEEPSEEK_API_KEY'], $model->lcSecrets());
        self::assertSame(self::ID, $model::lcId());
        self::assertArrayNotHasKey('apiKey', $model->toJson()['kwargs']);
        self::assertArrayNotHasKey('openai_api_key', $model->toJson()['kwargs']);
        $bound = $model->bindTools([], ['openai_api_key' => 'SYNTH_BOUND_RAW']);
        self::assertSame('SYNTH_BOUND_RAW', $bound->toJson()['kwargs']['openai_api_key']);
        self::assertSame('SYNTH_DIRECT', $model->apiKey);
        self::assertSame($client, $bound->httpClient);
        self::assertSame([], $client->requests);
        self::assertSame([], $client->streamRequests);
    }

    private static function setSyntheticProviderEnvironment(): void
    {
        foreach (self::ENV as $name) {
            putenv($name . '=SYNTH_ENV_ONLY');
            $_ENV[$name] = 'SYNTH_ENV_ONLY';
        }
    }

    /** @return array{lc: int, type: string, id: list<string>} */
    private static function marker(string $id): array
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => [$id]];
    }

    /** @param array<string, mixed> $fields
     * @param list<string> $id
     * @return array<string, mixed>
     */
    private static function wire(array $fields, array $id = self::ID): array
    {
        return ['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => $fields];
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed> $secrets
     */
    private static function load(array $record, array $secrets = []): mixed
    {
        return Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => $secrets]);
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed> $secrets
     */
    private static function mustLoad(array $record, array $secrets = []): object
    {
        $before = $record;
        try {
            $result = self::load($record, $secrets);
        } catch (\RuntimeException $exception) {
            self::fail('Expected public Load success: ' . $exception->getMessage());
        } finally {
            self::assertSame($before, $record);
        }

        return $result;
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed> $secrets
     */
    private static function assertRejected(array $record, string $message, array $secrets = []): void
    {
        $before = $record;
        try {
            self::load($record, $secrets);
        } catch (\RuntimeException $exception) {
            self::assertSame(\RuntimeException::class, $exception::class);
            self::assertSame($message, $exception->getMessage());
            self::assertSame($before, $record);

            return;
        }
        self::fail('Expected public Load rejection: ' . $message);
    }
}
