<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\Runnables\Graph\Edge;
use LangChain\Runnables\Graph\Mermaid;
use LangChain\Runnables\Graph\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain-core/src/runnables/tests/graph_mermaid.test.ts`.
 *
 * Upstream mocks the global `fetch`; here the `$fetch` callable of `drawMermaidImage` is the seam, and
 * a "Blob" is the response body string. The `drawMermaidImage` cases assert the URL requested.
 */
#[CoversClass(Mermaid::class)]
final class GraphMermaidTest extends TestCase
{
    /**
     * A fetch double: records every URL and answers with the scripted response, or throws.
     *
     * @param array{ok: bool, status?: int, statusText?: string, body?: string}|\Throwable $response
     * @param list<string>                                                                  $calls
     */
    private static function fetchReturning(array|\Throwable $response, array &$calls): callable
    {
        return static function (string $url) use ($response, &$calls): array {
            $calls[] = $url;
            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response + ['status' => 200, 'statusText' => 'OK', 'body' => ''];
        };
    }

    private static function okImage(string $body = 'mock image data'): array
    {
        return ['ok' => true, 'body' => $body];
    }

    private static function url(string $syntax, string $bgColor, string $type): string
    {
        return 'https://mermaid.ink/img/' . Mermaid::toBase64Url($syntax) . '?bgColor=' . $bgColor . '&type=' . $type;
    }

    public function testShouldRenderABasicMermaidGraphAsPng(): void
    {
        $calls = [];
        $syntax = 'graph TD; A --> B;';

        $result = Mermaid::drawMermaidImage($syntax, [], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!white', 'png')], $calls);
    }

    public function testShouldHandleDifferentImageTypes(): void
    {
        $calls = [];
        $syntax = 'graph LR; Start --> End;';

        $result = Mermaid::drawMermaidImage($syntax, ['imageType' => 'jpeg'], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!white', 'jpeg')], $calls);
    }

    public function testShouldHandleWebpImageType(): void
    {
        $calls = [];
        $syntax = 'graph TB; X --> Y;';

        $result = Mermaid::drawMermaidImage($syntax, ['imageType' => 'webp'], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!white', 'webp')], $calls);
    }

    public function testShouldHandleHexColorBackgrounds(): void
    {
        $calls = [];
        $syntax = 'flowchart TD; A --> B;';

        $result = Mermaid::drawMermaidImage($syntax, ['backgroundColor' => '#FF5733'], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '#FF5733', 'png')], $calls);
    }

    public function testShouldHandleShortHexColorBackgrounds(): void
    {
        $calls = [];
        $syntax = 'flowchart TD; A --> B;';

        $result = Mermaid::drawMermaidImage($syntax, ['backgroundColor' => '#FFF'], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '#FFF', 'png')], $calls);
    }

    public function testShouldHandleNamedColorBackgrounds(): void
    {
        $calls = [];
        $syntax = 'classDiagram; class A; class B;';

        $result = Mermaid::drawMermaidImage($syntax, ['backgroundColor' => 'transparent'], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!transparent', 'png')], $calls);
    }

    public function testShouldThrowErrorWhenApiReturnsNonOkResponse(): void
    {
        $calls = [];

        try {
            Mermaid::drawMermaidImage(
                'invalid syntax',
                [],
                self::fetchReturning(['ok' => false, 'status' => 400, 'statusText' => 'Bad Request'], $calls),
            );
            $this->fail('expected the Mermaid.INK failure to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame(
                "Failed to render the graph using the Mermaid.INK API.\nStatus code: 400\nStatus text: Bad Request",
                $e->getMessage(),
            );
        }

        $this->assertCount(1, $calls);
    }

    public function testShouldHandleNetworkErrors(): void
    {
        $calls = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Network error');

        try {
            Mermaid::drawMermaidImage('graph TD; A --> B;', [], self::fetchReturning(new \RuntimeException('Network error'), $calls));
        } finally {
            $this->assertCount(1, $calls);
        }
    }

    public function testShouldProperlyEncodeComplexMermaidSyntax(): void
    {
        $calls = [];
        $syntax = "graph TD;\n    A[Christmas] -->|Get money| B(Go shopping)\n    B --> C{Let me think}\n"
            . "    C -->|One| D[Laptop]\n    C -->|Two| E[iPhone]\n    C -->|Three| F[fa:fa-car Car]";

        $result = Mermaid::drawMermaidImage($syntax, [], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!white', 'png')], $calls);
    }

    public function testShouldHandleUndefinedBackgroundColor(): void
    {
        $calls = [];
        $syntax = 'graph TD; A --> B;';

        $result = Mermaid::drawMermaidImage($syntax, ['backgroundColor' => null], self::fetchReturning(self::okImage(), $calls));

        $this->assertSame('mock image data', $result);
        $this->assertSame([self::url($syntax, '!white', 'png')], $calls);
    }

    public function testShouldHandleServerErrorWithDifferentStatusCodes(): void
    {
        $calls = [];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            "Failed to render the graph using the Mermaid.INK API.\nStatus code: 500\nStatus text: Internal Server Error"
        );

        Mermaid::drawMermaidImage(
            'graph TD; A --> B;',
            [],
            self::fetchReturning(['ok' => false, 'status' => 500, 'statusText' => 'Internal Server Error'], $calls),
        );
    }

    public function testNestsDeepSubgraphsCorrectly(): void
    {
        $data = new NamedRunnable('');
        $make = static fn (string $id, string $name, ?array $metadata = []): Node => new Node($id, $data, $name, $metadata);

        $nodes = [];
        foreach ([
            $make('__start__', '__start__', null),
            $make('collectTools', 'collectTools'),
            $make('fooTool:userVerification', 'userVerification'),
            $make('fooTool:fooSearchSource:explainReasoning', 'explainReasoning'),
            $make('fooTool:fooSearchSource:fooGenericSearch:promptForTools', 'promptForTools'),
            $make('fooTool:fooSearchSource:fooGenericSearch:searchToolWithQuery', 'searchToolWithQuery'),
            $make('fooTool:fooSearchSource:emitfooToolResults', 'emitfooToolResults'),
            $make('fooTool:reportToolResults', 'reportToolResults'),
            $make('promptForAnswer', 'promptForAnswer'),
            $make('__end__', '__end__', null),
        ] as $node) {
            $nodes[$node->id] = $node;
        }

        $edges = [
            new Edge('fooTool:fooSearchSource:fooGenericSearch:promptForTools', 'fooTool:fooSearchSource:fooGenericSearch:searchToolWithQuery', null, false),
            new Edge('fooTool:fooSearchSource:fooGenericSearch:searchToolWithQuery', 'fooTool:fooSearchSource:emitfooToolResults', null, false),
            new Edge('fooTool:fooSearchSource:explainReasoning', 'fooTool:fooSearchSource:fooGenericSearch:promptForTools', null, false),
            new Edge('fooTool:fooSearchSource:emitfooToolResults', 'fooTool:reportToolResults', null, false),
            new Edge('fooTool:userVerification', 'fooTool:fooSearchSource:explainReasoning', null, true),
            new Edge('__start__', 'collectTools', null, false),
            new Edge('fooTool:reportToolResults', 'promptForAnswer', null, false),
            new Edge('collectTools', 'fooTool:userVerification', null, true),
            new Edge('promptForAnswer', '__end__', null, true),
        ];

        $result = Mermaid::drawMermaid($nodes, $edges);

        $this->assertStringContainsString('subgraph fooTool', $result);
        $this->assertStringContainsString('subgraph fooSearchSource', $result);
        $this->assertStringContainsString('subgraph fooGenericSearch', $result);

        // verify proper nested order of subgraphs
        $nestedPattern = implode('[\s\S]*?', [
            'subgraph fooTool',
            'subgraph fooSearchSource',
            'subgraph fooGenericSearch',
            // ... inside fooGenericSearch
            'end',
            // ... inside fooSearchSource
            'end',
            // ... inside fooTool
            'end',
        ]);
        $this->assertMatchesRegularExpression('/' . $nestedPattern . '/', $result);
    }
}
