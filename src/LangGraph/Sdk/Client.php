<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

use LangChain\Utils\Http\HttpClient;

/**
 * Port of `client/index.ts`: the LangGraph Platform client.
 *
 * Carries `assistants`, `threads`, `runs`, `crons` and `store`. The internal `~ui` client is not ported.
 *
 * Every sub-client shares the one transport, so passing a fake `HttpClient` here is all a test needs.
 * The config array is documented on {@see BaseClient}.
 */
class Client
{
    public readonly AssistantsClient $assistants;

    public readonly ThreadsClient $threads;

    public readonly RunsClient $runs;

    public readonly CronsClient $crons;

    public readonly StoreClient $store;

    private readonly string $configHash;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [], ?HttpClient $http = null)
    {
        $caller = $config['callerOptions'] ?? [];

        $this->configHash = (string) json_encode([
            'apiUrl' => $config['apiUrl'] ?? null,
            'apiKey' => $config['apiKey'] ?? null,
            'timeoutMs' => $config['timeoutMs'] ?? null,
            'defaultHeaders' => $config['defaultHeaders'] ?? null,
            'streamProtocol' => $config['streamProtocol'] ?? null,
            'maxConcurrency' => $caller['maxConcurrency'] ?? null,
            'maxRetries' => $caller['maxRetries'] ?? null,
            'callbacks' => [
                'onFailedResponseHook' => ($caller['onFailedResponseHook'] ?? null) !== null,
                'onRequest' => ($config['onRequest'] ?? null) !== null,
                'fetch' => ($caller['fetch'] ?? null) !== null,
            ],
        ], \JSON_UNESCAPED_SLASHES);

        $this->assistants = new AssistantsClient($config, $http);
        $this->threads = new ThreadsClient($config, $http);
        $this->runs = new RunsClient($config, $http);
        $this->crons = new CronsClient($config, $http);
        $this->store = new StoreClient($config, $http);
    }

    /**
     * Port of `getClientConfigHash`: a stable key for the config this client was built from.
     */
    public function getClientConfigHash(): string
    {
        return $this->configHash;
    }
}
