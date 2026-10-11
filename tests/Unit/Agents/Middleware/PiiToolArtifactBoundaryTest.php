<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\PiiMiddleware;
use LangGraph\Agents\Middleware\PiiRedactionMiddleware;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHP-authored boundary regressions against LC JS 17b6bba8343bdd3f3dfc5c59578e0b72337ec740.
 * Modern pii.ts:623-661 selects four ToolMessage fields on a content match; deprecated
 * piiRedaction.ts:99-104,370-395 spreads the message into a request-only reconstruction.
 * Neither contract scans artifacts or scrubs earlier checkpoint history. These are ordinary
 * ToolMessage fixtures; typed JS status/metadata, chunks and provider HTTP output are separate.
 */
#[CoversClass(PiiMiddleware::class)]
#[CoversClass(PiiRedactionMiddleware::class)]
final class PiiToolArtifactBoundaryTest extends TestCase
{
    private const CONTENT_EMAIL = 'content@example.test';
    private const ARTIFACT_EMAIL = 'artifact@example.test';
    private const ARTIFACT = ['raw_email' => self::ARTIFACT_EMAIL, 'source' => 'tool-private-result'];
    private const EXTRA = ['status' => 'error', 'marker' => 'extra'];
    private const RESPONSE = ['marker' => 'response'];

    /** @return iterable<string, array{string, string, bool, bool}> */
    public static function scenarios(): iterable
    {
        yield 'modern matched content' => ['modern', 'matched', true, true];
        yield 'modern clean content' => ['modern', 'clean', true, true];
        yield 'modern artifact-only PII under block' => ['modern-block', 'clean', true, true];
        yield 'modern empty content' => ['modern', 'empty', true, true];
        yield 'modern disabled tool processing' => ['modern', 'matched', false, true];
        yield 'modern no AI predecessor' => ['modern', 'matched', true, false];
        yield 'deprecated matched content' => ['deprecated', 'matched', true, true];
        yield 'deprecated artifact-only PII' => ['deprecated', 'clean', true, true];
        yield 'deprecated empty content' => ['deprecated', 'empty', true, true];
        yield 'deprecated empty rules' => ['deprecated', 'matched', false, true];
        yield 'deprecated no AI predecessor' => ['deprecated', 'matched', true, false];
    }

