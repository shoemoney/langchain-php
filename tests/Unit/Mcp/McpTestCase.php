<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Tools\StructuredTool;
use LangGraph\Mcp\McpClientInterface;
use LangGraph\Mcp\McpTools;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the converted `langchain-mcp-adapters` suites.
 */
abstract class McpTestCase extends TestCase
{
    /** A one-tool descriptor with a permissive object schema. */
    protected static function echoTool(array $extra = []): array
    {
        return [...['name' => 'echo', 'inputSchema' => ['type' => 'object']], ...$extra];
    }

    /** @return array<string, mixed> */
    protected static function text(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function firstTool(McpClientInterface $client, array $options = []): StructuredTool
    {
        $tools = McpTools::loadMcpTools('test', $client, $options);
        self::assertNotEmpty($tools);

        return $tools[0];
    }

    /** A model-issued tool call envelope. */
    protected static function toolCall(string $name = 'echo', array $args = [], string $id = 'call'): array
    {
        return ['type' => 'tool_call', 'id' => $id, 'name' => $name, 'args' => $args];
    }

    /**
     * Run `$fn`, return the throwable it raised (failing when it raised nothing).
     */
    protected static function thrownBy(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('Expected a throwable.');
    }
}
