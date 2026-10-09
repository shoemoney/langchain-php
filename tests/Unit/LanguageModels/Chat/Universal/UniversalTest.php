<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\Universal\ConfigurableModel;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\LanguageModels\Chat\Universal\ModelProviders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models/tests/universal.test.ts`: two profile-inference cases and four LangSmith gateway cases.
 *
 * The gateway cases assert on `ChatOpenAI` state. Upstream reads `chat.clientConfig.baseURL`, the root
 * (`.../v1`); this port's `ChatOpenAI` takes one endpoint URL, and with the Responses API forced on that
 * URL is `<root>/responses`, so the assertions read `baseUrl` with that suffix.
 */
#[CoversClass(InitChatModel::class)]
#[CoversClass(ConfigurableModel::class)]
#[CoversClass(ModelProviders::class)]
final class UniversalTest extends TestCase
{
    private const LANGSMITH_ENVIRONMENT_VARIABLES = ['LANGSMITH_GATEWAY', 'LANGSMITH_GATEWAY_API_KEY', 'LANGSMITH_API_KEY'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::LANGSMITH_ENVIRONMENT_VARIABLES as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name . '=');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    // ---- Will appropriately infer a model profiles ---------------------------------------------

    public function testWhenProvidedAProfile(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['apiKey' => 'sk-test', 'profile' => ['maxInputTokens' => 100000]]);

        self::assertSame(100000, $model->profile()['maxInputTokens']);
    }

    public function testWhenItShouldBeInferredFromTheModelInstance(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['apiKey' => 'sk-test', 'temperature' => 0]);

        self::assertArrayHasKey('maxInputTokens', $model->profile());
        self::assertNotNull($model->profile()['maxInputTokens']);
    }

    // ---- initChatModel LangSmith provider -------------------------------------------------------

    private static function chatOpenAI(ConfigurableModel $model): ChatOpenAI
    {
        $chat = $model->getModelInstance();
        self::assertInstanceOf(ChatOpenAI::class, $chat);

        return $chat;
    }

    public function testUsesTheDefaultGatewayAndGatewayApiKey(): void
    {
        putenv('LANGSMITH_GATEWAY_API_KEY=gateway-key');
        putenv('LANGSMITH_API_KEY=langsmith-key');

        $chat = self::chatOpenAI(InitChatModel::init('langsmith:moonshotai/kimi-k3'));

        self::assertSame('moonshotai/kimi-k3', $chat->model);
        self::assertSame('https://gateway.smith.langchain.com/v1/responses', $chat->baseUrl);
        self::assertSame('gateway-key', $chat->apiKey);
        self::assertTrue($chat->useResponsesApi);
    }

    public function testFallsBackToTheLangSmithApiKey(): void
    {
        putenv('LANGSMITH_API_KEY=langsmith-key');

        $chat = self::chatOpenAI(InitChatModel::init('langsmith:moonshotai/kimi-k3'));

        self::assertSame('langsmith-key', $chat->apiKey);
    }

    public function testUsesACustomGatewayUrlAndAlwaysEnablesTheResponsesApi(): void
    {
        putenv('LANGSMITH_GATEWAY=https://eu.gateway.example.com/');
        putenv('LANGSMITH_API_KEY=langsmith-key');

        $chat = self::chatOpenAI(InitChatModel::init('moonshotai/kimi-k3', ['modelProvider' => 'langsmith', 'useResponsesApi' => false]));

        self::assertSame('https://eu.gateway.example.com/v1/responses', $chat->baseUrl);
        self::assertSame('langsmith-key', $chat->apiKey);
        self::assertTrue($chat->useResponsesApi);
    }

    public function testPreservesExplicitGatewayConfiguration(): void
    {
        putenv('LANGSMITH_GATEWAY=https://eu.gateway.example.com');
        putenv('LANGSMITH_GATEWAY_API_KEY=gateway-key');

        $chat = self::chatOpenAI(InitChatModel::init('langsmith:moonshotai/kimi-k3', [
            'apiKey' => 'explicit-key',
            'configuration' => ['baseURL' => 'https://apac.gateway.example.com/v1'],
        ]));

        self::assertSame('https://apac.gateway.example.com/v1/responses', $chat->baseUrl);
        self::assertSame('explicit-key', $chat->apiKey);
    }
}
