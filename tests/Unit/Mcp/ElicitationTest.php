<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Mcp\Elicitation;
use LangGraph\Mcp\ElicitationAnswerSchema;
use LangGraph\Mcp\ToolException;
use LangGraph\Mcp\ValidationException;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ports the helper-level suites of `tests/elicitation.test.ts` ("elicitation answers", "resuming an
 * elicitation") and the graph-driven parts of `tests/interrupts.test.ts` over a scripted round function and a
 * {@see FakeMcpClient} playing a modern server.
 *
 * Skipped, with reasons: "handles legacy adapter elicitation" (6), "aborts an in-flight elicitation" and
 * "subscribes to modern catalog changes" (real stdio/HTTP MCP servers through `MCPAdapter`);
 * "elicitation and logging configuration" (`adapterConfigSchema`, the unported client config);
 * "retains the SDK-required legacy URL identifier" (asserts the SDK's own `ElicitRequestSchema`);
 * interrupts.test.ts "dynamic headers" / "thread isolation" / "real stdio servers" (HTTP header echo, a real
 * server per thread, child processes) and `agent.elicitation.test.ts` (a real server behind an agent).
 */
#[CoversClass(Elicitation::class)]
#[CoversClass(ElicitationAnswerSchema::class)]
final class ElicitationTest extends McpTestCase
{
    /** @return array<string, mixed> */
    private static function form(): array
    {
        return [
            'message' => 'Approve deployment?',
            'requestedSchema' => [
                'type' => 'object',
                'properties' => ['confirm' => ['type' => 'boolean']],
                'required' => ['confirm'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function question(string $message): array
    {
        return [
            'method' => 'elicitation/create',
            'params' => [
                'mode' => 'form',
                'message' => $message,
                'requestedSchema' => [
                    'type' => 'object',
                    'properties' => ['confirm' => ['type' => 'boolean'], 'note' => ['type' => 'string']],
                    'required' => ['confirm'],
                ],
            ],
        ];
    }

    // ---- "elicitation answers" ----------------------------------------------------------------------------

    public function testProjectsModernFormRequestsWithoutLegacyTaskMetadata(): void
    {
        $params = ['mode' => 'form', ...self::form()];
        $request = ['method' => 'elicitation/create', 'params' => $params];

        $projected = Elicitation::parseModernElicitationRequest([
            ...$request,
            'params' => [...$params, 'task' => ['ttl' => 1000], '_meta' => ['application' => 'example'], 'extension' => true],
        ]);

        self::assertSame($params, $projected);
    }

    public function testProjectsModernUrlRequests(): void
    {
        $params = ['mode' => 'url', 'message' => 'Continue in browser', 'url' => 'https://example.com/authorize'];
        $request = ['method' => 'elicitation/create', 'params' => $params];

        self::assertSame($params, Elicitation::parseModernElicitationRequest($request));
        self::assertSame($params, Elicitation::parseModernElicitationRequest([
            ...$request,
            'params' => [...$params, 'elicitationId' => 'legacy', 'task' => ['ttl' => 1000], '_meta' => ['application' => 'example'], 'extension' => true],
        ]));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidModernRequests(): array
    {
        $params = ['mode' => 'url', 'message' => 'Continue in browser', 'url' => 'https://example.com/authorize'];
        $request = ['method' => 'elicitation/create', 'params' => $params];

        return [
            'another method' => [[...$request, 'method' => 'tools/call']],
            'invalid url' => [[...$request, 'params' => [...$params, 'url' => 'invalid']]],
            'numeric message' => [[...$request, 'params' => [...$params, 'message' => 42]]],
        ];
    }

    /** @param array<string, mixed> $invalid */
    #[DataProvider('invalidModernRequests')]
    public function testRejectsInvalidModernRequests(array $invalid): void
    {
        $this->expectException(ValidationException::class);

        Elicitation::parseModernElicitationRequest($invalid);
    }

    public function testAcceptsSchemaValidFormContent(): void
    {
        $answer = ['action' => 'accept', 'content' => ['confirm' => false]];

        self::assertSame($answer, Elicitation::validateElicitationAnswer(self::form(), $answer));
    }

    /** @return array<string, array{0: string}> */
    public static function actionsWithoutContent(): array
    {
        return ['decline' => ['decline'], 'cancel' => ['cancel']];
    }

    #[DataProvider('actionsWithoutContent')]
    public function testAllowsAnActionWithoutFormContent(string $action): void
    {
        self::assertSame(['action' => $action], Elicitation::validateElicitationAnswer(self::form(), ['action' => $action]));
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidAnswers(): array
    {
        return [
            'null' => [null],
            'unknown action' => [['action' => 'unknown']],
            'accept with no content for a required field' => [['action' => 'accept']],
            'content that fails the schema' => [['action' => 'accept', 'content' => ['confirm' => 'yes']]],
        ];
    }

    #[DataProvider('invalidAnswers')]
    public function testRejectsInvalidAnswers(mixed $answer): void
    {
        $this->expectException(ValidationException::class);

        Elicitation::validateElicitationAnswer(self::form(), $answer);
    }

    public function testReportsFormValidationErrorsUnderContent(): void
    {
        $error = self::thrownBy(static fn () => Elicitation::validateElicitationAnswer(self::form(), ['action' => 'accept', 'content' => ['confirm' => 'yes']]));

        self::assertInstanceOf(ValidationException::class, $error);
        self::assertSame(['content'], $error->issues[0]['path']);
        self::assertStringContainsString('confirm', $error->issues[0]['message']);
    }

    public function testRejectsFormContentInUrlAnswers(): void
    {
        $url = ['mode' => 'url', 'message' => 'Continue in browser', 'url' => 'https://example.com/approve', 'elicitationId' => 'approval'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/URL/');

        Elicitation::validateElicitationAnswer($url, ['action' => 'accept', 'content' => ['confirm' => true]]);
    }

    public function testTheModernAnswerSchemaStripsEverythingButActionAndContent(): void
    {
        $answer = ['action' => 'accept', 'content' => ['confirm' => true], 'method' => 'elicitation/create', 'result' => []];

        self::assertSame($answer, Elicitation::elicitationAnswerFor(self::form())->parse($answer));
        self::assertSame(
            ['action' => 'accept', 'content' => ['confirm' => true]],
            Elicitation::elicitationAnswerFor(self::form(), true)->parse($answer),
        );
        self::assertFalse(Elicitation::elicitationAnswerFor(self::form())->safeParse('nope')['success']);
    }

    public function testConfigureElicitationInstallsAValidatingHandler(): void
    {
        $client = new FakeMcpClient();
        $seen = [];

        Elicitation::configureElicitation($client, 'legacy', static function (array $request, array $context) use (&$seen): array {
            $seen[] = [$request['message'], $context['server']];

            return ['action' => 'decline'];
        });

        self::assertNotNull($client->elicitationHandler);
        self::assertSame(['action' => 'decline'], ($client->elicitationHandler)(self::form()));
        self::assertSame([['Approve deployment?', 'legacy']], $seen);

        // A handler's invalid answer is refused before it reaches the server.
        $bad = new FakeMcpClient();
        Elicitation::configureElicitation($bad, 'legacy', static fn (): array => ['action' => 'accept', 'content' => ['confirm' => 'yes']]);
        $this->expectException(ValidationException::class);
        ($bad->elicitationHandler)(self::form());
    }

    public function testConfigureElicitationWithoutAHandlerIsANoOp(): void
    {
        $client = new FakeMcpClient();

        Elicitation::configureElicitation($client, 'legacy');
        Elicitation::configureElicitation(new PlainMcpClient($client), 'legacy');

        self::assertNull($client->elicitationHandler);
    }

    public function testConfigureElicitationNeedsAClientThatCanRegisterOne(): void
    {
        $this->expectException(ToolException::class);

        Elicitation::configureElicitation(new PlainMcpClient(new FakeMcpClient()), 'legacy', static fn (): array => ['action' => 'cancel']);
    }

    // ---- "resuming an elicitation" -------------------------------------------------------------------------

    /**
     * One question, then completion once it is answered.
     *
     * @return array{graph: Pregel, config: RunnableConfig, saver: MemorySaver, raised: array<string, mixed>, served: list<mixed>}
     */
    private function askOnce(): array
    {
        $served = [];
        $saver = new MemorySaver();
        $graph = Func::entrypoint(['name' => 'ask-once', 'checkpointer' => $saver], static function () use (&$served): array {
            Elicitation::callToolWithElicitation(
                static function (array $params) use (&$served): array {
                    $served[] = $params['inputResponses'] ?? null;
                    if (isset($params['inputResponses'])) {
                        return ['content' => [['type' => 'text', 'text' => 'done']]];
                    }

                    return [
                        'resultType' => 'input_required',
                        'requestState' => 'opaque',
                        'inputRequests' => ['confirmation' => self::question('approve $10')],
                    ];
                },
                ['name' => 'approve', 'arguments' => ['label' => 'operation']],
                'modern',
                'approve',
            );

            return ['done' => true];
        });
        $config = new RunnableConfig(configurable: ['thread_id' => 'resume-' . bin2hex(random_bytes(4))]);

        $graph->invoke([], $config);

        return ['graph' => $graph, 'config' => $config, 'saver' => $saver, 'raised' => $this->raisedBy($saver, $config), 'served' => &$served];
    }

    /** @return array<string, mixed> the pending interrupt */
    private function raisedBy(MemorySaver $saver, RunnableConfig $config): array
    {
        $tuple = $saver->getTuple(['configurable' => $config->configurable]);
        foreach ($tuple->pendingWrites ?? [] as [, $channel, $value]) {
            if ($channel === Constants::INTERRUPT) {
                return is_array($value) && array_is_list($value) ? $value[0] : $value;
            }
        }

        self::fail('No interrupt was raised.');
    }

    /** @return array<string, mixed> */
    private static function accepted(): array
    {
        return ['action' => 'accept', 'content' => ['confirm' => true]];
    }

    public function testTheInterruptCarriesTheEffectiveArgumentsAndTheQuestionsButNotTheServerState(): void
    {
        $ask = $this->askOnce();

        $value = $ask['raised']['value'];
        self::assertSame('mcp_elicitation', $value['type']);
        self::assertSame('modern', $value['server']);
        self::assertSame('approve', $value['tool']);
        self::assertSame(['label' => 'operation'], $value['arguments']);
        self::assertSame('approve $10', $value['requests']['confirmation']['message']);
        self::assertStringNotContainsString('opaque', (string) json_encode($ask['raised']));
    }

    public function testAcceptsAnAnswerBuiltByCreateMcpElicitationResume(): void
    {
        $ask = $this->askOnce();

        $result = $ask['graph']->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($ask['raised'], ['confirmation' => self::accepted()])),
            $ask['config'],
        );

        self::assertSame(['done' => true], $result);
        self::assertCount(1, array_filter($ask['served']));
    }

    /** @return array<string, array{0: mixed}> */
    public static function refusedResumes(): array
    {
        $accepted = ['action' => 'accept', 'content' => ['confirm' => true]];

        return [
            'no answers at all' => [['responses' => []]],
            'an answer under the wrong key' => [['responses' => ['wrong' => $accepted]]],
            'an unexpected extra answer' => [['responses' => ['confirmation' => $accepted, 'extra' => $accepted]]],
            'an action the protocol does not define' => [['responses' => ['confirmation' => ['action' => 'sideways']]]],
            'content that does not fit the requested schema' => [['responses' => ['confirmation' => ['action' => 'accept', 'content' => ['confirm' => 'yes']]]]],
            'responses that are not wrapped' => [['confirmation' => $accepted]],
            'null' => [null],
            'a string' => ['not an answer'],
        ];
    }

    #[DataProvider('refusedResumes')]
    public function testRefusesAMalformedResumeAndNeverReachesTheServerWithIt(mixed $body): void
    {
        $ask = $this->askOnce();

        $failure = self::thrownBy(static fn () => $ask['graph']->invoke(new Command(resume: [$ask['raised']['id'] => $body]), $ask['config']));

        // Re-asking would not help: the caller resuming the graph is code, not the human who filled the form.
        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('needs answers built by createMCPElicitationResume()', $failure->getMessage());
        self::assertSame([], array_values(array_filter($ask['served'])));
    }

    public function testRefusalsNameTheOffendingKeyAndThePathOfTheViolation(): void
    {
        // Each refusal gets a fresh thread: a refused resume value is recorded against the task.
        $refuse = function (array $body): ToolException {
            $ask = $this->askOnce();
            $failure = self::thrownBy(static fn () => $ask['graph']->invoke(new Command(resume: [$ask['raised']['id'] => $body]), $ask['config']));
            self::assertInstanceOf(ToolException::class, $failure);

            return $failure;
        };

        $schema = $refuse(['responses' => ['confirmation' => ['action' => 'accept', 'content' => ['confirm' => 'yes']]]]);
        self::assertStringContainsString('data/confirm must be boolean', $schema->getMessage());
        self::assertStringContainsString('responses.confirmation.content', $schema->getMessage());

        $extra = $refuse(['responses' => ['confirmation' => self::accepted(), 'extra' => self::accepted()]]);
        self::assertStringContainsString('Unrecognized key: "extra"', $extra->getMessage());
    }

    public function testCreateMcpElicitationResumeRefusesAnInterruptThatIsNotAnMcpQuestion(): void
    {
        $this->expectException(ValidationException::class);

        Elicitation::createMCPElicitationResume(['id' => 'abc', 'value' => 'approve $10'], []);
    }

    public function testCreateMcpElicitationResumeNeedsAnInterruptId(): void
    {
        $this->expectException(ValidationException::class);

        Elicitation::createMCPElicitationResume(['value' => []], []);
    }

    // ---- interrupts.test.ts, over a fake modern server ------------------------------------------------------

    /**
     * A modern server whose `approve` tool elicits, wired to a tool and a checkpointed entrypoint.
     *
     * @param array<string, mixed> $options
     *
     * @return array{client: FakeMcpClient, graph: Pregel, saver: MemorySaver, config: RunnableConfig, hooks: \stdClass}
     */
    private function harness(string $style = 'form', array $options = [], bool $checkpointer = true): array
    {
        $hooks = new \stdClass();
        $hooks->before = 0;
        $hooks->after = 0;

        $client = new FakeMcpClient([[
            'name' => 'approve',
            'inputSchema' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'outputSchema' => ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean']], 'required' => ['approved']],
        ]], 'modern');
        $approved = ['content' => [['type' => 'text', 'text' => 'approved']], 'structuredContent' => ['approved' => true]];
        $client->respondWith(static function (string $name, array $args, array $callOptions) use ($style, $approved): array {
            if ($style === 'complete') {
                return $approved;
            }
            if ($style === 'stateOnly') {
                return isset($callOptions['requestState'])
                    ? $approved
                    : ['resultType' => 'input_required', 'requestState' => 'opaque:+/%==', 'inputRequests' => []];
            }
            if (isset($callOptions['inputResponses']['confirmation'])) {
                return $approved;
            }

            return [
                'resultType' => 'input_required',
                'requestState' => 'opaque:+/%==',
                'inputRequests' => ['confirmation' => $style === 'url'
                    ? ['method' => 'elicitation/create', 'params' => ['mode' => 'url', 'message' => (string) $args['label'], 'url' => 'https://example.com/authorize', 'elicitationId' => 'legacy']]
                    : ['method' => 'elicitation/create', 'params' => [
                        'mode' => 'form',
                        'message' => (string) $args['label'],
                        'requestedSchema' => ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean']], 'required' => ['confirm']],
                    ]]],
            ];
        });

        $tool = self::firstTool($client, [
            'beforeToolCall' => static function () use ($hooks): array {
                ++$hooks->before;

                return ['args' => ['label' => 'effective']];
            },
            'afterToolCall' => static function () use ($hooks): void {
                ++$hooks->after;
            },
            ...$options,
        ]);

        $saver = new MemorySaver();
        $graph = Func::entrypoint(
            ['name' => 'durable', 'checkpointer' => $checkpointer ? $saver : null],
            static function () use ($tool): array {
                $tool->invoke(['label' => 'original']);

                return ['done' => true];
            },
        );

        return ['client' => $client, 'graph' => $graph, 'saver' => $saver, 'config' => new RunnableConfig(configurable: ['thread_id' => 'durable-round']), 'hooks' => $hooks];
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function answerShapes(): array
    {
        $answer = ['action' => 'accept', 'content' => ['confirm' => true]];

        return [
            'a form answer' => [$answer],
            'an answer carrying the SDK request method' => [[...$answer, 'method' => 'elicitation/create']],
            'an answer carrying an SDK result envelope' => [[...$answer, 'result' => []]],
        ];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('answerShapes')]
    public function testCompletesTheToolCallWithAFormAnswer(array $answer): void
    {
        $h = $this->harness();
        $h['graph']->invoke([], $h['config']);
        $raised = $this->raisedBy($h['saver'], $h['config']);

        // The question carries the effective arguments, never the server's state.
        self::assertSame('effective', $raised['value']['requests']['confirmation']['message']);
        self::assertSame(['label' => 'effective'], $raised['value']['arguments']);
        self::assertStringNotContainsString('opaque', (string) json_encode($raised));
        self::assertCount(1, $h['client']->calls);
        self::assertSame(1, $h['hooks']->before);
        self::assertSame(0, $h['hooks']->after);

        $resumed = $h['graph']->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($raised, ['confirmation' => $answer])),
            $h['config'],
        );

        self::assertSame(['done' => true], $resumed);
        // Resuming replays the tool call from its first round, so the server is asked again before it is
        // answered. Effects must be idempotent. The extra keys never reach the server.
        self::assertCount(3, $h['client']->calls);
        self::assertSame(['action' => 'accept', 'content' => ['confirm' => true]], $h['client']->calls[2]['options']['inputResponses']['confirmation']);
        self::assertSame('opaque:+/%==', $h['client']->calls[2]['options']['requestState']);
        self::assertSame(1, $h['hooks']->after);
        // beforeToolCall runs once per execution and a replayed execution runs it again: no once-only guarantee.
        self::assertSame(2, $h['hooks']->before);
    }

    public function testCompletesAUrlQuestionAndNeverExposesALegacyElicitationId(): void
    {
        $h = $this->harness('url');
        $h['graph']->invoke([], $h['config']);
        $raised = $this->raisedBy($h['saver'], $h['config']);

        self::assertSame('url', $raised['value']['requests']['confirmation']['mode']);
        self::assertSame('https://example.com/authorize', $raised['value']['requests']['confirmation']['url']);
        self::assertStringNotContainsString('elicitationId', (string) json_encode($raised));

        $resumed = $h['graph']->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($raised, ['confirmation' => ['action' => 'accept']])),
            $h['config'],
        );

        self::assertSame(['done' => true], $resumed);
        self::assertCount(3, $h['client']->calls);
        self::assertSame(1, $h['hooks']->after);
    }

    public function testRejectsAStateOnlyResponseInsideAGraphWithoutPolling(): void
    {
        $h = $this->harness('stateOnly');

        $failure = self::thrownBy(static fn () => $h['graph']->invoke([], $h['config']));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('state-only response', $failure->getMessage());
        self::assertCount(1, $h['client']->calls);
        self::assertSame(1, $h['hooks']->before);
        self::assertSame(0, $h['hooks']->after);
    }

    public function testRequiresACheckpointerToPause(): void
    {
        $h = $this->harness('form', [], false);

        $failure = self::thrownBy(static fn () => $h['graph']->invoke([], $h['config']));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('with a checkpointer', $failure->getMessage());
        self::assertCount(1, $h['client']->calls);
    }

    public function testRejectsAQuestionWithNoWayToAnswerItOutsideAGraph(): void
    {
        $h = $this->harness();
        $tool = self::firstTool($h['client']);

        $failure = self::thrownBy(static fn () => $tool->invoke(['label' => 'original']));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('inside a LangGraph with a checkpointer', $failure->getMessage());
        self::assertCount(1, $h['client']->calls);
    }

    public function testRejectsAStateOnlyResponseInsteadOfPollingOutsideAGraph(): void
    {
        $h = $this->harness('stateOnly');
        $tool = self::firstTool($h['client']);

        $failure = self::thrownBy(static fn () => $tool->invoke(['label' => 'original']));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('a state-only response carries no question to ask', $failure->getMessage());
        self::assertCount(1, $h['client']->calls);
    }

    public function testReportsAnAbortRatherThanWrappingIt(): void
    {
        $h = $this->harness('stateOnly');
        $aborted = false;
        // As the SDK client does: a request whose signal fired mid-flight rejects with the abort.
        $h['client']->respondWith(static function () use (&$aborted): array {
            $aborted = true;

            throw new \RuntimeException('This operation was aborted');
        });
        $tool = self::firstTool($h['client']);
        $signal = static function () use (&$aborted): bool {
            return $aborted;
        };

        $failure = self::thrownBy(static fn () => $tool->invoke(['label' => 'original'], new RunnableConfig(signal: $signal)));

        self::assertMatchesRegularExpression('/abort/i', $failure->getMessage());
        self::assertNotInstanceOf(ToolException::class, $failure);
        self::assertCount(1, $h['client']->calls);
        self::assertSame(0, $h['hooks']->after);
    }

    public function testStopsBeforeAskingWhenTheSignalHasAlreadyFired(): void
    {
        $rounds = 0;

        $failure = self::thrownBy(static function () use (&$rounds): void {
            Elicitation::callToolWithElicitation(
                static function () use (&$rounds): array {
                    ++$rounds;

                    return self::text('never');
                },
                ['name' => 'approve', 'arguments' => []],
                'modern',
                'approve',
                static fn (): bool => true,
            );
        });

        self::assertMatchesRegularExpression('/abort/i', $failure->getMessage());
        self::assertSame(0, $rounds);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: list<string>}> */
    public static function malformedAnswers(): array
    {
        $formAnswer = ['action' => 'accept', 'content' => ['confirm' => true]];

        return [
            'content that violates the requested schema' => [
                ['confirmation' => ['action' => 'accept', 'content' => ['confirm' => 'yes']]],
                ['data/confirm must be boolean', 'responses.confirmation.content'],
            ],
            'an answer under an unrequested key' => [['wrong' => $formAnswer], ['Unrecognized key: "wrong"']],
            'an extra key alongside the requested one' => [['confirmation' => $formAnswer, 'extra' => $formAnswer], ['Unrecognized key: "extra"']],
        ];
    }

    /**
     * @param array<string, mixed> $bad
     * @param list<string>         $reasons
     */
    #[DataProvider('malformedAnswers')]
    public function testFailsTheCallForAMalformedAnswer(array $bad, array $reasons): void
    {
        $h = $this->harness();
        $h['graph']->invoke([], $h['config']);
        $raised = $this->raisedBy($h['saver'], $h['config']);

        $failure = self::thrownBy(static fn () => $h['graph']->invoke(
            new Command(resume: Elicitation::createMCPElicitationResume($raised, $bad)),
            $h['config'],
        ));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString(
            'Resuming MCP tool "approve" on server "test" needs answers built by createMCPElicitationResume()',
            $failure->getMessage(),
        );
        foreach ($reasons as $reason) {
            self::assertStringContainsString($reason, $failure->getMessage());
        }
        self::assertSame(0, $h['hooks']->after);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function unanswerableRounds(): array
    {
        $elicit = ['method' => 'elicitation/create', 'params' => ['mode' => 'form', 'message' => 'ok?', 'requestedSchema' => ['type' => 'object', 'properties' => []]]];

        return [
            'sampling' => [['sampling' => ['method' => 'sampling/createMessage', 'params' => []]], 'sampling.method'],
            'roots' => [['roots' => ['method' => 'roots/list']], 'roots.method'],
            'a mixed round names only the request it cannot answer' => [['ok' => $elicit, 'sampling' => ['method' => 'sampling/createMessage', 'params' => []]], 'sampling.method'],
        ];
    }

    /** @param array<string, mixed> $inputRequests */
    #[DataProvider('unanswerableRounds')]
    public function testRefusesWhatAnInterruptCannotCarry(array $inputRequests, string $named): void
    {
        $failure = self::thrownBy(static fn () => Elicitation::callToolWithElicitation(
            static fn (): array => ['resultType' => 'input_required', 'inputRequests' => $inputRequests],
            ['name' => 'approve', 'arguments' => []],
            'modern',
            'approve',
        ));

        self::assertInstanceOf(ToolException::class, $failure);
        self::assertStringContainsString('asked for input this adapter cannot answer', $failure->getMessage());
        self::assertStringContainsString($named, $failure->getMessage());
        self::assertStringNotContainsString('ok.method', $failure->getMessage());
    }
}
