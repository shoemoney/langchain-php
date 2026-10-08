<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Tools\Bash;
use LangChain\LanguageModels\Chat\Anthropic\Tools\Computer;
use LangChain\LanguageModels\Chat\Anthropic\Tools\McpToolset;
use LangChain\LanguageModels\Chat\Anthropic\Tools\Memory;
use LangChain\LanguageModels\Chat\Anthropic\Tools\Types;
use LangChain\LanguageModels\Chat\Anthropic\Tools\WebFetch;
use LangChain\LanguageModels\Chat\Anthropic\Tools\WebSearch;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

#[CoversClass(MessageInputs::class)]
#[CoversClass(ChatAnthropic::class)]
#[CoversClass(Types::class)]
final class HostedToolsWiringTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function reply(): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];
    }

    private static function model(?FakeHttpClient $http = null, array $fields = []): ChatAnthropic
    {
        return new ChatAnthropic($fields + [
            'model' => 'claude-sonnet-4-5',
            'apiKey' => 'sk-ant-test',
            'httpClient' => $http ?? new FakeHttpClient([FakeHttpClient::json(200, self::reply())]),
            'maxRetries' => 0,
        ]);
    }

    public function testServerToolsPassThroughConvertToolUntouched(): void
    {
        foreach ([
            WebSearch::webSearch_20250305(['maxUses' => 2]),
            WebFetch::webFetch_20250910(),
            McpToolset::mcpToolset_20251120(['serverName' => 's']),
            ['type' => 'text_editor_20250124', 'name' => 'str_replace_editor'],
        ] as $serverTool) {
            self::assertSame($serverTool, MessageInputs::convertTool($serverTool, true));
        }
    }

    public function testClientToolsConvertToTheirProviderDefinition(): void
    {
        self::assertSame(
            ['type' => 'bash_20250124', 'name' => 'bash'],
            MessageInputs::convertTool(Bash::bash_20250124()),
        );
        self::assertSame(
            ['type' => 'memory_20250818', 'name' => 'memory'],
            MessageInputs::convertTool(Memory::memory_20250818()),
        );
    }

    public function testTheSchemaBranchIsNotLoosened(): void
    {
        // A dated `type` without a server-tool shape must still hit the old guards.
        $converted = MessageInputs::convertTool(['name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]);
        self::assertSame('object', $converted['input_schema']['type']);
        self::assertTrue(MessageInputs::isServerTool(['type' => 'bash_20250124', 'name' => 'bash']));
        self::assertFalse(MessageInputs::isServerTool(['type' => 'function', 'name' => 'x']));
        self::assertFalse(MessageInputs::isServerTool(['type' => 'web_search_20250305', 'input_schema' => []]));
        self::assertFalse(MessageInputs::isServerTool('web_search_20250305'));
    }

    public function testEndToEndRequestCarriesToolsAndBetaHeader(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply())]);
        $custom = tool(static fn (array $a): string => 'x', ['name' => 'lookup', 'description' => 'd', 'schema' => Schema::object(['q' => Schema::string()], ['q'])]);

        $model = self::model($http)->bindTools([
            WebSearch::webSearch_20250305(),
            WebFetch::webFetch_20250910(),
            Computer::computer_20251124(['displayWidthPx' => 800, 'displayHeightPx' => 600]),
            McpToolset::mcpToolset_20251120(['serverName' => 'cal']),
            Bash::bash_20250124(),
            $custom,
        ]);

        self::assertSame('ok', $model->invoke('hi')->content);

        $request = $http->requests[0];
        $body = json_decode($request['body'], true);
        self::assertArrayNotHasKey('betas', $body);
        self::assertSame(
            ['web_search_20250305', 'web_fetch_20250910', 'computer_20251124', 'mcp_toolset', 'bash_20250124'],
            array_map(static fn (array $t): string => $t['type'] ?? '', array_slice($body['tools'], 0, 5)),
        );
        self::assertSame('lookup', $body['tools'][5]['name']);
        self::assertArrayHasKey('input_schema', $body['tools'][5]);
        self::assertSame(
            'web-fetch-2025-09-10,computer-use-2025-11-24,mcp-client-2025-11-20',
            $request['headers']['anthropic-beta'],
        );
        self::assertSame(ChatAnthropic::API_VERSION, $request['headers']['anthropic-version']);
    }

    public function testAChainRunsWithHostedToolsAndSendsTheBetaHeader(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply())]);
        $model = self::model($http)->bindTools([WebFetch::webFetch_20250910(), WebSearch::webSearch_20250305()]);

        $answer = ChatPromptTemplate::fromMessages([['system', 'Be {tone}.'], ['human', '{q}']])
            ->pipe($model)
            ->pipe(new StringOutputParser())
            ->invoke(['tone' => 'brief', 'q' => 'summarise example.com']);

        self::assertSame('ok', $answer);
        self::assertSame('web-fetch-2025-09-10', $http->requests[0]['headers']['anthropic-beta']);
        self::assertSame('web_fetch', json_decode($http->requests[0]['body'], true)['tools'][0]['name']);
    }

    public function testNoBetaHeaderWithoutBetaTools(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply())]);
        self::model($http)->bindTools([WebSearch::webSearch_20250305()])->invoke('hi');

        self::assertArrayNotHasKey('anthropic-beta', $http->requests[0]['headers']);
    }

    public function testExplicitBetasAreMergedWithToolBetas(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply())]);
        self::model($http, ['betas' => ['files-api-2025-04-14']])
            ->bindTools([WebFetch::webFetch_20250910()])
            ->invoke('hi', null);

        self::assertSame(
            'files-api-2025-04-14,web-fetch-2025-09-10',
            $http->requests[0]['headers']['anthropic-beta'],
        );
    }

    public function testBetasForDeduplicates(): void
    {
        self::assertSame(
            ['advanced-tool-use-2025-11-20'],
            Types::betasFor([['type' => 'tool_search_tool_regex_20251119'], ['type' => 'tool_search_tool_bm25_20251119'], ['name' => 'x']]),
        );
    }

    public function testClientToolSchemasValidateModelArguments(): void
    {
        $bash = Bash::bash_20250124(['execute' => static fn (array $a): string => 'ran']);
        $this->expectException(\LangChain\Tools\ToolException::class);
        $bash->invoke(['nonsense' => 1]);
    }

    public function testToolWithoutExecuteFailsLoudly(): void
    {
        $this->expectException(\LangChain\Tools\ToolException::class);
        Bash::bash_20250124()->invoke(['command' => 'ls']);
    }
}
