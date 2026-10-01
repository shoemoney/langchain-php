<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Multi-modal content blocks must REACH the wire, not just exist on the message.
 *
 * The defect this guards: `convertMessage()` filtered `content` with
 * `($block['type'] ?? null) === 'text'` — an ALLOW-list that kept text and threw
 * away everything else. Upstream `converters/completions.ts:846-857` does the
 * opposite: it enumerates the six types the Chat Completions API rejects as
 * input (`tool_use`, `tool_call`, `functionCall`, `reasoning`,
 * `reasoning_content`, `thinking`) and returns `m` for every other block.
 *
 * The allow-list was upstream's rule inverted, and it silently deleted legal
 * multi-modal input. Measured by execution before the fix, on one message:
 *
 *     blocks IN : 4 (text, image, audio, file)
 *     blocks OUT: 1
 *
 * so the request went out asking "What is in this image?" with no image on the
 * wire — and nothing anywhere reported the loss. `image`, `audio`, `file` and
 * `video` are valid Chat Completions content parts, so this port was dropping
 * multimodal input upstream forwards.
 *
 * ASSERTED ON THE REQUEST BODY, not on the returned message: the message object
 * legitimately holds every block either way, so asserting on it would pass
 * identically before and after the fix. The only place this defect exists is
 * the encoded payload, which is why the sibling `NonOpenAIBlocksDroppedTest`
 * also asserts on encoded output.
 */
#[CoversClass(Completions::class)]
#[CoversClass(ChatOpenAI::class)]
final class MultimodalBlocksReachTheWireTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function completion(): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => 0,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'a cat'],
                'finish_reason' => 'stop',
            ]],
        ];
    }

    private static function model(): ChatOpenAI
    {
        return new ChatOpenAI([
            'model' => 'gpt-4o',
            'apiKey' => 'sk-test',
            'httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::completion())]),
            'maxRetries' => 0,
        ]);
    }

    /**
     * The single behavioural claim: every non-rejected block survives to the
     * payload, in order, with its fields intact.
     */
    public function testImageAudioAndFileBlocksReachTheRequestBody(): void
    {
        $model = self::model();
        $model->invoke([new HumanMessage([
            'content' => [
                ['type' => 'text', 'text' => 'What is in this image?'],
                ['type' => 'image', 'source_type' => 'url', 'url' => 'https://example.com/cat.png'],
                ['type' => 'audio', 'source_type' => 'url', 'url' => 'https://example.com/a.mp3'],
                ['type' => 'file', 'source_type' => 'url', 'url' => 'https://example.com/d.pdf'],
            ],
        ])]);

        $sent = $model->httpClient->lastRequestBody();

        self::assertSame(
            ['text', 'image', 'audio', 'file'],
            array_column($sent['messages'][0]['content'], 'type'),
            'every block upstream forwards must reach the wire, in order',
        );
        self::assertSame(
            'https://example.com/cat.png',
            $sent['messages'][0]['content'][1]['url'],
            'the image URL must be intact, not merely present as a block',
        );
    }

    /**
     * The other half of upstream's rule, so the fix cannot degenerate into
     * "forward absolutely everything": the six rejected types still go.
     */
    public function testTheSixRejectedBlockTypesAreStillDropped(): void
    {
        $out = Completions::convertMessage(new AIMessage([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'hmm'],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'f', 'input' => []],
                ['type' => 'tool_call', 'id' => 't2', 'name' => 'g', 'args' => []],
                ['type' => 'functionCall', 'name' => 'h', 'args' => []],
                ['type' => 'reasoning', 'reasoning' => 'because'],
                ['type' => 'reasoning_content', 'reasoning_content' => 'also'],
                ['type' => 'text', 'text' => 'kept'],
            ],
        ]));

        self::assertSame(
            [['type' => 'text', 'text' => 'kept']],
            $out['content'],
            'exactly upstream\'s six are dropped, and everything else survives',
        );
    }
}