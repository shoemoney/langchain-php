<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Load\Load;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Constructor defaults, captured fields and fake Messages API requests. */
#[CoversClass(ChatAnthropic::class)]
final class AnthropicTokenDefaultsTest extends TestCase
{
    private const SYNTH_KEY = 'SYNTH_ANTHROPIC_TOKEN_DEFAULT_KEY';

    private const RESPONSE = <<<'JSON'
{"id":"msg_synth","type":"message","role":"assistant","model":"claude-sonnet-4-5","content":[{"type":"text","text":"SYNTH_OUTPUT"}],"stop_reason":"end_turn","stop_sequence":null,"usage":{"input_tokens":5,"output_tokens":2}}
JSON;

    private const SSE = <<<'SSE'
event: message_start
data: {"type":"message_start","message":{"id":"msg_synth","type":"message","role":"assistant","model":"claude-sonnet-4-5","content":[],"stop_reason":null,"stop_sequence":null,"usage":{"input_tokens":5,"output_tokens":0}}}

event: content_block_start
data: {"type":"content_block_start","index":0,"content_block":{"type":"text","text":""}}

event: content_block_delta
data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"SYNTH_OUTPUT"}}

event: content_block_stop
data: {"type":"content_block_stop","index":0}

event: message_delta
data: {"type":"message_delta","delta":{"stop_reason":"end_turn","stop_sequence":null},"usage":{"output_tokens":2}}

event: message_stop
data: {"type":"message_stop"}


