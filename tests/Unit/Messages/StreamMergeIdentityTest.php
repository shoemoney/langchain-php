<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\MessageMerge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Fields that identify a message must not accumulate across chunks.
 *
 * Upstream declares `model_provider`, `model_name` and `output_version` as the
 * named members of `ResponseMetadata` beside an open index signature — they are
 * identity, not content. `model_name` was missing from the replace list, so
 * three streamed OpenAI deltas folded into `model_name: "gpt-4ogpt-4ogpt-4o"`.
 */
#[CoversClass(MessageMerge::class)]
final class StreamMergeIdentityTest extends TestCase
{
    public function testIdentityFieldsReplaceRatherThanConcatenate(): void
    {
        foreach (['id', 'name', 'model_provider', 'model_name', 'output_version'] as $key) {
            $merged = MessageMerge::mergeDicts([$key => 'first'], [$key => 'second']);

            self::assertSame('second', $merged[$key] ?? null, "$key must replace, not accumulate");
        }
    }

    public function testStreamedModelNameSurvivesTheFoldIntact(): void
    {
        $chunk = static fn (): AIMessageChunk => new AIMessageChunk([
            'content' => 'x',
            'response_metadata' => ['model_provider' => 'openai', 'model_name' => 'gpt-4o'],
        ]);

        $folded = $chunk()->concat($chunk())->concat($chunk());

        self::assertSame('gpt-4o', $folded->response_metadata['model_name']);
        self::assertSame('openai', $folded->response_metadata['model_provider']);
    }

    /**
     * Numeric metadata still sums — the fix must not make everything replace.
     */
    public function testUsageStillSumsAndContentStillConcatenates(): void
    {
        $a = new AIMessageChunk([
            'content' => 'a',
            'response_metadata' => ['usage_metadata' => ['input_tokens' => 5, 'output_tokens' => 1]],
        ]);
        $b = new AIMessageChunk([
            'content' => 'b',
            'response_metadata' => ['usage_metadata' => ['output_tokens' => 2]],
        ]);

        $folded = $a->concat($b);

        self::assertSame('ab', $folded->content);
        self::assertSame(
            3,
            $folded->response_metadata['usage_metadata']['output_tokens'] ?? null,
            '1 + 2 = 3; numbers must still sum after the identity fix',
        );
    }
}
