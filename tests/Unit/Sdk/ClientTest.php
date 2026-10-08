<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangGraph\Sdk\AssistantsClient;
use LangGraph\Sdk\BaseClient;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\CronsClient;
use LangGraph\Sdk\StoreClient;
use LangGraph\Sdk\ThreadsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/index.test.ts`.
 *
 * Only "exposes sub-clients on main Client" is portable. Every other test in that file exercises
 * `threads.stream` / the protocol transport adapters, which are the `client/stream/` browser
 * machinery the completion plan marks out of scope. `client.runs` is WP-23b.
 */
#[CoversClass(Client::class)]
final class ClientTest extends TestCase
{
    public function testExposesSubClientsOnTheMainClient(): void
    {
        $client = new Client(['apiUrl' => 'http://localhost:9999', 'apiKey' => null]);

        $this->assertInstanceOf(AssistantsClient::class, $client->assistants);
        $this->assertInstanceOf(ThreadsClient::class, $client->threads);
        $this->assertInstanceOf(CronsClient::class, $client->crons);
        $this->assertInstanceOf(StoreClient::class, $client->store);
        $this->assertInstanceOf(BaseClient::class, $client->store);
    }

    public function testEverySubClientSharesTheOneTransportAndConfig(): void
    {
        $t = new RecordingTransport();
        $client = new Client(['apiUrl' => 'http://localhost:9999', 'apiKey' => 'k', 'defaultHeaders' => ['x-tenant' => 'a']], $t);

        $client->assistants->get('a');
        $client->threads->get('t');
        $client->store->getItem(['n'], 'k');

        $this->assertCount(3, $t->requests);
        foreach ($t->requests as $request) {
            $this->assertStringStartsWith('http://localhost:9999/', $request['url']);
            $this->assertSame('k', $request['headers']['x-api-key']);
            $this->assertSame('a', $request['headers']['x-tenant']);
        }
    }

    public function testConfigHashIsStableForTheSameConfigAndDiffersAcrossCredentials(): void
    {
        $a1 = new Client(['apiUrl' => 'http://h', 'apiKey' => 'a', 'timeoutMs' => 5]);
        $a2 = new Client(['apiUrl' => 'http://h', 'apiKey' => 'a', 'timeoutMs' => 5]);
        $b = new Client(['apiUrl' => 'http://h', 'apiKey' => 'b', 'timeoutMs' => 5]);

        $this->assertSame($a1->getClientConfigHash(), $a2->getClientConfigHash());
        $this->assertNotSame($a1->getClientConfigHash(), $b->getClientConfigHash());
    }

    public function testConfigHashRecordsWhichCallbacksArePresentNotTheirIdentity(): void
    {
        $plain = new Client(['apiKey' => null]);
        $hooked = new Client(['apiKey' => null, 'onRequest' => static fn (string $u, array $i): array => $i]);

        $this->assertStringContainsString('"onRequest":false', $plain->getClientConfigHash());
        $this->assertStringContainsString('"onRequest":true', $hooked->getClientConfigHash());
    }
}
