<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\ApplyPatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/applyPatch.test.ts`. */
#[CoversClass(ApplyPatch::class)]
final class ApplyPatchTest extends TestCase
{
    public function testCreatesValidToolDefinitions(): void
    {
        $tool = ApplyPatch::create(['execute' => static fn (array $op): string => 'done']);

        self::assertSame('apply_patch', $tool->name);
        self::assertSame(['type' => 'apply_patch'], $tool->extras['providerToolDefinition']);
    }

    public function testExecuteCallbackReceivesTheOperation(): void
    {
        $operations = [];
        $tool = ApplyPatch::create(['execute' => static function (array $op) use (&$operations): string {
            $operations[] = $op;

            return 'Processed ' . $op['path'];
        }]);

        $result = $tool->invoke(['type' => 'create_file', 'path' => 'test.txt', 'diff' => '+hello world']);

        self::assertCount(1, $operations);
        self::assertSame('create_file', $operations[0]['type']);
        self::assertSame('test.txt', $operations[0]['path']);
        self::assertSame('Processed test.txt', $result);
    }

    public function testHandlesAllOperationTypes(): void
    {
        $types = [];
        $tool = ApplyPatch::create(['execute' => static function (array $op) use (&$types): string {
            $types[] = $op['type'];

            return 'ok';
        }]);

        $tool->invoke(['type' => 'create_file', 'path' => 'a.txt', 'diff' => '+a']);
        $tool->invoke(['type' => 'update_file', 'path' => 'b.txt', 'diff' => "-x\n+y"]);
        $tool->invoke(['type' => 'delete_file', 'path' => 'c.txt']);

        self::assertSame(['create_file', 'update_file', 'delete_file'], $types);
    }
}
