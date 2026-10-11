<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Pregel\RemoteRunStream;
use LangGraph\Pregel\StreamProtocol;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\Utils\StreamingMethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual Client/SDK transport fixtures compared with the pinned N22 source oracle. */
final class RemoteRunStreamThreadProtocolTest extends TestCase
{
    public static function event(string $method, mixed $data, int $seq = 17, array $namespace = []): array
    {
        return ['type' => 'event', 'seq' => $seq, 'event_id' => 'event-' . $seq, 'method' => $method,
            'params' => ['namespace' => $namespace, 'timestamp' => 9, 'data' => $data]];
    }

    private static function graph(array $events = []): array
    {
        $transport = new RemoteThreadProtocolTransport($events === [] ? [] : [...$events, self::event('lifecycle', ['event' => 'completed'], 100)]);
        $client = new Client(['apiUrl' => 'https://unit.test', 'apiKey' => null, 'callerOptions' => ['maxRetries' => 0]], $transport);

        return [new RemoteGraph('g', $client), $transport, $client];
    }

    public function testConfiguredThreadStartsOnlyCommandWithRecursiveInputAndSanitizedConfig(): void
    {
        [$graph, $http] = self::graph();
        $input = (object) ['nested' => (object) ['messages' => [new HumanMessage('hello')]]];
        $graph = $graph->withConfig(['tags' => ['bound'], 'recursionLimit' => 10,
            'configurable' => ['thread_id' => 't', 'checkpoint_id' => 'cp', 'checkpoint_ns' => 'ns',
                'checkpoint_map' => ['x' => 'y'], '__pregel_internal' => true, 'keep' => 'yes']]);
        $run = $graph->streamEventsV3($input, new RunnableConfig(tags: ['call'], context: ['ignored' => true]));
        self::assertSame('t', $run->threadId());
        self::assertSame('r', $run->runId());
        self::assertCount(1, $http->requests);
        self::assertSame('https://unit.test/threads/t/commands', $http->requests[0]['url']);
        $command = $http->body(0);
        self::assertSame('run.start', $command['method']);
        self::assertSame('g', $command['params']['assistant_id']);
        self::assertSame('human', $command['params']['input']['nested']['messages'][0]['role']);
        self::assertSame('hello', $command['params']['input']['nested']['messages'][0]['content']);
        self::assertSame(['tags' => ['bound', 'call'], 'metadata' => ['thread_id' => 't', 'checkpoint_id' => 'cp', 'checkpoint_ns' => 'ns'],
            'configurable' => ['keep' => 'yes'], 'recursion_limit' => 10], $command['params']['config']);
        self::assertArrayNotHasKey('context', $command['params']);
    }

