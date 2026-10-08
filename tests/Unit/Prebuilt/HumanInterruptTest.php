<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ActionRequest;
use LangGraph\Prebuilt\HumanInterrupt;
use LangGraph\Prebuilt\HumanInterruptConfig;
use LangGraph\Prebuilt\HumanResponse;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * The `interrupt.ts` types: `HumanInterrupt`, `HumanInterruptConfig`, `ActionRequest`, `HumanResponse`.
 *
 * Upstream ships only TypeScript interfaces (no runtime, no tests); these pin the wire shape they describe and run
 * them through a real `interrupt()` / `Command(resume)` round trip, which is the one thing they exist for.
 */
#[CoversClass(HumanInterrupt::class)]
#[CoversClass(HumanInterruptConfig::class)]
#[CoversClass(ActionRequest::class)]
#[CoversClass(HumanResponse::class)]
final class HumanInterruptTest extends TestCase
{
    private static function interrupt(): HumanInterrupt
    {
        return new HumanInterrupt(
            new ActionRequest('Approve XYZ action', ['amount' => 5]),
            new HumanInterruptConfig(allowIgnore: true, allowRespond: false, allowEdit: true, allowAccept: true),
            'Please review the transfer.',
        );
    }

    public function testItSerialisesToUpstreamsSnakeCaseShape(): void
    {
        self::assertSame(
            [
                'action_request' => ['action' => 'Approve XYZ action', 'args' => ['amount' => 5]],
                'config' => ['allow_ignore' => true, 'allow_respond' => false, 'allow_edit' => true, 'allow_accept' => true],
                'description' => 'Please review the transfer.',
            ],
            self::interrupt()->toArray(),
        );
    }

    public function testTheDescriptionIsOptionalAndOmittedWhenAbsent(): void
    {
        $interrupt = new HumanInterrupt(new ActionRequest('x'), new HumanInterruptConfig(false, false, false, true));

        self::assertArrayNotHasKey('description', $interrupt->toArray());
        self::assertNull(HumanInterrupt::fromArray($interrupt->toArray())->description);
    }

    public function testItRoundTripsThroughAnArray(): void
    {
        $original = self::interrupt();

        $copy = HumanInterrupt::fromArray($original->toArray());

        self::assertEquals($original, $copy);
        self::assertSame($original->toArray(), $copy->toArray());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function malformedInterrupts(): array
    {
        $good = self::interrupt()->toArray();

        return [
            'no action_request' => [array_diff_key($good, ['action_request' => 1]), 'requires an "action_request"'],
            'no config' => [array_diff_key($good, ['config' => 1]), 'requires a "config"'],
            'action without a name' => [['action_request' => ['args' => []]] + $good, 'requires a string "action"'],
            'action without args' => [['action_request' => ['action' => 'x']] + $good, 'requires an "args" map'],
            'config flag missing' => [['config' => ['allow_ignore' => true]] + $good, 'requires a boolean "allow_respond"'],
            'config flag not a bool' => [['config' => ['allow_ignore' => 1, 'allow_respond' => true, 'allow_edit' => true, 'allow_accept' => true]] + $good, 'requires a boolean "allow_ignore"'],
        ];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('malformedInterrupts')]
    public function testMalformedInputIsRefusedByName(array $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        HumanInterrupt::fromArray($data);
    }

    /** @return array<string, array{0: string, 1: string|ActionRequest|null, 2: array<string, mixed>}> */
    public static function responses(): array
    {
        return [
            'accept' => [HumanResponse::ACCEPT, null, ['type' => 'accept', 'args' => null]],
            'ignore' => [HumanResponse::IGNORE, null, ['type' => 'ignore', 'args' => null]],
            'response' => [HumanResponse::RESPONSE, 'looks fine', ['type' => 'response', 'args' => 'looks fine']],
            'edit' => [HumanResponse::EDIT, new ActionRequest('Approve XYZ action', ['amount' => 3]), ['type' => 'edit', 'args' => ['action' => 'Approve XYZ action', 'args' => ['amount' => 3]]]],
        ];
    }

    /** @param array<string, mixed> $wire */
    #[DataProvider('responses')]
    public function testAResponseRoundTripsForEachType(string $type, string|ActionRequest|null $args, array $wire): void
    {
        $response = new HumanResponse($type, $args);

        self::assertSame($wire, $response->toArray());
        self::assertEquals($response, HumanResponse::fromArray($wire));
    }

    public function testAnUnknownResponseTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown human response type "approve"');

        new HumanResponse('approve');
    }

    public function testResponseArgsOfTheWrongShapeAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HumanResponse::fromArray(['type' => 'response', 'args' => 42]);
    }

    public function testItDrivesARealInterruptAndResume(): void
    {
        $graph = (new StateGraph(Annotation::root(['outcome' => Annotation::last()])))
            ->addNode('review', static function (): array {
                $answer = HumanResponse::fromArray(interrupt(self::interrupt()->toArray()));

                return ['outcome' => match ($answer->type) {
                    HumanResponse::ACCEPT => 'accepted',
                    HumanResponse::IGNORE => 'skipped',
                    HumanResponse::RESPONSE => 'said: ' . $answer->args,
                    HumanResponse::EDIT => 'edited to ' . json_encode($answer->args->args),
                }];
            })
            ->addEdge(Constants::START, 'review')
            ->compile(['checkpointer' => new MemorySaver()]);

        $thread = static fn (string $id): RunnableConfig => new RunnableConfig(configurable: ['thread_id' => $id]);

        // The paused graph hands the human exactly what was asked.
        $chunks = iterator_to_array($graph->stream(['outcome' => null], $thread('t1')), false);
        $raised = [];
        foreach ($chunks as [$mode, $chunk]) {
            if ($mode === 'updates' && isset($chunk[Constants::INTERRUPT])) {
                $raised = $chunk[Constants::INTERRUPT];
            }
        }
        self::assertCount(1, $raised);
        self::assertEquals(self::interrupt(), HumanInterrupt::fromArray($raised[0]['value']));

        foreach ([
            't1' => [new HumanResponse(HumanResponse::ACCEPT), 'accepted'],
            't2' => [new HumanResponse(HumanResponse::IGNORE), 'skipped'],
            't3' => [new HumanResponse(HumanResponse::RESPONSE, 'ship it'), 'said: ship it'],
            't4' => [new HumanResponse(HumanResponse::EDIT, new ActionRequest('Approve XYZ action', ['amount' => 3])), 'edited to {"amount":3}'],
        ] as $id => [$response, $expected]) {
            if ($id !== 't1') {
                iterator_to_array($graph->stream(['outcome' => null], $thread($id)), false);
            }

            $result = $graph->invoke(new Command(resume: $response->toArray()), $thread($id));

            self::assertSame($expected, $result['outcome'], "resumed $id");
        }
    }
}
