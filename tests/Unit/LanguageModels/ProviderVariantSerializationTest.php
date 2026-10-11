<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\LanguageModels\Chat\Fireworks\ChatFireworks;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\LanguageModels\LLMs\Fireworks;
use LangChain\LanguageModels\LLMs\TogetherAI;
use LangChain\Load\Load;
use LangChain\Load\Serializable;
use LangChain\Load\Validation;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixed projections of actual constructors/toJSON at langchainjs
 * 17b6bba8343bdd3f3dfc5c59578e0b72337ec740, not an implementation oracle.
 * Whole source modules/core ran offline with neutral Runnable/model/caller/cache/
 * gateway/version seams; no client invocation. Ordinary PHP capture/defaults and
 * dictionary key order are deliberately outside source-equality assertions.
 *
 * Ordering harness/output SHA256: 2b5de294b2520d537cbd46deed94f6ff163c638e0b0241398cca3fb6f7bc8c99 /
 * 8ac3c7cac7d94a3ec131de92e1a99ae3ff6c9974a0d313452f456893cc6bdb40.
 * Organization types harness/output: 60f3c4c2926ed778eb2c0fbee212a40a01392c0141d3c204f3b8b011db5d58b9 /
 * 7add53ecdb449d85649728295afff7a56a4a8ee6431b1cddd82f478679b47481.
 * Mutation harness/output: 5d2aef78d1aac7a090a3fcce95fc9826b1c29ff1f009997b6206afa1a8b8100f /
 * 097b9b6a3bb247113b8f89a2d29875582ad6f3a2d8595ea00c4b51e51ae34d76.
 * Current API mutation harness/output: a8f5b5f6a8a465b9cc5fd13d32ef795dcd811c3b8b01621cb773e2467fa20395 /
 * f5cf6943d33716c1ae861c020a3e9e96c80fa2a41f2f1f4fbfd1f17bf0aacd8d.
 * Source undefined is omitted in JSON; assigned null is marked in source. Six
 * named mutation cells retain PHP same-value/reset/null adaptations explicitly.
 */
#[CoversClass(BaseLangChain::class)]
#[CoversClass(TogetherAI::class)]
#[CoversClass(Fireworks::class)]
#[CoversClass(ChatFireworks::class)]
#[CoversClass(ChatXAIResponses::class)]
final class ProviderVariantSerializationTest extends TestCase
{
    private const ENV = [
        'OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'TOGETHER_AI_API_KEY', 'FIREWORKS_API_KEY',
        'XAI_API_KEY', 'FIREWORKS_API_BASE', 'FIREWORKS_BASE_URL', 'LANGSMITH_GATEWAY',
        'LANGSMITH_GATEWAY_API_KEY', 'LANGSMITH_API_KEY',
    ];

    private const SOURCE_IDS = [
        TogetherAI::class => ['langchain', 'llms', 'together_ai', 'TogetherAI'],
        Fireworks::class => ['langchain', 'llms', 'fireworks', 'Fireworks'],
        ChatFireworks::class => ['langchain', 'chat_models', 'fireworks', 'ChatFireworks'],
        ChatXAIResponses::class => ['langchain', 'chat_models', 'xai', 'ChatXAIResponses'],
    ];

    private const SECRETS = [
        'TOGETHER_AI_API_KEY' => 'SYNTH_RELOAD_TOGETHER',
        'FIREWORKS_API_KEY' => 'SYNTH_RELOAD_FIREWORKS',
        'XAI_API_KEY' => 'SYNTH_RELOAD_XAI',
        'OPENAI_ORGANIZATION' => 'SYNTH_RELOAD_ORG',
    ];

    /** @var array<string, array{environment: string|false, exists: bool, value: mixed}> */
    private array $savedEnvironment = [];

    private static int $transportCalls = 0;

