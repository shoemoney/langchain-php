<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client;

/**
 * JSON-RPC 2.0 message builders and classifiers for the MCP wire format.
 */
final class JsonRpc
{
    public const VERSION = '2.0';

    public const METHOD_NOT_FOUND = -32601;

    public const INTERNAL_ERROR = -32603;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function request(int|string $id, string $method, array $params = []): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'method' => $method] + ($params === [] ? [] : ['params' => $params]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function notification(string $method, array $params = []): array
    {
        return ['jsonrpc' => self::VERSION, 'method' => $method] + ($params === [] ? [] : ['params' => $params]);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public static function response(int|string $id, array $result = []): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorResponse(int|string|null $id, int $code, string $message, mixed $data = null): array
    {
        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message] + ($data === null ? [] : ['data' => $data]),
        ];
    }

    /** @param array<string, mixed> $message */
    public static function isRequest(array $message): bool
    {
        return isset($message['method'], $message['id']);
    }

    /** @param array<string, mixed> $message */
    public static function isNotification(array $message): bool
    {
        return isset($message['method']) && !array_key_exists('id', $message);
    }

    /** @param array<string, mixed> $message */
    public static function isResponse(array $message): bool
    {
        return !isset($message['method']) && array_key_exists('id', $message)
            && (array_key_exists('result', $message) || array_key_exists('error', $message));
    }

    /** @param array<string, mixed> $message */
    public static function encode(array $message): string
    {
        return json_encode($message, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \JsonException
     */
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('A JSON-RPC message must be a JSON object.');
        }

        return $decoded;
    }
}