    public function testGeneratedThreadAndCommandAsInputWithoutRestCreate(): void
    {
        [$graph, $http] = self::graph();
        $run = $graph->streamEventsV3(new Command(update: ['x' => 2], resume: 'yes'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $run->threadId());
        self::assertCount(1, $http->requests);
        self::assertSame('https://unit.test/threads/' . $run->threadId() . '/commands', $http->requests[0]['url']);
        $params = $http->body(0)['params'];
        self::assertSame('Command', $params['input']['lg_name']);
        self::assertSame(['x' => 2], $params['input']['update']);
        self::assertSame('yes', $params['input']['resume']);
        self::assertArrayNotHasKey('command', $params);
    }

    public function testFragmentedRawProtocolPreservesEnvelopeAndDelegatedValues(): void
    {
        $event = self::event('values', ['value' => 1], 17);
        [$graph, $http] = self::graph([$event]);
        $run = $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't']));
        self::assertSame([$event, self::event('lifecycle', ['event' => 'completed'], 100)], iterator_to_array($run, false));
        self::assertSame($run->thread()->values(), $run->values());
        self::assertSame([['value' => 1]], iterator_to_array($run->values(), false));
        self::assertSame(['value' => 1], $run->output());
        self::assertCount(2, $http->requests, 'output reads existing values cache instead of opening joinStream');
        self::assertSame('https://unit.test/threads/t/stream/events', $http->requests[1]['url']);
        self::assertEqualsCanonicalizing(RemoteRunStream::PROTOCOL_CHANNELS, $http->body(1)['channels']);
        self::assertGreaterThan(1, $http->producedChunks);
        $run->abort();
        self::assertTrue($http->released);
    }

    public function testFactoryKeepsProjectionIdentityAndLateNamedExtension(): void
    {
        [, $http, $client] = self::graph([self::event('custom', ['name' => 'activity', 'payload' => ['ok' => true]])]);
        $thread = $client->threads->stream('t', ['assistantId' => 'g', 'maxReconnectAttempts' => 0]);
        $run = RemoteRunStream::fromThread($client, $thread);
        self::assertSame($thread, $run->thread());
        self::assertSame($thread->values(), $run->values());
        self::assertSame($thread->messages(), $run->messages());
        self::assertSame($thread->subgraphs(), $run->subgraphs());
        self::assertSame($thread->extension('activity'), $run->extension('activity'));
        self::assertSame([['ok' => true]], iterator_to_array($run->extension('activity'), false));
        self::assertCount(1, $http->requests);
        $run->abort();
    }

    public function testVersionRejectionIsEagerAndV3GeneratorEncodesSourceEnvelope(): void
    {
        [$graph, $http] = self::graph([self::event('values', ['value' => 1], 17, ['child'])]);
        foreach (['v1', 'v2'] as $version) {
            try {
                $graph->streamEvents([], null, $version);
                self::fail('Expected eager rejection');
            } catch (\RuntimeException $error) {
                self::assertSame('Not implemented.', $error->getMessage());
            }
        }
        self::assertSame([], $http->requests);
        self::assertSame(["event: values|child\ndata: {\"value\":1}\n\n", "event: lifecycle\ndata: {\"event\":\"completed\"}\n\n"], iterator_to_array(
            $graph->streamEvents([], new RunnableConfig(configurable: ['thread_id' => 't']), 'v3', encoding: 'text/event-stream'), false));
    }

    public function testOrdinaryStreamHookUnionsModesAndMatchesFullEventBeforeFiltering(): void
    {
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'custom', 'data' => ['c' => 1]], ['event' => 'custom|child', 'data' => ['c' => 2]],
            ['event' => 'values', 'data' => ['v' => 3]], ['event' => 'updates', 'data' => ['u' => 4]]];
        $pushed = [];
        $sink = new StreamProtocol(['custom', 'values'], static function ($chunk) use (&$pushed): void { $pushed[] = $chunk; });
        $data = iterator_to_array($graph->stream([], new RunnableConfig(context: ['c' => 1], options: ['streamMode' => 'values'],
            configurable: ['thread_id' => 't', 'checkpoint_ns' => 'outer', Constants::CONFIG_KEY_STREAM => $sink])), false);
        self::assertSame([['v' => 3]], $data);
        self::assertSame([[['outer'], 'custom', ['c' => 1]], [['outer'], 'values', ['v' => 3]]], $pushed);
        self::assertSame(['values', 'updates', 'custom'], $http->body(0)['stream_mode']);
        self::assertTrue($http->body(0)['stream_subgraphs']);
        self::assertSame(['c' => 1], $http->body(0)['context']);
    }

    public function testNestedConfigMetadataDefaultsPreserveCallerAndExplicitValues(): void
    {
        [$graph, $http] = self::graph();
        $nested = (object) ['configurable' => (object) ['thread_id' => 'inner', 'run_id' => 'rr'],
            'metadata' => (object) ['thread_id' => 'explicit']];
        $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't', 'nested' => $nested, 'empty' => (object) ['configurable' => new \stdClass()]]));
        self::assertSame(['thread_id' => 'explicit', 'run_id' => 'rr'], $http->body(0)['params']['config']['configurable']['nested']['metadata']);
        self::assertFalse(isset($nested->metadata->run_id), 'source defaults applied to a copy in PHP');
        $wire = json_decode($http->requests[0]['body']);
        self::assertInstanceOf(\stdClass::class, $wire->params->config->configurable->empty->metadata);
    }

    public function testUnsupportedNullOptionsRejectBeforeStartAndBoundOptionIsNotCallOption(): void
    {
        [$graph, $http] = self::graph();
        foreach (['transformers', 'control', 'interruptBefore', 'interruptAfter'] as $name) {
            try {
                $graph->streamEventsV3([], new RunnableConfig(options: [$name => null]));
                self::fail('Explicit null still supplies an unsupported source option');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($name, $error->getMessage());
            }
        }
        self::assertSame([], $http->requests);
        $graph->withConfig(['options' => ['transformers' => []]])->streamEventsV3([]);
        self::assertCount(1, $http->requests);
    }

    public function testLifecycleUsesEnvelopeNamespaceAndTimestampAndKeepsThreadOpen(): void
    {
        [$graph, $http] = self::graph([self::event('lifecycle', ['event' => 'started', 'namespace' => ['wrong'], 'timestamp' => 0], 2, ['child'])]);
        $run = $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't']));
        self::assertSame([['event' => 'started', 'namespace' => ['child'], 'timestamp' => 9],
            ['event' => 'completed', 'namespace' => [], 'timestamp' => 9]], iterator_to_array($run->lifecycle(), false));
        self::assertSame(['run_id' => 'r'], $run->thread()->run->start([]), 'subscription exhaustion does not close thread');
        $run->abort();
    }

    public function testEarlyDisposedCursorUnsubscribesWhileRetainedCursorKeepsSubscription(): void
    {
        [, $http, $client] = self::graph([self::event('values', ['v' => 1])]);
        $thread = $client->threads->stream('t', ['assistantId' => 'g', 'maxReconnectAttempts' => 0]);
        $run = RemoteRunStream::fromThread($client, $thread);
        $cursor = $run->getIterator();
        $cursor->rewind();
        self::assertSame('values', $cursor->current()['method']);
        self::assertEqualsCanonicalizing(RemoteRunStream::PROTOCOL_CHANNELS, $http->body(0)['channels']);
        unset($cursor);
        self::assertSame([['v' => 1]], iterator_to_array($run->values(), false));
        self::assertEqualsCanonicalizing(['values', 'lifecycle', 'input'], $http->body(1)['channels']);
        self::assertSame(['run_id' => 'r'], $thread->run->start([]));
        $run->abort();
    }

    public function testRawSignalCheckCancelsBeforeAdvancingNextTransportChunk(): void
    {
        [, $http, $client] = self::graph([self::event('values', 1), self::event('values', 2, 18)]);
        $thread = $client->threads->stream('t', ['assistantId' => 'g', 'maxReconnectAttempts' => 0]);
        $abort = false;
        $run = RemoteRunStream::fromThread($client, $thread, 'r', static function () use (&$abort): bool { return $abort; });
        $cursor = $run->getIterator();
        $cursor->rewind();
        $before = $http->producedChunks;
        $abort = true;
        $cursor->next();
        self::assertFalse($cursor->valid());
        self::assertSame($before, $http->producedChunks);
        self::assertTrue($http->released);
        self::assertTrue($run->aborted());
        self::assertCount(2, $http->requests);
        self::assertStringContainsString('/cancel', $http->requests[1]['url']);
    }

    public function testAbortClosesEvenWithMissingRunIdOrCancelFailureAndIsIdempotent(): void
    {
        foreach ([null, 'r'] as $id) {
            [, $http, $client] = self::graph();
            $thread = $client->threads->stream('t', ['assistantId' => 'g']);
            $http->cancelFails = true;
            $run = RemoteRunStream::fromThread($client, $thread, $id);
            $run->abort();
            $run->abort();
            self::assertTrue(($run->signal())());
            self::assertCount($id === null ? 0 : 1, $http->requests);
            try {
                $thread->run->start([]);
                self::fail('Thread must close even when server cancel fails');
            } catch (\RuntimeException $error) {
                self::assertSame('ThreadStream closed', $error->getMessage());
            }
        }
    }

    public function testAbsentRunIdStillSubscribesUnlikeLegacyDirectConstructor(): void
    {
        [, $http, $client] = self::graph([self::event('values', 1)]);
        $thread = $client->threads->stream('t', ['assistantId' => 'g', 'maxReconnectAttempts' => 0]);
        $run = RemoteRunStream::fromThread($client, $thread);
        self::assertCount(2, iterator_to_array($run, false));
        self::assertSame([], iterator_to_array(new RemoteRunStream($client, 't'), false));
        self::assertSame(['values', 'updates', 'messages-tuple', 'custom', 'tasks', 'checkpoints'], RemoteRunStream::STREAM_MODES);
        $run->abort();
    }

    public function testUnsolicitedProtocolErrorIsRecordedAndValuesContinue(): void
    {
        [$graph] = self::graph([['type' => 'error', 'error' => 'server_error', 'message' => 'bad'], self::event('values', 42)]);
        $run = $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't']));
        $events = iterator_to_array($run, false);
        self::assertSame(42, $events[0]['params']['data']);
        self::assertCount(1, $run->thread()->errors());
        self::assertSame('bad', $run->thread()->errors()[0]->getMessage());
        $run->abort();
    }

    public function testMessagesAndSubgraphsUseActualSdkHandlesAndNodeFiltering(): void
    {
        $events = [self::event('lifecycle', ['event' => 'started', 'graph_name' => 'child'], 1, ['child:0']),
            self::event('values', ['child' => true], 2, ['child:0'])];
        foreach ([['event' => 'message-start', 'id' => 'm'], ['event' => 'content-block-delta', 'index' => 0,
            'content' => ['type' => 'text', 'text' => 'hello']], ['event' => 'message-finish']] as $data) {
            $event = self::event('messages', $data, count($events) + 1);
            $event['params']['node'] = 'writer';
            $events[] = $event;
        }
        [$graph] = self::graph($events);
        $run = $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't']));
        $messages = $run->messages();
        $filtered = $run->messagesFrom('writer');
        $other = $run->messagesFrom('other');
        $children = $run->subgraphs();
        $handles = iterator_to_array($filtered, false);
        self::assertCount(1, $handles);
        self::assertSame($handles, iterator_to_array($messages, false));
        self::assertSame([], iterator_to_array($other, false));
        self::assertSame('hello', $handles[0]->text());
        $child = iterator_to_array($children, false)[0];
        self::assertSame('child', $child->name);
        self::assertSame(['child:0'], $child->namespace);
        $run->abort();
    }

    public function testDelegatedCallablePollingRemainsEntryExitRatherThanCommonPull(): void
    {
        [$graph, $http] = self::graph([self::event('values', ['done' => true])]);
        $abort = false;
        $run = $graph->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't'],
            signal: static function () use (&$abort): bool { return $abort; }));
        $projection = $run->values();
        $http->onChunk = static function () use (&$abort): void { $abort = true; };
        self::assertSame(['done' => true], $projection->value(), 'direct delegated pull cannot observe caller callable');
        self::assertFalse($run->aborted(), 'N21b common-pull observer remains absent');
        self::assertSame(['done' => true], $run->output(), 'entry polling cancels; cached output remains available');
        self::assertTrue($run->aborted());
    }


    public function testBoundDefaultsSurviveExplicitNullCallConfigAndCallerMetadataWins(): void
    {
        [$graph, $http] = self::graph();
        $graph = $graph->withConfig(['recursionLimit' => 10, 'tags' => ['bound'], 'metadata' => ['bound' => true, 'thread_id' => 'explicit'],
            'configurable' => ['thread_id' => 't', 'keep' => true]]);
        $graph->streamEventsV3([], RunnableConfig::fromArray(['recursionLimit' => null, 'tags' => null, 'configurable' => null, 'metadata' => ['caller' => true]]));
        self::assertSame(['bound' => true, 'thread_id' => 'explicit', 'caller' => true], $http->body(0)['params']['config']['metadata']);
        self::assertSame(10, $http->body(0)['params']['config']['recursion_limit']);
        self::assertSame(['keep' => true], $http->body(0)['params']['config']['configurable']);
    }

    public function testOrdinaryHookHonorsExplicitSubgraphsFalseAndRunsBeforeInterruptThrow(): void
    {
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'updates', 'data' => ['__interrupt__' => [['id' => 'i', 'value' => 'pause']]]]];
        $seen = [];
        $sink = new StreamProtocol(['updates'], static function ($value) use (&$seen): void { $seen[] = $value; });
        try {
            iterator_to_array($graph->stream([], new RunnableConfig(options: ['streamMode' => 'values', 'subgraphs' => false],
                configurable: [Constants::CONFIG_KEY_STREAM => $sink])));
            self::fail('Interrupt expected');
        } catch (GraphInterrupt) {
            self::assertSame([[[], 'updates', ['__interrupt__' => [['id' => 'i', 'value' => 'pause']]]]], $seen);
        }
        self::assertFalse($http->body(0)['stream_subgraphs']);
    }

    public function testOrdinaryStreamUsesCallModesAndHookRatherThanBoundOptions(): void
    {
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'updates', 'data' => ['u' => 1]]];
        $seen = [];
        $sink = new StreamProtocol(['custom'], static function ($value) use (&$seen): void { $seen[] = $value; });
        $graph = $graph->withConfig(['options' => ['streamMode' => 'values', 'subgraphs' => true],
            'configurable' => [Constants::CONFIG_KEY_STREAM => $sink]]);
        self::assertSame([['u' => 1]], iterator_to_array($graph->stream([], new RunnableConfig()), false));
        self::assertSame(['updates'], $http->body(0)['stream_mode']);
        self::assertFalse($http->body(0)['stream_subgraphs']);
        self::assertSame([], $seen);
    }

    public function testInheritedCallbackFilterArgumentIsNotRepurposedAsV3RuntimeOptions(): void
    {
        [$graph, $http] = self::graph();
        $cursor = $graph->streamEvents([], new RunnableConfig(configurable: ['thread_id' => 't']), 'v3', ['control' => []]);
        self::assertInstanceOf(\Generator::class, $cursor);
        self::assertCount(1, $http->requests);
    }

    public static function nativePaths(): iterable
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            foreach (['input', 'configurable'] as $section) {
                yield $path . ' ' . $section => [$path, $section];
            }
        }
    }

    #[DataProvider('nativePaths')]
    public function testPhpNativeJsonContractsSurviveInputAndConfigAcrossAllRemotePaths(string $path, string $section): void
    {
        $values = ['enum' => RemoteBoundaryColor::Red, 'money' => new RemoteBoundaryMoney(5),
            'date' => new \DateTimeImmutable('2026-10-09T12:00:00+00:00'), 'arrayObject' => new \ArrayObject(['x' => 1])];
        $expected = json_decode(json_encode($values, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
        $input = ['native' => $values, 'nested' => (object) ['native' => $values]];
        $config = new RunnableConfig(configurable: ['thread_id' => 't', 'native' => $values,
            'nested' => (object) ['native' => $values]]);
        match ($path) {
            'invoke' => $graph->invoke($input, $config),
            'stream' => iterator_to_array($graph->stream($input, $config), false),
            'v3' => $graph->streamEventsV3($input, $config),
        };
        $request = $http->body(0);
        $payload = $path === 'v3' ? $request['params'] : $request;
        $actual = $section === 'input' ? $payload['input'] : $payload['config']['configurable'];
        self::assertSame($expected, $actual['native'], $path . ' ' . $section . ' native JSON contracts');
        self::assertSame($expected, $actual['nested']['native'], $path . ' ' . $section . ' nested plain map');
    }

    #[DataProvider('nativePaths')]
    public function testStdClassSubclassesKeepTheirNativeJsonContractAcrossAllRemotePaths(string $path, string $section): void
    {
        $custom = new RemoteBoundaryCustomObject();
        $custom->x = 1;
        $customMessage = new RemoteBoundaryCustomObject();
        $customMessage->m = new HumanMessage('hi');
        $hiddenCycle = new RemoteBoundaryCustomObject();
        $hiddenCycle->self = $hiddenCycle;
        $subclassMessage = new RemoteBoundaryObjectSubclass();
        $subclassMessage->m = new HumanMessage('hi');
        $subclassConfig = new RemoteBoundaryObjectSubclass();
        $subclassConfig->configurable = ['thread_id' => 'x', 'user_id' => 'u'];
        $subclassConfig->metadata = new \stdClass();
        $values = ['custom' => $custom, 'customMessage' => $customMessage, 'hiddenCycle' => $hiddenCycle,
            'subclassMessage' => $subclassMessage, 'subclassConfig' => $subclassConfig];
        // The contract is whatever native json_encode produces for these objects, not a walker rewrite.
        $expected = json_decode(json_encode($values, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['custom' => true], $expected['hiddenCycle']);
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
        $input = ['native' => $values, 'nested' => (object) ['native' => $values]];
        $config = new RunnableConfig(configurable: ['thread_id' => 't', 'native' => $values,
            'nested' => (object) ['native' => $values]]);
        self::callPath($graph, $path, $section === 'input' ? $input : ['plain' => 1], $config);
        $request = $http->body(0);
        $payload = $path === 'v3' ? $request['params'] : $request;
        $actual = $section === 'input' ? $payload['input'] : $payload['config']['configurable'];
        self::assertSame($expected, $actual['native'], $path . ' ' . $section . ' stdClass subclass contracts');
        self::assertSame($expected, $actual['nested']['native'], $path . ' ' . $section . ' inside a plain map');
        self::assertSame($hiddenCycle, $hiddenCycle->self);
        self::assertInstanceOf(HumanMessage::class, $subclassMessage->m);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function foreignMetadataPaths(): iterable
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            foreach (['subclass', 'jsonSerializable', 'arrayObject'] as $kind) {
                yield $path . ' ' . $kind . ' propagatable' => [$path, $kind, true];
                yield $path . ' ' . $kind . ' nothing to propagate' => [$path, $kind, false];
            }
        }
    }

    #[DataProvider('foreignMetadataPaths')]
    public function testNonPlainNestedMetadataKeepsItsNativeContractBesidePlainConfigurable(string $path, string $kind, bool $propagatable): void
    {
        $metadata = match ($kind) {
            'subclass' => new RemoteBoundaryObjectSubclass(),
            'jsonSerializable' => new RemoteBoundaryCustomObject(),
            'arrayObject' => new \ArrayObject(['k' => 1, 'thread_id' => 'explicit']),
        };
        if ($metadata instanceof \stdClass) {
            $metadata->k = 1;
            $metadata->thread_id = 'explicit';
        }
        $before = serialize($metadata);
        $native = json_decode(json_encode($metadata, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $nested = $propagatable ? ['thread_id' => 'y', 'run_id' => 'run'] : ['other' => 'z'];
        $defaults = $propagatable ? ['thread_id' => 'y', 'run_id' => 'run'] : [];
        $inner = (object) ['configurable' => ['thread_id' => 'inner', 'run_id' => 'inner-run'],
            'metadata' => (object) ['thread_id' => 'inner-explicit']];
        $foreignConfigurable = $nested + ['inner' => $inner];
        $config = new RunnableConfig(configurable: ['thread_id' => 't',
            'object' => (object) ['configurable' => $foreignConfigurable, 'metadata' => $metadata],
            'array' => ['configurable' => $foreignConfigurable, 'metadata' => $metadata],
            'plain' => (object) ['configurable' => $nested, 'metadata' => (object) ['k' => 1, 'thread_id' => 'explicit']],
            'list' => ['configurable' => $nested, 'metadata' => ['k' => 1]],
            'absent' => (object) ['configurable' => $nested]]);
        $callerBefore = serialize($config);
        [$graph, $http] = self::graph();
        $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
        self::callPath($graph, $path, ['plain' => 1], $config);
        $request = $http->body(0);
        $configurable = ($path === 'v3' ? $request['params'] : $request)['config']['configurable'];

        self::assertSame($native, $configurable['object']['metadata'], 'object node keeps the native contract');
        self::assertSame($native, $configurable['array']['metadata'], 'array node keeps the native contract');
        foreach (['object', 'array'] as $node) {
            self::assertSame(['thread_id' => 'inner-explicit', 'run_id' => 'inner-run'],
                $configurable[$node]['configurable']['inner']['metadata'],
                'a foreign metadata node still recurses into nested configurable defaults');
        }
        self::assertSame(['k' => 1, 'thread_id' => 'explicit'] + $defaults, $configurable['plain']['metadata']);
        self::assertSame(['k' => 1] + $defaults, $configurable['list']['metadata']);
        self::assertSame($defaults, $configurable['absent']['metadata']);
        self::assertSame($before, serialize($metadata), 'caller metadata is not mutated');
        self::assertSame($metadata, $config->configurable['object']->metadata);
        self::assertSame($metadata, $config->configurable['array']['metadata']);
        self::assertSame($inner, $config->configurable['object']->configurable['inner']);
        self::assertSame($inner, $config->configurable['array']['configurable']['inner']);
        self::assertSame($callerBefore, serialize($config), 'nested defaults do not mutate caller objects');
        self::assertSame(['k' => 1, 'thread_id' => 'explicit'], get_object_vars($config->configurable['plain']->metadata));
    }

    public function testProtocolMalformedJsonBecomesFinalEncodedErrorAndCommandFailureDoesNotStart(): void
    {
        [, $http, $client] = self::graph([self::event('values', 1)]);
        $http->malformedWire = true;
        $thread = $client->threads->stream('t', ['assistantId' => 'g', 'maxReconnectAttempts' => 0]);
        $frames = iterator_to_array(RemoteRunStream::fromThread($client, $thread)->toEventStream(), false);
        self::assertCount(1, $frames);
        self::assertStringStartsWith("event: error\ndata: ", $frames[0]);
        self::assertStringContainsString('JsonException: Syntax error', $frames[0]);
        self::assertStringNotContainsString('#0', $frames[0], 'source String(error) does not include a stack trace');
        [$graph, $http] = self::graph();
        $http->commandError = true;
        try {
            $graph->streamEventsV3([]);
            self::fail('Command error expected');
        } catch (\LangGraph\Sdk\Stream\ProtocolError $error) {
            self::assertSame('invalid_request', $error->protocolCode);
        }
        self::assertCount(1, $http->requests);
    }

    public static function cyclicInputs(): iterable
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            foreach (['stdclass-self', 'stdclass-indirect', 'array-reference', 'array-reference-in-stdclass'] as $case) {
                yield $path . ' ' . $case => [$path, $case];
            }
        }
    }

    /**
     * Source `_serializeInputs` rejects a cycle with a catchable RangeError; PHP must not exhaust memory. A
     * child process with a fixed memory limit tells a catchable exception apart from a fatal error.
     */
    #[DataProvider('cyclicInputs')]
    public function testCyclicInputFailsCatchablyBeforeAnyRequestInBoundedChild(string $path, string $case): void
    {
        $result = self::runBoundedChild($path, $case);
        self::assertSame(0, $result['status'], 'child must not die fatally: ' . $result['output']);
        $report = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(\JsonException::class, $report['class']);
        self::assertContains($report['code'], [JSON_ERROR_RECURSION, JSON_ERROR_DEPTH]);
        self::assertSame(0, $report['requests'], 'no HTTP after a rejected input');
    }

    public function testCircularConfigFallbackIsRetainedInBoundedChild(): void
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            foreach (['config-stdclass', 'config-array-reference'] as $case) {
                $result = self::runBoundedChild($path, $case);
                self::assertSame(0, $result['status'], $path . ' ' . $case . ': ' . $result['output']);
                $report = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
                self::assertNull($report['class'], $path . ' ' . $case);
                self::assertSame(1, $report['requests']);
                self::assertStringContainsString('[Circular]', $report['configurable']);
                self::assertSame('kept', $report['input']['plain']);
            }
        }
    }

    public function testSharedAcyclicInputObjectsSerializeEveryOccurrenceWithoutMutation(): void
    {
        $shared = (object) ['message' => new HumanMessage('hi'), 'n' => 1];
        $input = ['a' => $shared, 'b' => $shared, 'list' => [$shared, (object) ['again' => $shared]]];
        $expected = ['message' => ['content' => 'hi', 'role' => 'human'], 'n' => 1];
        foreach (['invoke', 'stream', 'v3'] as $path) {
            [$graph, $http] = self::graph();
            $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
            self::callPath($graph, $path, $input);
            $payload = $path === 'v3' ? $http->body(0)['params'] : $http->body(0);
            foreach ([$payload['input']['a'], $payload['input']['b'], $payload['input']['list'][0], $payload['input']['list'][1]['again']] as $copy) {
                self::assertSame($expected['n'], $copy['n'], $path);
                self::assertSame($expected['message']['role'], $copy['message']['role'], $path);
                self::assertSame($expected['message']['content'], $copy['message']['content'], $path);
            }
            self::assertInstanceOf(HumanMessage::class, $shared->message, 'caller object is not mutated');
        }
    }

    public function testVeryDeepInputFailsCatchablyWithoutRequestWhileJsonDepthStillPasses(): void
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            foreach ([false, true] as $objects) {
                [$graph, $http] = self::graph();
                $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
                try {
                    self::callPath($graph, $path, self::nested(5000, $objects));
                    self::fail('depth rejection expected');
                } catch (\JsonException $error) {
                    self::assertSame(JSON_ERROR_DEPTH, $error->getCode());
                }
                self::assertSame([], $http->requests, $path);

                [$graph, $http] = self::graph();
                $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
                self::callPath($graph, $path, self::nested(500, $objects));
                self::assertCount(1, $http->requests, $path . ' input within the json_encode depth is still sent');
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inputPaths(): iterable
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('inputPaths')]
    public function testAliasedDagFits128MAndMatchesBaselineInputWire(string $path): void
    {
        $baseline = self::runDagChild($path, true, 'dag');
        self::assertSame(0, $baseline['status'], 'baseline child: ' . $baseline['output']);
        $expected = json_decode($baseline['output'], true, flags: JSON_THROW_ON_ERROR);
        $candidate = self::runDagChild($path, false, 'dag');
        self::assertSame(0, $candidate['status'], 'candidate must not die fatally: ' . $candidate['output']);
        $actual = json_decode($candidate['output'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $expected['requests']);
        self::assertSame(1, $actual['requests']);
        // Only generated command IDs are normalized. The complete body is hashed, never decoded.
        self::assertSame($expected['body'], $actual['body']);
        self::assertTrue($actual['callerUnchanged']);
    }

    #[DataProvider('inputPaths')]
    public function testLargeDecodedDocumentFits128MAndMatchesBaselineInputWire(string $path): void
    {
        $baseline = self::runDagChild($path, true, 'doc');
        self::assertSame(0, $baseline['status'], 'baseline child: ' . $baseline['output']);
        $expected = json_decode($baseline['output'], true, flags: JSON_THROW_ON_ERROR);
        $candidate = self::runDagChild($path, false, 'doc');
        self::assertSame(0, $candidate['status'], 'candidate must not die fatally: ' . $candidate['output']);
        $actual = json_decode($candidate['output'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $expected['requests']);
        self::assertSame(1, $actual['requests']);
        self::assertSame($expected['body'], $actual['body']);
        self::assertTrue($actual['callerUnchanged']);
    }

    #[DataProvider('inputPaths')]
    public function testMessageBearingDocumentFits128MAndMatchesMessageMappedBaselineWire(string $path): void
    {
        $baseline = self::runDagChild($path, true, 'docmsg');
        self::assertSame(0, $baseline['status'], 'baseline child: ' . $baseline['output']);
        $expected = json_decode($baseline['output'], true, flags: JSON_THROW_ON_ERROR);
        $candidate = self::runDagChild($path, false, 'docmsg');
        self::assertSame(0, $candidate['status'], 'candidate must not die fatally: ' . $candidate['output']);
        $actual = json_decode($candidate['output'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $expected['requests']);
        self::assertSame(1, $actual['requests']);
        self::assertSame($expected['body'], $actual['body']);
        self::assertTrue($actual['callerUnchanged']);
    }

    #[DataProvider('inputPaths')]
    public function testMessageBearingAliasedDagFits128MAndMatchesMessageMappedBaselineWire(string $path): void
    {
        $baseline = self::runDagChild($path, true, 'msgdag');
        self::assertSame(0, $baseline['status'], 'baseline child: ' . $baseline['output']);
        $expected = json_decode($baseline['output'], true, flags: JSON_THROW_ON_ERROR);
        $candidate = self::runDagChild($path, false, 'msgdag');
        self::assertSame(0, $candidate['status'], 'candidate must not die fatally: ' . $candidate['output']);
        $actual = json_decode($candidate['output'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $expected['requests']);
        self::assertSame(1, $actual['requests']);
        self::assertSame($expected['body'], $actual['body']);
        self::assertTrue($actual['callerUnchanged']);
    }

    /**
     * Every object in this shared chain is a changed copy, so a memo hit must use the copy's own
     * height. The chain has eight objects: 503 wrappers put its deepest object at 511, 504 at 512.
     */
    public function testChangedObjectMemoHeightGuardsTheDeeperOccurrenceInEitherOrder(): void
    {
        foreach ([503 => true, 504 => false] as $wrappers => $valid) {
            foreach ([false, true] as $deepFirst) {
                $message = new HumanMessage('x');
                $shared = $message;
                for ($i = 0; $i < 8; ++$i) {
                    $shared = (object) ['next' => $shared];
                }
                $deep = $shared;
                for ($i = 0; $i < $wrappers; ++$i) {
                    $deep = ['next' => $deep];
                }
                $input = $deepFirst ? ['deep' => $deep, 'shallow' => $shared] : ['shallow' => $shared, 'deep' => $deep];
                $label = $wrappers . ($deepFirst ? ' deep-first' : ' shallow-first');
                if (!$valid) {
                    try {
                        RemoteGraphInputWalkProbe::serialize($input);
                        self::fail($label . ': a changed memo hit must not bypass its deeper occurrence');
                    } catch (\JsonException $error) {
                        self::assertSame(JSON_ERROR_DEPTH, $error->getCode(), $label);
                    }
                    continue;
                }
                $result = RemoteGraphInputWalkProbe::serialize($input);
                $copy = $result['deep'];
                for ($i = 0; $i < $wrappers; ++$i) {
                    $copy = $copy['next'];
                }
                // One changed copy serves both occurrences; materializing one per occurrence is what
                // makes message-bearing aliased DAGs exponential.
                self::assertSame($result['shallow'], $copy, $label);
                self::assertNotSame($shared, $copy, $label);
                for ($leaf = $copy, $i = 0; $i < 8; ++$i) {
                    $leaf = $leaf->next;
                }
                self::assertSame(['content' => 'x', 'role' => 'human'], array_intersect_key($leaf, ['content' => 1, 'role' => 1]), $label);
                self::assertSame($message, $shared->next->next->next->next->next->next->next->next, 'caller chain is not mutated');
            }
        }
        foreach (['invoke', 'stream', 'v3'] as $path) {
            [$graph, $http] = self::graph();
            try {
                self::callPath($graph, $path, ['shallow' => $shared, 'deep' => $deep]);
                self::fail('depth failure expected before HTTP');
            } catch (\JsonException $error) {
                self::assertSame(JSON_ERROR_DEPTH, $error->getCode());
            }
            self::assertSame([], $http->requests);
        }
    }

    public function testUnchangedContainersAreReturnedAsOriginalsAndChangedOnesAreCopied(): void
    {
        $doc = json_decode('{"rows":[{"id":1,"tags":["a","b"],"meta":{"x":1}},{"id":2,"meta":{}}],"empty":{}}');
        self::assertSame($doc, RemoteGraphInputWalkProbe::serialize($doc));
        $list = [1, ['a' => $doc], 'x'];
        self::assertSame($list, RemoteGraphInputWalkProbe::serialize($list));

        $message = new HumanMessage('hi');
        $untouched = (object) ['k' => 1];
        $branch = (object) ['msg' => $message, 'sibling' => $untouched];
        $tail = ['t' => 1, 'm' => $message];
        $tail['self'] = &$tail['t'];
        $input = (object) ['before' => $untouched, 'branch' => $branch, 'list' => [$tail, $message], 'after' => $doc];
        $result = RemoteGraphInputWalkProbe::serialize($input);

        self::assertNotSame($input, $result);
        self::assertSame($untouched, $result->before);
        self::assertSame($untouched, $result->branch->sibling);
        self::assertSame($doc, $result->after);
        self::assertSame('human', $result->branch->msg['role']);
        self::assertSame('human', $result->list[1]['role']);
        self::assertSame(['t' => 1, 'm' => $result->list[1], 'self' => 1], $result->list[0]);
        $result->list[0]['t'] = 8;
        $result->list[0]['self'] = 9;
        self::assertSame(1, $tail['t'], 'a by-value copy must not write through a caller reference');
        self::assertSame($message, $branch->msg);
        self::assertSame($message, $input->list[1]);
        self::assertSame(['before', 'branch', 'list', 'after'], array_keys(get_object_vars($result)));
    }

    public function testCompletedObjectMemoCannotBypassDeeperContainerGuard(): void
    {
        foreach ([false, true] as $deepFirst) {
            $shared = self::nested(8, true);
            $deep = $shared;
            for ($i = 0; $i < 505; ++$i) {
                $deep = ['next' => $deep];
            }
            $input = $deepFirst ? ['deep' => $deep, 'shallow' => $shared] : ['shallow' => $shared, 'deep' => $deep];
            try {
                RemoteGraphInputWalkProbe::serialize($input);
                self::fail('a completed shallow object must not bypass its deeper occurrence');
            } catch (\JsonException $error) {
                self::assertSame(JSON_ERROR_DEPTH, $error->getCode());
            }
            foreach (['invoke', 'stream', 'v3'] as $path) {
                [$graph, $http] = self::graph();
                try {
                    self::callPath($graph, $path, $input);
                    self::fail('depth failure expected before HTTP');
                } catch (\JsonException $error) {
                    self::assertSame(JSON_ERROR_DEPTH, $error->getCode());
                }
                self::assertSame([], $http->requests);
            }
        }
    }

    public function testCompletedObjectMemoPreservesValidDepthInEitherTraversalOrder(): void
    {
        foreach ([false, true] as $deepFirst) {
            $shared = self::nested(8, true);
            $deep = $shared;
            for ($i = 0; $i < 498; ++$i) {
                $deep = ['next' => $deep];
            }
            $input = $deepFirst ? ['deep' => $deep, 'shallow' => $shared] : ['shallow' => $shared, 'deep' => $deep];
            self::assertIsArray(RemoteGraphInputWalkProbe::serialize($input));
            foreach (['invoke', 'stream', 'v3'] as $path) {
                [$graph, $http] = self::graph();
                $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
                self::callPath($graph, $path, $input);
                self::assertCount(1, $http->requests);
            }
        }
    }

    public function testCompletedMemoIsPerCallAndRevisitsCallerMutation(): void
    {
        foreach (['invoke', 'stream', 'v3'] as $path) {
            [$graph, $http] = self::graph();
            $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
            $shared = (object) ['n' => 1];
            $input = ['a' => $shared, 'b' => $shared];
            self::callPath($graph, $path, $input);
            $shared->n = 2;
            self::callPath($graph, $path, $input);
            self::assertCount(2, $http->requests);
            foreach ([1, 2] as $index => $expected) {
                $body = $http->body($index);
                $payload = $path === 'v3' ? $body['params'] : $body;
                self::assertSame($expected, $payload['input']['a']['n']);
                self::assertSame($expected, $payload['input']['b']['n']);
            }
            self::assertSame(2, $shared->n);
            self::assertSame($input['a'], $input['b']);
        }
    }

    public function testBoundedChildTimeoutCleansTemporaryScriptAndProcess(): void
    {
        $script = null;
        $started = microtime(true);
        try {
            self::runScriptInBoundedChild('<?php usleep(500000);', [], 0.02, $script);
            self::fail('a delayed child must exceed its short test deadline');
        } catch (\PHPUnit\Framework\AssertionFailedError $error) {
            self::assertStringContainsString('child exceeded', $error->getMessage());
        }
        self::assertLessThan(5, microtime(true) - $started);
        self::assertNotNull($script, 'the helper reports the temporary script created by this invocation');
        self::assertFileDoesNotExist($script);
    }

    /** @return array{status: int, output: string} */
    private static function runDagChild(string $path, bool $baseline, string $shape): array
    {
        return self::runScriptInBoundedChild(<<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2];
            use LangChain\Messages\HumanMessage;
            use LangChain\Runnables\RunnableConfig;
            use LangChain\Tests\Unit\Pregel\RemoteDagWireTransport;
            use LangGraph\Pregel\RemoteGraph;
            use LangGraph\Sdk\Client;

            [, , , $path, $baseline, $shape] = $argv;
            $http = new RemoteDagWireTransport();
            $client = new Client(['apiUrl' => 'https://unit.test', 'apiKey' => null, 'callerOptions' => ['maxRetries' => 0]], $http);
            $graph = new RemoteGraph('g', $client);
            if ($shape === 'dag') {
                $input = (object) ['leaf' => 1];
                for ($i = 0; $i < 20; ++$i) {
                    $input = (object) ['l' => $input, 'r' => $input];
                }
                $unchanged = static fn (): bool => $input->l === $input->r && count(get_object_vars($input)) === 2;
            } elseif ($shape === 'msgdag') {
                // A message inside an aliased DAG changes every node, so only the changed-object memo
                // keeps the walk linear. The baseline projection pre-maps the message.
                $message = new HumanMessage('x');
                $input = (object) ['m' => $baseline === '1' ? [...$message->toDict()['data'], 'role' => $message->getType()] : $message];
                for ($i = 0; $i < 18; ++$i) {
                    $input = (object) ['l' => $input, 'r' => [$input]];
                }
                $unchanged = static function () use ($input, $message, $baseline): bool {
                    for ($node = $input; isset($node->l); $node = $node->l) {
                        if ($node->r !== [$node->l] || count(get_object_vars($node)) !== 2) {
                            return false;
                        }
                    }

                    return $baseline === '1' || $node->m === $message;
                };
            } elseif ($shape === 'docmsg') {
                // 30000 decoded rows that each carry a message, so every row and its meta are copied.
                // The baseline projection pre-maps each message to the source `{...data, role}` shape.
                $rows = [];
                for ($i = 0; $i < 30000; ++$i) {
                    $rows[] = ['id' => $i, 'name' => "row-$i", 'tags' => ['a', 'b'], 'meta' => ['x' => $i, 'y' => [1, 2]]];
                }
                $input = json_decode(json_encode(['rows' => $rows]), flags: JSON_THROW_ON_ERROR);
                unset($rows);
                foreach ($input->rows as $row) {
                    $message = new HumanMessage('x');
                    $row->meta->m = $baseline === '1' ? [...$message->toDict()['data'], 'role' => $message->getType()] : $message;
                }
                // Identity and shape fingerprint, far cheaper than serialize() of the whole document.
                $fingerprint = static function () use ($input): string {
                    $context = hash_init('sha256');
                    foreach ($input->rows as $row) {
                        $m = $row->meta->m;
                        hash_update($context, spl_object_id($row) . ':' . spl_object_id($row->meta) . ':'
                            . implode(',', array_keys(get_object_vars($row->meta))) . ':'
                            . ($m instanceof HumanMessage ? spl_object_id($m) . $m->content : json_encode($m)) . ';');
                    }

                    return hash_final($context);
                };
                $hash = $fingerprint();
                $unchanged = static fn (): bool => $fingerprint() === $hash;
            } else {
                // An ordinary json_decode document of 30000 unique rows, with no aliasing and no messages.
                // The caller keeps its source rows, as an application holding its own data would.
                $rows = [];
                for ($i = 0; $i < 30000; ++$i) {
                    $rows[] = ['id' => $i, 'name' => "row-$i", 'tags' => ['a', 'b'], 'meta' => ['x' => $i, 'y' => [1, 2]]];
                }
                $input = json_decode(json_encode(['rows' => $rows]), flags: JSON_THROW_ON_ERROR);
                $hash = hash('sha256', serialize($input));
                $unchanged = static fn (): bool => hash('sha256', serialize($input)) === $hash;
            }
            // Baseline PHP leaves plain objects to native JSON encoding. This passthrough adapter
            // projects that exact plain-DAG wire inside the current envelope, including new v3.
            // Production supports JsonSerializable unchanged; it is not an alternative graph walker.
            $wireInput = $baseline === '1' ? new class($input) implements JsonSerializable {
                public function __construct(private readonly stdClass $input) {}
                public function jsonSerialize(): stdClass { return $this->input; }
            } : $input;
            $config = new RunnableConfig(configurable: ['thread_id' => 't']);
            match ($path) {
                'invoke' => $graph->invoke($wireInput, $config),
                'stream' => iterator_to_array($graph->stream($wireInput, $config), false),
                'v3' => $graph->streamEventsV3($wireInput, $config),
            };
            echo json_encode(['requests' => count($http->bodies), 'body' => $http->bodies[0],
                'callerUnchanged' => $unchanged(),
                'peakBytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
            PHP, [$path, $baseline ? '1' : '0', $shape]);
    }

    private static function nested(int $depth, bool $objects): mixed
    {
        $value = 'leaf';
        for ($i = 0; $i < $depth; ++$i) {
            $value = $objects ? (object) ['next' => $value] : ['next' => $value];
        }

        return $value;
    }

    private static function callPath(RemoteGraph $graph, string $path, mixed $input, ?RunnableConfig $config = null): void
    {
        $config ??= new RunnableConfig(configurable: ['thread_id' => 't']);
        match ($path) {
            'invoke' => $graph->invoke($input, $config),
            'stream' => iterator_to_array($graph->stream($input, $config), false),
            'v3' => $graph->streamEventsV3($input, $config),
        };
    }

    /** @return array{status: int, output: string} */
    private static function runBoundedChild(string $path, string $case): array
    {
        $code = <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            require $argv[2];
            use LangChain\Runnables\RunnableConfig;
            use LangChain\Tests\Unit\Pregel\RemoteThreadProtocolTransport;
            use LangGraph\Pregel\RemoteGraph;
            use LangGraph\Sdk\Client;

            [, , , $path, $case] = $argv;
            $http = new RemoteThreadProtocolTransport([['type' => 'event', 'seq' => 1, 'event_id' => 'e', 'method' => 'lifecycle',
                'params' => ['namespace' => [], 'timestamp' => 1, 'data' => ['event' => 'completed']]]]);
            $http->ordinaryParts = [['event' => 'values', 'data' => ['done' => true]]];
            $graph = new RemoteGraph('g', new Client(['apiUrl' => 'https://unit.test', 'apiKey' => null, 'callerOptions' => ['maxRetries' => 0]], $http));
            $input = ['plain' => 'kept'];
            $configurable = ['thread_id' => 't'];
            $object = new stdClass();
            $array = ['x' => 1];
            match ($case) {
                'stdclass-self' => $object->self = $object,
                'stdclass-indirect' => $object->child = (object) ['parent' => $object],
                'array-reference', 'config-array-reference' => $array['self'] = &$array,
                'array-reference-in-stdclass' => ($object->items = ['owner' => $object]) && ($array['object'] = $object) && ($array['self'] = &$array),
                'config-stdclass' => $object->self = $object,
            };
            if (str_starts_with($case, 'config-')) {
                $configurable['circular'] = str_contains($case, 'array') ? $array : $object;
            } else {
                $input['cycle'] = str_contains($case, 'array') ? $array : $object;
            }
            $config = new RunnableConfig(configurable: $configurable);
            $report = ['class' => null, 'code' => null, 'message' => null];
            try {
                match ($path) {
                    'invoke' => $graph->invoke($input, $config),
                    'stream' => iterator_to_array($graph->stream($input, $config), false),
                    'v3' => $graph->streamEventsV3($input, $config),
                };
            } catch (Throwable $error) {
                $report = ['class' => $error::class, 'code' => $error->getCode(), 'message' => $error->getMessage()];
            }
            $report['requests'] = count($http->requests);
            if ($http->requests !== []) {
                $body = $http->body(0);
                $payload = $path === 'v3' ? $body['params'] : $body;
                $report['input'] = $payload['input'];
                $report['configurable'] = json_encode($payload['config']['configurable']);
            }
            echo json_encode($report);
            PHP;

        return self::runScriptInBoundedChild($code, [$path, $case]);
    }

    /** @return array{status: int, output: string} */
    private static function runScriptInBoundedChild(string $code, array $arguments, float $timeout = 15, ?string &$createdScript = null): array
    {
        $script = tempnam(sys_get_temp_dir(), 'n22-child-');
        $createdScript = $script;
        file_put_contents($script, $code);
        $process = null;
        $pipes = [];
        try {
            $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-d', 'display_errors=stderr', $script,
                dirname(__DIR__, 3), __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $errors = '';
            $deadline = microtime(true) + $timeout;
            do {
                $output .= (string) stream_get_contents($pipes[1]);
                $errors .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if ($status['running'] && microtime(true) > $deadline) {
                    proc_terminate($process, 9);
                    self::fail('child exceeded ' . $timeout . 's: ' . $errors);
                }
                if ($status['running']) {
                    usleep(10000);
                }
            } while ($status['running']);
            $output .= (string) stream_get_contents($pipes[1]);
            $errors .= (string) stream_get_contents($pipes[2]);
            if ($status['exitcode'] === 0 && $errors !== '') {
                self::fail('successful child wrote stderr: ' . $errors);
            }

            return ['status' => $status['exitcode'], 'output' => $status['exitcode'] === 0 ? $output : substr($output . $errors, 0, 400)];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            unlink($script);
        }
    }
}

/** Local wire fixture; command responses and fragmented SSE use the real SDK parser. */
final class RemoteThreadProtocolTransport implements StreamingMethodHttpClient
{
    public array $requests = [];
    public array $ordinaryParts = [];
    public bool $released = false;
    public bool $cancelFails = false;
    public int $producedChunks = 0;
    public ?\Closure $onChunk = null;
    public bool $malformedWire = false;
    public bool $commandError = false;

    public function __construct(public array $events = [])
    {
    }

    public function body(int $index): array
    {
        return json_decode($this->requests[$index]['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (str_ends_with($url, '/commands')) {
            $command = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            if ($this->commandError) {
                return new HttpResponse(200, [], json_encode(['type' => 'error', 'id' => $command['id'], 'error' => 'invalid_request', 'message' => 'bad command']));
            }

            return new HttpResponse(200, [], json_encode(['type' => 'response', 'id' => $command['id'], 'result' => ['run_id' => 'r']]));
        }
        if (str_contains($url, '/cancel')) {
            return new HttpResponse($this->cancelFails ? 500 : 200, [], '{}');
        }

        return new HttpResponse(200, [], '{"run_id":"legacy","thread_id":"legacy-thread"}');
    }

    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        $text = str_ends_with($url, '/stream/events') ? implode('', array_map(
            static fn ($event): string => 'event: message' . "\ndata: " . json_encode($event) . "\n\n", $this->events))
            : implode('', array_map(static fn ($part): string => 'event: ' . $part['event'] . "\ndata: " . json_encode($part['data']) . "\n\n", $this->ordinaryParts));
        if ($this->malformedWire) {
            $text = "event: message\ndata: {not JSON\n\n";
        }
        $chunks = function () use ($text): \Generator {
            try {
                foreach (str_split($text, 7) as $chunk) {
                    ++$this->producedChunks;
                    ($this->onChunk ?? static function (): void {})();
                    yield $chunk;
                }
            } finally {
                $this->released = true;
            }
        };

        return new StreamResponse(new HttpResponse(200, ['content-type' => 'text/event-stream']), $chunks());
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        yield from $this->requestStream('POST', $url, $headers, $body, $timeout)->body;
    }
}

/** Native PHP JSON contracts have no direct TypeScript class analogue. */
enum RemoteBoundaryColor: string
{
    case Red = 'red';
}

final class RemoteBoundaryCustomObject extends \stdClass implements \JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['custom' => true];
    }
}

final class RemoteBoundaryObjectSubclass extends \stdClass
{
}

final class RemoteBoundaryMoney implements \JsonSerializable
{
    public function __construct(private readonly int $cents)
    {
    }

    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents];
    }
}


