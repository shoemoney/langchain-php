<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into a decoded JSON value.
 *
 * Port of `JsonOutputParser` from `@langchain_core/output_parsers/json`.
 *
 * Three things make this more than `json_decode` with the fences stripped:
 *
 *  - **Fences are optional.** A model may wrap its answer in ` ```json `, in a
 *    bare ` ``` `, in neither, or in neither plus a paragraph of preamble.
 *  - **Preambles are tolerated.** "Here is the JSON you asked for:" followed by
 *    a fence parses; the text before the first fence is ignored.
 *  - **Prefixes parse.** While streaming, `{` is a valid prefix that means
 *    "empty object", so the accumulated buffer is parsed leniently on every
 *    chunk and consumers watch the document grow.
 *
 * Set `diff: true` to receive RFC 6902 patches per chunk instead of whole
 * documents, which is what a UI wants — redrawing a five-hundred-key object per
 * token is how you make a streaming UI feel slow.
 *
 * @extends BaseCumulativeTransformOutputParser<mixed>
 */
class JsonOutputParser extends BaseCumulativeTransformOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'JsonOutputParser'];
    }

    /**
     * Leniently parse the accumulated text, tolerating truncation.
     *
     * @param list<array{text: string, message?: BaseMessage}> $generations
     */
    public function parsePartialResult(array $generations): mixed
    {
        $first = $generations[0] ?? null;
        if ($first === null) {
            return null;
        }

        return JsonUtils::parseJsonMarkdown((string) ($first['text'] ?? ''));
    }

    /**
     * Strictly parse a complete JSON value out of LLM output.
     *
     * A malformed document surfaces as the `JsonException` from `json_decode`,
     * exactly as the TypeScript original lets `JSON.parse`'s `SyntaxError`
     * propagate.
     */
    public function parse(string $text, ?RunnableConfig $config = null): mixed
    {
        return JsonUtils::parseJsonMarkdown(
            $text,
            static fn (string $json): mixed => json_decode($json, true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /** @param array<string, mixed> $options */
    public function getFormatInstructions(array $options = []): string
    {
        return '';
    }

    /**
     * JSON-Patch operations between two successive partial results.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function diffOperations(mixed $prev, mixed $next): ?array
    {
        // An empty object is a real value in JavaScript, so only `undefined`,
        // `null`, `''` and `0` short-circuit here — `{}` still produces a patch.
        if (JsonPatch::isFalsy($next)) {
            return null;
        }
        if (JsonPatch::isFalsy($prev)) {
            return [['op' => 'replace', 'path' => '', 'value' => $next]];
        }

        return JsonPatch::compare($prev, $next);
    }

    /**
     * Content blocks of any kind flatten to their concatenated text.
     */
    protected function baseMessageToString(BaseMessage $message): string
    {
        return $message->text();
    }
}
