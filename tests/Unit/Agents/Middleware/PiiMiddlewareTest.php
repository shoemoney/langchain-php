<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\PiiDetectionError;
use LangGraph\Agents\Middleware\PiiDetectors;
use LangGraph\Agents\Middleware\PiiMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The agent-level `describe` blocks of `langchain/src/agents/middleware/tests/pii.test.ts` (Redact, Mask, Hash and
 * Block Strategy, PII Middleware Integration, Custom Detector, Multiple Middleware).
 */
#[CoversClass(PiiMiddleware::class)]
final class PiiMiddlewareTest extends TestCase
{
    /**
     * @param list<AIMessage>      $responses
     * @param list<array<string, mixed>> $middleware
     * @param list<mixed>          $tools
     * @param list<mixed>          $messages
     * @return array<string, mixed>
     */
    private static function runAgent(array $responses, array $middleware, array $messages, array $tools = []): array
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat($responses),
            'tools' => $tools,
            'middleware' => $middleware,
        ]);

        return $agent->invoke(['messages' => $messages]);
    }

    private static function humanContent(array $result): string
    {
        $messages = AgentAssertions::ofType($result['messages'], HumanMessage::class);
        self::assertNotEmpty($messages);

        return (string) $messages[0]->content;
    }

    // --- Redact Strategy

    public function testShouldRedactEmail(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'redact'])], [new HumanMessage('Email me at test@example.com')]);

        $content = self::humanContent($result);
        self::assertStringContainsString('[REDACTED_EMAIL]', $content);
        self::assertStringNotContainsString('test@example.com', $content);
    }

    public function testShouldRedactMultiplePii(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'redact'])], [new HumanMessage('Contact alice@test.com or bob@test.com')]);

        $content = self::humanContent($result);
        self::assertSame(2, substr_count($content, '[REDACTED_EMAIL]'));
        self::assertStringNotContainsString('alice@test.com', $content);
        self::assertStringNotContainsString('bob@test.com', $content);
    }

    // --- Mask Strategy

    public function testShouldMaskEmail(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'mask'])], [new HumanMessage('Email: user@example.com')]);

        $content = self::humanContent($result);
        self::assertStringContainsString('u***@example.com', $content);
        self::assertStringNotContainsString('user@example.com', $content);
    }

    public function testShouldMaskCreditCard(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('credit_card', ['strategy' => 'mask'])], [new HumanMessage('Card: 4532015112830366')]);

        $content = self::humanContent($result);
        self::assertStringContainsString('0366', $content);
        self::assertStringNotContainsString('4532015112830366', $content);
    }

    public function testShouldMaskIp(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('ip', ['strategy' => 'mask'])], [new HumanMessage('IP: 192.168.1.100')]);

        $content = self::humanContent($result);
        self::assertStringContainsString('*********.100', $content);
        self::assertStringNotContainsString('192.168.1.100', $content);
    }

    // --- Hash Strategy

    public function testShouldHashEmail(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'hash'])], [new HumanMessage('Email: test@example.com')]);

        $content = self::humanContent($result);
        self::assertStringContainsString('<email_hash:', $content);
        self::assertStringContainsString('>', $content);
        self::assertStringNotContainsString('test@example.com', $content);
    }

    public function testShouldProduceDeterministicHash(): void
    {
        $middleware = [PiiMiddleware::create('email', ['strategy' => 'hash'])];
        $agent = Agent::create(['model' => AgentAssertions::fakeChat([new AIMessage('Response')]), 'middleware' => $middleware]);

        $result1 = $agent->invoke(['messages' => [new HumanMessage('Email: test@example.com')]]);
        $result2 = $agent->invoke(['messages' => [new HumanMessage('Email: test@example.com')]]);

        self::assertSame(self::humanContent($result1), self::humanContent($result2));
    }

    // --- Block Strategy

    public function testShouldRaiseExceptionWhenPiiDetected(): void
    {
        $this->expectException(PiiDetectionError::class);

        self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'block'])], [new HumanMessage('Email: test@example.com')]);
    }

    public function testShouldRaiseExceptionWithMultipleMatches(): void
    {
        try {
            self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'block'])], [new HumanMessage('Emails: alice@test.com and bob@test.com')]);
            self::fail('Should have thrown PiiDetectionError');
        } catch (PiiDetectionError $error) {
            self::assertSame('email', $error->piiType);
            self::assertCount(2, $error->matches);
        }
    }

    // --- PII Middleware Integration

    public function testShouldOnlyProcessInputWhenApplyToInputIsTrueAndApplyToOutputIsFalse(): void
    {
        $middleware = PiiMiddleware::create('email', ['strategy' => 'redact', 'applyToInput' => true, 'applyToOutput' => false]);

        $result = self::runAgent([new AIMessage('My email is ai@example.com')], [$middleware], [new HumanMessage('Email: test@example.com')]);

        self::assertStringContainsString('[REDACTED_EMAIL]', self::humanContent($result));
        $ai = AgentAssertions::ofType($result['messages'], AIMessage::class)[0];
        self::assertStringContainsString('ai@example.com', (string) $ai->content);
    }

    public function testShouldOnlyProcessOutputWhenApplyToInputIsFalseAndApplyToOutputIsTrue(): void
    {
        $middleware = PiiMiddleware::create('email', ['strategy' => 'redact', 'applyToInput' => false, 'applyToOutput' => true]);

        $result = self::runAgent([new AIMessage('My email is ai@example.com')], [$middleware], [new HumanMessage('Email: test@example.com')]);

        self::assertStringContainsString('test@example.com', self::humanContent($result));
        $ai = AgentAssertions::ofType($result['messages'], AIMessage::class)[0];
        self::assertStringContainsString('[REDACTED_EMAIL]', (string) $ai->content);
        self::assertStringNotContainsString('ai@example.com', (string) $ai->content);
    }

    public function testShouldProcessBothInputAndOutputWhenBothAreEnabled(): void
    {
        $middleware = PiiMiddleware::create('email', ['strategy' => 'redact', 'applyToInput' => true, 'applyToOutput' => true]);

        $result = self::runAgent([new AIMessage('My email is ai@example.com')], [$middleware], [new HumanMessage('Email: test@example.com')]);

        self::assertStringContainsString('[REDACTED_EMAIL]', self::humanContent($result));
        $ai = AgentAssertions::ofType($result['messages'], AIMessage::class)[0];
        self::assertStringContainsString('[REDACTED_EMAIL]', (string) $ai->content);
    }

    public function testShouldReturnNoChangesWhenNoPiiDetected(): void
    {
        $result = self::runAgent([new AIMessage('No PII here')], [PiiMiddleware::create('email', ['strategy' => 'redact'])], [new HumanMessage('No PII here')]);

        self::assertGreaterThan(0, \count($result['messages']));
        self::assertSame('No PII here', self::humanContent($result));
    }

    public function testShouldHandleEmptyMessagesGracefully(): void
    {
        $result = self::runAgent([new AIMessage('Response')], [PiiMiddleware::create('email', ['strategy' => 'redact'])], []);

        self::assertArrayHasKey('messages', $result);
    }

    public function testRunContextOverridesTheOptions(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')]),
            'middleware' => [PiiMiddleware::create('email', ['strategy' => 'redact', 'applyToInput' => true])],
            'contextSchema' => ['type' => 'object', 'properties' => ['applyToInput' => ['type' => 'boolean']]],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Email: test@example.com')]], ['context' => ['applyToInput' => false]]);

        self::assertStringContainsString('test@example.com', self::humanContent($result));
    }

    public function testShouldProcessToolResultsWhenApplyToToolResultsIsTrue(): void
    {
        $middleware = PiiMiddleware::create('email', ['strategy' => 'redact', 'applyToInput' => false, 'applyToToolResults' => true]);
        $searchTool = tool(static fn (array $in): string => 'Found: john@example.com', ['name' => 'search', 'description' => 'Search for information', 'schema' => Schema::object([])]);

        $result = self::runAgent(
            [new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_123', 'name' => 'search', 'args' => []]]]), new AIMessage('Response')],
            [$middleware],
            [new HumanMessage('Search for John')],
            [$searchTool],
        );

        $toolMessage = AgentAssertions::ofType($result['messages'], ToolMessage::class)[0] ?? null;
        self::assertNotNull($toolMessage);
        self::assertStringContainsString('[REDACTED_EMAIL]', (string) $toolMessage->content);
        self::assertStringNotContainsString('john@example.com', (string) $toolMessage->content);
        self::assertSame('call_123', $toolMessage->toolCallId);
    }

    public function testShouldApplyMaskStrategyToToolResults(): void
    {
        $middleware = PiiMiddleware::create('ip', ['strategy' => 'mask', 'applyToInput' => false, 'applyToToolResults' => true]);
        $getIpTool = tool(static fn (array $in): string => 'Server IP: 192.168.1.100', ['name' => 'get_ip', 'description' => 'Get server IP address', 'schema' => Schema::object([])]);

        $result = self::runAgent(
            [new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_456', 'name' => 'get_ip', 'args' => []]]]), new AIMessage('Response')],
            [$middleware],
            [new HumanMessage('Get server IP')],
            [$getIpTool],
        );

        $toolMessage = AgentAssertions::ofType($result['messages'], ToolMessage::class)[0] ?? null;
        self::assertInstanceOf(ToolMessage::class, $toolMessage);
        self::assertStringContainsString('.100', (string) $toolMessage->content);
        self::assertStringNotContainsString('192.168.1.100', (string) $toolMessage->content);
    }

    public function testShouldBlockPiiInToolResultsWhenStrategyIsBlock(): void
    {
        $middleware = PiiMiddleware::create('email', ['strategy' => 'block', 'applyToInput' => false, 'applyToToolResults' => true]);
        $searchTool = tool(static fn (array $in): string => 'User email: sensitive@example.com', ['name' => 'search', 'description' => 'Search for user information', 'schema' => Schema::object([])]);

        $this->expectException(PiiDetectionError::class);

        self::runAgent(
            [new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_789', 'name' => 'search', 'args' => []]]]), new AIMessage('Response')],
            [$middleware],
            [new HumanMessage('Search for user')],
            [$searchTool],
        );
    }

    public function testShouldWorkWithCreateAgent(): void
    {
        $result = self::runAgent([new AIMessage('Thanks for sharing!')], [PiiMiddleware::create('email', ['strategy' => 'redact'])], [new HumanMessage('Email: test@example.com')]);

        $texts = AgentAssertions::texts($result['messages']);
        self::assertNotEmpty(array_filter($texts, static fn (string $text): bool => str_contains($text, '[REDACTED_EMAIL]')));
    }

    // --- Custom Detector

    public function testShouldWorkWithCustomRegexDetector(): void
    {
        $middleware = PiiMiddleware::create('api_key', ['detector' => 'sk-[a-zA-Z0-9]{32}', 'strategy' => 'redact']);

        $result = self::runAgent([new AIMessage('Response')], [$middleware], [new HumanMessage('Key: sk-abcdefghijklmnopqrstuvwxyz123456')]);

        self::assertStringContainsString('[REDACTED_API_KEY]', self::humanContent($result));
    }

    public function testShouldWorkWithCustomCallableDetector(): void
    {
        $detectCustom = static function (string $content): array {
            $matches = [];
            if (str_contains($content, 'CONFIDENTIAL')) {
                $idx = strpos($content, 'CONFIDENTIAL');
                $matches[] = ['text' => 'CONFIDENTIAL', 'start' => $idx, 'end' => $idx + 12];
            }

            return $matches;
        };
        $middleware = PiiMiddleware::create('confidential', ['detector' => $detectCustom, 'strategy' => 'redact']);

        $result = self::runAgent([new AIMessage('Response')], [$middleware], [new HumanMessage('This is CONFIDENTIAL information')]);

        self::assertStringContainsString('[REDACTED_CONFIDENTIAL]', self::humanContent($result));
    }

    public function testShouldThrowErrorForUnknownBuiltinTypeWithoutDetector(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown PII type');

        PiiMiddleware::create('unknown_type', ['strategy' => 'redact']);
    }

    public function testShouldNotThrowErrorForCustomTypeWithDetector(): void
    {
        $middleware = PiiMiddleware::create('custom_type', ['detector' => '/\d+/', 'strategy' => 'redact']);

        self::assertSame('PIIMiddleware[custom_type]', $middleware['name']);
    }

    // --- Multiple Middleware

    public function testShouldApplyMultiplePiiTypesSequentially(): void
    {
        $result = self::runAgent(
            [new AIMessage('Response')],
            [PiiMiddleware::create('email', ['strategy' => 'redact']), PiiMiddleware::create('ip', ['strategy' => 'mask'])],
            [new HumanMessage('Email: test@example.com, IP: 192.168.1.1')],
        );

        $content = self::humanContent($result);
        self::assertStringContainsString('[REDACTED_EMAIL]', $content);
        self::assertStringNotContainsString('test@example.com', $content);
        self::assertStringContainsString('.1', $content);
        self::assertStringNotContainsString('192.168.1.1', $content);
    }

    public function testShouldWorkWithMultiplePiiMiddlewareInstancesInCreateAgent(): void
    {
        $result = self::runAgent(
            [new AIMessage('Response received')],
            [PiiMiddleware::create('email', ['strategy' => 'redact']), PiiMiddleware::create('ip', ['strategy' => 'mask'])],
            [new HumanMessage('Contact: test@example.com, IP: 192.168.1.100')],
        );

        $content = implode(' ', AgentAssertions::texts($result['messages']));
        self::assertStringNotContainsString('test@example.com', $content);
        self::assertStringNotContainsString('192.168.1.100', $content);
    }

    public function testShouldWorkWithCustomDetectorCombiningMultipleTypes(): void
    {
        $detectEmailAndIp = static fn (string $content): array => [...PiiDetectors::detectEmail($content), ...PiiDetectors::detectIP($content)];
        $middleware = PiiMiddleware::create('email_or_ip', ['detector' => $detectEmailAndIp, 'strategy' => 'redact']);

        $result = self::runAgent([new AIMessage('Response')], [$middleware], [new HumanMessage('Email: test@example.com, IP: 10.0.0.1')]);

        $content = self::humanContent($result);
        self::assertStringNotContainsString('test@example.com', $content);
        self::assertStringNotContainsString('10.0.0.1', $content);
    }
}
