<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Channels\LastValue;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\GraphValidationError;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\Validate;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `pregel/validate.test.ts` and `tests/pregel.validate.test.ts`.
 *
 * Differences from upstream: the TS `ts-expect-error` / `as unknown as` casts have no PHP
 * counterpart (PHP types are runtime-checked), so the "invalid input" cases pass the bad
 * value directly. The "wrong node type" case uses a subclass of `PregelNode` (upstream's
 * `pregel.validate.test.ts` form, which is the exact-class check) and a bare object (the
 * `validate.test.ts` form).
 */
#[CoversClass(Validate::class)]
#[CoversClass(GraphValidationError::class)]
final class ValidateTest extends TestCase
{
    /**
     * @return array{nodes: array<string, PregelNode>, channels: array<string, LastValue>}
     */
    private static function validGraph(): array
    {
        return [
            'nodes' => ['testNode' => new PregelNode(channels: [], triggers: ['input'])],
            'channels' => ['input' => new LastValue(), 'output' => new LastValue()],
        ];
    }

    private static function assertInvalid(string $message, callable $call): void
    {
        try {
            $call();
        } catch (GraphValidationError $e) {
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('expected GraphValidationError: ' . $message);
    }

    // ---- validate.test.ts: GraphValidationError ----------------------------

    public function testGraphValidationErrorCarriesItsMessage(): void
    {
        $error = new GraphValidationError('Test error message');

        self::assertInstanceOf(\Exception::class, $error);
        self::assertSame('Test error message', $error->getMessage());
    }

    // ---- validate.test.ts: validateGraph -----------------------------------

    public function testAValidGraphPasses(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        Validate::validateGraph($nodes, $channels, 'input', 'output');

        $this->addToAssertionCount(1);
    }

    public function testMissingChannelsAreRejected(): void
    {
        ['nodes' => $nodes] = self::validGraph();

        self::assertInvalid('Channels not provided', static fn () => Validate::validateGraph($nodes, null, 'input', 'output'));
    }

    public function testANodeNamedInterruptIsRejected(): void
    {
        ['channels' => $channels] = self::validGraph();
        $nodes = [Constants::INTERRUPT => new PregelNode(channels: [], triggers: ['input'])];

        self::assertInvalid(
            '"Node name ' . Constants::INTERRUPT . ' is reserved"',
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output'),
        );
    }

    public function testANodeThatIsNotAPregelNodeIsRejected(): void
    {
        ['channels' => $channels] = self::validGraph();
        $nodes = ['badNode' => (object) ['triggers' => ['input']]];

        self::assertInvalid(
            'Invalid node type object, expected PregelNode',
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output'),
        );
    }

    public function testASubclassOfPregelNodeIsRejectedBecauseUpstreamChecksTheExactClass(): void
    {
        $subclass = new class (channels: [''], triggers: []) extends PregelNode {
        };

        $this->expectException(GraphValidationError::class);
        Validate::validateGraph(['channelName' => $subclass], ['' => new LastValue()], '', '', '', [], []);
    }

    public function testASubscribedChannelMissingFromChannelsIsRejected(): void
    {
        ['channels' => $channels] = self::validGraph();
        $nodes = ['badNode' => new PregelNode(channels: [], triggers: ['nonexistent'])];

        self::assertInvalid(
            "Subscribed channel 'nonexistent' not in channels",
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output'),
        );
    }

    public function testASingularInputChannelNobodySubscribesToIsRejected(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            'Input channel output is not subscribed to by any node',
            static fn () => Validate::validateGraph($nodes, $channels, 'output', 'output'),
        );
    }

