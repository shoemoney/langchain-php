<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\PiiRedactionMiddleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `piiRedactionMiddleware` has only a live integration test upstream
 * (`tests/piiRedaction.int.test.ts`, which calls real OpenAI / Anthropic endpoints and is skipped here), so
 * this is a small unit test of the ported source: a scripted model sees only the redacted request, and the
 * response (and the arguments of a tool call) are restored.
 */
#[CoversClass(PiiRedactionMiddleware::class)]
final class PiiRedactionMiddlewareTest extends TestCase
{
    private const SSN_RULES = ['ssn' => '/\b\d{3}-?\d{2}-?\d{4}\b/'];

    /**
     * Build the middleware, swallowing the deprecation notice every creation triggers.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function middleware(array $options = []): array
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $middleware = PiiRedactionMiddleware::create($options);
        } finally {
            restore_error_handler();
        }
        self::assertCount(1, $notices);

        return $middleware;
    }

    /**
     * A model that records what it was sent and answers through a callback taking the last user text.
     *
     * @param callable(string): AIMessage $respond
     * @param list<list<BaseMessage>>     $seen
     */
    private static function echoModel(callable $respond, array &$seen): BaseChatModel
    {
        return new class($respond, $seen) extends BaseChatModel {
            /** @var callable */
            private $respond;

            /** @var list<list<BaseMessage>> */
            private array $seen;

            /** @param list<list<BaseMessage>> $seen */
            public function __construct(callable $respond, array &$seen)
            {
                parent::__construct([]);
                $this->respond = $respond;
                $this->seen = &$seen;
            }

            public function llmType(): string
            {
                return 'redaction-echo';
            }

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                $this->seen[] = $messages;
                $last = '';
                foreach ($messages as $message) {
                    if ($message instanceof HumanMessage || $message instanceof ToolMessage) {
                        $last = (string) $message->content;
                    }
                }

                return new ChatResult([new ChatGeneration(($this->respond)($last), '')]);
            }

            public function bindTools(array $tools, array $kwargs = []): static
            {
                return clone $this;
            }
        };
    }

    private static function marker(string $text): string
    {
        self::assertSame(1, preg_match('/\[REDACTED_[A-Z_]+_\w+\]/', $text, $m), 'no marker in: ' . $text);

        return $m[0];
    }

    public function testTheCreationWarnsThatItIsDeprecated(): void
    {
        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            $notices[] = [$level, $message];

            return true;
        });
        try {
            $middleware = PiiRedactionMiddleware::create();
        } finally {
            restore_error_handler();
        }

        self::assertSame('PIIRedactionMiddleware', $middleware['name']);
        self::assertSame(\E_USER_DEPRECATED, $notices[0][0]);
        self::assertStringContainsString('piiRedactionMiddleware is deprecated', $notices[0][1]);
    }

    public function testTheModelOnlySeesTheMarkerAndTheResponseIsRestored(): void
    {
        $seen = [];
        $model = self::echoModel(static fn (string $text): AIMessage => new AIMessage('You said: ' . $text), $seen);
        $agent = Agent::create(['model' => $model, 'middleware' => [self::middleware(['rules' => self::SSN_RULES])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('My SSN is 123-45-6789')]]);

        // What went to the provider.
        self::assertCount(1, $seen);
        $sent = (string) $seen[0][0]->content;
        self::assertMatchesRegularExpression('/^My SSN is \[REDACTED_SSN_[a-z0-9]{9}\]$/', $sent);
        self::assertStringNotContainsString('123-45-6789', $sent);

        // What the user gets back: the AI answer carries the real value again; the stored human message too, since the redaction is request-only.
        $messages = $result['messages'];
        $ai = $messages[array_key_last($messages)];
        self::assertSame('You said: My SSN is 123-45-6789', $ai->content);
        self::assertSame('My SSN is 123-45-6789', $messages[0]->content);
    }

    public function testToolCallArgumentsAreRestoredBeforeTheToolRuns(): void
    {
        $seenByTool = [];
        $lookup = tool(
            static function (array $in) use (&$seenByTool): string {
                $seenByTool[] = $in['ssn'];

                return 'found';
            },
            ['name' => 'lookup_user', 'description' => 'Look up a user by SSN', 'schema' => Schema::object(['ssn' => ['type' => 'string']], ['ssn'])],
        );
        $seen = [];
        $calls = 0;
        $model = self::echoModel(
            static function (string $text) use (&$calls): AIMessage {
                return ++$calls === 1
                    ? new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_1', 'name' => 'lookup_user', 'args' => ['ssn' => self::marker($text)]]]])
                    : new AIMessage('done');
            },
            $seen,
        );
        $agent = Agent::create(['model' => $model, 'tools' => [$lookup], 'middleware' => [self::middleware(['rules' => self::SSN_RULES])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Look up SSN 123-45-6789')]]);

        self::assertSame(['123-45-6789'], $seenByTool);
        $aiWithCall = array_values(array_filter($result['messages'], static fn (BaseMessage $m): bool => $m instanceof AIMessage && $m->toolCalls !== []));
        self::assertCount(1, $aiWithCall);
        self::assertSame('123-45-6789', $aiWithCall[0]->toolCalls[0]['args']['ssn']);
        // The second model call saw the tool call arguments redacted again.
        $secondRequest = $seen[1];
        $sentCall = array_values(array_filter($secondRequest, static fn (BaseMessage $m): bool => $m instanceof AIMessage))[0];
        self::assertMatchesRegularExpression('/^\[REDACTED_SSN_[a-z0-9]{9}\]$/', $sentCall->toolCalls[0]['args']['ssn']);
    }

    public function testWithoutRulesTheRequestIsLeftAlone(): void
    {
        $seen = [];
        $model = self::echoModel(static fn (string $text): AIMessage => new AIMessage('ok'), $seen);
        $agent = Agent::create(['model' => $model, 'middleware' => [self::middleware()]]);

        $agent->invoke(['messages' => [new HumanMessage('My SSN is 123-45-6789')]]);

        self::assertSame('My SSN is 123-45-6789', $seen[0][0]->content);
    }

    public function testRulesFromTheRunContextWinOverTheOptions(): void
    {
        $seen = [];
        $model = self::echoModel(static fn (string $text): AIMessage => new AIMessage('ok'), $seen);
        $agent = Agent::create([
            'model' => $model,
            'middleware' => [self::middleware(['rules' => self::SSN_RULES])],
            'contextSchema' => ['type' => 'object', 'properties' => ['rules' => ['type' => 'object']]],
        ]);

        $agent->invoke(
            ['messages' => [new HumanMessage('Mail me at a@b.com, SSN 123-45-6789')]],
            ['context' => ['rules' => ['email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/']]],
        );

        $sent = (string) $seen[0][0]->content;
        self::assertStringContainsString('[REDACTED_EMAIL_', $sent);
        self::assertStringContainsString('123-45-6789', $sent);
    }

    public function testAMarkerWithoutAMappingIsKept(): void
    {
        $seen = [];
        $model = self::echoModel(static fn (string $text): AIMessage => new AIMessage('see [REDACTED_SSN_unknown123] and ' . self::marker($text)), $seen);
        $agent = Agent::create(['model' => $model, 'middleware' => [self::middleware(['rules' => self::SSN_RULES])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('SSN 123-45-6789')]]);

        $ai = $result['messages'][array_key_last($result['messages'])];
        self::assertSame('see [REDACTED_SSN_unknown123] and 123-45-6789', $ai->content);
    }

    /**
     * Redact a message through the request phase of a middleware, returning what the model would be sent.
     *
     * @param array<string, mixed> $middleware
     */
    private static function redact(array $middleware, string $text): string
    {
        $sent = '';
        $middleware['wrapModelCall'](
            ['state' => ['messages' => [new HumanMessage($text)]], 'runtime' => null],
            static function (array $request) use (&$sent): AIMessage {
                $sent = (string) $request['messages'][0]->content;

                return new AIMessage('x');
            },
        );

        return $sent;
    }

    public function testAFinalJsonMessageIsRestoredIntoTheStructuredResponse(): void
    {
        $middleware = self::middleware(['rules' => self::SSN_RULES]);
        $marker = self::marker(self::redact($middleware, 'SSN 123-45-6789'));
        $ai = new AIMessage(['content' => '{"ssn":"' . $marker . '"}', 'id' => 'ai-1']);

        $update = MiddlewareUtils::getHookFunction($middleware['afterModel'])(['messages' => [new HumanMessage('hi'), $ai]], null);

        self::assertSame(['ssn' => '123-45-6789'], $update['structuredResponse']);
        self::assertInstanceOf(RemoveMessage::class, $update['messages'][0]);
        self::assertSame('ai-1', $update['messages'][0]->id);
        self::assertSame('{"ssn":"123-45-6789"}', $update['messages'][1]->content);
    }

    public function testAnExtractToolCallAndItsFollowingMessageAreRestored(): void
    {
        $middleware = self::middleware(['rules' => self::SSN_RULES]);
        $marker = self::marker(self::redact($middleware, 'SSN 123-45-6789'));
        $call = new AIMessage(['content' => '', 'id' => 'ai-1', 'tool_calls' => [['id' => 'c1', 'name' => 'extract-person', 'args' => ['ssn' => $marker]]]]);
        $last = new AIMessage(['content' => 'Returning ' . $marker, 'id' => 'ai-2']);

        $update = MiddlewareUtils::getHookFunction($middleware['afterModel'])(['messages' => [new HumanMessage('hi'), $call, $last]], null);

        self::assertSame(['ssn' => '123-45-6789'], $update['structuredResponse']);
        self::assertCount(4, $update['messages']);
        self::assertSame(['ai-1', 'ai-2'], [$update['messages'][0]->id, $update['messages'][1]->id]);
        self::assertSame('123-45-6789', $update['messages'][2]->toolCalls[0]['args']['ssn']);
        self::assertSame('Returning 123-45-6789', $update['messages'][3]->content);
    }

    public function testNothingIsRestoredWhenNothingWasRedacted(): void
    {
        $middleware = self::middleware(['rules' => self::SSN_RULES]);

        $update = MiddlewareUtils::getHookFunction($middleware['afterModel'])(['messages' => [new AIMessage('[REDACTED_SSN_abc123456]')]], null);

        self::assertNull($update);
    }

    public function testBlockContentIsRedactedThroughItsJsonForm(): void
    {
        $middleware = self::middleware(['rules' => self::SSN_RULES]);
        $sent = [];
        $middleware['wrapModelCall'](
            ['state' => ['messages' => [new HumanMessage([['type' => 'text', 'text' => 'SSN 123-45-6789']])]], 'runtime' => null],
            static function (array $request) use (&$sent): AIMessage {
                $sent = $request['messages'][0]->content;

                return new AIMessage('x');
            },
        );

        self::assertSame('text', $sent[0]['type']);
        self::assertMatchesRegularExpression('/^SSN \[REDACTED_SSN_[a-z0-9]{9}\]$/', $sent[0]['text']);
    }
}
