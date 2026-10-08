<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Converters;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;

/**
 * Re-expresses an OpenRouter AI message as v1 standard content blocks.
 *
 * Port of `convertToV1FromOpenRouterMessage` / `ChatOpenRouterTranslator` from
 * `@langchain/core` (`messages/block_translators/openrouter.ts`). Upstream
 * registers it by `model_provider` so `AIMessage.contentBlocks` picks it up; this
 * port's {@see \LangChain\Messages\BaseMessage::contentBlocks()} has no
 * translator registry, so call this directly.
 *
 * OpenRouter returns reasoning through two places:
 *
 *  1. `reasoning` / `delta.reasoning`, a flat string that
 *     {@see Messages} normalises into `additional_kwargs.reasoning_content`.
 *  2. `reasoning_details`, a structured array kept verbatim under
 *     `additional_kwargs.reasoning_details` for round-tripping to the provider
 *     (Anthropic extended thinking needs the original `signature` echoed back).
 *
 * When `reasoning_details` has visible entries (`reasoning.summary` /
 * `reasoning.text`) blocks come from those; opaque ones (`reasoning.encrypted`)
 * never become blocks, and the flat string is the fallback.
 */
final class ContentBlocks
{
    private function __construct()
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function convertToV1FromOpenRouterMessage(AIMessage|AIMessageChunk $message): array
    {
        $blocks = [];
        $kwargs = $message->additional_kwargs;

        $hasVisibleReasoningFromDetails = false;
        $details = $kwargs['reasoning_details'] ?? null;
        if (is_array($details) && $details !== []) {
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $type = $detail['type'] ?? null;
                if ($type === 'reasoning.summary') {
                    $summary = $detail['summary'] ?? null;
                    if (is_string($summary) && $summary !== '') {
                        $blocks[] = ['type' => 'reasoning', 'reasoning' => $summary];
                        $hasVisibleReasoningFromDetails = true;
                    }
                } elseif ($type === 'reasoning.text') {
                    $text = $detail['text'] ?? null;
                    if (is_string($text) && $text !== '') {
                        $blocks[] = ['type' => 'reasoning', 'reasoning' => $text];
                        $hasVisibleReasoningFromDetails = true;
                    }
                }
            }
        }

        if (!$hasVisibleReasoningFromDetails) {
            $reasoningContent = $kwargs['reasoning_content'] ?? null;
            if (is_string($reasoningContent) && $reasoningContent !== '') {
                $blocks[] = ['type' => 'reasoning', 'reasoning' => $reasoningContent];
            }
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

        $toolCalls = $message instanceof AIMessageChunk ? $message->parseToolCalls()[0] : $message->toolCalls;
        foreach ($toolCalls as $toolCall) {
            $blocks[] = [
                'type' => 'tool_call',
                'id' => $toolCall['id'] ?? null,
                'name' => $toolCall['name'] ?? null,
                'args' => $toolCall['args'] ?? [],
            ];
        }

        return $blocks;
    }
}
