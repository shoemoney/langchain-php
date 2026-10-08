<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\Converters\ContentBlocks;
use LangChain\LanguageModels\Chat\OpenRouter\Converters\Messages;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `converters/tests/index.test.ts` (everything but `formatToolChoice`,
 * which is {@see ToolsConverterTest}).
 *
 * `contentBlocks` is read through {@see ContentBlocks}: upstream's
 * `AIMessage.contentBlocks` dispatches on `model_provider` to the OpenRouter
 * translator, and this port's `BaseMessage::contentBlocks()` has no such
 * registry.
 */
#[CoversClass(Messages::class)]
#[CoversClass(ContentBlocks::class)]
final class MessagesConverterTest extends TestCase
{
    // ─── convertUsageMetadata ────────────────────────────────────────

    public function testReturnsNullWhenUsageIsNull(): void
    {
        self::assertNull(Messages::convertUsageMetadata(null));
    }

    public function testMapsBasicTokenCounts(): void
    {
        self::assertSame(
            ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            Messages::convertUsageMetadata(['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30]),
        );
    }

    public function testMapsFullTokenDetails(): void
    {
        $usage = [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'prompt_tokens_details' => ['cached_tokens' => 80, 'audio_tokens' => 5],
            'completion_tokens_details' => ['reasoning_tokens' => 10],
        ];

        self::assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'input_token_details' => ['cache_read' => 80, 'audio' => 5],
            'output_token_details' => ['reasoning' => 10],
        ], Messages::convertUsageMetadata($usage));
    }

    public function testOmitsDetailSubObjectsWhenAllDetailFieldsAreNull(): void
    {
        $result = Messages::convertUsageMetadata([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'prompt_tokens_details' => [],
            'completion_tokens_details' => [],
        ]);

        self::assertArrayNotHasKey('input_token_details', $result);
        self::assertArrayNotHasKey('output_token_details', $result);
    }

    // ─── response metadata smoke tests ───────────────────────────────

    /**
     * @param array<string, mixed> $message
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private static function response(array $message, string $model, string $id = 'gen-123'): array
    {
        $choice = ['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant'] + $message];

        return [$choice, ['id' => $id, 'choices' => [$choice], 'created' => 0, 'model' => $model, 'object' => 'chat.completion']];
    }

    public function testPatchesResponseMetadataWithOpenRouterFields(): void
    {
        [$choice, $raw] = self::response(['content' => 'hello'], 'anthropic/claude-4-sonnet');

        $meta = Messages::convertOpenRouterResponseToBaseMessage($choice, $raw)->response_metadata;

        self::assertSame('anthropic/claude-4-sonnet', $meta['model']);
        self::assertSame('openrouter', $meta['model_provider']);
        self::assertSame('anthropic/claude-4-sonnet', $meta['model_name']);
        self::assertSame('stop', $meta['finish_reason']);
    }

    public function testPatchesChunkResponseMetadataWithModelProvider(): void
    {
        $delta = ['role' => 'assistant', 'content' => 'hi'];
        $raw = ['id' => 'gen-456', 'choices' => [['delta' => $delta, 'finish_reason' => null, 'index' => 0]], 'created' => 0, 'model' => 'openai/gpt-4o', 'object' => 'chat.completion.chunk'];

        $chunk = Messages::convertOpenRouterDeltaToBaseMessageChunk($delta, $raw, 'assistant');

        self::assertSame('openrouter', $chunk->response_metadata['model_provider']);
    }

    // ─── reasoning extraction ────────────────────────────────────────

    public function testCopiesMessageReasoningIntoReasoningContent(): void
    {
        [$choice, $raw] = self::response(
            ['content' => 'The answer is 42.', 'reasoning' => 'Let me think... 6 * 7 = 42.'],
            'deepseek/deepseek-reasoner',
            'gen-r1',
        );

        $msg = Messages::convertOpenRouterResponseToBaseMessage($choice, $raw);

        self::assertSame('Let me think... 6 * 7 = 42.', $msg->additional_kwargs['reasoning_content']);
        $blocks = ContentBlocks::convertToV1FromOpenRouterMessage($msg);
        self::assertContains(['type' => 'reasoning', 'reasoning' => 'Let me think... 6 * 7 = 42.'], $blocks);
        self::assertContains(['type' => 'text', 'text' => 'The answer is 42.'], $blocks);
    }

    public function testCopiesMessageReasoningDetailsIntoReasoningDetails(): void
    {
        $details = [['type' => 'reasoning.text', 'text' => 'Step 1, step 2, step 3.', 'signature' => 'sig_abc']];
        [$choice, $raw] = self::response(['content' => 'Done.', 'reasoning_details' => $details], 'anthropic/claude-3.7-sonnet', 'gen-r2');

        $msg = Messages::convertOpenRouterResponseToBaseMessage($choice, $raw);

        self::assertSame($details, $msg->additional_kwargs['reasoning_details']);
        self::assertContains(
            ['type' => 'reasoning', 'reasoning' => 'Step 1, step 2, step 3.'],
            ContentBlocks::convertToV1FromOpenRouterMessage($msg),
        );
    }

    public function testOmitsReasoningFieldsWhenTheResponseHasNone(): void
    {
        [$choice, $raw] = self::response(['content' => 'plain reply'], 'openai/gpt-4o-mini', 'gen-plain');

        $msg = Messages::convertOpenRouterResponseToBaseMessage($choice, $raw);

        self::assertArrayNotHasKey('reasoning_content', $msg->additional_kwargs);
        self::assertArrayNotHasKey('reasoning_details', $msg->additional_kwargs);
    }

    /** @param array<string, mixed> $delta */
    private static function chunkOf(array $delta): AIMessageChunk
    {
        $raw = [
            'id' => 'gen-r-stream',
            'choices' => [['delta' => $delta, 'finish_reason' => null, 'index' => 0]],
            'created' => 0,
            'model' => 'deepseek/deepseek-reasoner',
            'object' => 'chat.completion.chunk',
        ];

        return Messages::convertOpenRouterDeltaToBaseMessageChunk($delta, $raw, 'assistant');
    }