    #[DataProvider('scenarios')]
    public function testActualAgentModelStateAndCheckpointBoundaries(string $kind, string $contentKind, bool $enabled, bool $hasAi): void
    {
        $original = self::tool($contentKind);
        $capture = (object) ['requests' => []];
        $model = new class($capture) extends BaseChatModel {
            public function __construct(private readonly object $capture)
            {
                parent::__construct([]);
            }

            public function llmType(): string
            {
                return 'pii-artifact-boundary-recording-model';
            }

            public function bindTools(array $tools, array $kwargs = []): static
            {
                return clone $this;
            }

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                $this->capture->requests[] = $messages;

                return new ChatResult([new ChatGeneration(new AIMessage('done'), '')]);
            }
        };
        $saver = new class extends MemorySaver {
            public function serializedStorage(): array
            {
                return $this->storage;
            }
        };
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'middleware' => [self::middleware($kind, $enabled)],
            'checkpointer' => $saver,
        ]);
        $config = new RunnableConfig(configurable: ['thread_id' => implode('-', [$kind, $contentKind, $enabled ? 'enabled' : 'disabled', $hasAi ? 'ai' : 'no-ai'])]);
        $result = $agent->invoke(['messages' => self::messages($original, $hasAi)], $config);
        self::assertCount(1, $capture->requests);
        $modelInput = self::findTool($capture->requests[0]);
        $finalState = self::findTool($result['messages']);
        $tuple = $saver->getTuple(['configurable' => $config->configurable]);
        self::assertNotNull($tuple);
        $latestCheckpoint = self::findTool($tuple->checkpoint->channelValues['messages']);

        $modernChanged = $kind !== 'deprecated' && $contentKind === 'matched' && $enabled && $hasAi;
        foreach ([$modelInput, $finalState, $latestCheckpoint] as $message) {
            self::assertBoundaryFields($message, $modernChanged);
        }
        if ($contentKind === 'matched' && $enabled && ($kind === 'deprecated' || $hasAi)) {
            self::assertStringNotContainsString(self::CONTENT_EMAIL, (string) $modelInput->content);
            if ($kind === 'deprecated') {
                self::assertMatchesRegularExpression('/^Contact \[REDACTED_EMAIL_[a-z0-9]{9}\]$/', (string) $modelInput->content);
            } else {
                self::assertSame('Contact [REDACTED_EMAIL]', $modelInput->content);
            }
        } else {
            self::assertSame($original->content, $modelInput->content);
        }
        $expectedStoredContent = $modernChanged ? 'Contact [REDACTED_EMAIL]' : $original->content;
        self::assertSame($expectedStoredContent, $finalState->content);
        self::assertSame($expectedStoredContent, $latestCheckpoint->content);
        // Deprecated processing changes the model request, never the existing input tool state.
        self::assertSame(self::ARTIFACT, $original->artifact);
        self::assertSame(self::content($contentKind), $original->content);
        self::assertSame(self::EXTRA, $original->additional_kwargs);

        $historicalTools = [];
        foreach ($agent->getStateHistory($config) as $snapshot) {
            foreach ($snapshot['values']['messages'] ?? [] as $message) {
                if ($message instanceof ToolMessage) {
                    $historicalTools[] = $message;
                }
            }
        }
        $originalHistory = array_values(array_filter($historicalTools, static fn (ToolMessage $message): bool =>
            $message->content === $original->content && $message->artifact === self::ARTIFACT));
        self::assertNotEmpty($originalHistory, 'Earlier checkpoint history still contains the original tool content and raw artifact.');
        foreach ($originalHistory as $message) {
            self::assertBoundaryFields($message, false);
        }
        if ($modernChanged) {
            self::assertNotEmpty(array_filter($historicalTools, static fn (ToolMessage $message): bool =>
                $message->content === 'Contact [REDACTED_EMAIL]' && !array_key_exists('artifact', $message->kwargs())));
        }
        $storage = json_encode($saver->serializedStorage(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString(self::ARTIFACT_EMAIL, $storage);
        if ($contentKind === 'matched') {
            self::assertStringContainsString(self::CONTENT_EMAIL, $storage);
        }
    }

    #[DataProvider('scenarios')]
    public function testPublicHooksReconstructOnlyOnTheirOwnContentContract(string $kind, string $contentKind, bool $enabled, bool $hasAi): void
    {
        $original = self::tool($contentKind);
        $messages = self::messages($original, $hasAi);
        $middleware = self::middleware($kind, $enabled);
        $matched = $contentKind === 'matched' && $enabled;
        if ($kind === 'deprecated') {
            $seen = null;
            $response = new AIMessage('done');
            $actual = $middleware['wrapModelCall'](
                ['messages' => $messages, 'state' => ['messages' => $messages], 'runtime' => null],
                static function (array $request) use (&$seen, $response): AIMessage {
                    $seen = self::findTool($request['messages']);

                    return $response;
                },
            );
            self::assertSame($response, $actual);
            self::assertInstanceOf(ToolMessage::class, $seen);
            self::assertBoundaryFields($seen, false);
            if ($matched) {
                self::assertNotSame($original, $seen);
                self::assertStringNotContainsString(self::CONTENT_EMAIL, (string) $seen->content);
            } else {
                self::assertSame($original, $seen);
            }
            self::assertSame($original, self::findTool($messages));
        } else {
            $update = Utils::getHookFunction($middleware['beforeModel'])(['messages' => $messages], null);
            if ($matched && $hasAi) {
                self::assertIsArray($update);
                $changed = self::findTool($update['messages']);
                self::assertNotSame($original, $changed);
                self::assertBoundaryFields($changed, true);
            } else {
                self::assertNull($update, 'Artifact-only PII, disabled flags, empty content and no-AI paths return no state update.');
                self::assertSame($original, self::findTool($messages));
                self::assertBoundaryFields($original, false);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function middleware(string $kind, bool $enabled): array
    {
        if ($kind !== 'deprecated') {
            return PiiMiddleware::create('email', [
                'strategy' => $kind === 'modern-block' ? 'block' : 'redact',
                'applyToInput' => false,
                'applyToToolResults' => $enabled,
            ]);
        }

        $notices = [];
        set_error_handler(static function (int $level, string $message) use (&$notices): bool {
            if ($level !== E_USER_DEPRECATED || !str_contains($message, 'piiRedactionMiddleware is deprecated')) {
                return false;
            }
            $notices[] = $message;

            return true;
        });
        try {
            $middleware = PiiRedactionMiddleware::create(['rules' => $enabled ? ['email' => '/content@example\\.test/'] : []]);
        } finally {
            restore_error_handler();
        }
        self::assertCount(1, $notices);

        return $middleware;
    }

    private static function content(string $kind): string
    {
        return match ($kind) {
            'matched' => 'Contact ' . self::CONTENT_EMAIL,
            'empty' => '',
            default => 'clean tool text',
        };
    }

    private static function tool(string $contentKind): ToolMessage
    {
        return new ToolMessage([
            'content' => self::content($contentKind),
            'id' => 'tool-id',
            'name' => 'lookup',
            'tool_call_id' => 'call-id',
            'tool_name' => 'producer-extension',
            'artifact' => self::ARTIFACT,
            // PHP's existing status convention is a bag entry, not a typed ToolMessage.status API.
            'additional_kwargs' => self::EXTRA,
            'response_metadata' => self::RESPONSE,
        ]);
    }

    /** @return list<HumanMessage|AIMessage|ToolMessage> */
    private static function messages(ToolMessage $tool, bool $hasAi): array
    {
        return [new HumanMessage('continue'), ...($hasAi ? [new AIMessage('prior')] : []), $tool];
    }

    private static function findTool(array $messages): ToolMessage
    {
        $tools = array_values(array_filter($messages, static fn (mixed $message): bool => $message instanceof ToolMessage));
        self::assertCount(1, $tools);

        return $tools[0];
    }

    private static function assertBoundaryFields(ToolMessage $message, bool $modernChanged): void
    {
        self::assertSame('tool-id', $message->id);
        self::assertSame('lookup', $message->name);
        self::assertSame('call-id', $message->toolCallId);
        self::assertSame($modernChanged ? null : self::ARTIFACT, $message->artifact);
        self::assertSame(!$modernChanged, array_key_exists('artifact', $message->kwargs()));
        self::assertSame($modernChanged ? [] : self::EXTRA, $message->additional_kwargs);
        self::assertSame($modernChanged ? [] : self::RESPONSE, $message->response_metadata);
        self::assertSame($modernChanged ? null : 'producer-extension', $message->toolName);
    }
}
