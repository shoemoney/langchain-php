<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Load;

use LangChain\Load\Load;
use LangChain\Load\Validation;
use LangChain\Load\Serializable;
use LangChain\Embeddings\EmbeddingsInterface;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\Prompts\PromptTemplate;
use LangChain\Prompts\HumanMessagePromptTemplate;
use LangChain\Prompts\DictPromptTemplate;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeLLM;
use LangChain\VectorStores\MemoryVectorStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\TestCase;

enum SerializationEscapingBackedValue: string
{
    case A = 'a';
}

#[CoversClass(Validation::class)]
#[CoversClass(Serializable::class)]
final class SerializationEscapingTest extends TestCase
{
    public function testLiteralSecretAndConstructorDoNotRevive(): void
    {
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'injected']];
        $literal = ['secret' => $secret, 'constructor' => $constructor, 'singleton' => ['__lc_escaped__' => $secret]];
        $message = new AIMessage(['content' => 'test', 'additional_kwargs' => $literal, 'tool_calls' => [['name' => 'test', 'id' => 'call', 'args' => $literal]]]);
        $loaded = Load::load(json_encode($message, JSON_THROW_ON_ERROR), ['secretsMap' => ['FAKE_SECRET' => 'fake replacement']]);
        self::assertInstanceOf(AIMessage::class, $loaded);
        self::assertSame($literal, $loaded->additional_kwargs);
        self::assertSame($literal, $loaded->toolCalls[0]['args']);
    }

    public static function literals(): iterable
    {
        yield 'lc zero' => [['lc' => 0]];
        yield 'lc null' => [['lc' => null]];
        yield 'lc arbitrary' => [['lc' => 'literal']];
        yield 'secret' => [['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']]];
        yield 'constructor' => [['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']]];
        yield 'singleton' => [['__lc_escaped__' => ['lc' => 1]]];
        yield 'nested singleton' => [['__lc_escaped__' => ['__lc_escaped__' => ['lc' => null]]]];
    }

    #[DataProvider('literals')]
    public function testVerbatimWrappingAndLoaderRoundTrip(array $literal): void
    {
        $expected = ['__lc_escaped__' => $literal];
        self::assertTrue(Validation::needsEscaping($literal));
        self::assertSame($expected, Validation::escapeIfNeeded($literal));
        self::assertSame($literal, Validation::unescapeValue($expected));
        $message = new HumanMessage(['content' => [$literal], 'additional_kwargs' => ['nested' => [$literal]], 'response_metadata' => $literal]);
        self::assertSame($expected, $message->toJson()['kwargs']['response_metadata']);
        $loaded = Load::load(json_encode($message, JSON_THROW_ON_ERROR));
        self::assertSame([$literal], $loaded->content);
        self::assertSame(['nested' => [$literal]], $loaded->additional_kwargs);
        self::assertSame($literal, $loaded->response_metadata);
        self::assertSame($literal, $message->response_metadata);
    }

    public function testOrdinaryMarkerFieldAndSharedReferencesRemainOrdinary(): void
    {
        $ordinary = ['__lc_escaped__' => ['lc' => null], 'other' => true];
        self::assertFalse(Validation::needsEscaping($ordinary));
        $shared = (object) ['nested' => ['lc' => 0]];
        $value = ['a' => $shared, 'b' => $shared, 'list' => [$shared]];
        $result = Validation::escapeIfNeeded($value);
        self::assertEquals($result['a'], $result['b']);
        self::assertEquals($result['a'], $result['list'][0]);
        self::assertEquals($value, Validation::unescapeValue($result));
        self::assertSame(['lc' => 0], $shared->nested);
    }

    public function testObjectCycleUsesUpstreamNotImplementedAndOwnerSelfReferenceIsBounded(): void
    {
        $object = new \stdClass();
        $object->self = $object;
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => ['Object']], Validation::escapeIfNeeded($object)->self);
        $message = new HumanMessage('test');
        $kwargs = Validation::escapeKwargs(['self' => $message], $message);
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => HumanMessage::lcId()], $kwargs['self']);
        self::assertSame($message, Validation::escapeIfNeeded($message));
    }

    public function testPhpReferenceArrayCycleFailsWithBoundedTraversal(): void
    {
        $array = [];
        $array[] = &$array;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Maximum literal serialization traversal depth');
        Validation::escapeIfNeeded($array);
    }

    public function testGenuineNestedPromptAndPlainDictionaryRoundTrips(): void
    {
        $prompt = HumanMessagePromptTemplate::fromTemplate('Hello {name}');
        $literal = ['lc' => null, 'nested' => ['__lc_escaped__' => ['lc' => 1]]];
        $value = Validation::escapeIfNeeded(['prompt' => $prompt, 'literal' => $literal]);
        self::assertSame($prompt, $value['prompt']);
        $loaded = Load::load(json_encode($value, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(HumanMessagePromptTemplate::class, $loaded['prompt']);
        self::assertSame('Hello Ada', $loaded['prompt']->formatMessages(['name' => 'Ada'])[0]->content);
        self::assertSame($literal, $loaded['literal']);
        self::assertSame(1, $prompt->toJson()['lc']);
    }

    public function testAllSevenSerializerPathsEscapeOnlyValuesAndKeepRuntimeKwargsRaw(): void
    {
        $literal = ['lc' => 0];
        $embeddings = new class implements EmbeddingsInterface {
            public function embedDocuments(array $documents): array { return []; }
            public function embedQuery(string $document): array { return []; }
        };
        $objects = [
            new HumanMessage(['additional_kwargs' => $literal]),
            new FakeLLM(['metadata' => $literal]),
            new PromptTemplate('test'),
            new HumanMessagePromptTemplate(new PromptTemplate('test'), $literal),
            new DictPromptTemplate(['lc' => 'literal']),
            new MemoryVectorStore($embeddings, ['metadata' => $literal]),
            new class(['metadata' => $literal]) extends BaseCallbackHandler {},
        ];
        // BasePromptTemplate captures only its established kwargs; inject the test payload
        // into that constructor record without adding a new production configuration field.
        $property = new \ReflectionProperty($objects[2], 'kwargs');
        $property->setValue($objects[2], $objects[2]->kwargs() + ['metadata' => $literal]);
        $keys = ['additional_kwargs', 'metadata', 'metadata', 'additional_options', 'template', 'metadata', 'metadata'];
        foreach ($objects as $index => $object) {
            $json = $object->toJson();
            $raw = $index === 4 ? ['lc' => 'literal'] : $literal;
            self::assertSame(1, $json['lc']);
            self::assertArrayNotHasKey('__lc_escaped__', $json['kwargs']);
            self::assertSame(['__lc_escaped__' => $raw], $json['kwargs'][$keys[$index]], $object::class);
            self::assertSame($json, $object->toJson(), 'Repeated serialization must not wrap again.');
        }
        self::assertSame($literal, $objects[0]->additional_kwargs);
        self::assertSame($literal, $objects[1]->kwargs()['metadata']);
        self::assertSame($literal, $objects[2]->kwargs()['metadata']);
        self::assertSame($literal, $objects[3]->kwargs()['additional_options']);
        self::assertSame($literal, $objects[5]->kwargs()['metadata']);
        self::assertSame($objects[6], Validation::escapeIfNeeded($objects[6]));
    }

    public function testAzureGeneratedSecretMarkerRemainsTrustedAfterBaseEscaping(): void
    {
        $model = new AzureChatOpenAI(['azureOpenAIApiKey' => 'fake-key', 'azureOpenAIApiInstanceName' => 'fake-instance', 'azureOpenAIApiDeploymentName' => 'fake-deployment', 'azureOpenAIApiVersion' => '2024-02-01', 'modelKwargs' => ['lc' => null]]);
        $json = $model->toJson();
        self::assertSame('secret', $json['kwargs']['azure_open_ai_api_key']['type']);
        self::assertSame(['__lc_escaped__' => ['lc' => null]], $json['kwargs']['modelKwargs']);
        $loaded = Load::load(json_encode($json, JSON_THROW_ON_ERROR), ['secretsMap' => ['AZURE_OPENAI_API_KEY' => 'fake-restored']]);
        self::assertInstanceOf(AzureChatOpenAI::class, $loaded);
    }

    public function testRealFakeHttpPipelineHasNoSerializationWrappersOnWire(): void
    {
        $literal = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'fake', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'call', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => json_encode($literal, JSON_THROW_ON_ERROR)]]]], 'finish_reason' => 'tool_calls']]])]);
        $model = new ChatOpenAI(['apiKey' => 'fake-key', 'httpClient' => $http, 'maxRetries' => 0]);
        $input = new AIMessage(['content' => '', 'tool_calls' => [['name' => 'test', 'id' => 'prior', 'args' => $literal]]]);
        $output = $model->invoke([$input]);
        $request = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($literal, json_decode($request['messages'][0]['tool_calls'][0]['function']['arguments'], true, 512, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('__lc_escaped__', $http->requests[0]['body']);
        self::assertSame($literal, $output->toolCalls[0]['args']);
        $loaded = Load::load(json_encode($output, JSON_THROW_ON_ERROR), ['secretsMap' => ['FAKE_SECRET' => 'fake replacement']]);
        self::assertSame($literal, $loaded->toolCalls[0]['args']);
    }


    #[DataProvider('literals')]
    public function testObjectLiteralsHaveTheSameJsonWrapper(array $literal): void
    {
        $object = (object) $literal;
        self::assertTrue(Validation::needsEscaping($object));
        self::assertSame(['__lc_escaped__' => $object], Validation::escapeIfNeeded($object));
        self::assertSame($object, Validation::unescapeValue((object) ['__lc_escaped__' => $object]));
        self::assertSame($literal, Load::load(json_encode(Validation::escapeIfNeeded($object), JSON_THROW_ON_ERROR)));
    }

    public function testActualSerializerReplacesDirectOwnerSelfReference(): void
    {
        $message = new HumanMessage('test');
        $property = new \ReflectionProperty($message, 'kwargs');
        $property->setValue($message, $message->kwargs() + ['self' => $message]);
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => HumanMessage::lcId()], $message->toJson()['kwargs']['self']);
        self::assertIsString(json_encode($message, JSON_THROW_ON_ERROR));
    }


    public function testAzureNonSecretAliasLiteralCannotBeSubstituted(): void
    {
        $literal = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_LITERAL']];
        $model = new AzureChatOpenAI(['azureOpenAIApiKey' => 'fake-key', 'azureOpenAIApiInstanceName' => 'fake-instance', 'azureOpenAIApiDeploymentName' => 'fake-deployment', 'azureOpenAIApiVersion' => '2024-02-01', 'deploymentName' => $literal]);
        self::assertSame(['__lc_escaped__' => $literal], $model->toJson()['kwargs']['deployment_name']);
        $loaded = Load::load(json_encode($model, JSON_THROW_ON_ERROR), ['secretsMap' => ['FAKE_LITERAL' => 'fake substitution', 'AZURE_OPENAI_API_KEY' => 'fake-restored']]);
        self::assertInstanceOf(AzureChatOpenAI::class, $loaded);
        $fields = (new \ReflectionProperty($loaded, 'azureConstructorFields'))->getValue($loaded);
        self::assertSame($literal, $fields['azureOpenAIApiDeploymentName']);
        self::assertSame('fake-restored', $loaded->azureOpenAIApiKey);
        self::assertSame(['__lc_escaped__' => $literal], $loaded->toJson()['kwargs']['deployment_name']);
    }

    public function testPhpNativeTypedValuesKeepTheirBaselineJson(): void
    {
        $custom = new class implements \JsonSerializable {
            private string $value = 'private value';
            public function jsonSerialize(): mixed { return ['x' => $this->value]; }
        };
        $payload = ['enum' => SerializationEscapingBackedValue::A, 'custom' => $custom, 'date' => new \DateTimeImmutable('2020-01-01T00:00:00Z')];
        $expected = json_encode($payload, JSON_THROW_ON_ERROR);
        $message = new HumanMessage(['additional_kwargs' => $payload]);
        self::assertSame($expected, json_encode($message->toJson()['kwargs']['additional_kwargs'], JSON_THROW_ON_ERROR));
    }

    public function testFiveHundredLevelLiteralDataCanSerializeLoadAndRestoreStoredMessage(): void
    {
        $literal = ['lc' => null];
        $deep = $literal;
        for ($i = 0; $i < 500; ++$i) { $deep = ['nested' => $deep]; }
        $message = new HumanMessage(['additional_kwargs' => ['deep' => $deep]]);
        $loaded = Load::load(json_encode($message, JSON_THROW_ON_ERROR), ['maxDepth' => 512]);
        self::assertSame(['deep' => $deep], $loaded->additional_kwargs);
        $stored = json_decode(json_encode($message->toDict(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['deep' => $deep], \LangChain\Messages\MessageUtils::mapStoredMessageToChatMessage($stored)->additional_kwargs);
    }


    public function testCustomJsonSerializableLiteralOutputEscapesAndLoadsAsData(): void
    {
        $literal = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $custom = new class($literal) implements \JsonSerializable {
            public int $calls = 0;
            public function __construct(private array $literal) {}
            public function jsonSerialize(): mixed { ++$this->calls; return $this->literal; }
        };
        $message = new HumanMessage(['additional_kwargs' => ['custom' => $custom]]);
        $json = json_encode($message, JSON_THROW_ON_ERROR);
        self::assertSame(1, $custom->calls);
        self::assertSame(['__lc_escaped__' => $literal], json_decode($json, true, 512, JSON_THROW_ON_ERROR)['kwargs']['additional_kwargs']['custom']);
        $loaded = Load::load($json, ['secretsMap' => ['FAKE_SECRET' => 'fake substitution']]);
        self::assertSame($literal, $loaded->additional_kwargs['custom']);
    }

    public function testCustomJsonSerializableSelfCycleIsBounded(): void
    {
        $custom = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed { return $this; }
        };
        self::assertSame(['lc' => 1, 'type' => 'not_implemented', 'id' => ['Object']], Validation::escapeIfNeeded($custom));
    }

}
