<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Core vocabulary of the run-stream protocol.
 *
 * Port of `langgraph-core/src/stream/types.ts`. That file is mostly TypeScript types; the runtime parts
 * are kept here as static helpers, and the contracts are separate files: {@see StreamTransformer},
 * {@see NativeStreamTransformer}, {@see StreamEmitter}, {@see StreamHandle}.
 *
 * A protocol event ({@code ProtocolEvent}) is an array:
 *
 * ```
 * ['type' => 'event', 'seq' => int, 'method' => string,
 *  'params' => ['namespace' => list<string>, 'timestamp' => int, 'node' => string (optional), 'data' => mixed]]
 * ```
 *
 * `method` is a stream mode (`values`, `updates`, `messages`, `tools`, `checkpoints`, `tasks`, `custom`),
 * a synthesized channel (`lifecycle`, `input`) or `custom:<name>` for a remote {@see StreamChannel}.
 *
 * A namespace is a `list<string>`; an interrupt payload is `['interruptId' => string, 'payload' => mixed]`.
 */
final class Types
{
    public const NATIVE_BRAND = '__native';

    private function __construct()
    {
    }

    /**
     * Build a protocol event (the upstream `makeProtocolEvent` test helper, promoted because the mux,
     * transformers and tests all build events).
     *
     * @param list<string> $namespace
     * @return array{type: string, seq: int, method: string, params: array<string, mixed>}
     */
    public static function protocolEvent(
        string $method,
        array $namespace = [],
        mixed $data = [],
        ?string $node = null,
        int $seq = 0,
        ?int $timestamp = null,
    ): array {
        $params = ['namespace' => $namespace, 'timestamp' => $timestamp ?? self::now()];
        if ($node !== null) {
            $params['node'] = $node;
        }
        $params['data'] = $data;

        return ['type' => 'event', 'seq' => $seq, 'method' => $method, 'params' => $params];
    }

    /** Milliseconds since the epoch, upstream's `Date.now()`. */
    public static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Whether `$transformer` is a native transformer (upstream `isNativeTransformer`).
     */
    public static function isNativeTransformer(object $transformer): bool
    {
        return $transformer instanceof NativeStreamTransformer;
    }

    /**
     * A null-byte-joined key for a namespace, suitable for map lookups (upstream `nsKey`).
     *
     * @param list<string> $ns
     */
    public static function nsKey(array $ns): string
    {
        return implode("\x00", $ns);
    }

    /**
     * Whether `$ns` starts with every segment of `$prefix` (upstream `hasPrefix`).
     *
     * @param list<string> $ns
     * @param list<string> $prefix
     */
    public static function hasPrefix(array $ns, array $prefix): bool
    {
        if (\count($prefix) > \count($ns)) {
            return false;
        }
        foreach ($prefix as $i => $segment) {
            if ($ns[$i] !== $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * A JS-`Error`-like failure for a non-throwable error value.
     */
    public static function toThrowable(mixed $error): \Throwable
    {
        if ($error instanceof \Throwable) {
            return $error;
        }

        return new \RuntimeException(\is_string($error) ? $error : (json_encode($error) ?: get_debug_type($error)));
    }
}
