<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\HumanMessage;
use LangGraph\Agents\Annotation;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\LastValue;
use LangGraph\Channels\UntrackedValue;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation as StateAnnotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/agents/tests/annotation.test.ts` (`createAgentState`).
 *
 * Upstream's Zod object cases become JSON Schema arrays and its `StateSchema`/`ReducedValue` cases become
 * `AnnotationRoot`s whose reducer channels play `ReducedValue`; both are what this port's schemas are
 * (see {@see Annotation}). The Zod v3 / v4 pair collapses to one JSON Schema case each.
 */
#[CoversClass(Annotation::class)]
final class AnnotationTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $properties */
    private static function jsonSchema(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties)];
    }

    private static function appendReducer(): BinaryOperatorAggregate
    {
        return StateAnnotation::withReducer(
            static fn (array $current, string $next): array => [...$current, $next],
            static fn (): array => [],
        );
    }

    // ---- basic fields -------------------------------------------------------------------------

    public function testShouldAlwaysIncludeMessagesAndJumpToInState(): void
    {
        ['state' => $state] = Annotation::createAgentState(false, null, []);

        self::assertContains('messages', $state->keys());
        self::assertContains('jumpTo', $state->keys());
    }

    public function testShouldIncludeStructuredResponseInOutputWhenHasStructuredResponseIsTrue(): void
    {
        ['output' => $output] = Annotation::createAgentState(true, null, []);

        self::assertContains('structuredResponse', $output->keys());
    }

    public function testShouldNotIncludeStructuredResponseInOutputWhenHasStructuredResponseIsFalse(): void
    {
        ['output' => $output] = Annotation::createAgentState(false, null, []);

        self::assertNotContains('structuredResponse', $output->keys());
    }

    public function testJumpToIsAnUntrackedChannelAndInternalOnly(): void
    {
        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(true, null, []);

        self::assertInstanceOf(UntrackedValue::class, $state->spec['jumpTo']);
        self::assertNotContains('jumpTo', $input->keys());
        self::assertNotContains('jumpTo', $output->keys());
        self::assertInstanceOf(UntrackedValue::class, $output->spec['structuredResponse']);
    }

    public function testMessagesAreMergedByTheMessagesReducer(): void
    {
        ['state' => $state] = Annotation::createAgentState(false, null, []);

        $messages = $state->spec['messages'];
        self::assertInstanceOf(BinaryOperatorAggregate::class, $messages);
        $messages->update([[new HumanMessage(['content' => 'a', 'id' => '1'])]]);
        $messages->update([[new HumanMessage(['content' => 'b', 'id' => '1']), new HumanMessage(['content' => 'c', 'id' => '2'])]]);

        self::assertSame(['b', 'c'], array_map(static fn ($m) => $m->content, $messages->get()));
    }

    // ---- user-provided schemas ----------------------------------------------------------------

    public function testShouldAddPublicFieldsOfAJsonSchemaToStateInputAndOutput(): void
    {
        $schema = self::jsonSchema(['userId' => ['type' => 'string'], 'count' => ['type' => 'number']]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        foreach ([$state, $input, $output] as $root) {
            self::assertContains('userId', $root->keys());
            self::assertContains('count', $root->keys());
        }
    }

    public function testShouldAddPublicFieldsOfAnAnnotationRootToStateInputAndOutput(): void
    {
        $schema = StateAnnotation::root(['userId' => StateAnnotation::last(), 'count' => StateAnnotation::last()]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        foreach ([$state, $input, $output] as $root) {
            self::assertContains('userId', $root->keys());
            self::assertContains('count', $root->keys());
        }
    }

    public function testShouldKeepReducerChannelsInStateAndExposeThemInInputAndOutput(): void
    {
        $schema = StateAnnotation::root(['history' => self::appendReducer()]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        self::assertContains('history', $state->keys());
        self::assertContains('history', $input->keys());
        self::assertContains('history', $output->keys());
        self::assertInstanceOf(BinaryOperatorAggregate::class, $state->spec['history']);
    }

    // ---- underscore-prefixed private state ----------------------------------------------------

    private function assertPrivateOnlyInState(AnnotationRoot $state, AnnotationRoot $input, AnnotationRoot $output, string ...$private): void
    {
        foreach ($private as $key) {
            self::assertContains($key, $state->keys(), "$key must persist in graph state");
            self::assertNotContains($key, $input->keys());
            self::assertNotContains($key, $output->keys());
        }
    }

    private function assertPublicEverywhere(AnnotationRoot $state, AnnotationRoot $input, AnnotationRoot $output, string ...$public): void
    {
        foreach ($public as $key) {
            self::assertContains($key, $state->keys());
            self::assertContains($key, $input->keys());
            self::assertContains($key, $output->keys());
        }
    }

    public function testShouldIncludeJsonSchemaPrefixedFieldsInStateButNotInInputOutput(): void
    {
        $schema = self::jsonSchema([
            '_privateEvent' => ['type' => 'string'],
            '_privateSessionId' => ['type' => 'string'],
            'publicField' => ['type' => 'string'],
        ]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        $this->assertPrivateOnlyInState($state, $input, $output, '_privateEvent', '_privateSessionId');
        $this->assertPublicEverywhere($state, $input, $output, 'publicField');
    }

    public function testShouldIncludeAnnotationRootPrefixedFieldsInStateButNotInInputOutput(): void
    {
        $schema = StateAnnotation::root([
            '_privateEvent' => StateAnnotation::last(),
            '_privateSessionId' => StateAnnotation::last(),
            'publicField' => StateAnnotation::last(),
        ]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        $this->assertPrivateOnlyInState($state, $input, $output, '_privateEvent', '_privateSessionId');
        $this->assertPublicEverywhere($state, $input, $output, 'publicField');
    }

    public function testShouldIncludePrefixedReducerFieldsInStateButNotInInputOutput(): void
    {
        $schema = StateAnnotation::root([
            '_privateAccum' => self::appendReducer(),
            'publicHistory' => self::appendReducer(),
        ]);

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $schema, []);

        self::assertInstanceOf(BinaryOperatorAggregate::class, $state->spec['_privateAccum']);
        $this->assertPrivateOnlyInState($state, $input, $output, '_privateAccum');
        $this->assertPublicEverywhere($state, $input, $output, 'publicHistory');
    }

    // ---- middleware state schemas -------------------------------------------------------------

    public function testShouldIncludeMiddlewareJsonSchemaPrefixedFieldsInStateButNotInInputOutput(): void
    {
        $middleware = [[
            'name' => 'SummarizationMiddleware',
            'stateSchema' => self::jsonSchema([
                '_summarizationEvent' => [],
                '_summarizationSessionId' => ['type' => 'string'],
            ]),
            'wrapModelCall' => static fn (mixed $req, callable $handler): mixed => $handler($req),
        ]];

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, null, $middleware);

        $this->assertPrivateOnlyInState($state, $input, $output, '_summarizationEvent', '_summarizationSessionId');
    }

    public function testShouldIncludeMiddlewareAnnotationRootPrefixedFieldsInStateButNotInInputOutput(): void
    {
        $middleware = [[
            'name' => 'TestMiddleware',
            'stateSchema' => StateAnnotation::root([
                '_internalCounter' => StateAnnotation::last(),
                'visibleStatus' => StateAnnotation::last(),
            ]),
            'beforeModel' => static fn (): array => [],
        ]];

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, null, $middleware);

        $this->assertPrivateOnlyInState($state, $input, $output, '_internalCounter');
        $this->assertPublicEverywhere($state, $input, $output, 'visibleStatus');
    }

    public function testShouldCombineUserSchemaAndMiddlewarePrivateFieldsCorrectly(): void
    {
        $user = self::jsonSchema(['userId' => ['type' => 'string']]);
        $middleware = [[
            'name' => 'TestMiddleware',
            'stateSchema' => self::jsonSchema(['_middlewarePrivate' => []]),
            'wrapModelCall' => static fn (mixed $req, callable $handler): mixed => $handler($req),
        ]];

        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(false, $user, $middleware);

        $this->assertPublicEverywhere($state, $input, $output, 'userId');
        $this->assertPrivateOnlyInState($state, $input, $output, '_middlewarePrivate');
    }

    public function testMiddlewareGivenAsObjectsAndWithoutSchemasIsAccepted(): void
    {
        $withSchema = new class () {
            public string $name = 'object-middleware';

            /** @var array<string, mixed> */
            public array $stateSchema = ['type' => 'object', 'properties' => ['fromObject' => ['type' => 'string']]];
        };
        $withoutSchema = ['name' => 'plain'];

        ['state' => $state] = Annotation::createAgentState(false, null, [$withSchema, $withoutSchema]);

        self::assertContains('fromObject', $state->keys());
    }

    // ---- deduplication ------------------------------------------------------------------------

    public function testShouldNotOverwriteExistingStateFieldsWithTheSameKey(): void
    {
        $user = StateAnnotation::root(['sharedKey' => self::appendReducer()]);
        $middleware = [[
            'name' => 'TestMiddleware',
            'stateSchema' => self::jsonSchema(['sharedKey' => ['type' => 'number']]),
            'wrapModelCall' => static fn (mixed $req, callable $handler): mixed => $handler($req),
        ]];

        ['state' => $state] = Annotation::createAgentState(false, $user, $middleware);

        self::assertContains('sharedKey', $state->keys());
        // The user's schema wins (it is applied first).
        self::assertInstanceOf(BinaryOperatorAggregate::class, $state->spec['sharedKey']);
    }

    public function testJsonSchemaPropertiesBecomeLastValueChannels(): void
    {
        ['state' => $state] = Annotation::createAgentState(false, self::jsonSchema(['publicState' => ['type' => 'string']]), []);

        self::assertInstanceOf(LastValue::class, $state->spec['publicState']);
    }

    // ---- the schemas run a real graph ----------------------------------------------------------

    public function testTheStateSchemaDrivesARealGraph(): void
    {
        // `StateGraph` has no separate input/output schemas yet (WP-05), so only `state` is wired in.
        $user = StateAnnotation::root(['history' => self::appendReducer(), '_scratch' => StateAnnotation::last()]);
        ['state' => $state] = Annotation::createAgentState(false, $user, []);

        $graph = (new StateGraph($state))
            ->addNode('work', static fn (array $s): array => [
                'history' => 'seen',
                '_scratch' => 'private',
                'messages' => [new HumanMessage(['content' => 'from node', 'id' => 'n1'])],
            ])
            ->addEdge(Constants::START, 'work')
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage(['content' => 'hi', 'id' => 'u1'])], 'history' => 'start']);

        self::assertSame(['start', 'seen'], $result['history']);
        self::assertSame(['hi', 'from node'], array_map(static fn ($m) => $m->content, $result['messages']));
        self::assertSame('private', $result['_scratch']);
    }
}
