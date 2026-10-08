<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables\Graph;

use LangChain\OutputParsers\CommaSeparatedListOutputParser;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\Runnable;
use LangChain\Utils\Testing\FakeLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain-core/src/runnables/tests/runnable_graph.test.ts`.
 *
 * NON-EXACT: the `lc_id` of the runnable nodes in `toJSON()` is each class's own `lcId()` in this
 * port (`FakeLLM` is `langchain/llms/FakeLLM` rather than `langchain/llms/fake/FakeLLM`, the list
 * parser has no trailing class name). The graph shape, ids, names and the Mermaid text are upstream's.
 */
#[CoversClass(Runnable::class)]
#[CoversClass(Graph::class)]
final class RunnableGraphTest extends TestCase
{
    public function testGraphSingleRunnable(): void
    {
        $graph = (new StringOutputParser())->getGraph();

        $this->assertNotNull($graph->firstNode());
        $this->assertNotNull($graph->lastNode());
        $this->assertCount(2, $graph->edges);
        $this->assertCount(3, $graph->nodes);
    }

    public function testGraphSequence(): void
    {
        $llm = new FakeLLM([]);
        $prompt = PromptTemplate::fromTemplate('Hello, {name}!');
        $listParser = new CommaSeparatedListOutputParser();

        $sequence = $prompt->pipe($llm)->pipe($listParser);
        $graph = $sequence->getGraph();

        $this->assertNotNull($graph->firstNode());
        $this->assertNotNull($graph->lastNode());

        $this->assertCount(4, $graph->edges);
        $this->assertCount(5, $graph->nodes);

        $schema = '$schema';
        $draft = 'http://json-schema.org/draft-07/schema#';
        $this->assertSame([
            'nodes' => [
                ['id' => 0, 'type' => 'schema', 'data' => [$schema => $draft, 'title' => 'PromptTemplateInput']],
                [
                    'id' => 1,
                    'type' => 'runnable',
                    'data' => ['id' => ['langchain_core', 'prompts', 'prompt', 'PromptTemplate'], 'name' => 'PromptTemplate'],
                ],
                [
                    'id' => 2,
                    'type' => 'runnable',
                    'data' => ['id' => ['langchain', 'llms', 'FakeLLM'], 'name' => 'FakeLLM'],
                ],
                [
                    'id' => 3,
                    'type' => 'runnable',
                    'data' => ['id' => ['langchain_core', 'output_parsers', 'list'], 'name' => 'CommaSeparatedListOutputParser'],
                ],
                ['id' => 4, 'type' => 'schema', 'data' => [$schema => $draft, 'title' => 'CommaSeparatedListOutputParserOutput']],
            ],
            'edges' => [
                ['source' => 0, 'target' => 1],
                ['source' => 1, 'target' => 2],
                ['source' => 3, 'target' => 4],
                ['source' => 2, 'target' => 3],
            ],
        ], $graph->toJSON());

        $this->assertSame(
            "%%{init: {'flowchart': {'curve': 'linear'}}}%%\n"
            . "graph TD;\n"
            . "\tPromptTemplateInput([PromptTemplateInput]):::first\n"
            . "\tPromptTemplate(PromptTemplate)\n"
            . "\tFakeLLM(FakeLLM)\n"
            . "\tCommaSeparatedListOutputParser(CommaSeparatedListOutputParser)\n"
            . "\tCommaSeparatedListOutputParserOutput([CommaSeparatedListOutputParserOutput]):::last\n"
            . "\tPromptTemplateInput --> PromptTemplate;\n"
            . "\tPromptTemplate --> FakeLLM;\n"
            . "\tCommaSeparatedListOutputParser --> CommaSeparatedListOutputParserOutput;\n"
            . "\tFakeLLM --> CommaSeparatedListOutputParser;\n"
            . "\tclassDef default fill:#f2f0ff,line-height:1.2;\n"
            . "\tclassDef first fill-opacity:0;\n"
            . "\tclassDef last fill:#bfb6fc;\n",
            $graph->drawMermaid(),
        );
    }
}
