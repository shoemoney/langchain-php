<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Runnables\RunnableConfig;

/**
 * Parse a model call into raw bytes.
 *
 * Port of `BytesOutputParser` from `@langchain_core/output_parsers/bytes`.
 *
 * The parser of last resort for a chain that pipes a model straight into a
 * binary sink — a socket, a file. In TypeScript the output is a `Uint8Array`;
 * PHP strings already carry bytes, so the UTF-8 encoding of the text *is* the
 * result and no separate byte array is needed.
 *
 * @extends BaseTransformOutputParser<string>
 */
class BytesOutputParser extends BaseTransformOutputParser
{
    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'output_parsers', 'bytes'];
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
}
