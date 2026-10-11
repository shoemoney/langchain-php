<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Utils;

use LangChain\LanguageModels\Chat\Anthropic\Utils\ExtractGeneratedFiles;
use LangChain\Messages\AIMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Pure file-ID assertion from chat_models-code_execution.int.test.ts, using local content blocks. */
#[CoversClass(ExtractGeneratedFiles::class)]
final class ExtractGeneratedFilesTest extends TestCase
{
    private static function bashResult(array $files): array
    {
        return [
            'type' => 'bash_code_execution_tool_result',
            'tool_use_id' => 'server_call',
            'content' => ['type' => 'bash_code_execution_result', 'stdout' => '', 'stderr' => '', 'return_code' => 0, 'content' => $files],
        ];
    }

    public function testExtractsGeneratedFileIdsFromDecodedProviderResultsInOrderWithoutDeduplicating(): void
    {
        $message = ['content' => [
            ['type' => 'text', 'text' => 'Created files'],
            self::bashResult([
                ['type' => 'bash_code_execution_output', 'file_id' => 'file_first'],
                ['type' => 'bash_code_execution_output', 'file_id' => 'file_second'],
            ]),
            self::bashResult([['type' => 'bash_code_execution_output', 'file_id' => 'file_first']]),
        ]];
        $before = $message;

        self::assertSame(['file_first', 'file_second', 'file_first'], ExtractGeneratedFiles::extractGeneratedFiles($message));
        self::assertSame($before, $message);
    }

    public function testExtractsTheFileOutputFromALangChainMessageLikeTheUpstreamCodeExecutionTest(): void
    {
        $message = new AIMessage(['content' => [self::bashResult([['type' => 'bash_code_execution_output', 'file_id' => 'file_results']])]]);
        $before = $message->toDict();

        self::assertSame(['file_results'], ExtractGeneratedFiles::extractGeneratedFiles($message));
        self::assertSame($before, $message->toDict());
    }

    public function testIgnoresOtherOuterResultsErrorsAndNonOutputInnerBlocks(): void
    {
        self::assertSame([], ExtractGeneratedFiles::extractGeneratedFiles(['content' => [
            ['type' => 'server_tool_use', 'name' => 'bash_code_execution', 'id' => 'server_call', 'input' => ['command' => 'echo hello']],
            ['type' => 'text_editor_code_execution_tool_result', 'content' => ['type' => 'bash_code_execution_result', 'content' => [['type' => 'bash_code_execution_output', 'file_id' => 'wrong_outer']]]],
            ['type' => 'bash_code_execution_tool_result', 'content' => ['type' => 'bash_code_execution_tool_result_error', 'error_code' => 'execution_time_exceeded']],
            self::bashResult([['type' => 'text', 'text' => 'stdout', 'file_id' => 'wrong_inner']]),
        ]]));
    }

    public function testEmptyResultsAndPlainTextMessagesContainNoGeneratedFiles(): void
    {
        self::assertSame([], ExtractGeneratedFiles::extractGeneratedFiles(['content' => []]));
        self::assertSame([], ExtractGeneratedFiles::extractGeneratedFiles(['content' => [self::bashResult([])]]));
        self::assertSame([], ExtractGeneratedFiles::extractGeneratedFiles(new AIMessage('No code execution')));
    }
}
