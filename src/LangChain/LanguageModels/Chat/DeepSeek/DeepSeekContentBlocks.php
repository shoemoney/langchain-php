<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\DeepSeek;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;

/**
 * A DeepSeek AI message as v1 standard content blocks.
 *
 * Port of `convertToV1FromDeepSeekMessage` (`block_translators/deepseek.ts`) from
 * `@langchain/core`. DeepSeek delivers its chain of thought in
 * `additional_kwargs.reasoning_content`; this turns it into a leading
 * `reasoning` block, followed by the text blocks and the tool calls.
 *
 * Upstream reaches this through `message.contentBlocks`, dispatching on
 * `response_metadata.model_provider`. The PHP `BaseMessage::contentBlocks()` has
 * no translator registry, so the translation is exposed as a function and
 * {@see ChatDeepSeek::contentBlocks()} calls it.
 */
final class DeepSeekContentBlocks
{
    private function __construct()
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function of(BaseMessage $message): array
    {
        $blocks = [];

        $reasoning = $message->additional_kwargs['reasoning_content'] ?? null;
        if (is_string($reasoning) && $reasoning !== '') {
            $blocks[] = ['type' => 'reasoning', 'reasoning' => $reasoning];
        }

        if (is_string($message->content)) {
            if ($message->content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $message->content];
            }
        } else {
            foreach ($message->content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $blocks[] = ['type' => 'text', 'text' => $block['text']];
                }
            }
        }

        foreach (self::toolCallsOf($message) as $toolCall) {
            $blocks[] = [
                'type' => 'tool_call',
                'id' => $toolCall['id'] ?? null,
                'name' => $toolCall['name'] ?? null,
                'args' => $toolCall['args'] ?? [],
            ];
        }

        return $blocks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOf(BaseMessage $message): array
    {
        if ($message instanceof AIMessageChunk) {
            return $message->parseToolCalls()[0];
        }

        return $message instanceof AIMessage ? $message->toolCalls : [];
    }
}
