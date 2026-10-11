<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Sdk\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** RemoteGraph sends serialized message kwargs, as pinned remote.ts _serializeInputs does. */
#[CoversClass(RemoteGraph::class)]
final class RemoteGraphEscapingBoundaryTest extends TestCase
{
    public function testActualRemoteSdkWireCarriesOneLiteralWrapperAfterRepeatedCheckpoints(): void
    {
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']];
        $literal = ['lc' => 0, 'secret' => $secret, 'constructor' => $constructor];
        $message = new AIMessage(['additional_kwargs' => $literal, 'tool_calls' => [['name' => 'test', 'id' => 'call', 'args' => $literal]]]);
        $transport = new RecordingTransport([
            new HttpResponse(200, ['content-type' => 'text/event-stream'], "event: values\ndata: {\"ok\":true}\n\n"),
            new HttpResponse(200, ['content-type' => 'text/event-stream'], "event: values\ndata: {\"ok\":true}\n\n"),
        ]);
        $remote = new RemoteGraph('fake-graph', new Client(['apiUrl' => 'http://fake.invalid', 'apiKey' => null], $transport));
        self::assertSame(['ok' => true], $remote->invoke(['messages' => [$message], 'plain' => $literal]));
        $expected = $transport->bodyOf()['input'];
        self::assertSame(['__lc_escaped__' => $literal], $expected['messages'][0]['additional_kwargs']);
        self::assertSame(['__lc_escaped__' => $literal], $expected['messages'][0]['tool_calls'][0]['args']);
        self::assertSame('ai', $expected['messages'][0]['role']);
        self::assertSame($literal, $expected['plain']);
        $serde = new JsonPlusSerializer();
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped($message);
            $message = $serde->loadsTyped($type, $payload);
        }
        self::assertSame(['ok' => true], $remote->invoke(['messages' => [$message], 'plain' => $literal]));
        self::assertSame($expected, $transport->bodyOf(1)['input']);
        self::assertSame($literal, $message->additional_kwargs);
    }
}