/** Body-only DAG transport: JSON decoding the exponential wire would itself exhaust memory. */
final class RemoteDagWireTransport implements StreamingMethodHttpClient
{
    public array $bodies = [];

    private function record(string $body, bool $command): void
    {
        if ($command) {
            $body = preg_replace('/"id":(?:"[^"]*"|[0-9]+)/', '"id":"command"', $body, 1);
        }
        $this->bodies[] = ['bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): HttpResponse
    {
        $command = str_ends_with($url, '/commands');
        $this->record($body ?? '', $command);
        if ($command) {
            preg_match('/"id":(?:"([^"]*)"|([0-9]+))/', $body, $match);
            $id = isset($match[2]) ? (int) $match[2] : $match[1];

            return new HttpResponse(200, [], json_encode(['type' => 'response', 'id' => $id, 'result' => ['run_id' => 'r']]));
        }

        return new HttpResponse(200, [], '{"run_id":"r"}');
    }

    public function requestStream(string $method, string $url, array $headers, ?string $body = null, ?float $timeout = null): StreamResponse
    {
        $this->record($body ?? '', false);

        return new StreamResponse(new HttpResponse(200, ['content-type' => 'text/event-stream']), (static function (): \Generator {
            yield "event: values\ndata: {\"done\":true}\n\n";
        })());
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeout);
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        yield from $this->requestStream('POST', $url, $headers, $body, $timeout)->body;
    }

}

/** Direct guard probe: native json_encode must not conceal a completed-memo depth bypass. */
final class RemoteGraphInputWalkProbe extends RemoteGraph
{
    public static function serialize(mixed $input): mixed
    {
        return parent::serializeInputs($input);
    }
}
