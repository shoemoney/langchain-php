<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\Mcp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/mcp.test.ts`. */
#[CoversClass(Mcp::class)]
final class McpTest extends TestCase
{
    private const URL = 'https://dmcp-server.deno.dev/sse';

    public function testBasicRemoteServerToolDefinition(): void
    {
        self::assertSame(
            ['type' => 'mcp', 'server_label' => 'dmcp', 'server_url' => self::URL],
            Mcp::tool(['serverLabel' => 'dmcp', 'serverUrl' => self::URL]),
        );
    }

    public function testConnectorToolDefinition(): void
    {
        self::assertSame(
            [
                'type' => 'mcp',
                'server_label' => 'google_calendar',
                'authorization' => 'test-oauth-token',
                'require_approval' => 'never',
                'connector_id' => 'connector_googlecalendar',
            ],
            Mcp::tool([
                'serverLabel' => 'google_calendar',
                'connectorId' => 'connector_googlecalendar',
                'authorization' => 'test-oauth-token',
                'requireApproval' => 'never',
            ]),
        );
    }

    public function testServerDescription(): void
    {
        $tool = Mcp::tool([
            'serverLabel' => 'dmcp',
            'serverUrl' => self::URL,
            'serverDescription' => 'A D&D MCP server for dice rolling',
            'requireApproval' => 'never',
        ]);

        self::assertSame('A D&D MCP server for dice rolling', $tool['server_description']);
        self::assertSame('never', $tool['require_approval']);
    }

    public function testAllowedToolsArray(): void
    {
        $tool = Mcp::tool(['serverLabel' => 'dmcp', 'serverUrl' => self::URL, 'allowedTools' => ['roll', 'flip_coin'], 'requireApproval' => 'never']);

        self::assertSame(['roll', 'flip_coin'], $tool['allowed_tools']);
    }

    public function testAllowedToolsFilterObject(): void
    {
        $tool = Mcp::tool([
            'serverLabel' => 'dmcp',
            'serverUrl' => self::URL,
            'allowedTools' => ['toolNames' => ['roll'], 'readOnly' => true],
            'requireApproval' => 'never',
        ]);

        self::assertSame(['tool_names' => ['roll'], 'read_only' => true], $tool['allowed_tools']);
    }

    public function testFineGrainedApprovalControl(): void
    {
        $tool = Mcp::tool([
            'serverLabel' => 'deepwiki',
            'serverUrl' => 'https://mcp.deepwiki.com/mcp',
            'requireApproval' => [
                'never' => ['toolNames' => ['ask_question', 'read_wiki_structure']],
                'always' => ['toolNames' => ['modify_content']],
            ],
        ]);

        self::assertSame(
            [
                'always' => ['tool_names' => ['modify_content']],
                'never' => ['tool_names' => ['ask_question', 'read_wiki_structure']],
            ],
            $tool['require_approval'],
        );
    }

    public function testCustomHeaders(): void
    {
        $headers = ['X-Custom-Header' => 'custom-value', 'X-Another-Header' => 'another-value'];
        $tool = Mcp::tool(['serverLabel' => 'custom', 'serverUrl' => 'https://custom-mcp.example.com', 'headers' => $headers, 'requireApproval' => 'never']);

        self::assertSame($headers, $tool['headers']);
    }

    public function testAllConnectorOptions(): void
    {
        self::assertSame(
            [
                'type' => 'mcp',
                'server_label' => 'Dropbox',
                'allowed_tools' => ['search', 'fetch'],
                'authorization' => 'dropbox-oauth-token',
                'require_approval' => 'always',
                'connector_id' => 'connector_dropbox',
            ],
            Mcp::tool([
                'serverLabel' => 'Dropbox',
                'connectorId' => 'connector_dropbox',
                'authorization' => 'dropbox-oauth-token',
                'allowedTools' => ['search', 'fetch'],
                'requireApproval' => 'always',
            ]),
        );
    }
}
