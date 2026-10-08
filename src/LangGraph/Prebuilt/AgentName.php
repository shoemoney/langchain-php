<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;

/**
 * Expose an agent's name to a model by folding it into the message text.
 *
 * Port of `langgraph-core/src/prebuilt/agentName.ts` (`_addInlineAgentName`, `_removeInlineAgentName`,
 * `withAgentName`).
 *
 * A history that interleaves several agents is easier for a model to follow when each AI message says who
 * spoke. `inline` mode rewrites `name: "bob", content: "hi"` into `<name>bob</name><content>hi</content>`
 * on the way IN and undoes it on the way OUT, so callers never see the tags.
 */
final class AgentName
{
    public const MODE_INLINE = 'inline';

    private const NAME_PATTERN = '~<name>(.*?)</name>~s';
    private const CONTENT_PATTERN = '~<content>(.*?)</content>~s';

    private function __construct()
    {
    }

    /**
     * Port of `_addInlineAgentName`. Anything that is not an AI message with a name passes through.
     *
     * @template T
     * @param T $message
     * @return T|AIMessage
     */
    public static function addInlineAgentName(mixed $message): mixed
    {
        if (!($message instanceof AIMessage || $message instanceof AIMessageChunk) || $message->name === null || $message->name === '') {
            return $message;
        }

        $name = $message->name;

        if (is_string($message->content)) {
            return self::rebuild($message, '<name>' . $name . '</name><content>' . $message->content . '</content>', null);
        }

        $updated = [];
        $textBlocks = 0;
        foreach ($message->content as $block) {
            if (is_string($block)) {
                $textBlocks++;
                $updated[] = '<name>' . $name . '</name><content>' . $block . '</content>';
            } elseif (is_array($block) && ($block['type'] ?? null) === 'text') {
                $textBlocks++;
                $block['text'] = '<name>' . $name . '</name><content>' . (string) ($block['text'] ?? '') . '</content>';
                $updated[] = $block;
            } else {
                $updated[] = $block;
            }
        }

        if ($textBlocks === 0) {
            array_unshift($updated, ['type' => 'text', 'text' => '<name>' . $name . '</name><content></content>']);
        }

        return self::rebuild($message, $updated, null);
    }

    /**
     * Port of `_removeInlineAgentName`. Strips the tags and restores `name` from them.
     *
     * Like upstream, a list-content message is always rebuilt and takes its name from the tags it found
     * (or none), whereas string content with no recognisable tags is returned untouched.
     *
     * @template T of BaseMessage
     * @param T $message
     * @return T|AIMessage
     */
    public static function removeInlineAgentName(BaseMessage $message): BaseMessage
    {
        if (!($message instanceof AIMessage || $message instanceof AIMessageChunk) || $message->content === '') {
            return $message;
        }

        $updatedName = null;

        if (is_array($message->content)) {
            $kept = [];
            foreach ($message->content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $nameMatch = preg_match(self::NAME_PATTERN, $block['text'], $nm) === 1;
                    $contentMatch = preg_match(self::CONTENT_PATTERN, $block['text'], $cm) === 1;
                    // An empty content block is one added only because there was no text block to modify.
                    if ($nameMatch && (!$contentMatch || $cm[1] === '')) {
                        $updatedName = $nm[1];
                        continue;
                    }
                }
                $kept[] = $block;
            }

            $updatedContent = [];
            foreach ($kept as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                    $nameMatch = preg_match(self::NAME_PATTERN, $block['text'], $nm) === 1;
                    $contentMatch = preg_match(self::CONTENT_PATTERN, $block['text'], $cm) === 1;
                    if ($nameMatch && $contentMatch) {
                        $updatedName = $nm[1];
                        $block['text'] = $cm[1];
                    }
                }
                $updatedContent[] = $block;
            }

            return self::rebuild($message, $updatedContent, $updatedName);
        }

        if (preg_match(self::NAME_PATTERN, $message->content, $nm) !== 1 || preg_match(self::CONTENT_PATTERN, $message->content, $cm) !== 1) {
            return $message;
        }

        return self::rebuild($message, $cm[1], $nm[1]);
    }

    /**
     * Port of `withAgentName`: `messages -> add tags -> model -> remove tags`.
     *
     * @param string $agentNameMode Only `"inline"` exists.
     */
    public static function withAgentName(RunnableInterface $model, string $agentNameMode = self::MODE_INLINE): RunnableInterface
    {
        if ($agentNameMode !== self::MODE_INLINE) {
            throw new \InvalidArgumentException(
                sprintf('Invalid agent name mode: %s. Needs to be one of: "inline"', $agentNameMode),
            );
        }

        return RunnableSequence::from([
            RunnableLambda::from(static fn (mixed $messages): array => array_map(
                static fn (mixed $m): mixed => self::addInlineAgentName($m),
                (array) $messages,
            )),
            $model,
            RunnableLambda::from(static fn (mixed $message): mixed => $message instanceof BaseMessage
                ? self::removeInlineAgentName($message)
                : $message),
        ]);
    }

    /** @param string|list<mixed> $content */
    private static function rebuild(AIMessage|AIMessageChunk $message, string|array $content, ?string $name): AIMessage
    {
        $ai = $message instanceof AIMessageChunk ? $message->toMessage() : $message;

        return new AIMessage([
            'content' => $content,
            'additional_kwargs' => $ai->additional_kwargs,
            'response_metadata' => $ai->response_metadata,
            'tool_calls' => $ai->toolCalls,
            'invalid_tool_calls' => $ai->invalidToolCalls,
            'id' => $ai->id,
            'name' => $name,
        ]);
    }
}
