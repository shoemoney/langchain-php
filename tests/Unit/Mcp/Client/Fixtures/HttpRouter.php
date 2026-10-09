<?php

declare(strict_types=1);

/*
 * `php -S` router for the streamable HTTP fixture server. The first path segment picks a variant:
 *
 *   /json          legacy server, JSON bodies
 *   /sse           legacy server, every response SSE framed (id: per event)
 *   /modern        modern server, JSON bodies
 *   /modern-sse    modern server, SSE framed
 *   /resume        legacy; tools/call streams one event then drops, the result waits behind GET + Last-Event-ID
 *   /auth          legacy JSON; 401 unless `Authorization: Bearer secret`
 *   /expired       legacy JSON; the session is gone after initialize (404)
 *
 * Each request is answered synchronously (the built-in server is single threaded). State lives in
 * LCPHP_MCP_DIR: log.ndjson (every request) and sessions/.
 */

use LangChain\Tests\Unit\Mcp\Client\Fixtures\FixtureServer;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$dir = (string) getenv('LCPHP_MCP_DIR');
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$variant = explode('/', trim($path, '/'))[0];
$method = $_SERVER['REQUEST_METHOD'];
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
$input = (string) file_get_contents('php://input');

file_put_contents($dir . '/log.ndjson', json_encode(['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $input]) . "\n", FILE_APPEND | LOCK_EX);

if ($variant === 'auth' && ($headers['authorization'] ?? '') !== 'Bearer secret') {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"unauthorized"}';

    return;
}

$sessionFile = static fn (string $id): string => $dir . '/session-' . preg_replace('/[^a-z0-9]/', '', $id);
$sessionId = $headers['mcp-session-id'] ?? null;

if ($method === 'DELETE') {
    if ($sessionId !== null && is_file($sessionFile($sessionId))) {
        unlink($sessionFile($sessionId));
        http_response_code(200);

        return;
    }
    http_response_code(404);

    return;
}

$sse = in_array($variant, ['sse', 'modern-sse', 'resume'], true);
$frame = static function (array $message, string $eventId) {
    echo "id: {$eventId}\nevent: message\ndata: " . json_encode($message, JSON_UNESCAPED_SLASHES) . "\n\n";
};

if ($method === 'GET') {
    $lastEventId = $headers['last-event-id'] ?? null;
    $pending = $dir . '/pending.json';
    if ($variant !== 'resume' || $lastEventId === null || !is_file($pending)) {
        http_response_code(405);

        return;
    }
    header('Content-Type: text/event-stream');
    $frame(json_decode((string) file_get_contents($pending), true), 'evt-2');

    return;
}

$message = json_decode($input, true);
if (!is_array($message)) {
    http_response_code(400);

    return;
}

$isInitialize = ($message['method'] ?? null) === 'initialize';
if (!$isInitialize) {
    if ($sessionId === null) {
        http_response_code(400);
        echo 'Mcp-Session-Id required';

        return;
    }
    if ($variant === 'expired' || !is_file($sessionFile($sessionId))) {
        http_response_code(404);
        echo 'Session not found';

        return;
    }
}

$server = new FixtureServer('http-' . $variant, str_starts_with($variant, 'modern') ? 'modern' : 'legacy');
$out = $server->handle($message, null, ['headers' => $headers]);

if (!isset($message['id'])) {
    http_response_code(202);

    return;
}

if ($isInitialize) {
    $id = bin2hex(random_bytes(8));
    touch($sessionFile($id));
    header('Mcp-Session-Id: ' . $id);
}

if ($variant === 'resume' && ($message['method'] ?? null) === 'tools/call') {
    $result = array_pop($out);
    file_put_contents($dir . '/pending.json', json_encode($result));
    header('Content-Type: text/event-stream');
    $frame(['jsonrpc' => '2.0', 'method' => 'notifications/message', 'params' => ['level' => 'info', 'message' => 'about to drop']], 'evt-1');

    return;
}

if ($sse) {
    header('Content-Type: text/event-stream');
    foreach ($out as $i => $part) {
        $frame($part, 'evt-' . ($i + 1));
    }

    return;
}

header('Content-Type: application/json');
echo json_encode(end($out), JSON_UNESCAPED_SLASHES);
