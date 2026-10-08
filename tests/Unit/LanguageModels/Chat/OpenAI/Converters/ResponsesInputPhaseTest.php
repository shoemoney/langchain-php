<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\Messages\AIMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `describe("phase parameter support")` > `convertMessagesToResponsesInput round-trip`
 * and the standard-content half of `full round-trip`, from upstream
 * `converters/tests/responses.test.ts`.
 *
 * The raw-provider "full round-trip" starts from `convertResponsesMessageToAIMessage`
 * (output side); its input half is covered here by the hand-built message in
 * {@see self::testPreservesPhaseWhenConvertingAnAiMessageBackToResponsesInput()}.
 */
#[CoversClass(ResponsesInput::class)]
final class ResponsesInputPhaseTest extends TestCase
{
    use AssertsWireJson;

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private static function messageItem(array $items): array
    {
        foreach ($items as $item) {
            if ($item['type'] === 'message') {
                return $item;
            }
        }

        self::fail('no message item was produced');
    }

    public function testPreservesPlainStringAssistantContent(): void
    {
        $message = new AIMessage([
            'id' => 'msg_001',
            'content' => 'Let me check that for you.',
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        $item = self::messageItem(ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-4o'));

        self::assertSame('Let me check that for you.', $item['content']);
        self::assertArrayNotHasKey('phase', $item);
    }

    public function testPreservesPhaseWhenConvertingAnAiMessageBackToResponsesInput(): void
    {
        $message = new AIMessage([
            'id' => 'msg_001',
            'content' => [['type' => 'text', 'text' => 'Let me check that for you.', 'phase' => 'commentary']],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        $item = self::messageItem(ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-5.4'));

        self::assertSame('commentary', $item['phase']);
        self::assertSame('msg_001', $item['id']);
        self::assertWire(
            [['type' => 'output_text', 'text' => 'Let me check that for you.', 'annotations' => []]],
            $item['content'],
        );
    }

    public function testDoesNotIncludePhaseWhenContentBlocksHaveNoPhase(): void
    {
        $message = new AIMessage([
            'id' => 'msg_001',
            'content' => [['type' => 'text', 'text' => 'Hello!']],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        $item = self::messageItem(ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-4o'));

        self::assertArrayNotHasKey('phase', $item);
    }

    public function testPreservesPhaseFromExtrasThroughTheStandardContentPath(): void
    {
        $message = new AIMessage([
            'id' => 'msg_001',
            'content' => [['type' => 'text', 'text' => 'The answer is 42.', 'extras' => ['phase' => 'final_answer']]],
            'response_metadata' => ['model_provider' => 'openai', 'output_version' => 'v1'],
        ]);

        $item = self::messageItem(ResponsesInput::convertStandardContentMessageToResponsesInput($message));

        self::assertSame('final_answer', $item['phase']);
    }

    public function testRoundTripsPhaseThroughTheStandardContentPath(): void
    {
        $message = new AIMessage([
            'id' => 'msg_001',
            'content' => [['type' => 'text', 'text' => 'The weather is sunny.', 'extras' => ['phase' => 'final_answer']]],
            'response_metadata' => ['model_provider' => 'openai', 'output_version' => 'v1'],
        ]);

        // Through convertMessagesToResponsesInput the v1 marker routes to the standard path.
        $item = self::messageItem(ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-5.4'));

        self::assertSame('final_answer', $item['phase']);
        self::assertWire(
            [['type' => 'output_text', 'text' => 'The weather is sunny.', 'annotations' => []]],
            $item['content'],
        );
    }
}