    protected function setUp(): void
    {
        self::$transportCalls = 0;
        foreach (self::ENV as $name) {
            $this->savedEnvironment[$name] = [
                'environment' => getenv($name),
                'exists' => array_key_exists($name, $_ENV),
                'value' => $_ENV[$name] ?? null,
            ];
            self::environment($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $saved) {
            putenv($saved['environment'] === false ? $name : $name . '=' . $saved['environment']);
            if ($saved['exists']) {
                $_ENV[$name] = $saved['value'];
            } else {
                unset($_ENV[$name]);
            }
        }
        self::assertSame(0, self::$transportCalls);
    }

    private static function environment(string $name, ?string $value): void
    {
        putenv($value === null ? $name : $name . '=' . $value);
        if ($value === null) {
            unset($_ENV[$name]);
        } else {
            $_ENV[$name] = $value;
        }
    }

    public static function countTransportCall(): void
    {
        ++self::$transportCalls;
    }

    private static function transport(): HttpClient
    {
        return new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                ProviderVariantSerializationTest::countTransportCall();

                throw new \LogicException('Serialization must stay offline.');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                ProviderVariantSerializationTest::countTransportCall();

                throw new \LogicException('Serialization must stay offline.');
            }
        };
    }

    private static function marker(string $id): array
    {
        return ['lc' => 1, 'type' => 'secret', 'id' => [$id]];
    }

    private static function markers(array $kwargs): array
    {
        $markers = array_filter($kwargs, static fn (mixed $value): bool => is_array($value) && ($value['lc'] ?? null) === 1 && ($value['type'] ?? null) === 'secret');
        ksort($markers);

        return $markers;
    }

    private static function record(BaseLangChain $model): array
    {
        $record = $model->toSerializedConstructor();
        self::assertSame($record, $model->toJson());
        self::assertSame($record, $model->toSerialized());
        self::assertSame($record, $model->jsonSerialize());
        self::assertSame($record, json_decode(json_encode($model, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR));

        return $record;
    }

    private static function hook(BaseLangChain $model, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($model, $method))->invoke($model, ...$arguments);
    }

    private static function caller(BaseLangChain $model): mixed
    {
        for ($class = new \ReflectionClass($model); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty('caller')) {
                return $class->getProperty('caller')->getValue($model);
            }
        }

        // PHP chat transport owns its retry state directly, not an AsyncCaller.
        return null;
    }

    /** @return iterable<string, array{class-string<BaseLangChain>, string, string}> */
    public static function families(): iterable
    {
        yield 'Together text' => [TogetherAI::class, 'together_ai_api_key', 'TOGETHER_AI_API_KEY'];
        yield 'Fireworks text' => [Fireworks::class, 'fireworks_api_key', 'FIREWORKS_API_KEY'];
        yield 'Fireworks chat' => [ChatFireworks::class, 'fireworks_api_key', 'FIREWORKS_API_KEY'];
        yield 'XAI Responses' => [ChatXAIResponses::class, 'xai_api_key', 'XAI_API_KEY'];
    }

    /** @param class-string<BaseLangChain> $class */
    #[DataProvider('families')]
    public function testExactPublicSourceMarkersPreserveRuntimeAndCaller(string $class, string $key, string $id): void
    {
        $caller = new AsyncCaller(maxRetries: 0);
        $http = self::transport();
        $fields = ['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_EXPLICIT', 'temperature' => 0.5, 'configuration' => ['organization' => 'SYNTH_CONFIG'], 'asyncCaller' => $caller, 'httpClient' => $http];
        $beforeFields = $fields;
        $model = new $class($fields);
        $beforeKwargs = $model->kwargs();
        $beforePublic = get_object_vars($model);
        $beforeCaller = self::caller($model);
        $record = self::record($model);
        $expected = [$key => self::marker($id)];
        if ($class === Fireworks::class || $class === ChatFireworks::class) {
            $expected['organization'] = self::marker('OPENAI_ORGANIZATION');
            self::assertArrayNotHasKey('configuration', $record['kwargs']);
            self::assertArrayNotHasKey('openai_api_key', $record['kwargs']);
        }
        ksort($expected);
        self::assertSame($expected, self::markers($record['kwargs']));
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => self::SOURCE_IDS[$class]], array_diff_key($record, ['kwargs' => true]));
        self::assertArrayNotHasKey('apiKey', $record['kwargs']);
        self::assertStringNotContainsString('SYNTH_EXPLICIT', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertSame($beforeFields, $fields);
        self::assertSame($beforeKwargs, $model->kwargs());
        self::assertSame($beforePublic, get_object_vars($model));
        self::assertSame($class === TogetherAI::class || $class === Fireworks::class ? $caller : null, $beforeCaller);
        self::assertSame($beforeCaller, self::caller($model));
        self::assertSame($http, $model->httpClient);
        $excluded = [$key => true, 'apiKey' => true, 'openAIApiKey' => true];
        if ($class === Fireworks::class || $class === ChatFireworks::class) {
            $excluded += ['organization' => true, 'configuration' => true, 'fireworksApiKey' => true, 'openai_api_key' => true];
        }
        self::assertSame(array_diff_key(Validation::escapeKwargs($beforeKwargs, $model), $excluded), array_diff_key($record['kwargs'], $excluded));
    }

    /** @param class-string<BaseLangChain> $class */
    #[DataProvider('families')]
    public function testEnvironmentCredentialsAndSpoofWireKeys(string $class, string $key, string $id): void
    {
        self::environment($id, 'SYNTH_ENV_KEY');
        $model = new $class(['model' => 'SYNTH_MODEL']);
        self::assertSame(self::marker($id), self::record($model)['kwargs'][$key]);
        $property = new \ReflectionProperty(BaseLangChain::class, 'kwargs');
        $before = $property->getValue($model);
        try {
            $property->setValue($model, ['apiKey' => 'SYNTH_RAW'] + ($class === Fireworks::class || $class === ChatFireworks::class ? ['fireworksApiKey' => 'SYNTH_RAW'] : []) + [$key => 'SYNTH_SPOOF'] + $before + ['literal' => self::marker('SYNTH_LITERAL')]);
            $record = self::record($model);
            self::assertSame(self::marker($id), $record['kwargs'][$key]);
            self::assertSame(['__lc_escaped__' => self::marker('SYNTH_LITERAL')], $record['kwargs']['literal']);
            self::assertStringNotContainsString('SYNTH_SPOOF', json_encode($record, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('SYNTH_RAW', json_encode($record, JSON_THROW_ON_ERROR));
        } finally {
            $property->setValue($model, $before);
        }
    }

    public function testLegacyKeyPrecedenceAndTypedValidationRemainPhpAdaptations(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            self::environment('FIREWORKS_API_KEY', 'SYNTH_ENV');
            $legacy = new $class(['model' => 'SYNTH_MODEL', 'fireworksApiKey' => 'SYNTH_LEGACY']);
            self::assertSame('SYNTH_LEGACY', $legacy->apiKey);
            $both = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_CANONICAL', 'fireworksApiKey' => 'SYNTH_LEGACY']);
            self::assertSame('SYNTH_CANONICAL', $both->apiKey);
            self::assertSame(self::marker('FIREWORKS_API_KEY'), self::record($both)['kwargs']['fireworks_api_key']);
        }
        self::environment('TOGETHER_AI_API_KEY', 'SYNTH_ENV');
        self::assertSame(self::marker('TOGETHER_AI_API_KEY'), self::record(new TogetherAI(['model' => 'SYNTH_MODEL', 'apiKey' => null]))['kwargs']['together_ai_api_key']);
        // Current PHP rejects explicit empty Together keys instead of falling back.
        try {
            new TogetherAI(['model' => 'SYNTH_MODEL', 'apiKey' => '']);
            self::fail('Empty Together key must retain constructor validation.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('TOGETHER_AI_API_KEY not found.', $error->getMessage());
        }
        self::environment('XAI_API_KEY', 'SYNTH_ENV');
        self::assertSame(self::marker('XAI_API_KEY'), self::record(new ChatXAIResponses(['model' => 'SYNTH_MODEL', 'apiKey' => '']))['kwargs']['xai_api_key']);
    }

    public function testCurrentPublicApiCredentialMutationsKeepDefaultNullAdaptation(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class, ChatXAIResponses::class] as $class) {
            $fireworks = $class !== ChatXAIResponses::class;
            $property = $fireworks ? 'fireworksApiKey' : 'apiKey';
            $key = $fireworks ? 'fireworks_api_key' : 'xai_api_key';
            $id = $fireworks ? 'FIREWORKS_API_KEY' : 'XAI_API_KEY';
            $model = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_INITIAL']);
            $model->{$property} = '';
            self::assertSame(self::marker($id), self::record($model)['kwargs'][$key], 'Defined empty strings remain resolved.');
            $model->{$property} = null;
            // Source defined-null is marked; default PHP current-null is omitted.
            self::assertArrayNotHasKey($key, self::record($model)['kwargs']);
            $model->{$property} = 'SYNTH_CURRENT';
            self::assertSame(self::marker($id), self::record($model)['kwargs'][$key]);
            self::assertStringNotContainsString('SYNTH_CURRENT', json_encode($model, JSON_THROW_ON_ERROR));
        }
    }

    public function testTogetherPrivatePresenceAndUnknownTokenDelegation(): void
    {
        $model = new TogetherAI(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_PRIVATE']);
        self::assertSame(self::marker('TOGETHER_AI_API_KEY'), self::record($model)['kwargs']['together_ai_api_key']);
        self::assertTrue(self::hook($model, 'serializationCredentialIsResolved', 'apiKey'));
        self::assertTrue(self::hook($model, 'serializationCredentialIsResolved', 'model'));
        self::assertFalse(self::hook($model, 'serializationCredentialIsResolved', 'maxTokens'));
        self::assertArrayNotHasKey('apiKey', get_object_vars($model));
        // Exact owning reader delegates unknown properties, not blanket true.
    }

    public function testUserSubclassesRetainNullContractAndPlainEscapedKwargs(): void
    {
        $fields = ['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY'];
        $models = [new class($fields) extends TogetherAI {}, new class($fields) extends Fireworks {}, new class($fields) extends ChatFireworks {}, new class($fields) extends ChatXAIResponses {}];
        foreach ($models as $model) {
            self::assertNull(self::hook($model, 'credentialSerialization'));
            self::assertSame([], self::hook($model, 'credentialSerializationExcludedKeys'));
            self::assertSame(Validation::escapeKwargs($model->kwargs(), $model), self::record($model)['kwargs']);
        }
    }

    public function testNullContractReturnsBeforeEitherPresenceOrExclusionHook(): void
    {
        $model = new class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY']) extends ChatFireworks {
            protected function serializationCredentialIsResolved(string $property): bool
            {
                throw new \LogicException('Null contract must not read presence.');
            }

            protected function credentialSerializationExcludedKeys(): array
            {
                throw new \LogicException('Null contract must not read exclusions.');
            }
        };
        self::assertSame(Validation::escapeKwargs($model->kwargs(), $model), self::record($model)['kwargs']);
    }

    public function testCustomFireworksContractsDoNotInheritExclusions(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            $fields = ['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY'];
            $model = $class === Fireworks::class ? new class($fields) extends Fireworks {
                protected function credentialSerialization(): ?array
                {
                    return ['apiKey' => ['property' => 'apiKey', 'secret' => 'SYNTH_CUSTOM', 'key' => 'openai_api_key']];
                }
            } : new class($fields) extends ChatFireworks {
                protected function credentialSerialization(): ?array
                {
                    return ['apiKey' => ['property' => 'apiKey', 'secret' => 'SYNTH_CUSTOM', 'key' => 'openai_api_key']];
                }
            };
            self::assertSame([], self::hook($model, 'credentialSerializationExcludedKeys'));
            self::assertSame(self::marker('SYNTH_CUSTOM'), self::record($model)['kwargs']['openai_api_key']);
        }
    }

    /** @return iterable<string, array{mixed, ?string, bool, bool}> */
    public static function organizationTypes(): iterable
    {
        yield 'config false: source marked' => [['organization' => false], null, true, true];
        yield 'config zero: source marked' => [['organization' => 0], null, true, true];
        yield 'config empty string: source marked' => [['organization' => ''], null, true, true];
        yield 'config object value: source marked' => [['organization' => (object) ['value' => 'SYNTH']], null, true, true];
        yield 'config null/noenv: source omitted' => [['organization' => null], null, false, false];
        yield 'config null/env: source marked' => [['organization' => null], 'SYNTH_ENV', true, true];
        yield 'missing/env empty: source marked' => [[], '', true, true];
        yield 'object container/noenv: PHP array-only adaptation' => [(object) ['organization' => 'SYNTH_CONFIG'], null, false, true];
        yield 'object container/env: presence matches' => [(object) ['organization' => 'SYNTH_CONFIG'], 'SYNTH_ENV', true, true];
    }

    #[DataProvider('organizationTypes')]
    public function testFireworksConstructorOrganizationProjections(mixed $configuration, ?string $environment, bool $phpMarked, bool $sourceMarked): void
    {
        self::environment('OPENAI_ORGANIZATION', $environment);
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            $fields = ['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'organization' => 'SYNTH_TOP', 'configuration' => $configuration];
            $before = $fields;
            $model = new $class($fields);
            $kwargs = $model->kwargs();
            $runtime = get_object_vars($model);
            $record = self::record($model);
            self::assertSame($phpMarked, array_key_exists('organization', $record['kwargs']));
            if ($phpMarked) {
                self::assertSame(self::marker('OPENAI_ORGANIZATION'), $record['kwargs']['organization']);
            }
            self::assertSame(is_object($configuration) && $environment === null ? false : $sourceMarked, $phpMarked, 'Only the named object-container cell is an adaptation.');
            self::assertArrayNotHasKey('configuration', $record['kwargs']);
            self::assertSame($before, $fields);
            self::assertSame($kwargs, $model->kwargs());
            self::assertSame($runtime, get_object_vars($model));
            if ($model instanceof ChatFireworks) {
                self::assertSame('SYNTH_TOP', $model->organization, 'Runtime headers retain current PHP top-level provenance.');
            } else {
                self::assertArrayNotHasKey('organization', get_object_vars($model));
            }
        }
    }

    public function testOrganizationSamplingIsConstructorOnlyAndS1AsymmetryIsPreserved(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            self::environment('OPENAI_ORGANIZATION', 'SYNTH_ENV');
            $resolved = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY']);
            self::environment('OPENAI_ORGANIZATION', null);
            self::assertSame(self::marker('OPENAI_ORGANIZATION'), self::record($resolved)['kwargs']['organization']);
            $missing = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'organization' => 'SYNTH_TOP']);
            self::environment('OPENAI_ORGANIZATION', 'SYNTH_LATER');
            self::assertArrayNotHasKey('organization', self::record($missing)['kwargs']);
        }
        self::environment('OPENAI_ORGANIZATION', null);
        $s1 = new ChatOpenAI(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'organization' => 'SYNTH_TOP']);
        $fire = new ChatFireworks(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'organization' => 'SYNTH_TOP']);
        self::assertSame(self::marker('OPENAI_ORGANIZATION'), self::record($s1)['kwargs']['organization']);
        self::assertArrayNotHasKey('organization', self::record($fire)['kwargs']);
        $xai = new ChatXAIResponses(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'organization' => 'SYNTH_TOP']);
        self::assertSame('SYNTH_TOP', self::record($xai)['kwargs']['organization'], 'XAI source has no organization secret row.');
    }

    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function mutations(): iterable
    {
        // Fixed actual source output: all sixteen assigned values, including null, are marked.
        yield 'config/assign-X: matching presence' => ['config', 'assign-X', true, true];
        yield 'config/assign-null: matching presence' => ['config', 'assign-null', true, true];
        yield 'config/X-then-reset: matching presence' => ['config', 'X-then-reset', true, true];
        yield 'config/same-PHP-value: matching presence' => ['config', 'same-PHP-value', true, true];
        yield 'environment/assign-X: matching presence' => ['environment', 'assign-X', true, true];
        yield 'environment/assign-null: matching presence' => ['environment', 'assign-null', true, true];
        yield 'environment/X-then-reset: matching presence' => ['environment', 'X-then-reset', true, true];
        yield 'environment/same-PHP-value: matching presence' => ['environment', 'same-PHP-value', true, true];
        yield 'top-only/assign-X: matching presence' => ['top-only', 'assign-X', true, true];
        yield 'top-only/assign-null: EXPLICIT null/same-value/reset adaptation' => ['top-only', 'assign-null', false, true];
        yield 'top-only/X-then-reset: EXPLICIT null/same-value/reset adaptation' => ['top-only', 'X-then-reset', false, true];
        yield 'top-only/same-PHP-value: EXPLICIT null/same-value/reset adaptation' => ['top-only', 'same-PHP-value', false, true];
        yield 'missing/assign-X: matching presence' => ['missing', 'assign-X', true, true];
        yield 'missing/assign-null: EXPLICIT null/same-value/reset adaptation' => ['missing', 'assign-null', false, true];
        yield 'missing/X-then-reset: EXPLICIT null/same-value/reset adaptation' => ['missing', 'X-then-reset', false, true];
        yield 'missing/same-PHP-value: EXPLICIT null/same-value/reset adaptation' => ['missing', 'same-PHP-value', false, true];
    }

    #[DataProvider('mutations')]
    public function testChatOrganizationMutationHeuristicNamesEveryAdaptation(string $initial, string $action, bool $phpMarked, bool $sourceMarked): void
    {
        $fields = ['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY'];
        if ($initial === 'config') {
            $fields['configuration'] = ['organization' => 'SYNTH_CONFIG'];
        } elseif ($initial === 'environment') {
            self::environment('OPENAI_ORGANIZATION', 'SYNTH_ENV');
        } elseif ($initial === 'top-only') {
            $fields['organization'] = 'SYNTH_TOP';
        }
        $model = new ChatFireworks($fields);
        $start = $model->organization;
        if ($action === 'assign-X') {
            $model->organization = 'SYNTH_X';
        } elseif ($action === 'assign-null') {
            $model->organization = null;
        } else {
            if ($action === 'X-then-reset') {
                $model->organization = 'SYNTH_X';
            }
            $model->organization = $start;
        }
        self::assertTrue($sourceMarked, 'Executed pinned source marks assigned null as well as strings.');
        $record = self::record($model);
        self::assertSame($phpMarked, array_key_exists('organization', $record['kwargs']));
        if ($phpMarked) {
            self::assertSame(self::marker('OPENAI_ORGANIZATION'), $record['kwargs']['organization']);
        }
    }

    public function testPublicDeletionRunsAfterEscapingAndBeforeNestedSerialization(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            foreach (['toSerializedConstructor', 'toJson', 'toSerialized', 'jsonSerialize', 'json_encode'] as $entry) {
                $counts = (object) ['plain' => 0, 'nested' => 0];
                // PHP adaptation of source enumerable getter. No lcId/toJson on plain.
                $plain = new class($counts) implements \JsonSerializable {
                    public function __construct(private object $counts) {}
                    public function jsonSerialize(): mixed
                    {
                        ++$this->counts->plain;

                        return ['read' => true];
                    }
                };
                $nested = new class($counts) extends Serializable {
                    public function __construct(private object $counts) {}
                    public static function lcId(): array
                    {
                        return ['SYNTH_NESTED'];
                    }
                    public function toJson(): array
                    {
                        ++$this->counts->nested;

                        return parent::toJson();
                    }
                };
                $model = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY']);
                $property = new \ReflectionProperty(BaseLangChain::class, 'kwargs');
                $before = $property->getValue($model);
                try {
                    $injected = $before + ['configuration' => ['plain' => $plain, 'nested' => $nested]];
                    $property->setValue($model, $injected);
                    $record = $entry === 'json_encode' ? json_decode(json_encode($model, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR) : $model->{$entry}();
                    json_encode($record, JSON_THROW_ON_ERROR);
                    self::assertArrayNotHasKey('configuration', $record['kwargs']);
                    self::assertSame(1, $counts->plain, 'Pre-delete-before-escape would incorrectly skip the plain read.');
                    self::assertSame(0, $counts->nested, 'Source deletion prevents nested Serializable toJSON.');
                    self::assertSame($injected, $model->kwargs());
                } finally {
                    $property->setValue($model, $before);
                }
                self::assertSame($before, $model->kwargs());
            }
        }
    }

    /** @param class-string<BaseLangChain> $class */
    #[DataProvider('families')]
    public function testActualLoadRetainsApiMarkersAndLiteralWrappersTwoRounds(string $class, string $key, string $id): void
    {
        $literal = self::marker('SYNTH_LITERAL_NOT_IN_SECRETS');
        // Preserve existing capture: PHP chat records modelKwargs, text records metadata.
        $container = $class === TogetherAI::class || $class === Fireworks::class ? 'metadata' : 'modelKwargs';
        $model = new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', $container => ['literal' => $literal, 'wrapper' => ['__lc_escaped__' => $literal], 'constructor' => ['lc' => 1, 'type' => 'constructor', 'id' => ['SYNTH_LITERAL_CONSTRUCTOR'], 'kwargs' => []]]]);
        $record = self::record($model);
        for ($round = 0; $round < 2; ++$round) {
            $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
            self::assertSame($class, $loaded::class);
            self::assertSame($literal, $loaded->kwargs()[$container]['literal']);
            self::assertSame(['__lc_escaped__' => $literal], $loaded->kwargs()[$container]['wrapper']);
            $next = self::record($loaded);
            self::assertSame(self::marker($id), $next['kwargs'][$key]);
            self::assertSame($record['kwargs'][$container], $next['kwargs'][$container]);
            $record = $next;
        }
    }

    public function testActualLoadOrganizationLossAndEnvironmentResamplingAreSourcePolicy(): void
    {
        foreach ([Fireworks::class, ChatFireworks::class] as $class) {
            self::environment('OPENAI_ORGANIZATION', null);
            $record = self::record(new $class(['model' => 'SYNTH_MODEL', 'apiKey' => 'SYNTH_KEY', 'configuration' => ['organization' => 'SYNTH_CONFIG']]));
            $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
            self::assertArrayNotHasKey('organization', self::record($loaded)['kwargs'], 'Deleted configuration cannot restore source organization without env.');
            if ($loaded instanceof ChatFireworks) {
                self::assertSame('SYNTH_RELOAD_ORG', $loaded->organization, 'Current PHP runtime header adaptation is preserved.');
            }
            self::environment('OPENAI_ORGANIZATION', 'SYNTH_ENV');
            $envLoaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
            self::assertSame(self::marker('OPENAI_ORGANIZATION'), self::record($envLoaded)['kwargs']['organization']);
        }
    }

    /** @param class-string<BaseLangChain> $class */
    #[DataProvider('families')]
    public function testFixedSourceCredentialProjectionLoadsAndMissingSecretFails(string $class, string $key, string $id): void
    {
        // Projection of full pinned records: unrelated source kwargs/defaults omitted.
        $record = ['lc' => 1, 'type' => 'constructor', 'id' => self::SOURCE_IDS[$class], 'kwargs' => ['model' => 'SYNTH_MODEL', $key => self::marker($id)]];
        $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
        self::assertSame($class, $loaded::class);
        self::assertSame(self::marker($id), self::record($loaded)['kwargs'][$key]);
        try {
            Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => []]);
            self::fail('Missing explicit secret must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('Missing secret "' . $id . '" at $.kwargs.' . $key, $error->getMessage());
        }
        $record['kwargs'][$key] = self::marker('SYNTH_WRONG_ID');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing secret "SYNTH_WRONG_ID" at $.kwargs.' . $key);
        Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => self::SECRETS]);
    }
}
