<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackManagerForToolRun;

/**
 * A tool that returns its own arguments, JSON-encoded.
 *
 * Port of `FakeTool` from `@langchain/core/utils/testing`.
 *
 * Echoing the arguments makes the tool an assertion point: whatever survived
 * validation and coercion is exactly what comes back, so a test can check what
 * the model actually sent without the tool's own logic getting in the way.
 */
final class FakeTool extends StructuredTool
{
    public static function lcName(): string
    {
        return 'FakeTool';
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields + ['schema' => Schema::any()]);
    }

    protected function callTool(mixed $arg, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $parentConfig = null): mixed
    {
        return (string) json_encode($arg, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
