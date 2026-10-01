<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A stream's FINAL chunk normally carries the finish reason and nothing else.
 *
 * `BaseChatModel::isMetadataOnly()` decides using `$chunk->message` alone — `content`,
 * `additional_kwargs`, `toolCalls`, `toolCallChunks` — but both providers put the stop reason in
 * `generationInfo`, never in `response_metadata`: OpenAI `finish_reason` at `ChatOpenAI.php:548`/`:613`,
 * Anthropic `stop_reason` at `ChatAnthropic.php:401`/`:577`.
 *
 * So a terminal chunk with empty content, empty `additional_kwargs` and no tool calls satisfies every
 * check `isMetadataOnly` makes, is classified metadata-only, and is `continue`d at
 * `BaseChatModel.php:442` — and the finish reason never reaches the caller.
 *
 * `testAContentBearingChunkIsNotMetadataOnly` and `testAPlainUsageChunkStillIs` are the controls: without
 * them, a fix that simply returned false always would satisfy this suite.
 */
#[CoversClass(BaseChatModel::class)]
final class TerminalChunkNotMetadataOnlyTest extends TestCase
{
    private function isMetadataOnly(ChatGenerationChunk $chunk): bool
    {
        $method = new \ReflectionMethod(BaseChatModel::class, 'isMetadataOnly');

        return $method->invoke($this->model(), $chunk);
    }

    private function model(): BaseChatModel
    {
        // `isMetadataOnly()` is private and reads no instance state, so any concrete model works.
        // An anonymous subclass would read as tidier but BaseChatModel has abstract members that must
        // be satisfied before it can be instantiated at all.
        return new ChatOpenAI(['apiKey' => 'k']);
    }

    /** RED before the fix: this chunk is dropped and the stop reason is lost. */
    public function testATerminalChunkCarryingOnlyAFinishReasonIsNotMetadataOnly(): void
    {
        $chunk = new ChatGenerationChunk(new AIMessageChunk(''), '', ['finish_reason' => 'stop']);

        self::assertFalse(
            $this->isMetadataOnly($chunk),
            'a chunk whose only payload is generationInfo[finish_reason] must not be discarded as '
            . 'metadata, or the caller never learns why the stream ended'
        );
    }

    /** Same for Anthropic's key. */
    public function testATerminalChunkCarryingOnlyAStopReasonIsNotMetadataOnly(): void
    {
        $chunk = new ChatGenerationChunk(new AIMessageChunk(''), '', ['stop_reason' => 'end_turn']);

        self::assertFalse($this->isMetadataOnly($chunk));
    }

    /** Control: real content is never metadata-only. */
    public function testAContentBearingChunkIsNotMetadataOnly(): void
    {
        self::assertFalse($this->isMetadataOnly(new ChatGenerationChunk(new AIMessageChunk('hi'), 'hi')));
    }

    /**
     * Control: a genuine usage-only chunk with NO finish reason must STILL be metadata-only. This is the
     * behaviour `isMetadataOnly` exists for, and a fix that returned false unconditionally would break it.
     */
    public function testAPlainUsageChunkStillIs(): void
    {
        $chunk = new ChatGenerationChunk(new AIMessageChunk(''), '', ['usage_metadata' => ['total_tokens' => 12]]);

        self::assertTrue($this->isMetadataOnly($chunk), 'a usage-only chunk carries nothing the caller needs');
    }
}