    public function testAnInputListWithNoSubscribedChannelIsRejected(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            'None of the input channels output,nonexistent are subscribed to by any node',
            static fn () => Validate::validateGraph($nodes, $channels, ['output', 'nonexistent'], 'output'),
        );
    }

    public function testAnInputListWithOneSubscribedChannelPasses(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        Validate::validateGraph($nodes, $channels, ['output', 'input'], 'output');

        $this->addToAssertionCount(1);
    }

    public function testAnOutputChannelMissingFromChannelsIsRejected(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            "Output channel 'nonexistent' not in channels",
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'nonexistent'),
        );
    }

    public function testAStreamChannelMissingFromChannelsIsReportedAsAnOutputChannel(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            "Output channel 'nonexistent' not in channels",
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output', 'nonexistent'),
        );
        self::assertInvalid(
            "Output channel 'nope' not in channels",
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output', ['output', 'nope']),
        );
    }

    public function testAnInterruptAfterNodeMissingFromNodesIsRejected(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            'Node nonexistentNode not in nodes',
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output', null, ['nonexistentNode']),
        );
    }

    public function testAnInterruptBeforeNodeMissingFromNodesIsRejected(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        self::assertInvalid(
            'Node nonexistentNode not in nodes',
            static fn () => Validate::validateGraph($nodes, $channels, 'input', 'output', null, null, ['nonexistentNode']),
        );
    }

    public function testStarIsAcceptedForBothInterruptLists(): void
    {
        ['nodes' => $nodes, 'channels' => $channels] = self::validGraph();

        Validate::validateGraph($nodes, $channels, 'input', 'output', null, '*');
        Validate::validateGraph($nodes, $channels, 'input', 'output', null, null, '*');

        $this->addToAssertionCount(2);
    }

    // ---- validate.test.ts: validateKeys ------------------------------------

    public function testValidateKeysAcceptsKeysThatExist(): void
    {
        ['channels' => $channels] = self::validGraph();

        Validate::validateKeys('input', $channels);
        Validate::validateKeys(['input', 'output'], $channels);

        $this->addToAssertionCount(2);
    }

    public function testValidateKeysRejectsAMissingSingleKey(): void
    {
        ['channels' => $channels] = self::validGraph();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key nonexistent not found in channels');
        Validate::validateKeys('nonexistent', $channels);
    }

    public function testValidateKeysRejectsAnyMissingKeyInAList(): void
    {
        ['channels' => $channels] = self::validGraph();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Key nonexistent not found in channels');
        Validate::validateKeys(['input', 'nonexistent'], $channels);
    }

    // ---- tests/pregel.validate.test.ts -------------------------------------

    public function testANodeNamedInterruptThrowsGraphValidationError(): void
    {
        $this->expectException(GraphValidationError::class);
        Validate::validateGraph(
            [Constants::INTERRUPT => new PregelNode(channels: [''], triggers: [])],
            ['' => new LastValue()],
            '',
            '',
            '',
            [],
            [],
        );
    }

    public function testAnUnsubscribedSingularInputThrows(): void
    {
        $nodes = ['channelName1' => new PregelNode(channels: [''], triggers: ['channelName2'])];

        $this->expectException(GraphValidationError::class);
        Validate::validateGraph($nodes, ['' => new LastValue(), 'channelName2' => new LastValue()], 'channelName3', '', '', [], []);
    }

    public function testAnUnsubscribedInputListThrows(): void
    {
        $nodes = ['channelName1' => new PregelNode(channels: [''], triggers: ['channelName2'])];

        $this->expectException(GraphValidationError::class);
        Validate::validateGraph($nodes, ['' => new LastValue(), 'channelName2' => new LastValue()], ['channelName3', 'channelName4', 'channelName5'], '', '', [], []);
    }

    public function testInterruptListsNamingUnknownNodesThrow(): void
    {
        $nodes = ['channelName1' => new PregelNode(channels: [''], triggers: ['channelName2'])];
        $channels = ['' => new LastValue(), 'channelName2' => new LastValue()];

        try {
            Validate::validateGraph($nodes, $channels, 'channelName2', '', '', ['channelName3'], []);
            self::fail('expected GraphValidationError for interruptAfter');
        } catch (GraphValidationError $e) {
            self::assertSame('Node channelName3 not in nodes', $e->getMessage());
        }

        $this->expectException(GraphValidationError::class);
        Validate::validateGraph($nodes, $channels, 'channelName2', '', '', [], ['channelName3']);
    }

    public function testMissingChannelsOfEveryKindThrow(): void
    {
        $nodes = ['channelName1' => new PregelNode(channels: [''], triggers: ['channelName2'])];
        $channels = ['' => new LastValue(), 'channelName2' => new LastValue()];

        $cases = [
            'output' => static fn () => Validate::validateGraph($nodes, $channels, 'channelName2', 'channelName3', null, [], []),
            'input' => static fn () => Validate::validateGraph($nodes, $channels, 'channelName3', 'channelName2', null, [], []),
            'stream' => static fn () => Validate::validateGraph($nodes, $channels, 'channelName2', '', 'channelName4', [], []),
        ];
        foreach ($cases as $name => $case) {
            try {
                $case();
                self::fail("expected GraphValidationError for the {$name} channel");
            } catch (GraphValidationError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- the compiled graph is a valid graph --------------------------------

    public function testACompiledStateGraphPassesValidation(): void
    {
        // The end-to-end check: validateGraph is stricter than compile(), so a graph the
        // builder emits must satisfy it or the strictness would have broken every caller.
        $graph = (new StateGraph(['value' => 'string']))
            ->addNode('a', static fn (): array => ['value' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', Constants::END)
            ->compile();

        $graph->validate();

        self::assertSame(['value' => 'a'], $graph->invoke(['value' => 'x']));
    }
}