    public function testCopiesDeltaReasoningIntoReasoningContent(): void
    {
        $chunk = self::chunkOf(['role' => 'assistant', 'reasoning' => 'first thought ']);

        self::assertSame('first thought ', $chunk->additional_kwargs['reasoning_content']);
    }

    public function testConcatenatesReasoningAcrossStreamingChunksViaChunkMerge(): void
    {
        $merged = self::chunkOf(['role' => 'assistant', 'reasoning' => 'Let me '])
            ->concat(self::chunkOf(['reasoning' => 'think ']))
            ->concat(self::chunkOf(['content' => '42.', 'reasoning' => 'carefully.']));

        self::assertInstanceOf(AIMessageChunk::class, $merged);
        self::assertSame('Let me think carefully.', $merged->additional_kwargs['reasoning_content']);
        $blocks = ContentBlocks::convertToV1FromOpenRouterMessage($merged);
        self::assertContains(['type' => 'reasoning', 'reasoning' => 'Let me think carefully.'], $blocks);
        self::assertContains(['type' => 'text', 'text' => '42.'], $blocks);
    }

    public function testOmitsReasoningFieldsWhenTheDeltaHasNone(): void
    {
        $chunk = self::chunkOf(['role' => 'assistant', 'content' => 'hi']);

        self::assertArrayNotHasKey('reasoning_content', $chunk->additional_kwargs);
        self::assertArrayNotHasKey('reasoning_details', $chunk->additional_kwargs);
    }

    public function testMergesStreamingReasoningDetailsByIndexViaChunkConcat(): void
    {
        $merged = self::chunkOf(['role' => 'assistant', 'reasoning_details' => [['type' => 'reasoning.text', 'text' => 'Let me ', 'index' => 0]]])
            ->concat(self::chunkOf(['reasoning_details' => [['type' => 'reasoning.text', 'text' => 'think.', 'index' => 0]]]))
            ->concat(self::chunkOf(['content' => '42.']));

        self::assertSame(
            [['type' => 'reasoning.text', 'text' => 'Let me think.', 'index' => 0]],
            $merged->additional_kwargs['reasoning_details'],
        );
    }

    // ─── beyond the upstream cases ───────────────────────────────────

    public function testConvertsMessagesToTheChatCompletionsWireShape(): void
    {
        self::assertSame(
            [['role' => 'system', 'content' => 'be brief'], ['role' => 'user', 'content' => 'hi']],
            Messages::convertMessagesToOpenRouterParams([new SystemMessage('be brief'), new HumanMessage('hi')], 'openai/gpt-4o'),
        );
    }

    public function testReasoningDetailsPreferredOverFlatReasoningAndEncryptedStaysHidden(): void
    {
        $message = new AIMessage([
            'content' => 'ok',
            'additional_kwargs' => [
                'reasoning_content' => 'flat',
                'reasoning_details' => [
                    ['type' => 'reasoning.encrypted', 'data' => 'opaque'],
                    ['type' => 'reasoning.summary', 'summary' => 'short'],
                ],
            ],
        ]);

        self::assertSame(
            [['type' => 'reasoning', 'reasoning' => 'short'], ['type' => 'text', 'text' => 'ok']],
            ContentBlocks::convertToV1FromOpenRouterMessage($message),
        );

        $onlyEncrypted = new AIMessage([
            'content' => '',
            'additional_kwargs' => ['reasoning_content' => 'flat', 'reasoning_details' => [['type' => 'reasoning.encrypted', 'data' => 'x']]],
        ]);
        self::assertSame([['type' => 'reasoning', 'reasoning' => 'flat']], ContentBlocks::convertToV1FromOpenRouterMessage($onlyEncrypted));
    }
}
