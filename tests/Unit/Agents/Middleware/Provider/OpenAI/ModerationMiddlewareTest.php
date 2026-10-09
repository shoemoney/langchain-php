<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware\Provider\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\StubInitChatModel;
use LangChain\Tools\Schema;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\Provider\OpenAI\ModerationClient;
use LangGraph\Agents\Middleware\Provider\OpenAI\ModerationMiddleware;
use LangGraph\Agents\Middleware\Provider\OpenAI\OpenAIModerationError;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Agents\Runtime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/provider/openai/tests/moderation.test.ts`.
 *
 * The mocked `client.moderations.create` is a {@see FakeHttpClient} behind a real `ChatOpenAI`, so every case also
 * pins the request the {@see ModerationClient} posts: the `/moderations` URL, the bearer key and the
 * `{input, model}` body. The mocked `initChatModel` is {@see StubInitChatModel}, aliased in a separate process.
 */
#[CoversClass(ModerationMiddleware::class)]
#[CoversClass(ModerationClient::class)]
#[CoversClass(OpenAIModerationError::class)]
final class ModerationMiddlewareTest extends TestCase
{
    private FakeHttpClient $http;

    private ChatOpenAI $mockModel;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->mockModel = self::openAI($this->http);
    }

    private static function openAI(FakeHttpClient $http): ChatOpenAI
    {
        return new ChatOpenAI(['apiKey' => 'sk-test', 'model' => 'gpt-4o-mini', 'httpClient' => $http]);
    }

    /** @param array<string, bool> $categories @param array<string, float> $scores */
    private static function flagged(array $categories, array $scores, string $id = 'modr-1'): HttpResponse
    {
        return FakeHttpClient::json(200, ['id' => $id, 'model' => 'omni-moderation-latest', 'results' => [[
            'flagged' => true,
            'categories' => $categories,
            'category_scores' => $scores,
            'category_applied_input_types' => array_map(static fn (): array => ['text'], $categories),
        ]]]);
    }

    private static function clean(string $id = 'modr-0', string $model = 'omni-moderation-latest'): HttpResponse
    {
        return FakeHttpClient::json(200, ['id' => $id, 'model' => $model, 'results' => [[
            'flagged' => false, 'categories' => [], 'category_scores' => [], 'category_applied_input_types' => [],
        ]]]);
    }

    private static function bigFlagged(): HttpResponse
    {
        return self::flagged(
            [
                'harassment' => false, 'harassment/threatening' => false, 'sexual' => false, 'hate' => false, 'hate/threatening' => false,
                'illicit' => false, 'illicit/violent' => false, 'self-harm/intent' => true, 'self-harm/instructions' => false,
                'self-harm' => true, 'violence' => true, 'violence/graphic' => false, 'sexual/minors' => false,
            ],
            [
                'harassment' => 0.000595613990691901, 'harassment/threatening' => 0.0007731439949613941, 'sexual' => 0.00006166297347617125,
                'hate' => 0.000014202364022997911, 'hate/threatening' => 0.000007843789122138342, 'illicit' => 0.0038094050391387574,
                'illicit/violent' => 0.00002868540823874629, 'self-harm/intent' => 0.998813087895366, 'self-harm/instructions' => 0.00029282492544709094,
                'self-harm' => 0.9765081883024809, 'sexual/minors' => 0.000005144221374220898, 'violence' => 0.4272401150888747,
                'violence/graphic' => 0.00006667023092435894,
            ],
            'modr-80',
        );
    }

    /** @param array<string, mixed> $middleware */
    private static function before(array $middleware, array $state): mixed
    {
        return Utils::getHookFunction($middleware['beforeModel'])($state, new Runtime());
    }

    /** @param array<string, mixed> $middleware */
    private static function after(array $middleware, array $state): mixed
    {
        return Utils::getHookFunction($middleware['afterModel'])($state, new Runtime());
    }

    /** The decoded body of the n-th moderation request. @return array<string, mixed> */
    private function sentBody(int $n): array
    {
        return (array) json_decode($this->http->requests[$n]['body'], true);
    }

    private function assertModerated(int $n, string $input, string $model = 'omni-moderation-latest'): void
    {
        self::assertSame('https://api.openai.com/v1/moderations', $this->http->requests[$n]['url']);
        self::assertSame('Bearer sk-test', $this->http->requests[$n]['headers']['Authorization']);
        self::assertSame(['input' => $input, 'model' => $model], $this->sentBody($n));
    }

    // ---- Initialization ------------------------------------------------------------------------

    public function testShouldCreateMiddlewareWithCorrectName(): void
    {
        self::assertSame('OpenAIModerationMiddleware', ModerationMiddleware::create(['model' => $this->mockModel])['name']);
    }

    public function testShouldThrowErrorIfModelIsNotOpenAI(): void
    {
        $nonOpenAIModel = new class () {
            public function getName(): string
            {
                return 'SomeOtherModel';
            }
        };
        $middleware = ModerationMiddleware::create(['model' => $nonOpenAIModel, 'checkInput' => true, 'exitBehavior' => 'end']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Model must be an OpenAI model');

        self::before($middleware, ['messages' => [new HumanMessage('I want to harm myself')]]);
    }

    public function testShouldThrowErrorIfModelDoesNotSupportModeration(): void
    {
        $modelWithoutModeration = new class () {
            public function getName(): string
            {
                return 'ChatOpenAI';
            }
        };
        $middleware = ModerationMiddleware::create(['model' => $modelWithoutModeration, 'checkInput' => true, 'exitBehavior' => 'end']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Model must support moderation');

        self::before($middleware, ['messages' => [new HumanMessage('I want to harm myself')]]);
    }

    // ---- Input moderation ----------------------------------------------------------------------

    public function testShouldModerateUserInputWhenCheckInputIsTrue(): void
    {
        $this->http->responses = [self::bigFlagged()];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('I want to harm myself')]]);

        self::assertCount(1, $this->http->requests);
        $this->assertModerated(0, 'I want to harm myself');
        self::assertSame('end', $result['jumpTo']);
        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('self-harm', $result['messages'][0]->content);
    }

    public function testShouldNotModerateInputWhenCheckInputIsFalse(): void
    {
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => false]);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Some input')]]);

        self::assertSame([], $this->http->requests);
        self::assertNull($result);
    }

    public function testShouldPassThroughWhenInputIsNotFlagged(): void
    {
        $this->http->responses = [self::clean('modr-81')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true]);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Hello, how are you?')]]);

        self::assertCount(1, $this->http->requests);
        self::assertNull($result);
    }

    // ---- Output moderation ---------------------------------------------------------------------

    public function testShouldModerateAIOutputWhenCheckOutputIsTrue(): void
    {
        $this->http->responses = [self::flagged(['violence' => true], ['violence' => 0.8], 'modr-82')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkOutput' => true, 'exitBehavior' => 'end']);

        $result = self::after($middleware, ['messages' => [new HumanMessage('Tell me about violence'), new AIMessage("Here's some violent content")]]);

        $this->assertModerated(0, "Here's some violent content");
        self::assertSame('end', $result['jumpTo']);
        self::assertStringContainsString('violence', $result['messages'][0]->content);
    }

    public function testShouldNotModerateOutputWhenCheckOutputIsFalse(): void
    {
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkOutput' => false]);

        $result = self::after($middleware, ['messages' => [new AIMessage('Some output')]]);

        self::assertSame([], $this->http->requests);
        self::assertNull($result);
    }

    // ---- Tool result moderation ----------------------------------------------------------------

    public function testShouldModerateToolResultsWhenCheckToolResultsIsTrue(): void
    {
        $this->http->responses = [self::flagged(['hate' => true], ['hate' => 0.9], 'modr-83')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkToolResults' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [
            new HumanMessage('Search for something'),
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'search', 'args' => []]]]),
            new ToolMessage(['content' => 'Hateful tool result', 'tool_call_id' => '1', 'name' => 'search']),
        ]]);

        $this->assertModerated(0, 'Hateful tool result');
        self::assertSame('end', $result['jumpTo']);
        self::assertStringContainsString('hate', $result['messages'][0]->content);
    }

    public function testShouldNotModerateToolResultsWhenCheckToolResultsIsFalse(): void
    {
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkToolResults' => false]);

        $result = self::before($middleware, ['messages' => [
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'tool', 'args' => []]]]),
            new ToolMessage(['content' => 'Tool result', 'tool_call_id' => '1']),
        ]]);

        self::assertSame([], $this->http->requests);
        self::assertNull($result);
    }

    // ---- Exit behaviors ------------------------------------------------------------------------

    public function testShouldThrowErrorWhenExitBehaviorIsError(): void
    {
        $this->http->responses = [self::flagged(['violence' => true], ['violence' => 0.8], 'modr-84')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'error']);

        try {
            self::before($middleware, ['messages' => [new HumanMessage('Violent content')]]);
            self::fail('a flagged message must throw');
        } catch (OpenAIModerationError $error) {
            self::assertSame('Violent content', $error->content);
            self::assertSame('input', $error->stage);
            self::assertTrue($error->result['flagged']);
            self::assertSame($error->getMessage(), $error->originalMessage);
            self::assertSame("I'm sorry, but I can't comply with that request. It was flagged for violence.", $error->getMessage());
        }
    }

    public function testShouldEndExecutionWhenExitBehaviorIsEnd(): void
    {
        $this->http->responses = [self::flagged(['hate' => true], ['hate' => 0.9], 'modr-85')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Hateful content')]]);

        self::assertSame('end', $result['jumpTo']);
        self::assertArrayHasKey('messages', $result);
        self::assertInstanceOf(AIMessage::class, $result['messages'][0]);
    }

    public function testShouldReplaceContentWhenExitBehaviorIsReplace(): void
    {
        $this->http->responses = [self::flagged(['inappropriate' => true], ['inappropriate' => 0.7], 'modr-86')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'replace']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Inappropriate content')]]);

        self::assertArrayNotHasKey('jumpTo', $result);
        self::assertCount(1, $result['messages']);
        self::assertInstanceOf(HumanMessage::class, $result['messages'][0]);
        self::assertStringContainsString('inappropriate', $result['messages'][0]->content);
        self::assertStringNotContainsString('Inappropriate content', $result['messages'][0]->content);
    }

    // ---- Violation message formatting ----------------------------------------------------------

    public function testShouldFormatViolationMessageWithCategories(): void
    {
        $this->http->responses = [self::flagged(['self-harm' => true, 'violence' => true], ['self-harm' => 0.9, 'violence' => 0.8], 'modr-87')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Harmful content')]]);

        self::assertSame(
            "I'm sorry, but I can't comply with that request. It was flagged for self-harm, violence.",
            $result['messages'][0]->content,
        );
    }

    public function testShouldUseCustomViolationMessageTemplate(): void
    {
        $this->http->responses = [self::flagged(['hate' => true], ['hate' => 0.9], 'modr-88')];
        $middleware = ModerationMiddleware::create([
            'model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'end', 'violationMessage' => 'Custom: {categories}',
        ]);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Hateful content')]]);

        self::assertSame('Custom: hate', $result['messages'][0]->content);
    }

    // ---- Edge cases ----------------------------------------------------------------------------

    public function testShouldHandleEmptyMessagesArray(): void
    {
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true]);

        $result = self::before($middleware, ['messages' => []]);

        self::assertSame([], $this->http->requests);
        self::assertNull($result);
    }

    public function testShouldHandleMessagesWithoutTextContent(): void
    {
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true]);

        $result = self::before($middleware, ['messages' => [new HumanMessage(['content' => []])]]);

        self::assertSame([], $this->http->requests);
        self::assertNull($result);
    }

    public function testShouldHandleMultipleToolResults(): void
    {
        $this->http->responses = [self::clean('modr-89'), self::flagged(['violence' => true], ['violence' => 0.8], 'modr-90')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkToolResults' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'tool1', 'args' => []], ['id' => '2', 'name' => 'tool2', 'args' => []]]]),
            new ToolMessage(['content' => 'Safe result', 'tool_call_id' => '1']),
            new ToolMessage(['content' => 'Violent result', 'tool_call_id' => '2']),
        ]]);

        self::assertCount(2, $this->http->requests);
        $this->assertModerated(0, 'Safe result');
        $this->assertModerated(1, 'Violent result');
        self::assertSame('end', $result['jumpTo']);
        self::assertInstanceOf(AIMessage::class, $result['messages'][0]);
        self::assertStringContainsString('violence', $result['messages'][0]->content);
    }

    public function testShouldUseCustomModerationModel(): void
    {
        $this->http->responses = [self::clean('modr-90', 'text-moderation-stable')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'moderationModel' => 'text-moderation-stable', 'checkInput' => true]);

        self::before($middleware, ['messages' => [new HumanMessage('Test input')]]);

        $this->assertModerated(0, 'Test input', 'text-moderation-stable');
    }

    // ---- Integration with the agent ------------------------------------------------------------

    private static function agentModel(array $responses): FakeToolCallingChatModel
    {
        return new FakeToolCallingChatModel(['sleep' => 0, 'responses' => $responses]);
    }

    private static function testTool(int &$calls, string $output): \LangChain\Tools\StructuredTool
    {
        return tool(static function () use (&$calls, $output): string {
            $calls++;

            return $output;
        }, ['name' => 'test_tool', 'description' => 'A test tool', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])]);
    }

    public function testShouldModerateInputInAgentExecution(): void
    {
        $this->http->responses = [self::flagged(['violence' => true], ['violence' => 0.8], 'modr-91')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'exitBehavior' => 'end']);
        $agent = Agent::create(['model' => self::agentModel([new AIMessage('Response')]), 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Violent input')]]);

        self::assertNotEmpty($this->http->requests);
        self::assertStringContainsString('violence', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldModerateOutputInAgentExecution(): void
    {
        $this->http->responses = [self::clean('modr-92'), self::flagged(['hate' => true], ['hate' => 0.9], 'modr-93')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'checkOutput' => true, 'exitBehavior' => 'end']);
        $agent = Agent::create(['model' => self::agentModel([new AIMessage('Hateful response content')]), 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Tell me something')]]);

        self::assertCount(2, $this->http->requests);
        $this->assertModerated(0, 'Tell me something'); // input
        $this->assertModerated(1, 'Hateful response content'); // output
        self::assertStringContainsString('hate', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldModerateToolResultsInAgentExecution(): void
    {
        $this->http->responses = [self::clean('modr-94'), self::flagged(['inappropiate' => true], ['inappropiate' => 0.85], 'modr-95')];
        $toolCalls = 0;
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'checkToolResults' => true, 'exitBehavior' => 'end']);
        $agent = Agent::create([
            'model' => self::agentModel([
                new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call-1', 'name' => 'test_tool', 'args' => ['query' => 'test']]]]),
                new AIMessage('Final response'),
            ]),
            'tools' => [self::testTool($toolCalls, 'Inappropriate tool result')],
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Use the tool')]]);

        self::assertCount(2, $this->http->requests);
        $this->assertModerated(0, 'Use the tool'); // input
        $this->assertModerated(1, 'Inappropriate tool result'); // tool result
        self::assertSame(
            "I'm sorry, but I can't comply with that request. It was flagged for inappropiate.",
            $result['messages'][array_key_last($result['messages'])]->content,
        );
        self::assertGreaterThan(0, $toolCalls, 'the tool ran before its result was moderated');
    }

    public function testShouldModerateInputOutputAndToolResultsTogether(): void
    {
        $this->http->responses = [self::clean('modr-96'), self::flagged(['self-harm' => true], ['self-harm' => 0.95], 'modr-97')];
        $toolCalls = 0;
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'checkInput' => true, 'checkOutput' => true, 'checkToolResults' => true, 'exitBehavior' => 'end']);
        $agent = Agent::create([
            'model' => self::agentModel([new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call-1', 'name' => 'test_tool', 'args' => ['query' => 'test']]]])]),
            'tools' => [self::testTool($toolCalls, 'Self-harm related content')],
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for something')]]);

        self::assertCount(2, $this->http->requests);
        $this->assertModerated(0, 'Search for something'); // input
        $this->assertModerated(1, 'Self-harm related content'); // tool result
        self::assertSame(
            "I'm sorry, but I can't comply with that request. It was flagged for self-harm.",
            $result['messages'][array_key_last($result['messages'])]->content,
        );
    }

    // ---- String model support ------------------------------------------------------------------

    private function installInitChatModel(mixed $result): void
    {
        if (!StubInitChatModel::install()) {
            self::markTestSkipped('initChatModel is ported now: this stub-based case needs a rewrite against the real one.');
        }
        StubInitChatModel::$result = $result;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShouldAcceptStringModelNameForInputModeration(): void
    {
        $this->installInitChatModel($this->mockModel);
        $this->http->responses = [self::flagged(['violence' => true], ['violence' => 0.8], 'modr-100')];
        $middleware = ModerationMiddleware::create(['model' => 'gpt-4o-mini', 'checkInput' => true, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('Violent content')]]);

        self::assertSame([['gpt-4o-mini', []]], StubInitChatModel::$calls);
        $this->assertModerated(0, 'Violent content');
        self::assertSame('end', $result['jumpTo']);
        self::assertSame(
            "I'm sorry, but I can't comply with that request. It was flagged for violence.",
            $result['messages'][array_key_last($result['messages'])]->content,
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShouldLazilyInitializeModelOnlyWhenNeeded(): void
    {
        $this->installInitChatModel($this->mockModel);
        $middleware = ModerationMiddleware::create(['model' => 'gpt-4o-mini', 'checkInput' => false, 'checkOutput' => false, 'checkToolResults' => false]);

        self::before($middleware, ['messages' => [new HumanMessage('Some input')]]);

        // The model is not initialized when no moderation check is enabled.
        self::assertSame([], StubInitChatModel::$calls);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShouldCacheInitializedModelInstance(): void
    {
        $this->installInitChatModel($this->mockModel);
        $this->http->responses = [
            self::flagged(['violence' => true], ['violence' => 0.8], 'modr-104'),
            self::flagged(['violence' => true], ['violence' => 0.8], 'modr-105'),
        ];
        $middleware = ModerationMiddleware::create(['model' => 'gpt-4o-mini', 'checkInput' => true, 'checkOutput' => true, 'exitBehavior' => 'end']);

        self::before($middleware, ['messages' => [new HumanMessage('Violent input')]]);
        self::after($middleware, ['messages' => [new HumanMessage('Safe input'), new AIMessage('Violent output')]]);

        self::assertCount(2, $this->http->requests);
        self::assertSame([['gpt-4o-mini', []]], StubInitChatModel::$calls, 'initChatModel is called once; the model is cached');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShouldThrowErrorIfStringModelDoesNotResolveToOpenAIModel(): void
    {
        $this->installInitChatModel(new class () {
            public function getName(): string
            {
                return 'SomeOtherModel';
            }
        });
        $middleware = ModerationMiddleware::create(['model' => 'non-openai-model', 'checkInput' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Model must be an OpenAI model');

        self::before($middleware, ['messages' => [new HumanMessage('Test input')]]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShouldThrowErrorIfStringModelResolvesToModelWithoutModerationSupport(): void
    {
        $this->installInitChatModel(new class () {
            public function getName(): string
            {
                return 'ChatOpenAI';
            }
        });
        $middleware = ModerationMiddleware::create(['model' => 'gpt-4o-mini', 'checkInput' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Model must support moderation');

        self::before($middleware, ['messages' => [new HumanMessage('Test input')]]);
    }

    // ---- Beyond the upstream cases -------------------------------------------------------------

    public function testAStringModelFailsClearlyWhileInitChatModelIsNotPorted(): void
    {
        if (class_exists('LangChain\\ChatModels\\InitChatModel')) {
            self::markTestSkipped('initChatModel is ported: the string model resolves now.');
        }
        $middleware = ModerationMiddleware::create(['model' => 'gpt-4o-mini', 'checkInput' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('model id strings need initChatModel');

        self::before($middleware, ['messages' => [new HumanMessage('Hello')]]);
    }

    public function testTheModerationClientPostsTheOrganizationHeaderAndDerivesTheEndpointFromTheChatBaseUrl(): void
    {
        $http = new FakeHttpClient([self::clean()]);
        $model = new ChatOpenAI([
            'apiKey' => 'sk-test', 'organization' => 'org-7', 'httpClient' => $http, 'baseUrl' => 'https://proxy.example/v1/chat/completions',
        ]);

        $response = ModerationClient::fromModel($model)->create(['input' => 'hi', 'model' => 'omni-moderation-latest']);

        self::assertSame('https://proxy.example/v1/moderations', $http->requests[0]['url']);
        self::assertSame('org-7', $http->requests[0]['headers']['OpenAI-Organization']);
        self::assertFalse($response['results'][0]['flagged']);
    }

    public function testAnErrorResponseFromTheModerationEndpointIsRaised(): void
    {
        $http = new FakeHttpClient([new HttpResponse(401, [], '{"error":{"message":"bad key"}}')]);
        $middleware = ModerationMiddleware::create(['model' => self::openAI($http), 'checkInput' => true]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('status 401');

        self::before($middleware, ['messages' => [new HumanMessage('Hello')]]);
    }

    public function testTheViolationTemplateFillsInTheScoresAndTheOriginalContent(): void
    {
        $this->http->responses = [self::flagged(['violence' => true, 'self_harm' => true], ['violence' => 0.5], 'modr-7')];
        $middleware = ModerationMiddleware::create([
            'model' => $this->mockModel,
            'exitBehavior' => 'end',
            'violationMessage' => '{categories} / {category_scores} / {original_content}',
        ]);

        $result = self::before($middleware, ['messages' => [new HumanMessage('bad words')]]);

        self::assertSame("violence, self harm / {\n  \"violence\": 0.5\n} / bad words", $result['messages'][0]->content);
    }

    public function testAFlaggedResultWithNoFlaggedCategoryNamesTheSafetyPolicies(): void
    {
        $this->http->responses = [self::flagged(['violence' => false], ['violence' => 0.1], 'modr-8')];
        $middleware = ModerationMiddleware::create(['model' => $this->mockModel, 'exitBehavior' => 'end']);

        $result = self::before($middleware, ['messages' => [new HumanMessage('hm')]]);

        self::assertSame("I'm sorry, but I can't comply with that request. It was flagged for OpenAI's safety policies.", $result['messages'][0]->content);
    }
}
