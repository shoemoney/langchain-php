<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

use LangChain\Messages\BaseMessage;

/** Pure file-ID extraction from `@langchain/anthropic/utils/extractGeneratedFiles.ts`. */
final class ExtractGeneratedFiles
{
    private function __construct()
    {
    }

    /**
     * Read generated file IDs from successful bash code-execution result blocks.
     *
     * A provider BetaMessage is represented by its decoded array in PHP. A LangChain message
     * also supplies the content blocks, as in upstream's code-execution integration test.
     * IDs retain their order and duplicates; this helper does not fetch or download files.
     *
     * @param array{content: list<array<string, mixed>>}|BaseMessage $message
     *
     * @return list<string>
     */
    public static function extractGeneratedFiles(array|BaseMessage $message): array
    {
        $content = $message instanceof BaseMessage ? $message->content : $message['content'];
        $fileIds = [];

        if (!is_array($content)) {
            return $fileIds;
        }

        foreach ($content as $item) {
            if (($item['type'] ?? null) === 'bash_code_execution_tool_result'
                && ($item['content']['type'] ?? null) === 'bash_code_execution_result'
            ) {
                foreach ($item['content']['content'] as $file) {
                    if (($file['type'] ?? null) === 'bash_code_execution_output') {
                        $fileIds[] = $file['file_id'];
                    }
                }
            }
        }

        return $fileIds;
    }
}
