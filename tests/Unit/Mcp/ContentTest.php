<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\Content;
use LangGraph\Mcp\ToolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit cases for `content.ts`, which upstream covers only through `tools.test.ts` and `hooks.test.ts`.
 */
#[CoversClass(Content::class)]
final class ContentTest extends McpTestCase
{
    private const TEXT = ['type' => 'text', 'text' => 'hello'];

    private const IMAGE = ['type' => 'image', 'data' => 'aW1n', 'mimeType' => 'image/png'];

    private const RESOURCE = ['type' => 'resource', 'resource' => ['uri' => 'memory://r', 'text' => 'body', 'mimeType' => 'text/plain']];

    public function testASingleMetadataFreeTextBlockCollapsesToAString(): void
    {
        self::assertSame(['hello', []], Content::convertCallToolResult('s', 't', ['content' => [self::TEXT]]));
    }

    public function testSeveralBlocksStayBlocks(): void
    {
        [$content, $artifacts] = Content::convertCallToolResult('s', 't', ['content' => [self::TEXT, self::IMAGE]]);

        self::assertSame([self::TEXT, self::IMAGE], $content);
        self::assertSame([], $artifacts);
    }

    public function testEmptyContentIsAnEmptyList(): void
    {
        self::assertSame([[], []], Content::convertCallToolResult('s', 't', ['content' => []]));
    }

    public function testResourcesDefaultToArtifactsWhileEverythingElseDefaultsToContent(): void
    {
        [$content, $artifacts] = Content::convertCallToolResult('s', 't', ['content' => [self::TEXT, self::IMAGE, self::RESOURCE]]);

        self::assertSame([self::TEXT, self::IMAGE], $content);
        self::assertSame([self::RESOURCE], $artifacts);
    }

    public function testASingleStringPolicyRoutesEveryType(): void
    {
        $result = ['content' => [self::TEXT, self::IMAGE, self::RESOURCE]];

        [$content, $artifacts] = Content::convertCallToolResult('s', 't', $result, 'artifact');
        self::assertSame([[], [self::TEXT, self::IMAGE, self::RESOURCE]], [$content, $artifacts]);

        [$content, $artifacts] = Content::convertCallToolResult('s', 't', $result, 'content');
        self::assertCount(3, $content);
        // Resources routed to content still keep provenance as an `mcp_content` artifact.
        self::assertSame([['type' => 'mcp_content', 'data' => self::RESOURCE]], $artifacts);
    }

    public function testAPerTypePolicyRoutesOnlyTheTypesItNames(): void
    {
        $result = ['content' => [self::TEXT, self::IMAGE]];

        [$content, $artifacts] = Content::convertCallToolResult('s', 't', $result, ['image' => 'artifact']);

        self::assertSame('hello', $content);
        self::assertSame([self::IMAGE], $artifacts);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function embeddedBlobs(): array
    {
        return [
            'image' => [['uri' => 'm://i', 'blob' => 'QQ==', 'mimeType' => 'image/png'], 'image'],
            'audio' => [['uri' => 'm://a', 'blob' => 'QQ==', 'mimeType' => 'audio/wav'], 'audio'],
            'other' => [['uri' => 'm://f', 'blob' => 'QQ==', 'mimeType' => 'application/pdf'], 'file'],
            'no mime type' => [['uri' => 'm://f', 'blob' => 'QQ=='], 'file'],
        ];
    }

    /** @param array<string, string> $resource */
    #[DataProvider('embeddedBlobs')]
    public function testEmbeddedBlobsBecomeTheMatchingStandardBlockWithTheirUri(array $resource, string $type): void
    {
        [$content] = Content::convertCallToolResult('s', 't', ['content' => [['type' => 'resource', 'resource' => $resource]]], 'content');

        self::assertSame($type, $content[0]['type']);
        self::assertSame('QQ==', $content[0]['data']);
        self::assertSame($resource['mimeType'] ?? 'application/octet-stream', $content[0]['mimeType']);
        self::assertSame(['uri' => $resource['uri']], $content[0]['metadata']);
    }

    public function testAResourceLinkBecomesAFileBlockAndKeepsItsProvenance(): void
    {
        $link = ['type' => 'resource_link', 'uri' => 'memory://linked', 'name' => 'linked', 'title' => 'Linked', 'mimeType' => 'text/csv'];

        [$content, $artifacts] = Content::convertCallToolResult('s', 't', ['content' => [$link]]);

        self::assertSame(
            [['type' => 'file', 'url' => 'memory://linked', 'mimeType' => 'text/csv', 'metadata' => ['uri' => 'memory://linked', 'name' => 'linked', 'title' => 'Linked']]],
            $content,
        );
        self::assertSame([['type' => 'mcp_content', 'data' => $link]], $artifacts);
    }

    public function testExtraKeysOnABlockAreRetainedAsAnArtifactNotInTheContent(): void
    {
        $text = [...self::TEXT, '_meta' => ['private' => true]];

        [$content, $artifacts] = Content::convertCallToolResult('s', 't', ['content' => [$text]]);

        self::assertSame('hello', $content);
        self::assertSame([['type' => 'mcp_content', 'data' => $text]], $artifacts);
    }

    public function testStructuredContentAndMetaAreAppendedInThatOrder(): void
    {
        [, $artifacts] = Content::convertCallToolResult('s', 't', [
            'content' => [self::TEXT],
            'structuredContent' => ['n' => 1],
            '_meta' => ['k' => 'v'],
        ]);

        self::assertSame(
            [['type' => 'mcp_structured_content', 'data' => ['n' => 1]], ['type' => 'mcp_meta', 'data' => ['k' => 'v']]],
            $artifacts,
        );
    }

    public function testAnErrorResultThrowsWithTheServersTextAndTheWholeResult(): void
    {
        $result = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'first'], self::IMAGE, ['type' => 'text', 'text' => 'second']]];

        $error = self::thrownBy(static fn () => Content::convertCallToolResult('srv', 'tool', $result));

        self::assertInstanceOf(ToolException::class, $error);
        self::assertSame("MCP tool 'tool' on server 'srv' returned an error: first\n\nsecond", $error->getMessage());
        self::assertSame($result, $error->result);
    }

    public function testAnUnknownBlockTypeIsRefusedByName(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('returned unexpected content type "hologram"');

        Content::convertCallToolResult('s', 't', ['content' => [['type' => 'hologram']]]);
    }

    public function testResolvesPoliciesIntoPerTypeMaps(): void
    {
        self::assertSame([], Content::resolveDetailedOutputHandling(null));
        self::assertSame(
            ['text' => 'artifact', 'image' => 'artifact', 'audio' => 'artifact', 'resource_link' => 'artifact', 'resource' => 'artifact'],
            Content::resolveDetailedOutputHandling('artifact'),
        );
        self::assertSame(['text' => 'artifact'], Content::resolveDetailedOutputHandling(['text' => 'artifact']));
        self::assertSame(
            ['text' => 'content', 'image' => 'content', 'audio' => 'content', 'resource_link' => 'content', 'resource' => 'artifact'],
            Content::resolveDetailedOutputHandling([], true),
        );
    }

    public function testAServerLevelPolicyOverridesTheAdapterLevelOne(): void
    {
        self::assertSame(
            ['text' => 'content', 'image' => 'artifact', 'audio' => 'content', 'resource_link' => 'content', 'resource' => 'content'],
            Content::resolveAndApplyOverrideHandlingOverrides('content', ['image' => 'artifact']),
        );
    }
}
