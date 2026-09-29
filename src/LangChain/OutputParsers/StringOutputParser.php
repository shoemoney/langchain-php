<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call down to its plain text.
 *
 * Port of `StringOutputParser` from `@langchain_core/output_parsers/string`.
 *
 * The trivial-looking half of a chain, and the half that decides whether a
 * chain works at all: a chat model returns a message whose content may be a
 * list of blocks, and this is where those become the single string the next
 * step (another model, a regex, a database write) expects.
 *
 * Text blocks concatenate; reasoning and thinking blocks are dropped, since
 * they are scratch space rather than output. An image block is an error — a
 * parser that silently produced `''` for one would lose data without saying so.
 *
 * @extends BaseTransformOutputParser<string>
 */
class StringOutputParser extends BaseTransformOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'string'];
    }

    public function parse(string $text, ?RunnableConfig $config = null): string
    {
        return $text;
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return '';
    }

    /**
     * Flatten one content block to text.
     *
     * @param array<string, mixed> $content
     */
    protected function messageContentToString(array $content): string
    {
        $type = $content['type'] ?? null;

        return match ($type) {
            'text', 'text_delta', 'text-plain' => is_string($content['text'] ?? null)
                ? $content['text']
                : throw new \LogicException('Invalid content type: text'),
            'image_url', 'image' => throw new \RuntimeException(
                'Cannot coerce a multimodal "image_url" message part into a string.'
            ),
            'reasoning', 'thinking', 'redacted_thinking' => '',
            default => throw new \RuntimeException(
                'Cannot coerce "' . (is_string($type) ? $type : get_debug_type($type)) . '" message part into a string.'
            ),
        };
    }

    /**
     * @param list<mixed> $content
     */
    protected function baseMessageContentToString(array $content): string
    {
        $out = '';
        foreach ($content as $block) {
            if (is_string($block)) {
                $out .= $block;
                continue;
            }
            if (is_array($block)) {
                $out .= $this->messageContentToString($block);
            }
        }

        return $out;
    }
}
