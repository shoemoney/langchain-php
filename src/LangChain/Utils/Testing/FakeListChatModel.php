<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model that replays a fixed list of responses, in order.
 *
 * Port of `FakeListChatModel` from `@langchain/core/utils/testing`.
 *
 * This is the workhorse test double: script the exact outputs, then assert on
 * how they were consumed. Three behaviours are worth calling out.
 *
 * **The list is a loop, not a script.** After the last entry the index resets,
 * so a test that makes one more call than it scripted still gets a response
 * instead of `null` — and a `null` would fail in a way that looks like a bug in
 * the model under test.
 *
 * **Responses advance on both paths.** `generate()` and `streamResponseChunks()`
 * each consume one entry, so a test cannot accidentally get the same scripted
 * response from both and believe they agreed.
 *
 * **`generationInfo` lands on the final chunk only**, matching how real
 * providers report `finish_reason`. It is merged into the accumulated message's
 * `response_metadata`, so this is how a test checks that finish reasons survive
 * streaming.
 */
final class FakeListChatModel extends BaseChatModel
{
    public static function lcName(): string
    {
        return 'FakeListChatModel';
    }

    /** @var list<string> */
    public array $responses;

    /** Index into {@see self::$responses}. */
    public int $i = 0;

    /** Milliseconds to pause between streamed characters. */
    public int $sleep = 0;

    /** Emit a custom event before each response. */
    public bool $emitCustomEvent = false;

    /** Attached to the final streamed chunk, as a provider would send `finish_reason`. */
    public array $generationInfo = [];

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->responses = array_values(array_map(strval(...), (array) ($fields['responses'] ?? [])));
        $this->sleep = isset($fields['sleep']) ? (int) $fields['sleep'] : 0;
        $this->emitCustomEvent = (bool) ($fields['emitCustomEvent'] ?? false);
        $this->generationInfo = (array) ($fields['generationInfo'] ?? []);
    }

    public function llmType(): string
    {
        return 'fake-list';
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain', 'chat_models', 'fake-list', 'FakeListChatModel'];
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $this->sleepIfRequested();

        $this->throwIfRequested($options);

        if ($this->emitCustomEvent) {
            $runManager?->handleCustomEvent('some_test_event', ['someval' => true]);
        }

        $stop = $options['stop'] ?? [];
        if (is_array($stop) && $stop !== [] && is_string($stop[0])) {
            return new ChatResult([$this->formatGeneration($stop[0])]);
        }

        $response = $this->currentResponse();
        $this->incrementResponse();

        return new ChatResult([$this->formatGeneration($response)], []);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        $response = $this->currentResponse();
        $this->incrementResponse();

        if ($this->emitCustomEvent) {
            $runManager?->handleCustomEvent('some_test_event', ['someval' => true]);
        }

        $chars = $response === '' ? [] : mb_str_split($response);
        $last = count($chars) - 1;

        foreach ($chars as $index => $char) {
            $this->sleepIfRequested();
            $this->throwIfRequested($options);

            yield $this->createResponseChunk($char, $index === $last ? $this->generationInfo : []);
            $runManager?->handleLLMNewToken($char);
        }
    }

    /**
     * @return ChatGeneration
     */
    private function formatGeneration(string $text): ChatGeneration
    {
        return new ChatGeneration(new AIMessage($text), $text);
    }

    /**
     * @param array<string, mixed> $generationInfo
     */
    private function createResponseChunk(string $text, array $generationInfo = []): ChatGenerationChunk
    {
        return new ChatGenerationChunk(new AIMessageChunk($text), $text, $generationInfo);
    }

    private function currentResponse(): string
    {
        return $this->responses[$this->i] ?? '';
    }

    private function incrementResponse(): void
    {
        if ($this->i < count($this->responses) - 1) {
            ++$this->i;
        } else {
            $this->i = 0;
        }
    }

    private function sleepIfRequested(): void
    {
        if ($this->sleep > 0) {
            usleep($this->sleep * 1000);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function throwIfRequested(array $options): void
    {
        $message = $options['thrownErrorString'] ?? null;
        if (is_string($message) && $message !== '') {
            throw new \RuntimeException($message);
        }
    }

    /**
     * The fields recorded in the serialized payload.
     *
     * `cache` is excluded: it is runtime wiring, and a model reconstructed from
     * a payload should not inherit an observer that happened to be attached.
     */
    public function kwargs(): array
    {
        return array_filter([
            'responses' => $this->responses,
            'sleep' => $this->sleep > 0 ? $this->sleep : null,
            'emit_custom_event' => $this->emitCustomEvent ? true : null,
            'generationInfo' => $this->generationInfo === [] ? null : $this->generationInfo,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
