<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangGraph\Channels\AnyValue;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Messages\StreamMessagesHandler;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelLoop;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `streamMode: 'messages'` end to end: real graphs, a real (fake-transport) chat
 * model, the real callback manager. The handler unit tests prove each hook; this
 * proves the wiring between `Pregel::stream()`, the loop and the handlers.
 *
 * The chunk contract (HANDOFF section 3) is `[mode, payload]`, so a messages
 * chunk is `['messages', [message, metadata]]` - the pair upstream calls a
 * messages-tuple, inside the envelope rather than flattened into it.
 */
#[CoversClass(Pregel::class)]
#[CoversClass(PregelLoop::class)]
#[CoversClass(StreamMessagesHandler::class)]
final class PregelStreamMessagesTest extends TestCase
{
    /** @param list<string> $pieces */
    private static function model(array $pieces): FakeStreamingChatModel
    {
        return new FakeStreamingChatModel([
            'chunks' => array_map(static fn (string $p): AIMessageChunk => new AIMessageChunk(['content' => $p]), $pieces),
        ]);
    }

    /**
     * A one-node graph whose node calls $model with the node's own config - the
     * config is what carries the callbacks and the node's metadata to the model.
     *
     * @param array<string, mixed> $nodeOptions
     * @param list<string>|string  $streamMode
     */
    private static function agentGraph(FakeStreamingChatModel $model, array|string $streamMode, array $nodeOptions = [], ?\Closure $configure = null): Pregel
    {
        return (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('agent', static function (array $state, RunnableConfig $config) use ($model, $configure): array {
                $config = $configure !== null ? $configure($config) : $config;

                return ['messages' => [$model->invoke([new HumanMessage('hi')], $config)]];
            }, $nodeOptions)
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile(['streamMode' => (array) $streamMode]);
    }

    /**
     * @return list<array{0: string, 1: mixed}>
     */
    private static function streamAll(Pregel $graph, ?RunnableConfig $config = null): array
    {
        return iterator_to_array($graph->stream(['messages' => []], $config), false);
    }

    /**
     * @param list<array{0: string, 1: mixed}> $chunks
     * @return list<array{0: BaseMessage, 1: array<string, mixed>}>
     */
    private static function messageTuples(array $chunks): array
    {
        return array_values(array_map(
            static fn (array $c): array => $c[1],
            array_filter($chunks, static fn (array $c): bool => $c[0] === 'messages'),
        ));
    }

    public function testModelTokensStreamAsMessageMetadataTuplesInsideTheEnvelope(): void
    {
        $chunks = self::streamAll(self::agentGraph(self::model(['Hel', 'lo', '!']), 'messages'));

        self::assertCount(3, $chunks);
        foreach ($chunks as $chunk) {
            self::assertSame('messages', $chunk[0]);
            self::assertCount(2, $chunk[1], 'a messages-tuple is [message, metadata]');
            self::assertInstanceOf(AIMessageChunk::class, $chunk[1][0]);
            self::assertIsArray($chunk[1][1]);
        }

        self::assertSame(['Hel', 'lo', '!'], array_map(static fn (array $c): mixed => $c[1][0]->content, $chunks));
    }

    public function testTokenMetadataNamesTheNodeAndItsNamespace(): void
    {
        $tuples = self::messageTuples(self::streamAll(self::agentGraph(self::model(['Hi']), 'messages')));

        $metadata = $tuples[0][1];
        self::assertSame('agent', $metadata['langgraph_node']);
        self::assertStringStartsWith('agent:', $metadata['langgraph_checkpoint_ns']);
        self::assertArrayHasKey('tags', $metadata);
        self::assertArrayHasKey('name', $metadata);
    }

    public function testTheChunksOfOneModelRunShareAStableMessageId(): void
    {
        $tuples = self::messageTuples(self::streamAll(self::agentGraph(self::model(['a', 'b', 'c']), 'messages')));

        $ids = array_unique(array_map(static fn (array $t): ?string => $t[0]->id, $tuples));

        self::assertCount(1, $ids);
        self::assertStringStartsWith('run-', (string) $ids[0]);
    }

    public function testTheModelsFinalMessageIsNotEmittedASecondTime(): void
    {
        // The node returns the model's complete message; it was already streamed
        // token by token, so the chain-end event must be deduplicated away.
        $tuples = self::messageTuples(self::streamAll(self::agentGraph(self::model(['x', 'y']), 'messages')));

        self::assertCount(2, $tuples);
    }

    public function testAMessageANodeReturnsWithoutAModelIsStreamedFromItsOutput(): void
    {
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('echo', static fn (array $s): array => ['messages' => [new AIMessage(['content' => 'from node', 'id' => 'm-1'])]])
            ->addEdge(Constants::START, 'echo')
            ->addEdge('echo', Constants::END)
            ->compile(['streamMode' => ['messages']]);

        $tuples = self::messageTuples(self::streamAll($graph));

        self::assertCount(1, $tuples);
        self::assertSame('from node', $tuples[0][0]->content);
        self::assertSame('m-1', $tuples[0][0]->id);
        self::assertSame('echo', $tuples[0][1]['langgraph_node']);
    }

    public function testAMessageThatWasInTheNodesInputIsNotReEmitted(): void
    {
        $existing = new HumanMessage(['content' => 'already here', 'id' => 'h-1']);
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('passthrough', static fn (array $s): array => ['messages' => $s['messages']])
            ->addEdge(Constants::START, 'passthrough')
            ->addEdge('passthrough', Constants::END)
            ->compile(['streamMode' => ['messages']]);

        $tuples = self::messageTuples(iterator_to_array($graph->stream(['messages' => [$existing]]), false));

        self::assertSame([], $tuples);
    }

    public function testAModelTaggedNoStreamIsNotStreamed(): void
    {
        $graph = self::agentGraph(
            self::model(['qu', 'iet']),
            'messages',
            configure: static fn (RunnableConfig $c): RunnableConfig => $c->with(['tags' => [...$c->tags, Constants::TAG_NOSTREAM]]),
        );

        // The node's own output is still a message, so it IS emitted - once, whole,
        // from the chain-end event. Only the token-level stream is silenced.
        $tuples = self::messageTuples(self::streamAll($graph));

        self::assertCount(1, $tuples, 'two tokens would have streamed without the tag');
        self::assertSame('quiet', $tuples[0][0]->content);
    }

    public function testANodeTaggedHiddenStreamsNothing(): void
    {
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode(
                'secret',
                static fn (array $s): array => ['messages' => [new AIMessage(['content' => 'shh', 'id' => 's-1'])]],
                ['tags' => [Constants::TAG_HIDDEN]],
            )
            ->addEdge(Constants::START, 'secret')
            ->addEdge('secret', Constants::END)
            ->compile(['streamMode' => ['messages']]);

        self::assertSame([], self::messageTuples(self::streamAll($graph)));
    }

    public function testMessagesAreOnlyEmittedWhenTheModeIsRequested(): void
    {
        $chunks = self::streamAll(self::agentGraph(self::model(['Hi']), 'updates'));

        self::assertNotContains('messages', array_column($chunks, 0));
        self::assertContains('updates', array_column($chunks, 0));
    }

    public function testMessagesInterleaveWithOtherModesInOneStream(): void
    {
        $chunks = self::streamAll(self::agentGraph(self::model(['A', 'B']), ['messages', 'updates']));

        $modes = array_column($chunks, 0);
        self::assertSame(2, count(array_keys($modes, 'messages', true)));
        self::assertSame(1, count(array_keys($modes, 'updates', true)));
    }

    public function testInvokeStillReturnsTheFinalStateWhenMessagesIsRequested(): void
    {
        $result = self::agentGraph(self::model(['ok']), 'messages')->invoke(['messages' => []]);

        self::assertCount(1, $result['messages']);
        self::assertSame('ok', $result['messages'][0]->content);
    }

    public function testACallersOwnHandlerKeepsReceivingEventsAndTheirConfigIsNotMutated(): void
    {
        $seen = [];
        $mine = new class ($seen) extends BaseCallbackHandler {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
                parent::__construct();
            }

            public function handleChatModelStart(
                Serialized $llm,
                array $messages,
                string $runId,
                ?string $parentRunId = null,
                array $extraParams = [],
                array $tags = [],
                array $metadata = [],
                ?string $runName = null,
            ): void {
                $this->seen[] = 'chat-model-start';
            }
        };
        $config = new RunnableConfig(callbacks: [$mine]);

        $chunks = self::streamAll(self::agentGraph(self::model(['Hi']), 'messages'), $config);

        self::assertNotSame([], self::messageTuples($chunks));
        self::assertSame(['chat-model-start'], $seen);
        self::assertSame([$mine], $config->callbacks, 'the caller config must not gain the internal handler');
    }

