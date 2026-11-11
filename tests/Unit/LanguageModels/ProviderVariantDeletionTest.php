<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\LanguageModels\Chat\OpenAI\BaseChatOpenAI;
use LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\Load\Load;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixed deletion/absence projections of actual langchainjs constructors/toJSON at
 * 17b6bba8343bdd3f3dfc5c59578e0b72337ec740: Together chat_models.ts126-138 and
 * xAI completions.ts658-670 serialize the parent, then delete these two keys.
 * Parent-wins OpenAI aliases/secrets leave no API marker after deletion.
 * Whole source/core modules ran offline; retained source archive SHA256:
 * 683be24df9997161fa9c5fa62778210b4a1bb54b31c89849e72231118f4286b2.
 * PHP bindTools captures a model clone; source binding uses a separate Runnable.
 * Ordinary capture, source getter versus PHP JsonSerializable, organization and
 * raw bound credential spellings remain explicitly bounded PHP adaptations.
 * This tests only top-level deletion, not generic credential safety or parity.
 */
#[CoversClass(ChatTogetherAI::class)]
#[CoversClass(ChatXAI::class)]
final class ProviderVariantDeletionTest extends TestCase
{
    private const ENVIRONMENT = ['OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'TOGETHER_AI_API_KEY', 'XAI_API_KEY'];

    // Executed source deletion projections, used by the public assertions below.
    private const SOURCE_DELETED_KEYS = ['openai_api_key', 'configuration'];

    private const VARIANTS = [
        ChatTogetherAI::class => ['langchain', 'chat_models', 'together_ai', 'ChatTogetherAI'],
        ChatXAI::class => ['langchain', 'chat_models', 'xai', 'ChatXAI'],
        ProviderDeletionTogetherDescendant::class => ['langchain', 'chat_models', 'together_ai', 'ProviderDeletionTogetherDescendant'],
        ProviderDeletionXAIDescendant::class => ['langchain', 'chat_models', 'xai', 'ProviderDeletionXAIDescendant'],
    ];

    /** @var array<string, array{environment: string|false, exists: bool, value: mixed}> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        ProviderDeletionOfflineClient::$calls = 0;
        foreach (self::ENVIRONMENT as $name) {
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
        self::assertSame(0, ProviderDeletionOfflineClient::$calls);
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

    public static function variantsAndPaths(): iterable
    {
        foreach (self::VARIANTS as $class => $id) {
            foreach (['constructor', 'json', 'tracer', 'native'] as $path) {
                yield $id[3] . '/' . $path => [$class, $id, $path];
            }
        }
    }

    private static function model(string $class, array $fields = []): BaseChatOpenAI
    {
        return new $class($fields + [
            'model' => 'synthetic',
            'apiKey' => 'SYNTH_EXPLICIT',
            'httpClient' => new ProviderDeletionOfflineClient(),
        ]);
    }

    private static function record(BaseLangChain $model, string $path): array
    {
        return match ($path) {
            'constructor' => $model->toSerializedConstructor(),
            'json' => $model->toJson(),
            'tracer' => $model->toSerialized(),
            'native' => json_decode(json_encode($model, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        };
    }

    private static function assertRecord(array $record, array $id, array $kwargs): void
    {
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => $kwargs], $record);
        foreach (self::SOURCE_DELETED_KEYS as $key) {
            self::assertArrayNotHasKey($key, $record['kwargs']);
        }
    }

    private static function deletionBag(): array
    {
        return [
            'configuration' => ['organization' => 'SYNTH_BOUND_ORG', 'apiKey' => 'SYNTH_NESTED_KEY'],
            'openai_api_key' => 'SYNTH_BOUND_WIRE',
        ];
    }

    #[DataProvider('variantsAndPaths')]
    public function testPublicBindingDeletesOnlyReturnedKeysWithoutMutatingState(string $class, array $id, string $path): void
    {
        $original = self::model($class);
        $caller = self::deletionBag();
        $originalKwargs = $original->kwargs();
        $bound = $original->bindTools([], $caller);
        $captured = $bound->kwargs();
        $properties = get_object_vars($bound);
        $transport = (new \ReflectionProperty(BaseChatOpenAI::class, 'httpClient'))->getValue($bound);

        self::assertNotSame($original, $bound);
        self::assertSame($class, $bound::class);
        self::assertSame(['model' => 'synthetic', 'tools' => []] + self::deletionBag(), $captured);
        self::assertNull((new \ReflectionMethod($bound, 'credentialSerialization'))->invoke($bound));
        $record = self::record($bound, $path);
        self::assertRecord($record, $id, ['model' => 'synthetic', 'tools' => []]);
        self::assertSame($captured, $bound->kwargs());
        self::assertSame($originalKwargs, $original->kwargs());
        self::assertSame(self::deletionBag(), $caller);
        self::assertSame($properties, get_object_vars($bound));
        self::assertSame($transport, (new \ReflectionProperty(BaseChatOpenAI::class, 'httpClient'))->getValue($bound));
        self::assertSame('SYNTH_EXPLICIT', $bound->apiKey);
    }

    #[DataProvider('variantsAndPaths')]
    public function testPublicEscapeRunsBeforeDeletion(string $class, array $id, string $path): void
    {
        // Source escape reads an enumerable getter once; PHP escape calls this
        // non-lcId JsonSerializable once. Deletion must come from production.
        $value = new ProviderDeletionPlainValue();
        $bound = self::model($class)->bindTools([], ['configuration' => $value]);
        $captured = $bound->kwargs();
        $record = self::record($bound, $path);
        self::assertSame(1, $value->calls);
        self::assertRecord($record, $id, ['model' => 'synthetic', 'tools' => []]);
        self::assertSame($value, $bound->kwargs()['configuration']);
        self::assertSame($captured, $bound->kwargs());
    }

    #[DataProvider('variantsAndPaths')]
    public function testSerializableLikeValuesAreNotSerializedBeforeDeletion(string $class, array $id, string $path): void
    {
        $value = new ProviderDeletionSerializableValue();
        $bound = self::model($class)->bindTools([], ['configuration' => $value]);
        $record = self::record($bound, $path);
        self::assertSame(0, $value->calls);
        self::assertRecord($record, $id, ['model' => 'synthetic', 'tools' => []]);
        self::assertSame($value, $bound->kwargs()['configuration']);
    }

    #[DataProvider('variantsAndPaths')]
    public function testNamedRawBoundSpellingAndOrganizationResidualIsPreserved(string $class, array $id, string $path): void
    {
        // Explicitly accepted residual, not source-equal credential protection.
        $raw = [
            'apiKey' => 'SYNTH_BOUND_API',
            'togetherAIApiKey' => 'SYNTH_BOUND_LEGACY',
            'together_ai_api_key' => 'SYNTH_BOUND_TOGETHER',
            'xai_api_key' => 'SYNTH_BOUND_XAI',
            'organization' => 'SYNTH_BOUND_ORGANIZATION',
        ];
        $caller = self::deletionBag() + $raw;
        $bound = self::model($class)->bindTools([], $caller);
        $captured = $bound->kwargs();
        self::assertRecord(self::record($bound, $path), $id, ['model' => 'synthetic', 'tools' => []] + $raw);
        self::assertSame($captured, $bound->kwargs());
        self::assertSame(self::deletionBag() + $raw, $caller);
        self::assertSame('SYNTH_EXPLICIT', $bound->apiKey);
        self::assertNull($bound->organization);
    }

    #[DataProvider('variantsAndPaths')]
    public function testDeletionIsTopLevelAndPreservesEscapedUserLiterals(string $class, array $id, string $path): void
    {
        $literal = ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_LITERAL']];
        $nested = ['configuration' => 'SYNTH_NESTED_CONFIG', 'openai_api_key' => 'SYNTH_NESTED_WIRE', 'literal' => $literal];
        $bound = self::model($class)->bindTools([], self::deletionBag() + ['unrelated' => $nested]);
        self::assertRecord(self::record($bound, $path), $id, [
            'model' => 'synthetic',
            'tools' => [],
            'unrelated' => [
                'configuration' => 'SYNTH_NESTED_CONFIG',
                'openai_api_key' => 'SYNTH_NESTED_WIRE',
                'literal' => ['__lc_escaped__' => $literal],
            ],
        ]);
        self::assertSame($nested, $bound->kwargs()['unrelated']);
    }

    #[DataProvider('variantsAndPaths')]
    public function testExistingConstructorCaptureAndCurrentPropertyResidualAreUnchanged(string $class, array $id, string $path): void
    {
        self::environment('OPENAI_ORGANIZATION', 'SYNTH_ENV_ORG');
        $fields = ['configuration' => ['organization' => 'SYNTH_CONFIG_ORG'], 'organization' => 'SYNTH_TOP_ORG'];
        $model = self::model($class, $fields);
        $model->model = 'SYNTH_CHANGED_CURRENT_MODEL';
        self::assertRecord(self::record($model, $path), $id, ['organization' => 'SYNTH_TOP_ORG', 'model' => 'synthetic']);
        self::assertSame('SYNTH_TOP_ORG', $model->organization);
        self::assertSame('SYNTH_CHANGED_CURRENT_MODEL', $model->model);
        self::assertSame(['configuration' => ['organization' => 'SYNTH_CONFIG_ORG'], 'organization' => 'SYNTH_TOP_ORG'], $fields);
        // This is the existing S1 limited model+key fixture, not a universal
        // null-contract guarantee for arbitrary public bound kwargs.
        self::assertRecord(self::record(self::model($class), $path), $id, ['model' => 'synthetic']);
    }

    public function testExactFamiliesLoadTheirOwnRawWireWithEmptySecretsMap(): void
    {
        foreach ([ChatTogetherAI::class => 'together_ai_api_key', ChatXAI::class => 'xai_api_key'] as $class => $wire) {
            $bound = self::model($class)->bindTools([], [$wire => 'SYNTH_BOUND_OWN']);
            foreach (['constructor', 'json', 'tracer', 'native'] as $path) {
                $record = self::record($bound, $path);
                self::assertRecord($record, self::VARIANTS[$class], ['model' => 'synthetic', 'tools' => [], $wire => 'SYNTH_BOUND_OWN']);
                $loaded = Load::load(json_encode($record, JSON_THROW_ON_ERROR), ['secretsMap' => []]);
                self::assertSame($class, $loaded::class);
                self::assertSame('SYNTH_BOUND_OWN', $loaded->apiKey);
                self::assertSame(['model' => 'synthetic', 'tools' => []], $loaded->toJson()['kwargs']);
            }
        }
    }

    public function testMissingOwnCredentialLoadFailureIsAPreservedGuardNotCausalRed(): void
    {
        foreach ([ChatTogetherAI::class, ChatXAI::class] as $class) {
            $model = self::model($class);
            foreach ([$model, $model->bindTools([], self::deletionBag())] as $control) {
                try {
                    Load::load(json_encode($control, JSON_THROW_ON_ERROR), ['secretsMap' => []]);
                    self::fail('No own provider credential may be obtained from the environment.');
                } catch (\RuntimeException $error) {
                    self::assertSame('Provider loading requires an explicit API key or serialized secret supplied through secretsMap.', $error->getMessage());
                }
            }
        }
        foreach ([ProviderDeletionTogetherDescendant::class, ProviderDeletionXAIDescendant::class] as $class) {
            try {
                Load::load(json_encode(self::model($class), JSON_THROW_ON_ERROR), ['secretsMap' => []]);
                self::fail('Named descendant IDs remain unregistered.');
            } catch (\RuntimeException $error) {
                $id = self::VARIANTS[$class];
                self::assertSame('Invalid identifier ' . $id[3] . ' in ' . implode('/', array_slice($id, 0, 3)) . '.', $error->getMessage());
            }
        }
    }
}

final class ProviderDeletionTogetherDescendant extends ChatTogetherAI {}
final class ProviderDeletionXAIDescendant extends ChatXAI {}

final class ProviderDeletionPlainValue implements \JsonSerializable
{
    public int $calls = 0;

    public function jsonSerialize(): mixed
    {
        ++$this->calls;

        return ['value' => 'SYNTH_ORDER'];
    }
}

final class ProviderDeletionSerializableValue implements \JsonSerializable
{
    public int $calls = 0;

    public static function lcId(): array
    {
        return ['SYNTH_NESTED_SERIALIZABLE'];
    }

    public function toJson(): array
    {
        ++$this->calls;

        return ['lc' => 1, 'type' => 'not_implemented', 'id' => self::lcId()];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }
}

final class ProviderDeletionOfflineClient implements HttpClient
{
    public static int $calls = 0;

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        ++self::$calls;

        throw new \LogicException('Serialization must remain offline.');
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        ++self::$calls;

        throw new \LogicException('Serialization must remain offline.');
    }
}
