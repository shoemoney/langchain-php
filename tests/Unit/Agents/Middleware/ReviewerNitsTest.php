<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\Chat\Universal\ConfigurableModel;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\BindRecordingModel;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\InvalidRetryConfigError;
use LangGraph\Agents\Middleware\LlmToolSelectorMiddleware;
use LangGraph\Agents\Middleware\ModelRetryMiddleware;
use LangGraph\Agents\Middleware\PiiRedactionMiddleware;
use LangGraph\Agents\Middleware\ProviderToolSearchMiddleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Wave 4 reviewer nits: the small places the middleware port drifted from upstream.
 */
#[CoversClass(ModelRetryMiddleware::class)]
#[CoversClass(ProviderToolSearchMiddleware::class)]
#[CoversClass(PiiRedactionMiddleware::class)]
#[CoversClass(LlmToolSelectorMiddleware::class)]
final class ReviewerNitsTest extends TestCase
{
    private static function sendEmail(): StructuredTool
    {
        return tool(static fn (): string => 'sent', ['name' => 'send_email', 'description' => 'Send an email', 'schema' => Schema::object(['to' => ['type' => 'string']], ['to'])]);
    }

    public function testRetryOnAcceptsAnInterfaceName(): void
    {
        $middleware = ModelRetryMiddleware::create(['retryOn' => [\Throwable::class], 'maxRetries' => 1, 'initialDelayMs' => 0, 'jitter' => false]);

        self::assertIsCallable($middleware['wrapModelCall']);
    }

    public function testRetryOnStillRejectsAStringThatIsNeitherClassNorInterface(): void
    {
        $this->expectException(InvalidRetryConfigError::class);

        ModelRetryMiddleware::create(['retryOn' => ['Not\\A\\Thing']]);
    }

    public function testAnInterfaceRetryOnRunsThroughARealAgent(): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('done')]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [ModelRetryMiddleware::create(['retryOn' => [\Throwable::class], 'maxRetries' => 1, 'initialDelayMs' => 0, 'jitter' => false])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        self::assertSame('done', $result['messages'][1]->content);
    }

    public function testAnUnknownModelNameIsProviderOtherAndIsRejected(): void
    {
        $middleware = ProviderToolSearchMiddleware::create(['searchableTools' => ['send_email']]);

        $this->expectExceptionMessageMatches('/but got other$/');
        $middleware['wrapModelCall'](
            ['model' => new BindRecordingModel('ChatMistralAI', ['responses' => [new AIMessage('x')]]), 'tools' => [self::sendEmail()], 'messages' => []],
            static fn (array $request): AIMessage => new AIMessage('ok'),
        );
    }

    public function testAConfigurableModelReportsItsDefaultConfigProvider(): void
    {
        $middleware = ProviderToolSearchMiddleware::create(['searchableTools' => ['send_email']]);
        $seen = null;

        $middleware['wrapModelCall'](
            ['model' => new ConfigurableModel(['defaultConfig' => ['model' => 'gpt-5', 'modelProvider' => 'openai']]), 'tools' => [self::sendEmail()], 'messages' => []],
            static function (array $request) use (&$seen): AIMessage {
                $seen = $request;

                return new AIMessage('ok');
            },
        );

        self::assertNotNull($seen, 'an openai-configured model passes provider detection');
        self::assertSame('tool_search', $seen['tools'][1]['type'] ?? null);
    }

    public function testAnExtractCallWithEmptyArgsYieldsAnEmptyStructuredResponse(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        });
        try {
            $middleware = PiiRedactionMiddleware::create(['rules' => ['ssn' => '/\b\d{3}-?\d{2}-?\d{4}\b/']]);
        } finally {
            restore_error_handler();
        }

        // Redact one value so the map is non-empty.
        $request = null;
        $middleware['wrapModelCall'](
            ['messages' => [], 'state' => ['messages' => [new HumanMessage('SSN 123-45-6789')]], 'runtime' => null],
            static function (array $r) use (&$request): AIMessage {
                $request = $r;

                return new AIMessage('ok');
            },
        );
        preg_match('/\[REDACTED_SSN_[a-z0-9]+\]/', (string) $request['messages'][0]->content, $match);

        $call = new AIMessage(['content' => 'see ' . $match[0], 'id' => 'ai-1', 'tool_calls' => [['id' => 'c1', 'name' => 'extract-person', 'args' => []]]]);
        $last = new AIMessage(['content' => 'Returning ' . $match[0], 'id' => 'ai-2']);

        $update = MiddlewareUtils::getHookFunction($middleware['afterModel'])(['messages' => [new HumanMessage('hi'), $call, $last]], null);

        self::assertArrayHasKey('structuredResponse', $update);
        self::assertSame([], $update['structuredResponse']);
    }

    public function testAnEmptyStringSelectorModelUsesTheRequestModel(): void
    {
        $tools = array_map(
            static fn (string $letter): StructuredTool => tool(static fn (): string => $letter, ['name' => "tool{$letter}", 'description' => "Tool {$letter}", 'schema' => Schema::object([])]),
            ['A', 'B', 'C'],
        );
        $agentModel = ReactAgentFixtures::spy([new AIMessage('done')]);
        $agentModel->structuredOutputOverride['runnable'] = \LangChain\Runnables\RunnableLambda::from(static fn (): array => ['tools' => ['toolB']]);

        $agent = Agent::create(['model' => $agentModel, 'tools' => $tools, 'middleware' => [LlmToolSelectorMiddleware::create(['model' => '', 'maxTools' => 1])]]);
        $agent->invoke(['messages' => [new HumanMessage('Use tool B')]]);

        self::assertCount(1, $agentModel->structuredOutputCalls, 'the request model made the selection');
        self::assertSame(['toolB'], array_map(static fn (mixed $t): string => $t->name, $agentModel->bindToolsCalls[0]));
    }
}