    public function testTheProtocolHandlerIsSelectedByVersionV3(): void
    {
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('echo', static fn (array $s): array => ['messages' => [new AIMessage(['content' => 'Done', 'id' => 'msg-1'])]])
            ->addEdge(Constants::START, 'echo')
            ->addEdge('echo', Constants::END)
            ->compile(['streamMode' => ['messages']]);

        $chunks = iterator_to_array(
            $graph->stream(['messages' => []], new RunnableConfig(options: ['version' => 'v3'])),
            false,
        );

        $events = array_map(static fn (array $c): string => $c[1][0]['event'], $chunks);
        self::assertSame(
            ['message-start', 'content-block-start', 'content-block-delta', 'content-block-finish', 'message-finish'],
            $events,
        );
        self::assertSame('echo', $chunks[0][1][1]['langgraph_node']);
    }

    public function testAFailingNodeLeavesNoHalfStreamedStateBehind(): void
    {
        $graph = (new StateGraph(['messages' => new AnyValue()]))
            ->addNode('boom', static function (array $s): array {
                throw new \RuntimeException('node failed');
            })
            ->addEdge(Constants::START, 'boom')
            ->addEdge('boom', Constants::END)
            ->compile(['streamMode' => ['messages']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('node failed');

        self::streamAll($graph);
    }

    public function testMessagesAndToolsAreAcceptedButTasksStillRefused(): void
    {
        self::assertSame(['messages', 'tools'], Pregel::HANDLER_STREAM_MODES);
        self::assertNotContains('messages', Pregel::SUPPORTED_STREAM_MODES, 'the loop-emitted set is pinned elsewhere');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported stream mode.*tasks/');

        self::streamAll(self::agentGraph(self::model(['x']), ['messages', 'tasks']));
    }
}
