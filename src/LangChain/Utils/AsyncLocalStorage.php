<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Ambient per-call storage, as a synchronous stack.
 *
 * Port of `singletons/async_local_storage/{globals,index}.ts`. In Node this wraps
 * `AsyncLocalStorage` from `node:async_hooks`, whose value follows an async call
 * chain. PHP has no async context, so the same contract is delivered with
 * **synchronous static-stack semantics**:
 *
 * - {@see self::run()} pushes a store, runs the callback and pops it in a `finally`,
 *   so the store is visible to everything the callback calls, and only to that.
 * - {@see self::enterWith()} replaces the store of the innermost frame, so it lasts
 *   until the enclosing `run()` returns (or forever, at top level). That is how a
 *   child's `setContextVariable` stays invisible to its parent.
 * - The stack is process-global static state. It is correct for straight-line and
 *   nested synchronous calls. It does NOT isolate interleaved {@see \Fiber}s or the
 *   {@see Await} scheduler: a fiber suspended inside `run()` leaves its frame on the
 *   stack for whoever runs next. Do not suspend inside a `run()` callback if the
 *   store matters.
 *
 * Until {@see self::initializeGlobalInstance()} is called, {@see self::getInstance()}
 * returns a no-op instance (upstream's `MockAsyncLocalStorage`): `getStore()` is
 * always null and `run()` just calls the callback, so nothing is propagated.
 *
 * `runWithConfig()` is the provider singleton's method. Upstream also seeds a
 * LangSmith `RunTree` and consults the `langchain_tracer` handler for a parent run;
 * neither exists in the port, so the store is a plain array holding the config under
 * `extra[CHILD_CONFIG_KEY]` and the context variables under {@see self::CONTEXT_VARIABLES_KEY}.
 */
final class AsyncLocalStorage
{
    /** Upstream `Symbol.for("lc:context_variables")`. */
    public const CONTEXT_VARIABLES_KEY = 'lc:context_variables';

    /** Upstream `Symbol.for("lc:child_config")`. */
    public const CHILD_CONFIG_KEY = 'lc:child_config';

    private static ?self $global = null;

    private static ?self $mock = null;

    /** @var list<mixed> */
    private array $stack = [];

    public function __construct(private readonly bool $noop = false)
    {
    }

    /** The store of the innermost frame, or null. */
    public function getStore(): mixed
    {
        if ($this->noop || $this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)];
    }

    /**
     * Run `$callback` with `$store` as the current store, restoring the previous one afterwards.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(mixed $store, callable $callback): mixed
    {
        if ($this->noop) {
            return $callback();
        }

        $this->stack[] = $store;
        $depth = count($this->stack);
        try {
            return $callback();
        } finally {
            array_splice($this->stack, $depth - 1);
        }
    }

    /** Replace the current frame's store (see class note for how long it lasts). */
    public function enterWith(mixed $store): void
    {
        if ($this->noop) {
            return;
        }
        if ($this->stack === []) {
            $this->stack[] = $store;

            return;
        }
        $this->stack[array_key_last($this->stack)] = $store;
    }

    // ------------------------------------------------------ globals.ts / provider

    /** Upstream `setGlobalAsyncLocalStorageInstance`; null clears it (tests). */
    public static function setGlobalInstance(?self $instance): void
    {
        self::$global = $instance;
    }

    /** Upstream `getGlobalAsyncLocalStorageInstance`. */
    public static function getGlobalInstance(): ?self
    {
        return self::$global;
    }

    /** Install `$instance` as the global one, unless one is already installed. */
    public static function initializeGlobalInstance(self $instance): void
    {
        if (self::$global === null) {
            self::$global = $instance;
        }
    }

    /** The global instance, else the shared no-op one. */
    public static function getInstance(): self
    {
        return self::$global ?? (self::$mock ??= new self(true));
    }

    /** The runnable config stored by the innermost {@see self::runWithConfig()}, if any. */
    public static function getRunnableConfig(): mixed
    {
        $store = self::getInstance()->getStore();

        return is_array($store) ? ($store['extra'][self::CHILD_CONFIG_KEY] ?? null) : null;
    }

    /**
     * Run `$callback` with `$config` retrievable through {@see self::getRunnableConfig()},
     * carrying over any context variables set by the enclosing scope.
     *
     * @template T
     * @param callable(): T $callback
     * @param bool          $avoidCreatingRootRunTree when true no store is created, only context variables are carried
     * @return T
     */
    public static function runWithConfig(mixed $config, callable $callback, bool $avoidCreatingRootRunTree = false): mixed
    {
        $storage = self::getInstance();
        $previous = $storage->getStore();

        $store = $avoidCreatingRootRunTree ? null : ['extra' => [self::CHILD_CONFIG_KEY => $config]];

        if (is_array($previous) && isset($previous[self::CONTEXT_VARIABLES_KEY])) {
            $store ??= [];
            $store[self::CONTEXT_VARIABLES_KEY] = $previous[self::CONTEXT_VARIABLES_KEY];
        }

        return $storage->run($store, $callback);
    }
}
