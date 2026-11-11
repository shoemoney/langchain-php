<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Serde;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LangGraph\Checkpoint\Serde\JsonPlusDecoder::class)]
#[CoversClass(\LangGraph\Checkpoint\Serde\LcConstructorLoader::class)]
final class JsonPlusLiteralEscapingTest extends TestCase
{
    public function testTwoActualCheckpointRoundTripsKeepLiteralsAndProviderArgumentsRaw(): void
    {
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $constructor = ['lc' => 1, 'type' => 'constructor', 'id' => HumanMessage::lcId(), 'kwargs' => ['content' => 'literal']];
        $literal = ['secret' => $secret, 'constructor' => $constructor, 'zero' => ['lc' => 0], 'singleton' => ['__lc_escaped__' => $constructor]];
        $message = new AIMessage(['content' => '', 'additional_kwargs' => $literal, 'tool_calls' => [['name' => 'test', 'id' => 'call', 'args' => $literal]]]);
        $serde = new JsonPlusSerializer();
        $payloads = [];
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped(['messages' => [$message], 'genuine' => new HumanMessage('genuine')]);
            $payloads[] = $payload;
            $loaded = $serde->loadsTyped($type, $payload);
            self::assertInstanceOf(HumanMessage::class, $loaded['genuine']);
            self::assertSame('genuine', $loaded['genuine']->content);
            $message = $loaded['messages'][0];
            self::assertInstanceOf(AIMessage::class, $message);
            self::assertSame($literal, $message->additional_kwargs);
            self::assertSame($literal, $message->toolCalls[0]['args']);
        }
        self::assertSame($payloads[0], $payloads[1]);
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'fake', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]])]);
        $model = new ChatOpenAI(['apiKey' => 'fake-key', 'httpClient' => $http, 'maxRetries' => 0]);
        $model->invoke([new HumanMessage('test'), $message, new ToolMessage(['content' => 'result', 'tool_call_id' => 'call'])]);
        $request = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($literal, json_decode($request['messages'][1]['tool_calls'][0]['function']['arguments'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testEscapedSingletonStopsBottomUpRevivalAndGenuineNestedConstructorsStillLoad(): void
    {
        $constructor = (new HumanMessage('literal'))->toJson();
        $secret = ['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']];
        $literal = ['constructor' => $constructor, 'secret' => $secret, 'nested' => ['__lc_escaped__' => $constructor]];
        $serde = new JsonPlusSerializer();
        [$type, $payload] = $serde->dumpsTyped(new HumanMessage(['additional_kwargs' => ['lc' => 0] + $literal]));
        $loaded = $serde->loadsTyped($type, $payload);
        self::assertSame(['lc' => 0] + $literal, $loaded->additional_kwargs);
        [$type, $payload] = $serde->dumpsTyped(new HumanMessage(['additional_kwargs' => ['genuine' => new HumanMessage('genuine')]]));
        $loaded = $serde->loadsTyped($type, $payload);
        self::assertInstanceOf(HumanMessage::class, $loaded->additional_kwargs['genuine']);
        self::assertSame('genuine', $loaded->additional_kwargs['genuine']->content);
    }

    public static function literalRecords(): iterable
    {
        yield 'zero' => [['lc' => 0]];
        yield 'null' => [['lc' => null]];
        yield 'arbitrary' => [['lc' => 'literal']];
        yield 'secret' => [['lc' => 1, 'type' => 'secret', 'id' => ['FAKE_SECRET']]];
        yield 'constructor' => [(new HumanMessage('literal'))->toJson()];
        yield 'lc2 envelope' => [['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['literal error']]];
        yield 'singleton' => [['__lc_escaped__' => (new HumanMessage('literal'))->toJson()]];
    }

    #[DataProvider('literalRecords')]
    public function testEveryLiteralRecordSurvivesTwoCheckpointRoundTrips(array $literal): void
    {
        $message = new AIMessage(['content' => [$literal], 'additional_kwargs' => $literal, 'response_metadata' => $literal, 'tool_calls' => [['name' => 'test', 'id' => 'call', 'args' => $literal]]]);
        $serde = new JsonPlusSerializer();
        $first = null;
        for ($round = 0; $round < 2; ++$round) {
            [$type, $payload] = $serde->dumpsTyped($message);
            $first ??= $payload;
            self::assertSame($first, $payload);
            $message = $serde->loadsTyped($type, $payload);
            self::assertSame([$literal], $message->content);
            self::assertSame($literal, $message->additional_kwargs);
            self::assertSame($literal, $message->response_metadata);
            self::assertSame($literal, $message->toolCalls[0]['args']);
        }
    }

    public function testAlreadyRevivedLc2ChildRetainsItsRuntimeTypeAtConstructorBoundary(): void
    {
        $record = (new HumanMessage('genuine'))->toJson();
        $record['kwargs']['additional_kwargs'] = ['error' => ['lc' => 2, 'type' => 'constructor', 'id' => ['Error'], 'args' => ['genuine error']]];
        $message = (new JsonPlusSerializer())->loadsTyped('json', json_encode($record, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(\RuntimeException::class, $message->additional_kwargs['error']);
        self::assertSame('genuine error', $message->additional_kwargs['error']->getMessage());
    }

}
