<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\OpenAI\BaseChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * What every OpenAI chat client shares, asserted against each concrete class so
 * the split cannot make one protocol drift from the others.
 */
#[CoversClass(BaseChatOpenAI::class)]
final class BaseChatOpenAITest extends TestCase
{
    /** @return iterable<string, array{0: class-string<BaseChatOpenAI>}> */
    public static function clients(): iterable
    {
        yield 'facade' => [ChatOpenAI::class];
        yield 'completions' => [ChatOpenAICompletions::class];
        yield 'responses' => [ChatOpenAIResponses::class];
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     * @param array<string, mixed>         $fields
     */
    private static function make(string $class, array $fields = []): BaseChatOpenAI
    {
        return new $class($fields + ['apiKey' => 'sk-test', 'httpClient' => new FakeHttpClient(), 'maxRetries' => 0]);
    }

    public function testTheHierarchy(): void
    {
        self::assertTrue((new \ReflectionClass(BaseChatOpenAI::class))->isAbstract());
        self::assertTrue(is_subclass_of(BaseChatOpenAI::class, BaseChatModel::class));
        foreach ([ChatOpenAI::class, ChatOpenAICompletions::class, ChatOpenAIResponses::class] as $class) {
            self::assertTrue(is_subclass_of($class, BaseChatOpenAI::class), $class);
        }
        self::assertFalse(is_subclass_of(ChatOpenAIResponses::class, ChatOpenAICompletions::class));
        self::assertFalse(is_subclass_of(ChatOpenAICompletions::class, ChatOpenAIResponses::class));
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testAnEmptyModelNameIsRefused(string $class): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty model name');

        self::make($class, ['model' => '  ']);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testTopKIsRefusedInEverySpellingAndLayer(string $class): void
    {
        foreach ([['topK' => 5], ['top_k' => 5]] as $fields) {
            try {
                self::make($class, $fields);
                self::fail('constructor accepted topK');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('topK', $e->getMessage());
            }
        }

        $model = self::make($class);
        try {
            $model->invocationParams(['top_k' => 5]);
            self::fail('per-call options accepted top_k');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $model->bindTools([], ['topK' => 5]);
            self::fail('bindTools accepted topK');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testTheApiKeyAndTransportNeverReachKwargs(string $class): void
    {
        $kwargs = self::make($class, ['temperature' => 0.2, 'organization' => 'org-1'])->kwargs();

        self::assertArrayNotHasKey('apiKey', $kwargs);
        self::assertArrayNotHasKey('httpClient', $kwargs);
        self::assertSame(0.2, $kwargs['temperature']);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testWireSpelledOptionsAreCanonicalised(string $class): void
    {
        $model = self::make($class, ['max_tokens' => 99, 'top_p' => 0.4, 'service_tier' => 'auto', 'zdr_enabled' => true]);

        self::assertSame(99, $model->maxTokens);
        self::assertSame(0.4, $model->topP);
        self::assertSame('auto', $model->serviceTier);
        self::assertTrue($model->zdrEnabled);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testBindToolsReturnsANewInstanceAndLeavesTheReceiverAlone(string $class): void
    {
        $model = self::make($class);

        $bound = $model->bindTools([['type' => 'function', 'function' => ['name' => 'f', 'parameters' => ['type' => 'object']]]], ['temperature' => 0.1]);

        self::assertNotSame($model, $bound);
        self::assertInstanceOf($class, $bound);
        self::assertArrayNotHasKey('tools', $model->kwargs());
        self::assertSame(0.1, $bound->kwargs()['temperature']);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testStrictnessSurvivesAChainedBind(string $class): void
    {
        $once = self::make($class)->bindTools([], ['strict' => true]);

        self::assertTrue($once->supportsStrictToolCalling);
        self::assertTrue($once->bindTools([])->supportsStrictToolCalling);
        self::assertArrayNotHasKey('strict', $once->kwargs());
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testCustomToolsAndBuiltInToolsAreStoredUntouched(string $class): void
    {
        $custom = tool(static fn (array $a): string => 'x', [
            'name' => 'exec', 'description' => 'd', 'schema' => Schema::object([]),
            'metadata' => ['customTool' => ['name' => 'exec']],
        ]);
        $builtIn = ['type' => 'web_search_preview'];

        $stored = self::make($class)->bindTools([$custom, $builtIn])->kwargs()['tools'];

        self::assertSame($custom, $stored[0]);
        self::assertSame($builtIn, $stored[1]);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testAProviderToolDefinitionIsStoredInPlaceOfTheTool(string $class): void
    {
        $shell = tool(static fn (array $a): string => 'x', [
            'name' => 'local_shell', 'description' => 'd', 'schema' => Schema::object([]),
            'extras' => ['providerToolDefinition' => ['type' => 'local_shell']],
        ]);

        self::assertSame([['type' => 'local_shell']], self::make($class)->bindTools([$shell])->kwargs()['tools']);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testDeferLoadingExtrasMarkTheFunctionTool(string $class): void
    {
        $deferred = tool(static fn (array $a): string => 'x', [
            'name' => 'lookup', 'description' => 'd', 'schema' => Schema::object([]),
            'extras' => ['defer_loading' => true],
        ]);

        $stored = self::make($class)->bindTools([$deferred])->kwargs()['tools'][0];

        self::assertSame('function', $stored['type']);
        self::assertTrue($stored['defer_loading']);
        self::assertSame('lookup', $stored['function']['name']);
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testAMissingApiKeyNamesTheEnvironmentVariable(string $class): void
    {
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY');

        try {
            $model = new $class(['httpClient' => new FakeHttpClient()]);
            $this->expectException(OpenAIException::class);
            $this->expectExceptionMessage('OPENAI_API_KEY');
            $model->invoke('hi');
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testAClientErrorIsNotRetried(string $class): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(400, ['error' => ['message' => 'bad request']]),
            FakeHttpClient::json(200, []),
        ]);
        $model = new $class(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 3]);

        try {
            $model->invoke('hi');
            self::fail('expected an exception');
        } catch (OpenAIException $e) {
            self::assertSame(400, $e->status);
            self::assertCount(1, $http->requests);
        }
    }

    /**
     * @param class-string<BaseChatOpenAI> $class
     */
    #[DataProvider('clients')]
    public function testATransportFaultIsWrappedInAnOpenAiException(string $class): void
    {
        $http = new class () implements \LangChain\Utils\Http\HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \LangChain\Utils\Http\HttpResponse
            {
                throw new \LogicException('socket on fire');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                throw new \LogicException('socket on fire');
                yield '';
            }
        };

        try {
            (new $class(['apiKey' => 'k', 'httpClient' => $http]))->invoke('hi');
            self::fail('expected an exception');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('socket on fire', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function testTheNameAndSerialisationPathAreUnchangedByTheSplit(): void
    {
        $model = self::make(ChatOpenAI::class);

        self::assertSame('ChatOpenAI', $model->getName());
        self::assertSame(['langchain', 'chat_models', 'openai'], ChatOpenAI::lcNamespace());
        self::assertSame('openai-chat', $model->llmType());
        self::assertSame(['langchain', 'chat_models', 'openai', 'ChatOpenAI'], ChatOpenAI::lcId());
    }

    public function testRetryBackoffCanBeRedirectedByAFacade(): void
    {
        $slept = [];
        $http = new FakeHttpClient([
            FakeHttpClient::json(500, ['error' => ['message' => 'oops']]),
            FakeHttpClient::json(200, ['id' => 'x', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok']]]]),
        ]);
        $model = new class (['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 1]) extends ChatOpenAICompletions {
            public function redirect(\Closure $handler): void
            {
                $this->backoffHandler = $handler;
            }
        };
        $model->redirect(static function (int $attempt) use (&$slept): void {
            $slept[] = $attempt;
        });

        $model->invoke('hi');

        self::assertSame([1], $slept);
    }

    public function testCanonicaliseFoldsWireKeysOntoCamelCaseWithoutOverridingAnExplicitOne(): void
    {
        $folded = ChatOpenAI::canonicalise(['max_tokens' => 1, 'maxTokens' => 2, 'tool_choice' => 'auto', 'zdr_enabled' => true]);

        self::assertSame(2, $folded['maxTokens']);
        self::assertSame('auto', $folded['toolChoice']);
        self::assertTrue($folded['zdrEnabled']);
    }
}