SSE;

    /** All expectations are literal; the production map is never inspected. */
    public static function prefixRows(): array
    {
        return [
            'opus-5' => ['claude-opus-5', 16384],
            'sonnet-5' => ['claude-sonnet-5', 16384],
            'fable-5-1' => ['claude-fable-5-1', 16384],
            'fable-5' => ['claude-fable-5', 16384],
            'mythos-5' => ['claude-mythos-5', 16384],
            'mythos-preview' => ['claude-mythos-preview', 16384],
            'opus-4-7' => ['claude-opus-4-7', 16384],
            'opus-4-6' => ['claude-opus-4-6', 16384],
            'sonnet-4-6' => ['claude-sonnet-4-6', 16384],
            'opus-4-5' => ['claude-opus-4-5', 16384],
            'sonnet-4-5' => ['claude-sonnet-4-5', 16384],
            'haiku-4-5' => ['claude-haiku-4-5', 16384],
            'opus-4-1' => ['claude-opus-4-1', 16384],
            'sonnet-4' => ['claude-sonnet-4', 16384],
            'opus-4' => ['claude-opus-4', 16384],
            'sonnet-3-7' => ['claude-3-7-sonnet', 8192],
            'sonnet-3-5' => ['claude-3-5-sonnet', 8192],
            'haiku-3-5' => ['claude-3-5-haiku', 8192],
            'opus-3' => ['claude-3-opus', 4096],
            'sonnet-3' => ['claude-3-sonnet', 4096],
            'haiku-3' => ['claude-3-haiku', 4096],
            'broad-opus' => ['claude-opus-4-8', 16384],
            'broad-sonnet' => ['claude-sonnet-4-8', 16384],
            'dated-fable' => ['claude-fable-5-1-20261001', 16384],
            'dated-mythos-preview' => ['claude-mythos-preview-20261001', 16384],
            'dated-sonnet-3-7' => ['claude-3-7-sonnet-20250219', 8192],
            'latest-sonnet-3-5' => ['claude-3-5-sonnet-latest', 8192],
            'dated-haiku-3-5' => ['claude-3-5-haiku-20241022', 8192],
            'dated-opus-3' => ['claude-3-opus-20240229', 4096],
            'dated-sonnet-3' => ['claude-3-sonnet-20240229', 4096],
            'dated-haiku-3' => ['claude-3-haiku-20240307', 4096],
            'uppercase-is-unmatched' => ['CLAUDE-OPUS-5', 4096],
            'leading-space-is-unmatched' => [' claude-opus-5', 4096],
            'substring-is-unmatched' => ['xclaude-opus-5', 4096],
            'partial-prefix-is-unmatched' => ['claude-3-5', 4096],
            'unknown-is-unmatched' => ['claude-synthetic', 4096],
            'trailing-space-still-matches' => ['claude-opus-5 ', 16384],
        ];
    }

    #[DataProvider('prefixRows')]
    public function testLiteralDefaultReachesEagerAndStreamingBodies(string $modelName, int $tokens): void
    {
        $http = self::http(2);
        $fields = ['model' => $modelName, 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http];
        $callerBefore = $fields;
        $model = new ChatAnthropic($fields);
        $kwargs = ['model' => $modelName, 'maxRetries' => 0];
        $serialized = self::record($kwargs);
        self::assertSame($tokens, $model->maxTokens);
        self::assertSame(['model' => $modelName, 'max_tokens' => $tokens], $model->invocationParams());
        self::assertSame($kwargs, $model->kwargs());
        self::assertSame($serialized, $model->toJson());
        $body = ['model' => $modelName, 'max_tokens' => $tokens, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]];
        $first = $model->invoke('SYNTH_INPUT');
        self::assertSame('SYNTH_OUTPUT', $first->content);
        self::assertSame(['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7], $first->response_metadata['usage_metadata']);
        self::assertCount(1, $http->requests);
        self::assertSame($body, $http->lastRequestBody());
        self::assertSame('SYNTH_OUTPUT', $model->invoke('SYNTH_INPUT')->content);
        self::assertCount(2, $http->requests);
        self::assertSame($http->requests[0]['body'], $http->requests[1]['body']);
        self::assertSame($callerBefore, $fields);
        self::assertSame($kwargs, $model->kwargs());
        self::assertSame($serialized, $model->toJson());

        $streamHttp = self::http(0, true);
        $streamFields = ['model' => $modelName, 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $streamHttp];
        $streamBefore = $streamFields;
        $streamModel = new ChatAnthropic($streamFields);
        $text = '';
        foreach ($streamModel->stream('SYNTH_INPUT') as [$channel, $chunk]) {
            $text .= $chunk->content;
        }
        self::assertSame('SYNTH_OUTPUT', $text);
        self::assertCount(1, $streamHttp->streamRequests);
        self::assertSame([], $streamHttp->requests);
        self::assertSame(['model' => $modelName, 'max_tokens' => $tokens, 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $streamHttp->lastRequestBody());
        self::assertSame($streamBefore, $streamFields);
        self::assertSame($kwargs, $streamModel->kwargs());
        self::assertSame($serialized, $streamModel->toJson());
    }

    public static function constructorRows(): array
    {
        return [
            'omitted-16k' => [['model' => 'claude-opus-5'], 'claude-opus-5', 16384, null, null],
            'omitted-8k' => [['model' => 'claude-3-5-sonnet'], 'claude-3-5-sonnet', 8192, null, null],
            'omitted-4k' => [['model' => 'claude-3-haiku'], 'claude-3-haiku', 4096, null, null],
            'no-model' => [[], 'claude-sonnet-4-5', 16384, null, null],
            'null-model' => [['model' => null], 'claude-sonnet-4-5', 16384, null, null],
            'null-16k' => [['model' => 'claude-opus-5', 'maxTokens' => null], 'claude-opus-5', 16384, null, null],
            'null-8k' => [['model' => 'claude-3-5-sonnet', 'maxTokens' => null], 'claude-3-5-sonnet', 8192, null, null],
            'null-4k' => [['model' => 'claude-3-haiku', 'maxTokens' => null], 'claude-3-haiku', 4096, null, null],
            'zero' => [['model' => 'claude-opus-5', 'maxTokens' => 0], 'claude-opus-5', 0, 0, 0],
            'explicit-1024' => [['model' => 'claude-opus-5', 'maxTokens' => 1024], 'claude-opus-5', 1024, 1024, 1024],
            'explicit-small-8k' => [['model' => 'claude-3-5-sonnet', 'maxTokens' => 7], 'claude-3-5-sonnet', 7, 7, 7],
            'zero-8k' => [['model' => 'claude-3-5-sonnet', 'maxTokens' => 0], 'claude-3-5-sonnet', 0, 0, 0],
            'explicit-1024-8k' => [['model' => 'claude-3-5-sonnet', 'maxTokens' => 1024], 'claude-3-5-sonnet', 1024, 1024, 1024],
            'explicit-small-16k' => [['model' => 'claude-opus-5', 'maxTokens' => 7], 'claude-opus-5', 7, 7, 7],
            'zero-4k' => [['model' => 'claude-3-haiku', 'maxTokens' => 0], 'claude-3-haiku', 0, 0, 0],
            'explicit-1024-4k' => [['model' => 'claude-3-haiku', 'maxTokens' => 1024], 'claude-3-haiku', 1024, 1024, 1024],
            'explicit-small-4k' => [['model' => 'claude-3-haiku', 'maxTokens' => 7], 'claude-3-haiku', 7, 7, 7],
            'wire-1024' => [['model' => 'claude-opus-5', 'max_tokens' => 1024], 'claude-opus-5', 1024, 1024, 1024],
            'wire-zero' => [['model' => 'claude-opus-5', 'max_tokens' => 0], 'claude-opus-5', 0, 0, 0],
            'wire-null' => [['model' => 'claude-opus-5', 'max_tokens' => null], 'claude-opus-5', 16384, null, null],
            'wire-1024-8k' => [['model' => 'claude-3-5-sonnet', 'max_tokens' => 1024], 'claude-3-5-sonnet', 1024, 1024, 1024],
            'wire-zero-8k' => [['model' => 'claude-3-5-sonnet', 'max_tokens' => 0], 'claude-3-5-sonnet', 0, 0, 0],
            'wire-null-8k' => [['model' => 'claude-3-5-sonnet', 'max_tokens' => null], 'claude-3-5-sonnet', 8192, null, null],
            'wire-1024-4k' => [['model' => 'claude-3-haiku', 'max_tokens' => 1024], 'claude-3-haiku', 1024, 1024, 1024],
            'wire-zero-4k' => [['model' => 'claude-3-haiku', 'max_tokens' => 0], 'claude-3-haiku', 0, 0, 0],
            'wire-null-4k' => [['model' => 'claude-3-haiku', 'max_tokens' => null], 'claude-3-haiku', 4096, null, null],
            'canonical-first' => [['model' => 'claude-opus-5', 'maxTokens' => 512, 'max_tokens' => 1024], 'claude-opus-5', 512, 512, 512],
            'wire-first' => [['model' => 'claude-opus-5', 'max_tokens' => 1024, 'maxTokens' => 512], 'claude-opus-5', 512, 512, 512],
            'canonical-null-first' => [['model' => 'claude-opus-5', 'maxTokens' => null, 'max_tokens' => 0], 'claude-opus-5', 16384, null, null],
            'wire-before-canonical-null' => [['model' => 'claude-opus-5', 'max_tokens' => 0, 'maxTokens' => null], 'claude-opus-5', 16384, null, null],
            'false-casts-only-property' => [['model' => 'claude-opus-5', 'maxTokens' => false], 'claude-opus-5', 0, false, false],
            'string-casts-only-property' => [['model' => 'claude-opus-5', 'maxTokens' => '512'], 'claude-opus-5', 512, '512', '512'],
            'float-casts-only-property' => [['model' => 'claude-opus-5', 'maxTokens' => 512.5], 'claude-opus-5', 512, 512.5, 512.5],
            'modelName-held' => [['modelName' => 'claude-3-haiku'], 'claude-sonnet-4-5', 16384, null, null],
            'modelName-null-model-held' => [['model' => null, 'modelName' => 'claude-3-haiku'], 'claude-sonnet-4-5', 16384, null, null],
            'modelName-null-held' => [['modelName' => null], 'claude-sonnet-4-5', 16384, null, null],
            'modelName-empty-held' => [['modelName' => ''], 'claude-sonnet-4-5', 16384, null, null],
            'canonical-model-masks-modelName' => [['model' => 'claude-3-5-sonnet', 'modelName' => 'claude-opus-5'], 'claude-3-5-sonnet', 8192, null, null],
        ];
    }

    #[DataProvider('constructorRows')]
    public function testOverridesAliasesAndHeldIngress(array $input, string $modelName, int $propertyTokens, mixed $capturedTokens, mixed $wireTokens): void
    {
        $http = self::http(1);
        $fields = [...$input, 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http];
        $before = $fields;
        $model = new ChatAnthropic($fields);
        self::assertSame($modelName, $model->model);
        self::assertSame($propertyTokens, $model->maxTokens);
        $kwargs = [];
        if (isset($input['model'])) {
            $kwargs['model'] = $modelName;
        }
        // A wire-only alias is appended by canonicalisation after the supplied maxRetries.
        $wireOnly = array_key_exists('max_tokens', $input) && !array_key_exists('maxTokens', $input);
        if ($capturedTokens !== null && !$wireOnly) {
            $kwargs['maxTokens'] = $capturedTokens;
        }
        $kwargs['maxRetries'] = 0;
        if ($capturedTokens !== null && $wireOnly) {
            $kwargs['maxTokens'] = $capturedTokens;
        }
        self::assertSame($kwargs, $model->kwargs());
        self::assertSame(self::record($kwargs), $model->toJson());
        $expectedTokens = $wireTokens ?? $propertyTokens;
        self::assertSame(['model' => $modelName, 'max_tokens' => $expectedTokens], $model->invocationParams());
        self::assertSame('SYNTH_OUTPUT', $model->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => $modelName, 'max_tokens' => $expectedTokens, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame($before, $fields);
        self::assertSame($kwargs, $model->kwargs());
        self::assertSame(self::record($kwargs), $model->toJson());
    }

    public function testExistingEmptyModelGuardRemainsBeforeTransport(): void
    {
        foreach (['', '   ', "\t\n"] as $name) {
            $http = self::http(0);
            $exception = null;
            try {
                new ChatAnthropic(['model' => $name, 'apiKey' => self::SYNTH_KEY, 'httpClient' => $http]);
            } catch (\InvalidArgumentException $caught) {
                $exception = $caught;
            }
            self::assertInstanceOf(\InvalidArgumentException::class, $exception);
            self::assertSame('ChatAnthropic requires a non-empty model name.', $exception->getMessage());
            self::assertSame([], $http->requests);
            self::assertSame([], $http->streamRequests);
        }
    }

    public function testBudgetIsResolvedOnceAndCapturedBindingsStayIndependent(): void
    {
        $model = new ChatAnthropic(['apiKey' => self::SYNTH_KEY]);
        $before = $model->toJson();
        $model->model = 'claude-3-5-sonnet';
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 16384], $model->invocationParams());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 16384], $model->invocationParams(['model' => 'claude-opus-5']));
        $model->maxTokens = 17;
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 17], $model->invocationParams());
        self::assertSame([], $model->kwargs());
        self::assertSame($before, $model->toJson());

        $captured = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'maxTokens' => 1024, 'apiKey' => self::SYNTH_KEY]);
        $capturedBefore = $captured->toJson();
        $captured->model = 'claude-opus-5';
        $captured->maxTokens = 17;
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 1024], $captured->invocationParams());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 1024], $captured->invocationParams(['model' => 'claude-opus-5']));
        self::assertSame($capturedBefore, $captured->toJson());

        $http = self::http(2);
        $fields = ['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http];
        $callerBefore = $fields;
        $once = new ChatAnthropic($fields);
        $onceBefore = $once->toJson();
        $once->model = 'claude-opus-5';
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192], $once->invocationParams());
        self::assertSame('SYNTH_OUTPUT', $once->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        $options = ['model' => 'claude-opus-5'];
        $optionsBefore = $options;
        self::assertSame('SYNTH_OUTPUT', $once->invoke('SYNTH_INPUT', new RunnableConfig(options: $options))->content);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 8192, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame($optionsBefore, $options);
        self::assertSame($callerBefore, $fields);
        self::assertSame($onceBefore, $once->toJson());
    }

    public function testBareAndRedeclaredSubclassDefaultsUseThePrivateParentHelper(): void
    {
        $bare = new AnthropicTokenBareSubclass(['model' => 'claude-3-5-haiku', 'apiKey' => self::SYNTH_KEY]);
        self::assertSame(8192, $bare->maxTokens);
        self::assertSame(['model' => 'claude-3-5-haiku', 'max_tokens' => 8192], $bare->invocationParams());
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'AnthropicTokenBareSubclass'], 'kwargs' => ['model' => 'claude-3-5-haiku']], $bare->toJson());

        // PHP deliberately replaces a redeclared initializer on omission; JS fields run after super().
        $declared = new AnthropicTokenDeclaredSubclass(['apiKey' => self::SYNTH_KEY]);
        self::assertSame(8192, $declared->maxTokens);
        self::assertSame(['model' => 'claude-3-5-haiku', 'max_tokens' => 8192], $declared->invocationParams());
        self::assertSame(['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'AnthropicTokenDeclaredSubclass'], 'kwargs' => []], $declared->toJson());
        $explicit = new AnthropicTokenDeclaredSubclass(['maxTokens' => 17, 'apiKey' => self::SYNTH_KEY]);
        self::assertSame(17, $explicit->maxTokens);
        self::assertSame(['model' => 'claude-3-5-haiku', 'max_tokens' => 17], $explicit->invocationParams());
    }

    public function testBoundAndPerCallTokenPrecedenceKeepsCanonicalPresenceRules(): void
    {
        $model = new ChatAnthropic(['model' => 'claude-opus-5', 'apiKey' => self::SYNTH_KEY]);
        $before = $model->toJson();
        $binding = ['max_tokens' => 19];
        $bindingBefore = $binding;
        $bound = $model->bindTools([], $binding);
        self::assertNotSame($model, $bound);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 19], $bound->invocationParams());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 19], $model->bindTools([], ['maxTokens' => 19])->invocationParams());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 23], $bound->invocationParams(['maxTokens' => 23]));
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 19], $bound->invocationParams(['maxTokens' => null]));
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 0], $bound->invocationParams(['maxTokens' => null, 'max_tokens' => 0]));
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 29], $bound->invocationParams(['maxTokens' => 23, 'invocationKwargs' => ['max_tokens' => 29]]));
        self::assertSame(['model' => 'claude-opus-5'], $bound->invocationParams(['invocationKwargs' => ['max_tokens' => null]]));
        self::assertSame(['model' => 'claude-3-haiku', 'max_tokens' => 19], $bound->invocationParams(['model' => 'claude-3-haiku']));
        self::assertSame($bindingBefore, $binding);
        self::assertSame($before, $model->toJson());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 16384], $model->invocationParams());

        $explicit = new ChatAnthropic(['model' => 'claude-opus-5', 'maxTokens' => 1024, 'apiKey' => self::SYNTH_KEY]);
        $wireBound = $explicit->bindTools([], ['max_tokens' => 19]);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 1024], $wireBound->invocationParams());
        self::assertSame(['model' => 'claude-opus-5', 'maxTokens' => 1024, 'tools' => null, 'max_tokens' => 19], $wireBound->kwargs());
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 19], $explicit->bindTools([], ['maxTokens' => 19])->invocationParams());

        $http = self::http(2);
        $transport = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http]);
        $boundTransport = $transport->bindTools([], ['maxTokens' => 19]);
        self::assertSame(8192, $boundTransport->maxTokens);
        self::assertSame('SYNTH_OUTPUT', $boundTransport->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 19, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        $options = ['maxTokens' => 23, 'invocationKwargs' => ['max_tokens' => 29]];
        $optionsBefore = $options;
        self::assertSame('SYNTH_OUTPUT', $boundTransport->invoke('SYNTH_INPUT', new RunnableConfig(options: $options))->content);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 29, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame($optionsBefore, $options);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'maxRetries' => 0], $transport->kwargs());
    }

    public function testPromptCacheControlAndValidationRemainSeparateFromTheDefault(): void
    {
        $http = self::http(1, true);
        $model = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http]);
        $binding = ['cache_control' => ['type' => 'ephemeral'], 'betas' => ['SYNTH_BETA']];
        $before = $binding;
        $bound = $model->bindTools([], $binding);
        self::assertSame('SYNTH_OUTPUT', $bound->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192, 'cache_control' => ['type' => 'ephemeral'], 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame('SYNTH_BETA', $http->requests[0]['headers']['anthropic-beta']);
        $text = '';
        foreach ($bound->stream('SYNTH_INPUT') as [$channel, $chunk]) {
            $text .= $chunk->content;
        }
        self::assertSame('SYNTH_OUTPUT', $text);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192, 'cache_control' => ['type' => 'ephemeral'], 'stream' => true, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], json_decode($http->streamRequests[0]['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('SYNTH_BETA', $http->streamRequests[0]['headers']['anthropic-beta']);
        self::assertSame($before, $binding);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'maxRetries' => 0], $model->kwargs());
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192, 'temperature' => 0.5], $model->invocationParams(['temperature' => 0.5]));
        $adaptiveHttp = self::http(0);
        $adaptive = new ChatAnthropic(['model' => 'claude-opus-5', 'apiKey' => self::SYNTH_KEY, 'httpClient' => $adaptiveHttp]);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 16384], $adaptive->invocationParams(['temperature' => 1.0]));
        $exception = null;
        try {
            $adaptive->invoke('SYNTH_INPUT', new RunnableConfig(options: ['temperature' => 0.5]));
        } catch (\InvalidArgumentException $caught) {
            $exception = $caught;
        }
        self::assertInstanceOf(\InvalidArgumentException::class, $exception);
        self::assertSame('temperature is not supported for claude-opus-5 when set to non-default values', $exception->getMessage());
        self::assertSame([], $adaptiveHttp->requests);
        self::assertSame([], $adaptiveHttp->streamRequests);
    }

    public function testLiteralSerializedRecordsRetainCredentialOrderAndCapturedNullPolicy(): void
    {
        $http = self::http(0);
        $fields = ['model' => 'claude-3-5-sonnet', 'maxTokens' => null, 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http];
        $before = $fields;
        $model = new ChatAnthropic($fields);
        $literal = '{"lc":1,"type":"constructor","id":["langchain","chat_models","anthropic","ChatAnthropic"],"kwargs":{"model":"claude-3-5-sonnet","maxRetries":0,"anthropic_api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]},"api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]}}}';
        self::assertSame($literal, json_encode($model, JSON_THROW_ON_ERROR));
        self::assertSame(json_decode($literal, true, 512, JSON_THROW_ON_ERROR), $model->toJson());
        self::assertSame($model->toJson(), $model->toSerializedConstructor());
        self::assertSame(['model' => 'claude-3-5-sonnet', 'maxRetries' => 0], $model->kwargs());
        self::assertSame($before, $fields);

        $explicit = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'maxTokens' => 1024, 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0]);
        self::assertSame('{"lc":1,"type":"constructor","id":["langchain","chat_models","anthropic","ChatAnthropic"],"kwargs":{"model":"claude-3-5-sonnet","maxTokens":1024,"maxRetries":0,"anthropic_api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]},"api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]}}}', json_encode($explicit, JSON_THROW_ON_ERROR));

        $nested = ['literal' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_USER_LITERAL']]];
        $escaped = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'invocationKwargs' => $nested]);
        self::assertSame(self::record(['model' => 'claude-3-5-sonnet', 'invocationKwargs' => ['literal' => ['__lc_escaped__' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_USER_LITERAL']]]]]), $escaped->toJson());
        self::assertSame(['literal' => ['lc' => 1, 'type' => 'secret', 'id' => ['SYNTH_USER_LITERAL']]], $nested);
    }

    public static function replayRows(): array
    {
        return [
            'old-omitted-budget' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","anthropic","ChatAnthropic"],"kwargs":{"model":"claude-3-5-sonnet","maxRetries":0,"api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]}}}', 8192, ['model' => 'claude-3-5-sonnet', 'maxRetries' => 0]],
            'old-explicit-1024' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","anthropic","ChatAnthropic"],"kwargs":{"model":"claude-3-5-sonnet","maxTokens":1024,"maxRetries":0,"api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]}}}', 1024, ['model' => 'claude-3-5-sonnet', 'maxTokens' => 1024, 'maxRetries' => 0]],
            'source-wire-budget' => ['{"lc":1,"type":"constructor","id":["langchain","chat_models","anthropic","ChatAnthropic"],"kwargs":{"model":"claude-3-5-sonnet","max_tokens":11,"maxRetries":0,"api_key":{"lc":1,"type":"secret","id":["ANTHROPIC_API_KEY"]}}}', 11, ['model' => 'claude-3-5-sonnet', 'maxTokens' => 11, 'maxRetries' => 0]],
        ];
    }

    #[DataProvider('replayRows')]
    public function testLoadUsesNewOmittedDefaultWithoutRewritingTheInput(string $literal, int $tokens, array $kwargs): void
    {
        $inputBefore = $literal;
        $secrets = ['ANTHROPIC_API_KEY' => self::SYNTH_KEY];
        $secretsBefore = $secrets;
        $loaded = Load::load($literal, ['secretsMap' => $secrets]);
        self::assertInstanceOf(ChatAnthropic::class, $loaded);
        self::assertSame($tokens, $loaded->maxTokens);
        self::assertSame(self::SYNTH_KEY, $loaded->apiKey);
        self::assertSame($kwargs, $loaded->kwargs());
        self::assertSame(self::record($kwargs), $loaded->toJson());
        $http = self::http(1);
        $loaded->httpClient = $http;
        self::assertSame('SYNTH_OUTPUT', $loaded->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => $tokens, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        $roundTrip = Load::load((string) json_encode($loaded, JSON_THROW_ON_ERROR), ['secretsMap' => $secrets]);
        self::assertInstanceOf(ChatAnthropic::class, $roundTrip);
        self::assertSame($tokens, $roundTrip->maxTokens);
        self::assertSame(self::record($kwargs), $roundTrip->toJson());
        self::assertSame($inputBefore, $literal);
        self::assertSame($secretsBefore, $secrets);
    }

    public function testConfigurableModelSwitchBuildsANewBudgetWhilePerCallModelDoesNot(): void
    {
        $http = self::http(3);
        $fields = ['apiKey' => self::SYNTH_KEY, 'httpClient' => $http, 'maxRetries' => 0, 'configurableFields' => ['model']];
        $before = $fields;
        $wrapper = InitChatModel::init('claude-3-haiku', $fields);
        $original = $wrapper->getModelInstance();
        self::assertInstanceOf(ChatAnthropic::class, $original);
        self::assertSame(4096, $original->maxTokens);
        self::assertSame('SYNTH_OUTPUT', $wrapper->invoke('SYNTH_INPUT')->content);
        self::assertSame(['model' => 'claude-3-haiku', 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        $config = new RunnableConfig(configurable: ['model' => 'claude-opus-5']);
        $switched = $wrapper->getModelInstance($config);
        self::assertInstanceOf(ChatAnthropic::class, $switched);
        self::assertNotSame($original, $switched);
        self::assertSame(16384, $switched->maxTokens);
        self::assertSame('SYNTH_OUTPUT', $wrapper->invoke('SYNTH_INPUT', $config)->content);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 16384, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame('SYNTH_OUTPUT', $original->invoke('SYNTH_INPUT', new RunnableConfig(options: ['model' => 'claude-opus-5']))->content);
        self::assertSame(['model' => 'claude-opus-5', 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], $http->lastRequestBody());
        self::assertSame(['model' => 'claude-opus-5'], $config->configurable);
        self::assertSame($before, $fields);
        self::assertSame($original, $wrapper->getModelInstance());
    }

    public function testOneStartRecordSeparatesResolvedInvocationParamsFromCapturedFields(): void
    {
        $handler = new AnthropicTokenRecordingHandler();
        $http = self::http(1);
        $fields = ['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http, 'callbacks' => [$handler]];
        $before = $fields;
        $model = new ChatAnthropic($fields);
        self::assertSame('SYNTH_OUTPUT', $model->invoke('SYNTH_INPUT')->content);
        self::assertSame([self::record(['model' => 'claude-3-5-sonnet', 'maxRetries' => 0])], $handler->starts);
        self::assertSame([['options' => [], 'invocation_params' => ['model' => 'claude-3-5-sonnet', 'max_tokens' => 8192], 'batch_size' => 1]], $handler->extras);
        self::assertSame(1, $handler->ends);
        self::assertSame(0, $handler->errors);
        self::assertSame($before, $fields);
    }

    public function testOnePerCallCacheSeparationPreservesHashInputsAndCachedZeroUsage(): void
    {
        $http = self::http(2);
        $model = new ChatAnthropic(['model' => 'claude-3-5-sonnet', 'apiKey' => self::SYNTH_KEY, 'maxRetries' => 0, 'httpClient' => $http, 'cache' => new InMemoryCache()]);
        $options = ['maxTokens' => 19];
        $before = $options;
        $serializedBefore = $model->toJson();
        self::assertSame([], $model->identifyingParams());
        self::assertSame('_model:"chat",_type:"anthropic-chat",maxTokens:19', $model->serializedCacheKeyParametersForCall($options));
        self::assertSame('_model:"chat",_type:"anthropic-chat",maxTokens:23', $model->serializedCacheKeyParametersForCall(['maxTokens' => 23]));
        $first = $model->invoke('SYNTH_INPUT', new RunnableConfig(options: $options));
        $second = $model->invoke('SYNTH_INPUT', new RunnableConfig(options: ['maxTokens' => 23]));
        $cached = $model->invoke('SYNTH_INPUT', new RunnableConfig(options: $options));
        self::assertSame('SYNTH_OUTPUT', $cached->content);
        self::assertCount(2, $http->requests);
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 19, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['model' => 'claude-3-5-sonnet', 'max_tokens' => 23, 'messages' => [['role' => 'user', 'content' => 'SYNTH_INPUT']]], json_decode($http->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7], $first->response_metadata['usage_metadata']);
        self::assertSame(['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7], $second->response_metadata['usage_metadata']);
        self::assertSame(['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0], $cached->response_metadata['usage_metadata']);
        self::assertSame($before, $options);
        self::assertSame($serializedBefore, $model->toJson());
        self::assertSame('_model:"chat",_type:"anthropic-chat",maxTokens:19', $model->serializedCacheKeyParametersForCall($options));
    }

    /** Complete literal marker order, independent of the provider serializer. */
    private static function record(array $kwargs): array
    {
        return ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'anthropic', 'ChatAnthropic'], 'kwargs' => [...$kwargs, 'anthropic_api_key' => ['lc' => 1, 'type' => 'secret', 'id' => ['ANTHROPIC_API_KEY']], 'api_key' => ['lc' => 1, 'type' => 'secret', 'id' => ['ANTHROPIC_API_KEY']]]];
    }

    /** Fixed synthetic wire bytes; no source oracle, environment or real client. */
    private static function http(int $responses, bool $stream = false): FakeHttpClient
    {
        $script = [];
        for ($i = 0; $i < $responses; $i++) {
            $script[] = new HttpResponse(200, ['Content-Type' => ['application/json']], self::RESPONSE);
        }

        return new FakeHttpClient($script, $stream ? str_split(self::SSE, 17) : []);
    }
}

/** Bare inheritance exercises the private self helper without credential opt-in. */
class AnthropicTokenBareSubclass extends ChatAnthropic
{
}

/** Redeclared defaults are intentionally overridden on omitted constructor tokens. */
final class AnthropicTokenDeclaredSubclass extends ChatAnthropic
{
    public string $model = 'claude-3-5-haiku';

    public int $maxTokens = 17;
}

/** One scoped start-record collector; no prompt or stream-events infrastructure sweep. */
final class AnthropicTokenRecordingHandler extends BaseCallbackHandler
{
    public array $starts = [];

    public array $extras = [];

    public int $ends = 0;

    public int $errors = 0;

    public function handleChatModelStart(Serialized $llm, array $messages, string $runId, ?string $parentRunId = null, array $extraParams = [], array $tags = [], array $metadata = [], ?string $runName = null): void
    {
        $this->starts[] = $llm->toArray();
        $this->extras[] = $extraParams;
    }

    public function handleLLMEnd(LLMResult $output, string $runId, ?string $parentRunId = null, array $tags = [], array $extraParams = []): void
    {
        $this->ends++;
    }

    public function handleLLMError(\Throwable $error, string $runId, ?string $parentRunId = null, array $tags = [], array $extraParams = []): void
    {
        $this->errors++;
    }
}
