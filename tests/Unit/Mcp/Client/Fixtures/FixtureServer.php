<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client\Fixtures;

/**
 * The protocol logic shared by the stdio and HTTP fixture servers: a small MCP server in the
 * spirit of upstream's `dummy-stdio-server.ts`, `modern-stdio-server.ts` and `dummy-http-server.ts`.
 *
 * `handle()` takes one decoded client message and returns the messages to emit, notifications
 * first and the response last. `$ask` lets a transport that can hold a request open (stdio) put a
 * question to the client mid-call, which is how legacy elicitation works.
 */
final class FixtureServer
{
    public const MODERN_VERSION = '2026-07-28';

    public string $logLevel = 'unset';

    public function __construct(
        private readonly string $name = 'dummy-server',
        private readonly string $mode = 'legacy',
        private readonly string $legacyVersion = '2025-06-18',
    ) {
    }

    /**
     * @param array<string, mixed>                                  $message
     * @param (callable(array<string, mixed>): ?array<string, mixed>)|null $ask
     * @param array<string, mixed>                                  $context `headers` the transport received
     *
     * @return list<array<string, mixed>>
     */
    public function handle(array $message, ?callable $ask = null, array $context = []): array
    {
        if (!isset($message['method']) || !array_key_exists('id', $message)) {
            return [];
        }
        $id = $message['id'];
        $params = (array) ($message['params'] ?? []);

        $reply = static fn (array $result): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
        $error = static fn (int $code, string $text): array => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $text]];

        switch ($message['method']) {
            case 'initialize':
                $offered = (string) ($params['protocolVersion'] ?? '');
                $version = $this->mode === 'modern' && $offered === self::MODERN_VERSION ? self::MODERN_VERSION : $this->legacyVersion;

                return [$reply([
                    'protocolVersion' => $version,
                    'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass(), 'logging' => new \stdClass()],
                    'serverInfo' => ['name' => $this->name, 'version' => '1.0.0'],
                    'instructions' => 'fixture instructions',
                    'clientCapabilities' => $params['capabilities'] ?? new \stdClass(),
                ])];
            case 'ping':
                return [$reply([])];
            case 'tools/list':
                $cursor = $params['cursor'] ?? null;

                return [$reply($cursor === null
                    ? ['tools' => $this->tools(0, 3), 'nextCursor' => 'page-2']
                    : ['tools' => $this->tools(3, 99)])];
            case 'tools/call':
                return $this->callTool($id, (string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? []), $params, $ask, $context);
            case 'resources/list':
                return [$reply(['resources' => [['uri' => 'file:///hello.txt', 'name' => 'hello', 'mimeType' => 'text/plain']]])];
            case 'resources/read':
                if (($params['uri'] ?? null) !== 'file:///hello.txt') {
                    return [$error(-32002, 'Resource not found')];
                }

                return [$reply(['contents' => [['uri' => 'file:///hello.txt', 'mimeType' => 'text/plain', 'text' => 'hello world']]])];
            case 'logging/setLevel':
                $this->logLevel = (string) ($params['level'] ?? '');

                return [$reply([])];
        }

        return [$error(-32601, "Method not found: {$message['method']}")];
    }

    /**
     * @param array<string, mixed>                                  $args
     * @param array<string, mixed>                                  $params
     * @param (callable(array<string, mixed>): ?array<string, mixed>)|null $ask
     * @param array<string, mixed>                                  $context
     *
     * @return list<array<string, mixed>>
     */
    private function callTool(int|string $id, string $tool, array $args, array $params, ?callable $ask, array $context): array
    {
        $text = static fn (string $value): array => ['content' => [['type' => 'text', 'text' => $value]]];
        $result = static fn (array $payload): array => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $payload];
        $notification = static fn (string $method, array $p): array => ['jsonrpc' => '2.0', 'method' => $method, 'params' => $p];

        switch ($tool) {
            case 'test_tool':
                $out = [$notification('notifications/message', ['level' => 'info', 'message' => 'test_tool invoked with ' . ($args['input'] ?? '')])];
                $token = ((array) ($params['_meta'] ?? []))['progressToken'] ?? null;
                if ($token !== null) {
                    foreach ([1, 2, 3] as $step) {
                        $out[] = $notification('notifications/progress', ['progress' => $step, 'total' => 3, 'progressToken' => $token]);
                    }
                }
                $out[] = $result($text((string) json_encode(['input' => $args['input'] ?? null, 'meta' => $params['_meta'] ?? null, 'serverName' => $this->name])));

                return $out;
            case 'check_env':
                return [$result($text((string) (getenv((string) ($args['varName'] ?? '')) ?: 'NOT_SET')))];
            case 'show_header':
                return [$result($text((string) ($context['headers'][strtolower((string) ($args['name'] ?? ''))] ?? 'NOT_SET')))];
            case 'cwd':
                return [$result($text((string) getcwd()))];
            case 'log_level':
                return [$result($text($this->logLevel))];
            case 'fail_tool':
                return [$result(['content' => [['type' => 'text', 'text' => 'tool failed']], 'isError' => true])];
            case 'rpc_error':
                return [['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Invalid params: nope', 'data' => ['field' => 'nope']]]];
            case 'structured':
                $value = ($args['bad'] ?? false) ? 'x' : 42;

                return [$result($text('structured') + ['structuredContent' => ['n' => $value]])];
            case 'no_structured':
                return [$result($text('plain'))];
            case 'slow':
                usleep((int) (((float) ($args['seconds'] ?? 1)) * 1_000_000));

                return [$result($text('slow done'))];
            case 'approve':
                return $this->approve($id, $params, $ask, $text, $result);
        }

        return [['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => "Tool {$tool} not found"]]];
    }

    /**
     * @param array<string, mixed>                                  $params
     * @param (callable(array<string, mixed>): ?array<string, mixed>)|null $ask
     *
     * @return list<array<string, mixed>>
     */
    private function approve(int|string $id, array $params, ?callable $ask, callable $text, callable $result): array
    {
        $schema = ['type' => 'object', 'properties' => ['confirm' => ['type' => 'boolean']], 'required' => ['confirm']];

        if ($this->mode === 'modern') {
            $answer = ((array) ($params['inputResponses'] ?? []))['confirmation'] ?? null;
            if ($answer === null) {
                return [$result([
                    'resultType' => 'input_required',
                    'requestState' => 'fixture-state',
                    'inputRequests' => ['confirmation' => ['method' => 'elicitation/create', 'params' => ['mode' => 'form', 'message' => 'Approve modern?', 'requestedSchema' => $schema]]],
                ])];
            }
            if (($params['requestState'] ?? null) !== 'fixture-state') {
                return [['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => 'Missing continuation state']]];
            }

            return [$result($text((string) (((array) $answer)['action'] ?? 'unknown')))];
        }

        if ($ask === null) {
            return [$result($text('no-ask'))];
        }
        $response = $ask(['jsonrpc' => '2.0', 'id' => 'srv-1', 'method' => 'elicitation/create', 'params' => ['message' => 'Approve legacy?', 'requestedSchema' => $schema]]);
        if ($response === null || isset($response['error'])) {
            return [$result($text('elicitation-refused'))];
        }

        return [$result($text((string) (((array) ($response['result'] ?? []))['action'] ?? 'unknown')))];
    }

    /** @return list<array<string, mixed>> */
    private function tools(int $offset, int $length): array
    {
        $object = static fn (array $properties = [], array $required = []): array => ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties] + ($required === [] ? [] : ['required' => $required]);
        $tool = static fn (string $name, string $description, array $schema, array $extra = []): array => ['name' => $name, 'description' => $description, 'inputSchema' => $schema] + $extra;

        $all = [
            $tool('test_tool', 'Echoes input and request metadata', $object(['input' => ['type' => 'string']], ['input'])),
            $tool('check_env', 'Reads an environment variable', $object(['varName' => ['type' => 'string']], ['varName'])),
            $tool('show_header', 'Reads a request header', $object(['name' => ['type' => 'string']], ['name'])),
            $tool('approve', 'Asks for approval', $object()),
            $tool('fail_tool', 'Always reports isError', $object()),
            $tool('rpc_error', 'Always raises a JSON-RPC error', $object()),
            $tool('structured', 'Returns structured content', $object(['bad' => ['type' => 'boolean']]), ['outputSchema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']]]),
            $tool('no_structured', 'Declares an output schema and omits it', $object(), ['outputSchema' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']], 'required' => ['n']]]),
            $tool('slow', 'Sleeps', $object(['seconds' => ['type' => 'number']])),
            $tool('cwd', 'Working directory', $object()),
            $tool('log_level', 'Current logging level', $object()),
        ];

        return array_slice($all, $offset, $length);
    }
}
